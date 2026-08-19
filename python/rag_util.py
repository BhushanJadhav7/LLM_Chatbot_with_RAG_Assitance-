"""
rag_util.py — RAG utilities: PDF loading, text splitting, embeddings, FAISS.
"""

import os
import logging

from langchain_community.document_loaders import PyPDFLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_community.embeddings import HuggingFaceEmbeddings
from langchain_community.vectorstores import FAISS
from langchain_community.vectorstores.utils import DistanceStrategy
from transformers import AutoTokenizer

logger = logging.getLogger(__name__)

# Shared model-weight cache directory
CACHE_DIR = os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "models")
)

ENCODER_MODEL = "sentence-transformers/all-MiniLM-L12-v2"


class Encoder:
    """Sentence embedding wrapper backed by HuggingFace sentence-transformers."""

    def __init__(self, model_name: str = ENCODER_MODEL, device: str = "cpu"):
        logger.info("Loading encoder model: %s on %s", model_name, device)
        self.embedding_function = HuggingFaceEmbeddings(
            model_name=model_name,
            cache_folder=CACHE_DIR,
            model_kwargs={"device": device},
        )
        logger.info("Encoder ready.")


class FaissDb:
    """In-memory FAISS vector store built from a list of LangChain Documents."""

    def __init__(self, docs: list, embedding_function):
        logger.info("Building FAISS index from %d document chunks …", len(docs))
        self.db = FAISS.from_documents(
            docs,
            embedding_function,
            distance_strategy=DistanceStrategy.COSINE,
        )
        logger.info("FAISS index built successfully.")

    def similarity_search(self, question: str, k: int = 3) -> str:
        """Return top-k relevant chunks as a single concatenated string."""
        retrieved = self.db.similarity_search(question, k=k)
        return "".join(doc.page_content + "\n" for doc in retrieved)


def load_and_split_pdfs(file_paths: list, chunk_size: int = 256) -> list:
    """Load PDF files and split them into overlapping token-based chunks."""
    logger.info("Loading %d PDF file(s) …", len(file_paths))
    pages = []
    for path in file_paths:
        loader = PyPDFLoader(path)
        pages.extend(loader.load())
    logger.info("Loaded %d pages total.", len(pages))

    tokenizer = AutoTokenizer.from_pretrained(ENCODER_MODEL)
    splitter = RecursiveCharacterTextSplitter.from_huggingface_tokenizer(
        tokenizer=tokenizer,
        chunk_size=chunk_size,
        chunk_overlap=max(1, chunk_size // 10),
        strip_whitespace=True,
    )
    docs = splitter.split_documents(pages)
    logger.info("Split into %d chunks (chunk_size=%d).", len(docs), chunk_size)
    return docs
