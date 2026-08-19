# LLM Chatbot with RAG Assistance

A decoupled architecture for an LLM chatbot with Retrieval-Augmented Generation (RAG) capabilities.
The system features a **PHP web frontend** and a **Python FastAPI backend**.

## Architecture

```
Chatbot-main/
├── php/                    # Web frontend (PHP)
│   ├── assets/
│   │   ├── css/style.css   # Responsive styles
│   │   └── js/             # chat.js, main.js
│   ├── uploads/            # Uploaded PDF storage (PHP side)
│   ├── index.php           # Main chat interface
│   ├── chat.php            # Chat proxy endpoint
│   ├── upload.php          # File upload endpoint
│   └── config.php          # Configuration + cURL helpers
│
├── python/                 # Backend API (Python / FastAPI)
│   ├── api.py              # FastAPI server
│   ├── model.py            # LLM model (Gemma-2b-it)
│   ├── rag_util.py         # RAG utilities (FAISS, LangChain)
│   ├── requirements.txt    # Python dependencies
│   └── .env                # Environment variables (HF token, etc.)
│
├── models/                 # Model weight cache (created at runtime)
├── uploads/                # Upload cache (created at runtime)
└── README.md
```

## Features

- **LLM**: Google Gemma-2b-it with 4-bit quantization via `bitsandbytes`
- **RAG**: FAISS vector database with `sentence-transformers/all-MiniLM-L12-v2` embeddings
- **PDF Support**: Upload and process PDF documents as knowledge base
- **REST API**: FastAPI backend with auto-generated Swagger docs at `/docs`
- **PHP Frontend**: Responsive web interface with real-time chat

## Prerequisites

- **Python 3.11+** for backend
- **PHP 7.4+** with cURL extension for frontend
- **CUDA-enabled GPU** recommended (4-bit quantization requires CUDA on Windows)

## Installation

### 1. Backend Setup (Python)

```bash
cd python

# Create and activate virtual environment
python -m venv venv
venv\Scripts\activate        # Windows
# source venv/bin/activate   # Linux/macOS

# Install dependencies
pip install -r requirements.txt
```

### 2. Environment Configuration

Edit `python/.env` with your Hugging Face access token:

```env
ACCESS_TOKEN=hf_your_token_here
API_PORT=8000
DEBUG=False
```

> Obtain a token at [huggingface.co/settings/tokens](https://huggingface.co/settings/tokens).
> You also need to accept the [Gemma model license](https://huggingface.co/google/gemma-2b-it).

## Running the Application

### 1. Start the Python Backend

```bash
cd python
python api.py
```

The API will be available at `http://localhost:8000`.
Interactive docs: `http://localhost:8000/docs`

### 2. Start the PHP Frontend

```bash
cd php
php -S localhost:8080
```

Access the chat UI at `http://localhost:8080`.

> The PHP frontend is pre-configured to call the Python API at `http://127.0.0.1:8000`
> (see `php/config.php` → `FASTAPI_BASE_URL`).

## API Reference

### Health check

```
GET /health
```

### Chat

```
POST /chat
Content-Type: application/json

{
  "message": "Your question here",
  "max_new_tokens": 512,
  "k": 3
}
```

Response:
```json
{ "response": "Generated answer..." }
```

### Upload PDF

```
POST /upload
Content-Type: multipart/form-data

file=<pdf_file>
```

### Load documents for RAG

```
POST /load-documents
Content-Type: application/json

{ "chunk_size": 256 }
```

### Clear documents

```
POST /clear-documents
```

### Current settings / state

```
GET /settings
```

## Configuration

### PHP (`php/config.php`)

| Constant | Default | Description |
|----------|---------|-------------|
| `FASTAPI_BASE_URL` | `http://127.0.0.1:8000` | Python API address |
| `REQUEST_TIMEOUT` | `120` | cURL timeout (seconds) |
| `MAX_UPLOAD_SIZE` | `52428800` (50 MB) | Max PDF upload size |

### Python (`python/.env`)

| Variable | Description |
|----------|-------------|
| `ACCESS_TOKEN` | HuggingFace API token (required) |
| `API_PORT` | FastAPI port (default: `8000`) |
| `DEBUG` | Debug mode toggle |

## Troubleshooting

**"API server not responding"**
- Ensure the Python backend is running: `cd python && python api.py`
- Verify port 8000 is not blocked by a firewall or another process
- Check `php/config.php` → `FASTAPI_BASE_URL` points to the correct address

**"File upload failed"**
- Check `php/uploads/` directory exists and is writable
- File must be PDF format and under 50 MB

**"CUDA out of memory"**
- Reduce `max_new_tokens` in the chat settings
- Ensure only one API instance is running
- `bitsandbytes` 4-bit quantization requires a CUDA-capable GPU on Windows

**"bitsandbytes error on CPU"**
- 4-bit quantization is GPU-only on Windows; CPU-only inference is not supported with the default config

## Built With

- [Transformers](https://huggingface.co/transformers/) — Model loading & tokenization
- [LangChain](https://python.langchain.com/) — RAG pipeline
- [FAISS](https://github.com/facebookresearch/faiss) — Vector similarity search
- [FastAPI](https://fastapi.tiangolo.com/) — REST API backend
- [sentence-transformers](https://www.sbert.net/) — Text embeddings
