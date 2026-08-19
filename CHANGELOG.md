# CHANGELOG

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [2.1.0] — 2026-07-24

### Security
- **Removed hardcoded HuggingFace token** from `model.py`; token now read exclusively from `ACCESS_TOKEN` env variable via `python-dotenv`.
- **Tightened CORS** — restricted to `localhost:8080` and `127.0.0.1:8080` (no more wildcard `*`).
- **Removed `htmlspecialchars()`** applied to AI message in `chat.php` — was corrupting user input with HTML entities before sending to the LLM.

### Architecture
- **CPU fallback in `model.py`** — when CUDA is not available, model loads in full-precision `float32` on CPU instead of crashing. Suitable for development/testing without a GPU.
- **Thread-safe model initialisation** — double-checked locking (`threading.Lock`) prevents race conditions on the first `/chat` request.
- **FastAPI lifespan events** — startup banner now logs the upload directory, model cache path, and Swagger UI URL.
- **New `/status` endpoint** — returns `model_loaded`, `encoder_loaded`, `documents_loaded`, and `uploaded_files`. Used by the frontend for live status polling.

### Python Backend (`python/`)
- **`model.py`**: Removed `print(formatted_prompt)` debug statement. Added `logging`. Added docstrings. CPU graceful fallback.
- **`rag_util.py`**: Migrated to `langchain_text_splitters` (correct import for LangChain 0.2.x). Extracted `ENCODER_MODEL` constant. Added `logging` throughout.
- **`api.py`**: Full rewrite — structured logging, lifespan hook, `/status` endpoint, Pydantic `Field` validators, filename sanitisation on upload, proper docstrings, removed global-state `stream_chat_request`.
- **`requirements.txt`**: Fixed `langchain-text-splitters==0.0.1` → `0.2.4` (was causing pip resolution failure).

### PHP Layer (`php/`)
- **`chat.php`**: Rewrote from streaming → standard JSON POST. Fixed undefined `json_error()` → `send_json_error()`. Removed `htmlspecialchars()` on AI payload.
- **`upload.php`**: Removed unused `UPLOAD_TEMP_DIR` constant and directory creation. Added detailed PHP error code mapping. Improved MIME validation messages.

### Frontend (`php/assets/`)
- **`main.js` deleted** — was never loaded by `index.php`; dead code.
- **`chat.js` rewritten**:
  - Polls `/status` every 5 seconds to drive the header status pill.
  - Calls FastAPI `/load-documents` directly after upload (no extra PHP round-trip).
  - Fixed `fetch('../upload.php')` → `fetch('upload.php')` (wrong relative URL).
  - Fixed `fetch('../chat.php')` → `fetch('chat.php')`.
  - Uses `textContent` for user messages (XSS safe), `marked.parse` for AI responses.
  - `AbortSignal.timeout` on status fetch to avoid stale polls.
- **`style.css` redesigned** — Inter font, deep navy colour palette, gradient user bubbles, animated status pill, glassmorphic input with focus glow, improved typing indicator, better code block styling, accessible keyboard hint bar.
- **`index.php` updated** — semantic HTML5 (`header`/`main`/`footer`), ARIA labels, live-region status pill, meta description, keyboard shortcut hints.

### Project Hygiene
- **Deleted stale Streamlit-era files**: root `app.py`, `model.py`, `rag_util.py`, `requirements.txt`.
- **Deleted 18 agent-generated markdown/text files** from the project root.
- **Deleted** `setup.bat`, `setup.sh` (outdated Streamlit entry points).
- **Deleted** empty `uploads/` placeholder at root and `php/api/` directory.
- **Updated `.gitignore`**: added `uploads/*`, `!uploads/.gitkeep`, `php/uploads_temp/`.
- **README.md rewritten** to reflect FastAPI architecture, correct port (8000), correct API routes.

---

## [2.0.0] — 2026-07-21 (prior restructuring)

### Added
- PHP frontend (`php/`) with `index.php`, `chat.php`, `upload.php`, `config.php`.
- FastAPI backend (`python/api.py`) replacing Streamlit.
- FAISS vector store for RAG.
- PHP → FastAPI cURL bridge (`config.php`: `forward_file_request`, `stream_chat_request`).

### Removed
- Streamlit entry point (`app.py`).

---

## [1.0.0] — Initial

- Streamlit chatbot with Gemma-2b-it and FAISS RAG.
- `app.py`, `model.py`, `rag_util.py`.
