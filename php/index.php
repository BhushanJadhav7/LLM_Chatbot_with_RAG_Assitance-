<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="AI PDF Assistant — Ask questions about your documents using Gemma LLM and RAG.">
    <title>AI PDF Assistant</title>

    <!-- Fonts & Styles -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Markdown rendering -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/13.0.0/marked.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js" defer></script>
    <script src="assets/js/chat.js" defer></script>
</head>
<body>

<div id="app-shell">

    <!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
    <aside class="sidebar" id="sidebar" aria-label="Sidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="brand-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 14.93V15a1 1 0 0 0-2 0v1.93A8 8 0 0 1 4.07 11H6a1 1 0 0 0 0-2H4.07A8 8 0 0 1 11 4.07V6a1 1 0 0 0 2 0V4.07A8 8 0 0 1 19.93 11H18a1 1 0 0 0 0 2h1.93A8 8 0 0 1 13 16.93z"/>
                </svg>
            </div>
            <div>
                <div class="brand-name">DocuMind AI</div>
                <div class="brand-tag">PDF Intelligence</div>
            </div>
        </div>

        <!-- New Chat -->
        <button class="new-chat-btn" id="new-chat-btn" title="Start new conversation">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Conversation
        </button>

        <!-- History -->
        <div class="sidebar-section-label">Recent</div>
        <div class="history-list" id="history-list">
            <!-- populated by JS -->
        </div>

        <!-- Documents panel -->
        <div class="sidebar-docs" id="sidebar-docs">
            <div class="docs-header">
                <span class="docs-title">Documents</span>
                <span class="docs-count" id="docs-count">0</span>
            </div>
            <div id="doc-pills-container">
                <!-- doc pills injected here -->
            </div>
        </div>

        <!-- Status badge -->
        <div class="sidebar-footer">
            <div class="status-badge" id="status-badge" aria-live="polite">
                <div class="status-dot-outer" id="status-dot"></div>
                <div class="status-info">
                    <div class="status-label" id="status-text">Connecting…</div>
                    <div class="status-sublabel" id="status-sublabel">AI Backend</div>
                </div>
            </div>
        </div>

    </aside>

    <!-- ── Main Chat Panel ─────────────────────────────────────────────────── -->
    <div class="chat-panel">

        <!-- Header -->
        <header class="chat-header">
            <div class="header-left">
                <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <div>
                    <div class="header-title">AI PDF Assistant</div>
                    <div class="header-subtitle">Powered by Gemma + RAG</div>
                </div>
            </div>

            <div class="header-actions">
                <!-- Mobile status -->
                <div class="mobile-status-pill" id="mobile-status-pill">
                    <div class="status-dot-outer" id="mobile-status-dot"></div>
                    <span id="mobile-status-text">…</span>
                </div>

                <!-- Clear chat -->
                <button class="icon-btn" id="clear-btn" title="Clear conversation" aria-label="Clear conversation">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14H6L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4h6v2"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Messages -->
        <main class="chat-messages" id="chat-messages" role="log" aria-label="Chat messages" aria-live="polite">

            <!-- Welcome / empty state -->
            <div class="empty-chat-screen" id="empty-screen">
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
                </div>
            </div>

        </main>

        <!-- Scroll-to-bottom button -->
        <button class="scroll-to-bottom" id="scroll-btn" aria-label="Scroll to bottom" title="Scroll to bottom">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>

        <!-- Input Area -->
        <footer class="chat-input-area">

            <!-- Progress bar (hidden until upload) -->
            <div class="upload-progress-bar" id="upload-progress"></div>

            <div class="chat-input-wrapper" id="input-wrapper">
                <button class="upload-btn" id="upload-btn" title="Upload PDF" aria-label="Upload PDF">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14">
                        <path d="M14 2H6C4.9 2 4 2.9 4 4v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
                    </svg>
                    <span>Upload PDF</span>
                </button>

                <form class="chat-form" id="chat-form" autocomplete="off">
                    <textarea
                        id="message-input"
                        placeholder="Ask anything about your documents…"
                        rows="1"
                        aria-label="Message input"
                        maxlength="5000"
                    ></textarea>
                    <button type="submit" id="send-btn" title="Send (Enter)" aria-label="Send message">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </form>

                <!-- Hidden file input -->
                <input type="file" id="file-input" accept=".pdf" hidden multiple aria-hidden="true">
            </div>

            <p class="input-hint">
                <span><kbd>Enter</kbd> to send &nbsp;·&nbsp; <kbd>Shift+Enter</kbd> for new line</span>
            </p>
        </footer>

    </div><!-- /.chat-panel -->

</div><!-- /#app-shell -->

<!-- Toast container -->
<div class="toast-container" id="toast-container" aria-live="assertive"></div>

</body>
</html>