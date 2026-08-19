/**
 * chat.js — AI PDF Assistant  v2.0
 *
 * Features:
 *  - Sidebar toggle, history list, new conversation
 *  - Animated progress bar on PDF upload
 *  - Toast notifications (success / error / info)
 *  - Scroll-to-bottom FAB
 *  - Suggested prompt cards
 *  - Document pills in sidebar
 *  - Avatar rows for user + AI bubbles
 *  - Status badge synced to sidebar & mobile header
 *  - Markdown + syntax highlighting
 */
(function () {
    "use strict";

    // ── Constants ────────────────────────────────────────────────────────────
    const STATUS_POLL_INTERVAL = 5000;
    const STATUS_URL = "http://localhost:8000/status";

    // ── DOM refs ─────────────────────────────────────────────────────────────
    const chatMessages      = document.getElementById("chat-messages");
    const chatForm          = document.getElementById("chat-form");
    const messageInput      = document.getElementById("message-input");
    const sendBtn           = document.getElementById("send-btn");
    const uploadBtn         = document.getElementById("upload-btn");
    const fileInput         = document.getElementById("file-input");
    const emptyScreen       = document.getElementById("empty-screen");
    const statusBadge       = document.getElementById("status-badge");
    const statusText        = document.getElementById("status-text");
    const statusSubLabel    = document.getElementById("status-sublabel");
    const statusDot         = document.getElementById("status-dot");
    const mobileStatusPill  = document.getElementById("mobile-status-pill");
    const mobileStatusText  = document.getElementById("mobile-status-text");
    const mobileStatusDot   = document.getElementById("mobile-status-dot");
    const toastContainer    = document.getElementById("toast-container");
    const uploadProgress    = document.getElementById("upload-progress");
    const scrollBtn         = document.getElementById("scroll-btn");
    const sidebar           = document.getElementById("sidebar");
    const sidebarToggle     = document.getElementById("sidebar-toggle");
    const clearBtn          = document.getElementById("clear-btn");
    const newChatBtn        = document.getElementById("new-chat-btn");
    const historyList       = document.getElementById("history-list");
    const docPillsContainer = document.getElementById("doc-pills-container");
    const docsCount         = document.getElementById("docs-count");
    const suggestedPrompts  = document.getElementById("suggested-prompts");

    // ── State ────────────────────────────────────────────────────────────────
    let isAwaitingResponse = false;
    let backendOnline      = false;
    let uploadedDocs       = [];
    let conversations      = []; // [{ id, title, messages[] }]
    let activeConvId       = null;
    let messageCount       = 0;

    // ── Init ─────────────────────────────────────────────────────────────────
    document.addEventListener("DOMContentLoaded", () => {
        initMarked();
        bindEvents();
        startNewConversation();
        pollStatus();
        setInterval(pollStatus, STATUS_POLL_INTERVAL);
    });

    function initMarked() {
        if (window.marked && window.hljs) {
            marked.setOptions({
                highlight: (code, lang) => {
                    const language = hljs.getLanguage(lang) ? lang : "plaintext";
                    return hljs.highlight(code, { language }).value;
                },
                breaks: true,
                gfm: true,
            });
        }
    }

    function bindEvents() {
        chatForm.addEventListener("submit", handleSubmit);
        messageInput.addEventListener("keydown", handleKeydown);
        messageInput.addEventListener("input", autoResize);
        uploadBtn.addEventListener("click", () => fileInput.click());
        fileInput.addEventListener("change", handleFileChange);

        // Sidebar toggle
        sidebarToggle.addEventListener("click", () => {
            sidebar.classList.toggle("open");
        });

        // Close sidebar on outside click (mobile)
        document.addEventListener("click", (e) => {
            if (window.innerWidth <= 768 &&
                !sidebar.contains(e.target) &&
                !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove("open");
            }
        });

        // Clear conversation
        clearBtn.addEventListener("click", () => {
            if (messageCount === 0) return;
            clearConversation();
        });

        // New chat
        newChatBtn.addEventListener("click", () => {
            startNewConversation();
            if (window.innerWidth <= 768) sidebar.classList.remove("open");
        });

        // Scroll to bottom button
        scrollBtn.addEventListener("click", scrollToBottom);
        chatMessages.addEventListener("scroll", onMessagesScroll);

        // Suggested prompts
        if (suggestedPrompts) {
            suggestedPrompts.querySelectorAll(".prompt-card").forEach(card => {
                card.addEventListener("click", () => {
                    const prompt = card.dataset.prompt;
                    if (prompt) {
                        messageInput.value = prompt;
                        autoResize();
                        messageInput.focus();
                    }
                });
            });
        }
    }

    // ── Conversations ─────────────────────────────────────────────────────────
    function startNewConversation() {
        const id = Date.now().toString();
        const conv = { id, title: "New conversation", messages: [] };
        conversations.unshift(conv);
        activeConvId = id;
        messageCount = 0;

        // Clear the chat window
        clearChatWindow();
        renderHistoryList();
    }

    function clearConversation() {
        clearChatWindow();
        messageCount = 0;
        const conv = getActiveConv();
        if (conv) conv.messages = [];
        showToast("Conversation cleared", "info");
    }

    function clearChatWindow() {
        chatMessages.innerHTML = "";
        // Re-inject the empty/welcome screen
        const es = document.createElement("div");
        es.className = "empty-chat-screen";
        es.id = "empty-screen";
        es.innerHTML = `
            <div class="welcome-orb">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                </svg>
            </div>
            <div>
                <h2 class="welcome-title">How can I help you?</h2>
                <p class="welcome-subtitle">Upload a PDF and ask questions, or start a conversation right away.</p>
            </div>
            <div class="suggested-prompts" id="suggested-prompts">
                <div class="prompt-card" data-prompt="Summarize the key points of the document">
                    <span class="prompt-card-icon">📋</span>
                    <span class="prompt-card-text">Summarize the key points of the document</span>
                </div>
                <div class="prompt-card" data-prompt="What are the main conclusions?">
                    <span class="prompt-card-icon">🎯</span>
                    <span class="prompt-card-text">What are the main conclusions?</span>
                </div>
                <div class="prompt-card" data-prompt="Extract all important dates and events">
                    <span class="prompt-card-icon">📅</span>
                    <span class="prompt-card-text">Extract all important dates and events</span>
                </div>
                <div class="prompt-card" data-prompt="Explain this document in simple terms">
                    <span class="prompt-card-icon">💡</span>
                    <span class="prompt-card-text">Explain this in simple terms</span>
                </div>
            </div>`;

        chatMessages.appendChild(es);

        // Re-bind prompt cards
        es.querySelectorAll(".prompt-card").forEach(card => {
            card.addEventListener("click", () => {
                const prompt = card.dataset.prompt;
                if (prompt) {
                    messageInput.value = prompt;
                    autoResize();
                    messageInput.focus();
                }
            });
        });
    }

    function getActiveConv() {
        return conversations.find(c => c.id === activeConvId);
    }

    function renderHistoryList() {
        historyList.innerHTML = "";
        if (conversations.length === 0) return;

        conversations.forEach(conv => {
            const item = document.createElement("div");
            item.className = "history-item" + (conv.id === activeConvId ? " active" : "");
            item.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <span class="history-text">${escapeHtml(conv.title)}</span>`;

            item.addEventListener("click", () => {
                if (conv.id === activeConvId) return;
                switchConversation(conv.id);
            });

            historyList.appendChild(item);
        });
    }

    function switchConversation(id) {
        activeConvId = id;
        renderHistoryList();
        // For now just start fresh (no persistence across sessions)
        clearChatWindow();
        showToast("Switched conversation", "info");
    }

    function updateConvTitle(text) {
        const conv = getActiveConv();
        if (conv && conv.title === "New conversation") {
            conv.title = text.length > 36 ? text.slice(0, 36) + "…" : text;
            renderHistoryList();
        }
    }

    // ── Backend Status Polling ─────────────────────────────────────────────
    async function pollStatus() {
        try {
            const res  = await fetch(STATUS_URL, { signal: AbortSignal.timeout(4000) });
            const data = await res.json();
            backendOnline = true;
            updateStatus(data);
        } catch {
            backendOnline = false;
            setStatus("error", "Backend offline", "Check if Python API is running");
        }
    }

    function updateStatus(data) {
        if (data.model_loaded) {
            const n = data.uploaded_files?.length ?? 0;
            const sub = n > 0 ? `${n} document${n !== 1 ? "s" : ""} loaded` : "Ready to chat";
            setStatus("ready", "Model ready", sub);
        } else {
            setStatus("loading", "Loading model…", "Please wait");
        }
    }

    function setStatus(state, label, sub = "") {
        // Sidebar badge
        if (statusBadge) statusBadge.className = `status-badge ${state}`;
        if (statusText) statusText.textContent = label;
        if (statusSubLabel) statusSubLabel.textContent = sub || "AI Backend";

        // Mobile pill
        if (mobileStatusPill) mobileStatusPill.className = `mobile-status-pill ${state}`;
        if (mobileStatusText) mobileStatusText.textContent = label;
        if (mobileStatusDot) {
            const dotClass = `status-dot-outer`;
            mobileStatusDot.className = dotClass;
        }
    }

    // ── File Upload ───────────────────────────────────────────────────────────
    async function handleFileChange() {
        const files = Array.from(fileInput.files);
        if (!files.length) return;

        setInputLock(true);
        showProgress(0);

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            showProgress(((i / files.length) * 60) + 10);
            appendSystemMessage(`Uploading "${file.name}"…`);

            try {
                const formData = new FormData();
                formData.append("file", file);

                const res  = await fetch("upload.php", { method: "POST", body: formData });
                const data = await res.json();

                if (data.status === "success") {
                    showProgress(70 + (i / files.length) * 20);
                    addDocPill(file.name);
                    appendSystemMessage(`✅ "${file.name}" uploaded — processing for RAG…`);
                    await loadDocuments();
                    showToast(`"${file.name}" ready!`, "success");
                } else {
                    const msg = data.data?.message || data.message || "Upload failed.";
                    appendSystemMessage(`❌ Error uploading "${file.name}": ${msg}`);
                    showToast(`Upload failed: ${msg}`, "error");
                }
            } catch (err) {
                appendSystemMessage(`❌ Network error: ${err.message}`);
                showToast("Network error during upload", "error");
            }
        }

        showProgress(100);
        setTimeout(() => hideProgress(), 600);
        fileInput.value = "";
        setInputLock(false);
        messageInput.focus();
    }

    async function loadDocuments() {
        try {
            const res  = await fetch("http://localhost:8000/load-documents", {
                method:  "POST",
                headers: { "Content-Type": "application/json" },
                body:    JSON.stringify({ chunk_size: 256 }),
            });
            const data = await res.json();
            if (res.ok) {
                appendSystemMessage(`📚 ${data.message}`);
                pollStatus();
            } else {
                appendSystemMessage(`⚠️ Indexing failed: ${data.detail || "Unknown error."}`);
            }
        } catch (err) {
            appendSystemMessage(`⚠️ Could not reach indexing endpoint: ${err.message}`);
        }
    }

    // ── Progress bar ──────────────────────────────────────────────────────────
    function showProgress(pct) {
        uploadProgress.classList.add("visible");
        uploadProgress.style.width = `${pct}%`;
    }

    function hideProgress() {
        uploadProgress.style.width = "0%";
        uploadProgress.classList.remove("visible");
    }

    // ── Doc pills (sidebar) ───────────────────────────────────────────────────
    function addDocPill(name) {
        if (uploadedDocs.includes(name)) return;
        uploadedDocs.push(name);
        docsCount.textContent = uploadedDocs.length;

        const pill = document.createElement("div");
        pill.className = "doc-pill";
        pill.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="13" height="13">
                <path d="M14 2H6C4.9 2 4 2.9 4 4v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
            </svg>
            <span class="doc-pill-name">${escapeHtml(name)}</span>`;
        docPillsContainer.appendChild(pill);
    }

    // ── Toasts ────────────────────────────────────────────────────────────────
    function showToast(message, type = "info", duration = 3500) {
        const icons = { success: "✅", error: "❌", info: "💬" };
        const toast = document.createElement("div");
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span class="toast-icon">${icons[type] || "💬"}</span><span>${escapeHtml(message)}</span>`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = "toast-out .25s var(--ease) both";
            setTimeout(() => toast.remove(), 250);
        }, duration);
    }

    // ── Chat ──────────────────────────────────────────────────────────────────
    function handleSubmit(e) {
        e.preventDefault();
        const msg = messageInput.value.trim();
        if (!msg || isAwaitingResponse) return;
        sendMessage(msg);
    }

    function handleKeydown(e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            chatForm.dispatchEvent(new Event("submit"));
        }
    }

    async function sendMessage(message) {
        hideEmptyScreen();
        updateConvTitle(message);

        appendBubble("user", message);
        messageInput.value = "";
        autoResize();
        setInputLock(true);
        messageCount++;

        const typingRow = showTyping();

        try {
            const res  = await fetch("chat.php", {
                method:  "POST",
                headers: { "Content-Type": "application/json" },
                body:    JSON.stringify({ message }),
            });
            const data = await res.json();
            typingRow.remove();

            if (data.status === "success" && data.data?.response) {
                appendBubble("ai", data.data.response, true);
                messageCount++;
            } else {
                const detail = data.data?.message || data.message || `HTTP ${res.status}`;
                appendBubble("ai", `⚠️ ${detail}`, false);
            }
        } catch (err) {
            typingRow.remove();
            appendBubble("ai", `⚠️ Connection error: ${err.message}`, false);
        } finally {
            setInputLock(false);
            messageInput.focus();
        }
    }

    // ── UI Helpers ────────────────────────────────────────────────────────────
    function hideEmptyScreen() {
        const es = document.getElementById("empty-screen");
        if (es) es.remove();
    }

    /**
     * Append a message row (user or ai) with avatar + bubble.
     */
    function appendBubble(sender, content, isMarkdown = false) {
        const row = document.createElement("div");
        row.classList.add("message-row", sender);

        // Avatar
        const avatar = document.createElement("div");
        avatar.classList.add("msg-avatar", sender === "user" ? "user-avatar" : "ai-avatar");

        if (sender === "user") {
            avatar.textContent = "U";
        } else {
            // AI avatar with ring
            const ring = document.createElement("div");
            ring.className = "ai-avatar-ring";
            avatar.appendChild(ring);
            avatar.innerHTML += `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14" style="color:#818cf8;z-index:1;position:relative">
                    <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                </svg>`;
        }

        // Bubble wrapper
        const wrap = document.createElement("div");
        wrap.className = "bubble-wrap";

        const bubble = document.createElement("div");
        bubble.classList.add("message-bubble", sender);

        const body = document.createElement("div");
        body.classList.add("message-content");

        if (isMarkdown && window.marked) {
            body.innerHTML = marked.parse(content);
            body.querySelectorAll("pre code").forEach(el => {
                if (window.hljs) hljs.highlightElement(el);
            });
        } else {
            body.textContent = content;
        }

        bubble.appendChild(body);
        wrap.appendChild(bubble);

        // Timestamp
        const ts = document.createElement("div");
        ts.className = "msg-timestamp";
        ts.textContent = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        wrap.appendChild(ts);

        row.appendChild(avatar);
        row.appendChild(wrap);

        chatMessages.appendChild(row);
        scrollToBottom();
        return row;
    }

    function appendSystemMessage(text) {
        const row = document.createElement("div");
        row.classList.add("message-row", "system");
        const el = document.createElement("div");
        el.classList.add("message-bubble", "system");
        el.textContent = text;
        row.appendChild(el);
        chatMessages.appendChild(row);
        scrollToBottom();
        return row;
    }

    function showTyping() {
        const row = document.createElement("div");
        row.classList.add("message-row", "ai", "typing-row");

        const avatar = document.createElement("div");
        avatar.classList.add("msg-avatar", "ai-avatar");
        const ring = document.createElement("div");
        ring.className = "ai-avatar-ring";
        avatar.appendChild(ring);
        avatar.innerHTML += `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14" style="color:#818cf8;z-index:1;position:relative"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>`;

        const wrap = document.createElement("div");
        wrap.className = "bubble-wrap";

        const bubble = document.createElement("div");
        bubble.className = "message-bubble ai";
        bubble.innerHTML = `<div class="typing-indicator"><span></span><span></span><span></span></div>`;

        wrap.appendChild(bubble);
        row.appendChild(avatar);
        row.appendChild(wrap);
        chatMessages.appendChild(row);
        scrollToBottom();
        return row;
    }

    function setInputLock(locked) {
        isAwaitingResponse    = locked;
        messageInput.disabled = locked;
        sendBtn.disabled      = locked;
        uploadBtn.disabled    = locked;
    }

    function autoResize() {
        messageInput.style.height = "auto";
        const max = 180;
        const h   = Math.min(messageInput.scrollHeight, max);
        messageInput.style.height    = `${h}px`;
        messageInput.style.overflowY = messageInput.scrollHeight > max ? "auto" : "hidden";
    }

    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
        scrollBtn.classList.remove("visible");
    }

    function onMessagesScroll() {
        const distFromBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight;
        scrollBtn.classList.toggle("visible", distFromBottom > 120);
    }

    // ── Utility ───────────────────────────────────────────────────────────────
    function escapeHtml(str) {
        return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
    }

})();