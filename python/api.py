"""
api.py — FastAPI backend for the LLM Chatbot RAG Assistant.

Endpoints
---------
GET  /health           — Liveness check
GET  /status           — Detailed service state (model loaded, docs loaded, etc.)
GET  /settings         — Parameter ranges and current runtime state
POST /chat             — Send a message, receive an AI-generated response
POST /upload           — Upload a PDF for RAG context
POST /load-documents   — Embed uploaded PDFs into the FAISS index
POST /clear-documents  — Delete uploaded files and reset the FAISS index
"""

import os
import logging
import threading
from contextlib import asynccontextmanager
from typing import Optional

from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from model import ChatModel
import rag_util

# ============================================================================
# Logging
# ============================================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%H:%M:%S",
)
logger = logging.getLogger(__name__)

# ============================================================================
# Directories
# ============================================================================

UPLOAD_DIR = os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "uploads")
)
CACHE_DIR = os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "models")
)

os.makedirs(UPLOAD_DIR, exist_ok=True)
os.makedirs(CACHE_DIR, exist_ok=True)

# ============================================================================
# Global State (model caching + thread safety)
# ============================================================================

_model: Optional[ChatModel] = None
_encoder: Optional[rag_util.Encoder] = None
_db: Optional[rag_util.FaissDb] = None
_uploaded_files: list[str] = []
_model_lock = threading.Lock()
_encoder_lock = threading.Lock()


def get_model() -> ChatModel:
    """Return the cached ChatModel, initialising it on first call (thread-safe)."""
    global _model
    if _model is None:
        with _model_lock:
            if _model is None:  # double-checked locking
                logger.info("Initialising ChatModel (this may take a while) …")
                _model = ChatModel(model_id="google/gemma-2b-it", device="cuda")
    return _model


def get_encoder() -> rag_util.Encoder:
    """Return the cached Encoder, initialising it on first call (thread-safe)."""
    global _encoder
    if _encoder is None:
        with _encoder_lock:
            if _encoder is None:
                _encoder = rag_util.Encoder(
                    model_name=rag_util.ENCODER_MODEL, device="cpu"
                )
    return _encoder


# ============================================================================
# Lifespan
# ============================================================================

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup / shutdown lifecycle hook."""
    logger.info("=" * 60)
    logger.info("LLM Chatbot RAG Assistant — starting up")
    logger.info("Upload directory : %s", UPLOAD_DIR)
    logger.info("Model cache dir  : %s", CACHE_DIR)
    logger.info("API docs         : http://localhost:8000/docs")
    logger.info("=" * 60)
    yield
    logger.info("Shutting down.")


# ============================================================================
# FastAPI App
# ============================================================================

app = FastAPI(
    title="LLM Chatbot RAG Assistant",
    description=(
        "REST API for an LLM-based chatbot with Retrieval-Augmented Generation. "
        "Backend model: google/gemma-2b-it. Embeddings: all-MiniLM-L12-v2."
    ),
    version="2.1.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost:8080",
        "http://127.0.0.1:8080",
        "http://localhost:8000",
        "http://127.0.0.1:8000",
    ],
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["Content-Type", "Accept"],
)

# ============================================================================
# Pydantic Models
# ============================================================================

class ChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=5000, description="User message")
    max_new_tokens: int = Field(512, ge=128, le=4096)
    k: int = Field(3, ge=1, le=10)


class ChatResponse(BaseModel):
    response: str


class UploadResponse(BaseModel):
    filename: str
    message: str


class LoadDocumentsRequest(BaseModel):
    chunk_size: int = Field(256, ge=64, le=1024)


class LoadDocumentsResponse(BaseModel):
    doc_count: int
    file_count: int
    message: str


class ClearResponse(BaseModel):
    message: str


class StatusResponse(BaseModel):
    model_config = {"protected_namespaces": ()}

    status: str
    model_loaded: bool
    encoder_loaded: bool
    documents_loaded: bool
    uploaded_files: list[str]


# ============================================================================
# Helper
# ============================================================================

def _save_upload(uploaded_file: UploadFile) -> str:
    """Persist an uploaded file to UPLOAD_DIR and return its path."""
    # Basic filename sanitisation — strip path components
    safe_name = os.path.basename(uploaded_file.filename or "upload.pdf")
    file_path = os.path.join(UPLOAD_DIR, safe_name)
    with open(file_path, "wb") as fh:
        fh.write(uploaded_file.file.read())
    return file_path


# ============================================================================
# Endpoints
# ============================================================================

@app.get("/health", tags=["Monitoring"])
async def health():
    """Simple liveness probe."""
    return {"status": "ok", "service": "LLM Chatbot RAG Assistant"}


@app.get("/status", response_model=StatusResponse, tags=["Monitoring"])
async def status():
    """Detailed service state — useful for the frontend to poll on startup."""
    return StatusResponse(
        status="ok",
        model_loaded=_model is not None,
        encoder_loaded=_encoder is not None,
        documents_loaded=_db is not None,
        uploaded_files=list(_uploaded_files),
    )


@app.get("/settings", tags=["Monitoring"])
async def get_settings():
    """Expose parameter ranges and current runtime state."""
    return {
        "models": {
            "model_id": "google/gemma-2b-it",
            "encoder_model": rag_util.ENCODER_MODEL,
            "quantization": "4-bit (GPU) / float32 (CPU fallback)",
        },
        "parameters": {
            "max_new_tokens": {"min": 128, "max": 4096, "default": 512},
            "k": {"min": 1, "max": 10, "default": 3},
        },
        "state": {
            "documents_loaded": _db is not None,
            "uploaded_files": list(_uploaded_files),
            "model_cached": _model is not None,
            "encoder_cached": _encoder is not None,
        },
    }


@app.post("/chat", response_model=ChatResponse, tags=["Chat"])
async def chat(request: ChatRequest):
    """
    Generate an AI response.

    If documents have been loaded via /load-documents, the top-k most relevant
    chunks are retrieved from the FAISS index and injected as context.
    """
    try:
        model = get_model()

        context: Optional[str] = None
        if _db is not None and _uploaded_files:
            context = _db.similarity_search(request.message, k=request.k)

        response = model.generate(
            question=request.message,
            context=context,
            max_new_tokens=request.max_new_tokens,
        )
        return ChatResponse(response=response)

    except Exception as exc:
        logger.exception("Error generating response: %s", exc)
        raise HTTPException(status_code=500, detail=f"Inference error: {exc}")


@app.post("/upload", response_model=UploadResponse, tags=["Documents"])
async def upload(file: UploadFile = File(...)):
    """
    Upload a PDF.  The file is stored on disk but NOT yet embedded —
    call /load-documents afterwards to build / rebuild the FAISS index.
    """
    if not (file.filename or "").lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only PDF files are accepted.")

    try:
        file_path = _save_upload(file)
        safe_name = os.path.basename(file_path)
        if safe_name not in _uploaded_files:
            _uploaded_files.append(safe_name)
        logger.info("File uploaded: %s → %s", file.filename, file_path)
        return UploadResponse(
            filename=safe_name,
            message=f"'{safe_name}' uploaded successfully. Call /load-documents to embed it.",
        )
    except Exception as exc:
        logger.exception("Upload error: %s", exc)
        raise HTTPException(status_code=500, detail=f"Upload failed: {exc}")


@app.post("/load-documents", response_model=LoadDocumentsResponse, tags=["Documents"])
async def load_documents(request: LoadDocumentsRequest):
    """
    Embed all uploaded PDFs into a FAISS vector index.
    Re-running this rebuilds the index (useful after adding more files).
    """
    global _db

    if not _uploaded_files:
        raise HTTPException(status_code=400, detail="No files have been uploaded yet.")

    file_paths = [os.path.join(UPLOAD_DIR, fn) for fn in _uploaded_files]
    missing = [p for p in file_paths if not os.path.exists(p)]
    if missing:
        raise HTTPException(
            status_code=404,
            detail=f"Files not found on disk: {missing}. Re-upload them.",
        )

    try:
        docs = rag_util.load_and_split_pdfs(file_paths, chunk_size=request.chunk_size)
        encoder = get_encoder()
        _db = rag_util.FaissDb(docs=docs, embedding_function=encoder.embedding_function)
        return LoadDocumentsResponse(
            doc_count=len(docs),
            file_count=len(_uploaded_files),
            message=f"Indexed {len(_uploaded_files)} file(s) → {len(docs)} chunks.",
        )
    except Exception as exc:
        logger.exception("Document loading error: %s", exc)
        raise HTTPException(status_code=500, detail=f"Indexing failed: {exc}")


@app.post("/clear-documents", response_model=ClearResponse, tags=["Documents"])
async def clear_documents():
    """Remove all uploaded files and reset the FAISS index."""
    global _db, _uploaded_files

    _db = None
    cleared = list(_uploaded_files)
    for filename in cleared:
        path = os.path.join(UPLOAD_DIR, filename)
        try:
            if os.path.exists(path):
                os.remove(path)
        except OSError as exc:
            logger.warning("Could not delete %s: %s", path, exc)
    _uploaded_files = []
    logger.info("Cleared %d document(s).", len(cleared))
    return ClearResponse(message=f"Cleared {len(cleared)} document(s) successfully.")


# ============================================================================
# Entry point
# ============================================================================

if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("API_PORT", "8000"))
    uvicorn.run(app, host="0.0.0.0", port=port, log_level="info")
