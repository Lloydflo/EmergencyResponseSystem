<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/interagency.php');

$pageTitle = 'Inter-Agency Conversations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <style>
        :root {
            --ia-bg: #f4f7fb;
            --ia-card: #ffffff;
            --ia-border: #dfe6ee;
            --ia-text: #122031;
            --ia-muted: #5f7286;
            --ia-primary: #0f766e;
            --ia-primary-dark: #115e59;
            --ia-alert: #b45309;
            --ia-danger: #b91c1c;
            --ia-soft: #edf3f8;
        }

        .main-content {
            background:
                radial-gradient(circle at 85% 5%, rgba(14, 165, 233, 0.1), transparent 32%),
                radial-gradient(circle at 10% 5%, rgba(16, 185, 129, 0.12), transparent 35%),
                var(--ia-bg);
            padding: 3rem 1.5rem;
        }

        .ia-shell {
            padding-top: 0.75rem;
        }

        .ia-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .ia-head h1 {
            margin: 0;
            color: var(--ia-text);
            font-size: 1.7rem;
            line-height: 1.2;
        }

        .ia-head p {
            margin: 0.3rem 0 0;
            color: var(--ia-muted);
            font-size: 0.94rem;
        }

        .ia-pill {
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
            border-radius: 999px;
            padding: 0.45rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .ia-overview {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .ia-stat {
            background: var(--ia-card);
            border: 1px solid var(--ia-border);
            border-radius: 12px;
            padding: 0.8rem 0.9rem;
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.05);
        }

        .ia-stat-label {
            color: var(--ia-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .ia-stat-value {
            margin-top: 0.35rem;
            color: var(--ia-text);
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1;
        }

        .ia-board {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 0.9rem;
            min-height: 620px;
        }

        .ia-list-panel,
        .ia-chat-panel {
            background: var(--ia-card);
            border: 1px solid var(--ia-border);
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .ia-list-top {
            padding: 0.85rem;
            border-bottom: 1px solid var(--ia-border);
            background: #f8fbff;
        }

        .ia-search {
            position: relative;
            margin-bottom: 0.65rem;
        }

        .ia-search i {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #7b8fa4;
            font-size: 0.8rem;
        }

        .ia-search input {
            width: 100%;
            border: 1px solid #cfdae6;
            border-radius: 9px;
            padding: 0.62rem 0.7rem 0.62rem 2rem;
            font-size: 0.9rem;
            color: var(--ia-text);
            background: #fff;
        }

        .ia-search input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
            outline: none;
        }

        .ia-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.45rem;
        }

        .ia-tab {
            border: 1px solid #d4dde8;
            background: #fff;
            color: #35516d;
            border-radius: 8px;
            padding: 0.45rem 0.25rem;
            font-size: 0.79rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .ia-tab:hover {
            background: #f1f6fb;
        }

        .ia-tab.active {
            border-color: #0f766e;
            background: #0f766e;
            color: #fff;
        }

        .ia-thread-list {
            max-height: 545px;
            overflow-y: auto;
        }

        .ia-thread {
            width: 100%;
            border: none;
            border-bottom: 1px solid #edf2f7;
            background: #fff;
            text-align: left;
            padding: 0.85rem;
            cursor: pointer;
            transition: 0.2s ease;
            display: flex;
            gap: 0.7rem;
        }

        .ia-thread:hover {
            background: #f8fbff;
        }

        .ia-thread.active {
            background: #ebf8f6;
            border-left: 4px solid var(--ia-primary);
            padding-left: calc(0.85rem - 4px);
        }

        .ia-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .ia-avatar.department {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
        }

        .ia-avatar.responder {
            background: linear-gradient(135deg, #14b8a6 0%, #0f766e 100%);
        }

        .ia-thread-main {
            flex: 1;
            min-width: 0;
        }

        .ia-thread-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .ia-thread-name {
            margin: 0;
            color: #102132;
            font-size: 0.91rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-thread-time {
            color: #7b8fa4;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .ia-thread-sub {
            margin: 0.18rem 0 0;
            color: #47627b;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .ia-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        .ia-dot.online {
            background: #22c55e;
        }

        .ia-dot.busy {
            background: #f59e0b;
        }

        .ia-dot.offline {
            background: #94a3b8;
        }

        .ia-thread-preview {
            margin: 0.3rem 0 0;
            color: #5f7286;
            font-size: 0.78rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-unread {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 19px;
            height: 19px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 0.69rem;
            font-weight: 700;
            padding: 0 0.3rem;
        }

        .ia-empty-list {
            padding: 2rem 1rem;
            color: var(--ia-muted);
            text-align: center;
            font-size: 0.88rem;
        }

        .ia-chat-head {
            border-bottom: 1px solid var(--ia-border);
            padding: 0.85rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            background: #f8fbff;
        }

        .ia-chat-title {
            margin: 0;
            color: var(--ia-text);
            font-size: 1rem;
            font-weight: 800;
        }

        .ia-chat-meta {
            margin: 0.2rem 0 0;
            color: #56708a;
            font-size: 0.79rem;
        }

        .ia-chat-badge {
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
            border-radius: 999px;
            padding: 0.35rem 0.7rem;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .ia-chat-body {
            height: 440px;
            overflow-y: auto;
            padding: 1rem;
            background:
                linear-gradient(to bottom, rgba(248, 250, 252, 0.9), rgba(241, 245, 249, 0.9));
        }

        .ia-message {
            margin-bottom: 0.78rem;
            display: flex;
            flex-direction: column;
            max-width: 80%;
        }

        .ia-message .meta {
            color: #64748b;
            font-size: 0.72rem;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }

        .ia-message .bubble {
            border-radius: 12px;
            padding: 0.68rem 0.78rem;
            font-size: 0.9rem;
            line-height: 1.42;
            box-shadow: 0 2px 7px rgba(15, 23, 42, 0.07);
            word-break: break-word;
        }

        .ia-message.incoming {
            align-items: flex-start;
        }

        .ia-message.incoming .bubble {
            background: #fff;
            border: 1px solid #dce6f1;
            color: #17283a;
        }

        .ia-message.outgoing {
            margin-left: auto;
            align-items: flex-end;
        }

        .ia-message.outgoing .bubble {
            background: linear-gradient(135deg, #0f766e 0%, #0f766e 65%, #0d9488 100%);
            color: #fff;
            border: 1px solid #0f766e;
        }

        .ia-chat-compose {
            border-top: 1px solid var(--ia-border);
            padding: 0.85rem 1rem;
            background: #fff;
        }

        .ia-form-row {
            display: grid;
            grid-template-columns: 160px 1fr auto;
            gap: 0.6rem;
            align-items: center;
        }

        .ia-select,
        .ia-input {
            border: 1px solid #cdd9e5;
            border-radius: 9px;
            font-size: 0.9rem;
            padding: 0.63rem 0.72rem;
            color: var(--ia-text);
            background: #fff;
            width: 100%;
        }

        .ia-select:focus,
        .ia-input:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .ia-send {
            border: none;
            background: var(--ia-primary);
            color: #fff;
            border-radius: 9px;
            padding: 0.63rem 0.92rem;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .ia-send:hover {
            background: var(--ia-primary-dark);
        }

        .ia-note {
            margin-top: 0.55rem;
            font-size: 0.75rem;
            color: #64748b;
        }

        @media (max-width: 1080px) {
            .ia-board {
                grid-template-columns: 1fr;
            }

            .ia-thread-list {
                max-height: 320px;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: 1rem 0.8rem;
            }

            .ia-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .ia-overview {
                grid-template-columns: 1fr;
            }

            .ia-form-row {
                grid-template-columns: 1fr;
            }

            .ia-chat-body {
                height: 390px;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container ia-shell">
            <section class="ia-head">
                <div>
                    <h1>Inter-Agency Conversations</h1>
                    <p>Unified communication panel para sa departments at responders during active incidents.</p>
                </div>
                <div class="ia-pill">
                    <i class="fas fa-signal"></i> Coordination Hub Live
                </div>
            </section>

            <section class="ia-overview">
                <article class="ia-stat">
                    <div class="ia-stat-label">Total Threads</div>
                    <div class="ia-stat-value" id="iaTotalThreads">0</div>
                </article>
                <article class="ia-stat">
                    <div class="ia-stat-label">Active Responders</div>
                    <div class="ia-stat-value" id="iaActiveResponders">0</div>
                </article>
                <article class="ia-stat">
                    <div class="ia-stat-label">Unread Messages</div>
                    <div class="ia-stat-value" id="iaUnreadCount">0</div>
                </article>
            </section>

            <section class="ia-board">
                <aside class="ia-list-panel">
                    <div class="ia-list-top">
                        <div class="ia-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="threadSearchInput" placeholder="Search department or responder...">
                        </div>
                        <div class="ia-tabs">
                            <button type="button" class="ia-tab active" data-filter="all">All</button>
                            <button type="button" class="ia-tab" data-filter="department">Departments</button>
                            <button type="button" class="ia-tab" data-filter="responder">Responders</button>
                        </div>
                    </div>
                    <div class="ia-thread-list" id="threadList" aria-live="polite"></div>
                </aside>

                <section class="ia-chat-panel">
                    <div class="ia-chat-head" id="chatHeader"></div>
                    <div class="ia-chat-body" id="chatTimeline"></div>
                    <div class="ia-chat-compose">
                        <form id="chatForm">
                            <div class="ia-form-row">
                                <select id="messagePriority" class="ia-select">
                                    <option value="Routine">Routine</option>
                                    <option value="Urgent">Urgent</option>
                                    <option value="Critical">Critical</option>
                                </select>
                                <input type="text" id="messageInput" class="ia-input" maxlength="260" placeholder="Type update for selected thread...">
                                <button type="submit" class="ia-send">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            </div>
                        </form>
                        <div class="ia-note">Tip: choose thread sa kaliwa, then send incident update directly to that department/responder.</div>
                    </div>
                </section>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        (function () {
            const state = {
                threads: [],
                filter: 'all',
                query: '',
                activeId: '',
                lastIdByDept: {},
                currentUser: { id: 0, name: 'Admin' },
                poller: null
            };

            const threadListEl = document.getElementById('threadList');
            const chatHeaderEl = document.getElementById('chatHeader');
            const chatTimelineEl = document.getElementById('chatTimeline');
            const threadSearchInput = document.getElementById('threadSearchInput');
            const messageInput = document.getElementById('messageInput');
            const messagePriority = document.getElementById('messagePriority');
            const chatForm = document.getElementById('chatForm');
            const totalThreadsEl = document.getElementById('iaTotalThreads');
            const activeRespondersEl = document.getElementById('iaActiveResponders');
            const unreadCountEl = document.getElementById('iaUnreadCount');

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function rel(dateLike) {
                const d = new Date(dateLike);
                if (isNaN(d.getTime())) return 'just now';
                const mins = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
                if (!mins) return 'just now';
                if (mins < 60) return `${mins}m ago`;
                if (mins < 1440) return `${Math.round(mins / 60)}h ago`;
                return `${Math.round(mins / 1440)}d ago`;
            }

            function time(dateLike) {
                const d = new Date(dateLike);
                if (isNaN(d.getTime())) return 'Now';
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            function activeThread() {
                return state.threads.find((item) => item.id === state.activeId) || null;
            }

            function filteredThreads() {
                return state.threads.filter((item) => {
                    const filterMatch = state.filter === 'all' ? true : item.kind === state.filter;
                    if (!filterMatch) return false;
                    if (!state.query) return true;
                    const preview = item.last_text ? `${item.last_sender_name || ''} ${item.last_text}` : '';
                    const hay = `${item.title || ''} ${preview}`.toLowerCase();
                    return hay.includes(state.query.toLowerCase());
                });
            }

            function renderOverview(stats) {
                totalThreadsEl.textContent = String((stats && stats.total_threads) || state.threads.length || 0);
                activeRespondersEl.textContent = String((stats && stats.active_responders) || 0);
                unreadCountEl.textContent = String((stats && stats.unread_messages) || 0);
            }

            function previewText(item) {
                const text = String(item.last_text || '').trim();
                const sender = String(item.last_sender_name || '').trim();
                if (!text) return 'No messages yet';
                if (!sender) return text;
                const senderLabel = sender === state.currentUser.name ? 'You' : sender;
                return `${senderLabel}: ${text}`;
            }

            function renderThreadList() {
                const items = filteredThreads();
                if (!items.length) {
                    threadListEl.innerHTML = '<div class="ia-empty-list">No conversations match your filter.</div>';
                    return;
                }

                threadListEl.innerHTML = items.map((item) => {
                    const activeClass = item.id === state.activeId ? 'active' : '';
                    const unread = item.unread > 0 ? `<span class="ia-unread">${item.unread}</span>` : '';
                    const avatarType = item.kind === 'department' ? 'department' : 'responder';
                    const stat = item.status === 'busy' ? 'busy' : (item.status === 'offline' ? 'offline' : 'online');
                    return `
                        <button type="button" class="ia-thread ${activeClass}" data-id="${escapeHtml(item.id)}">
                            <div class="ia-avatar ${avatarType}">
                                <i class="fas ${escapeHtml(item.icon || 'fa-comments')}"></i>
                            </div>
                            <div class="ia-thread-main">
                                <div class="ia-thread-row">
                                    <p class="ia-thread-name">${escapeHtml(item.title || item.id)}</p>
                                    <span class="ia-thread-time">${escapeHtml(rel(item.last_at))}</span>
                                </div>
                                <p class="ia-thread-sub">
                                    <span class="ia-dot ${escapeHtml(stat)}"></span>
                                    ${escapeHtml(item.kind === 'department' ? 'Department Channel' : 'Responder Channel')}
                                </p>
                                <div class="ia-thread-row">
                                    <p class="ia-thread-preview">${escapeHtml(previewText(item))}</p>
                                    ${unread}
                                </div>
                            </div>
                        </button>
                    `;
                }).join('');
            }

            function renderChatHeader() {
                const active = activeThread();
                if (!active) {
                    chatHeaderEl.innerHTML = '<p class="ia-chat-title">No thread selected</p>';
                    return;
                }
                const statusLabel = active.status === 'online' ? 'Online' : (active.status === 'busy' ? 'Busy' : 'Offline');
                chatHeaderEl.innerHTML = `
                    <div>
                        <p class="ia-chat-title">${escapeHtml(active.title || active.id)}</p>
                        <p class="ia-chat-meta">${escapeHtml(active.kind === 'department' ? 'Department' : 'Responder')} channel · Status: ${escapeHtml(statusLabel)}</p>
                    </div>
                    <div class="ia-chat-badge">Last activity ${escapeHtml(rel(active.last_at))}</div>
                `;
            }

            function appendMessage(item) {
                const outgoing = !!item.is_self;
                const who = outgoing ? 'You' : (item.sender_name || 'System');
                const html = `
                    <article class="ia-message ${outgoing ? 'outgoing' : 'incoming'}">
                        <div class="meta">${escapeHtml(who)} · ${escapeHtml(time(item.created_at))}</div>
                        <div class="bubble">${escapeHtml(item.text)}</div>
                    </article>
                `;
                chatTimelineEl.insertAdjacentHTML('beforeend', html);
            }

            async function loadThreads() {
                const res = await fetch('api/interagency_chat_threads.php', { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;

                state.threads = Array.isArray(data.threads) ? data.threads : [];
                if (data.current_user) {
                    state.currentUser.id = Number(data.current_user.id) || 0;
                    state.currentUser.name = String(data.current_user.name || 'Admin');
                }
                if (!state.activeId || !state.threads.some((item) => item.id === state.activeId)) {
                    state.activeId = state.threads.length ? state.threads[0].id : '';
                }
                renderOverview(data.stats || null);
                renderThreadList();
                renderChatHeader();
            }

            async function loadMessages(initial, markRead) {
                const active = activeThread();
                if (!active) {
                    chatTimelineEl.innerHTML = '';
                    return;
                }

                const dept = String(active.department || '');
                const params = new URLSearchParams({ department: dept });
                const lastId = Number(state.lastIdByDept[dept] || 0);
                if (lastId > 0) params.set('since_id', String(lastId));
                if (markRead) params.set('mark_read', '1');

                const res = await fetch('api/interagency_chat_feed.php?' + params.toString(), { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;

                if (initial) chatTimelineEl.innerHTML = '';
                const items = Array.isArray(data.items) ? data.items : [];
                if (!items.length) {
                    if (initial) chatTimelineEl.innerHTML = '<div class="ia-empty-list">No messages yet for this thread.</div>';
                    return;
                }

                const list = lastId === 0 ? items.slice().reverse() : items;
                list.forEach((item) => {
                    if (item.id > (state.lastIdByDept[dept] || 0)) state.lastIdByDept[dept] = item.id;
                    appendMessage(item);
                });

                const last = list[list.length - 1];
                if (last) {
                    active.last_text = last.text;
                    active.last_sender_name = last.sender_name;
                    active.last_sender_role = last.sender_role;
                    active.last_at = last.created_at;
                    active.unread = 0;
                }

                chatTimelineEl.scrollTop = chatTimelineEl.scrollHeight;
                renderThreadList();
                renderChatHeader();
            }

            async function selectThread(threadId) {
                const target = state.threads.find((item) => item.id === threadId);
                if (!target) return;
                state.activeId = target.id;
                renderThreadList();
                renderChatHeader();
                chatTimelineEl.innerHTML = '';
                try {
                    await loadMessages(true, true);
                    await loadThreads();
                } catch (_) {}
            }

            async function handleSendMessage(event) {
                event.preventDefault();
                const text = messageInput.value.trim();
                if (!text) return;
                const active = activeThread();
                if (!active) return;

                const priority = String(messagePriority.value || 'Routine');
                const payload = `[${priority.toUpperCase()}] ${text}`;
                try {
                    const res = await fetch('api/activity_event.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'chat',
                            entity_type: 'agency_chat',
                            entity_id: active.entity_id,
                            details: payload
                        })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        const reason = (data && (data.detail || data.error)) ? String(data.detail || data.error) : 'Send failed';
                        alert('Failed to send message: ' + reason);
                        return;
                    }
                    messageInput.value = '';
                    await loadMessages(false, true);
                    await loadThreads();
                } catch (_) {}
            }

            function bindEvents() {
                document.querySelectorAll('.ia-tab').forEach((tab) => {
                    tab.addEventListener('click', () => {
                        document.querySelectorAll('.ia-tab').forEach((btn) => btn.classList.remove('active'));
                        tab.classList.add('active');
                        state.filter = tab.getAttribute('data-filter') || 'all';
                        renderThreadList();
                    });
                });

                threadSearchInput.addEventListener('input', () => {
                    state.query = threadSearchInput.value.trim().toLowerCase();
                    renderThreadList();
                });

                threadListEl.addEventListener('click', (event) => {
                    const thread = event.target.closest('.ia-thread');
                    if (!thread) return;
                    const id = thread.getAttribute('data-id');
                    if (!id) return;
                    selectThread(id);
                });

                chatForm.addEventListener('submit', handleSendMessage);
            }

            document.addEventListener('DOMContentLoaded', async () => {
                bindEvents();
                try {
                    await loadThreads();
                    if (state.activeId) await selectThread(state.activeId);
                } catch (_) {}

                state.poller = setInterval(async () => {
                    try {
                        await loadThreads();
                        await loadMessages(false, false);
                    } catch (_) {}
                }, 5000);
            });
        })();
    </script>
</body>
</html>
