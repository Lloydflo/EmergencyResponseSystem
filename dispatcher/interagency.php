<?php
$pageTitle = 'Inter-Agency Coordination';
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/interagency.php');
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
    <link rel="stylesheet" href="css/cards.css">
    <style>
        .ia-wrap { margin-top: 3.5rem; display: grid; gap: 1rem; }
        .ia-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.9rem; }
        .ia-card, .ia-pane, .ia-chat { background: #f4f7f7; border: 1px solid #d7e2e5; border-radius: 14px; box-shadow: 0 2px 8px rgba(9, 36, 44, 0.06); }
        .ia-card { padding: 0.8rem 1rem; min-height: 78px; }
        .ia-card p { margin: 0 0 0.25rem; font-size: 0.76rem; text-transform: uppercase; letter-spacing: .04em; color: #496070; font-weight: 700; }
        .ia-card h3 { margin: 0; font-size: 2rem; line-height: 1; color: #09242c; }
        .ia-main { display: grid; grid-template-columns: 390px 1fr; gap: 1rem; min-height: calc(100vh - 255px); }
        .ia-pane { overflow: hidden; display: flex; flex-direction: column; }
        .ia-pane-top { padding: 0.8rem; border-bottom: 1px solid #d7e2e5; display: grid; gap: 0.75rem; }
        .ia-search { display: flex; align-items: center; gap: 0.55rem; border: 1px solid #c6d7db; background: #fff; border-radius: 10px; padding: 0.6rem 0.8rem; }
        .ia-search i { color: #557487; font-size: 0.92rem; }
        .ia-search input { border: 0; outline: 0; width: 100%; background: transparent; font-size: 1rem; color: #243a43; }
        .ia-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.55rem; }
        .ia-filter { border: 1px solid #bfcfd3; background: #f1f5f6; color: #1f4560; border-radius: 10px; padding: 0.52rem 0.4rem; font-weight: 700; font-size: 0.86rem; cursor: pointer; }
        .ia-filter.active { background: #0d7b76; color: #fff; border-color: #0d7b76; }
        .ia-list { overflow: auto; flex: 1; }
        .ia-empty-list { padding: 1rem; color: #5c6f7f; font-size: 0.94rem; }
        .ia-thread { width: 100%; border: 0; border-bottom: 1px solid #d7e2e5; background: #fff; text-align: left; padding: 0.86rem; display: grid; grid-template-columns: 48px 1fr auto; gap: 0.75rem; cursor: pointer; }
        .ia-thread:hover { background: #f6fbfb; }
        .ia-thread.active { background: #e4eff0; border-left: 4px solid #0d7b76; padding-left: calc(0.86rem - 4px); }
        .ia-icon { width: 42px; height: 42px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 1.05rem; }
        .tone-police { background: linear-gradient(135deg, #197fc5, #0871b5); }
        .tone-fire { background: linear-gradient(135deg, #ea7b24, #db601c); }
        .tone-medical { background: linear-gradient(135deg, #1b9cb6, #0b7da1); }
        .tone-responder { background: linear-gradient(135deg, #149d95, #0f847d); }
        .tone-coordinator { background: linear-gradient(135deg, #6480a8, #46668f); }
        .ia-thread-main { min-width: 0; display: grid; gap: 0.14rem; }
        .ia-row { display: flex; justify-content: space-between; align-items: baseline; gap: 0.6rem; }
        .ia-row strong { color: #0a2430; font-size: 1.02rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ia-time { color: #62798b; font-size: 0.86rem; font-weight: 700; white-space: nowrap; }
        .ia-meta { color: #35556a; font-size: 0.86rem; display: flex; align-items: center; gap: 0.42rem; }
        .ia-dot { width: 8px; height: 8px; border-radius: 999px; display: inline-block; }
        .ia-dot.online { background: #1da84f; }
        .ia-dot.busy { background: #f0a11e; }
        .ia-preview { color: #4c6779; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ia-badge { align-self: center; min-width: 24px; height: 24px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: #e92f2f; color: #fff; font-size: 0.82rem; font-weight: 700; }
        .ia-chat { display: grid; grid-template-rows: auto 1fr auto; min-height: 500px; }
        .ia-head { padding: 0.9rem 1rem; border-bottom: 1px solid #d7e2e5; background: #f4f7f7; display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
        .ia-head h2 { margin: 0; color: #06233a; font-size: 2.1rem; }
        .ia-head p { margin: 0.1rem 0 0; color: #4d6577; font-size: 0.95rem; }
        .ia-last { background: #ddf6e9; color: #107a4a; border: 1px solid #8bd3a5; border-radius: 999px; padding: 0.35rem 0.8rem; font-size: 0.9rem; font-weight: 700; white-space: nowrap; }
        .ia-messages { overflow: auto; padding: 1rem; background: #eef2f4; }
        .ia-empty { color: #4f6576; font-size: 0.94rem; padding: 0.45rem; }
        .ia-msg { display: grid; gap: 0.36rem; margin-bottom: 1rem; }
        .ia-msg.out { justify-items: end; }
        .ia-msg-meta { color: #5f7689; font-size: 0.92rem; font-weight: 700; }
        .ia-msg-bubble { border-radius: 13px; padding: 0.75rem 0.95rem; max-width: min(68ch, 90%); font-size: 1.05rem; line-height: 1.35; box-shadow: 0 2px 6px rgba(6, 38, 55, 0.08); }
        .ia-msg.in .ia-msg-bubble { background: #fff; border: 1px solid #cfdae0; color: #082535; }
        .ia-msg.out .ia-msg-bubble { background: #0d8a86; color: #fff; }
        .ia-prio { display: inline-flex; margin-right: 0.48rem; font-size: 0.7rem; border-radius: 999px; padding: 0.16rem 0.48rem; border: 1px solid rgba(255,255,255,0.7); }
        .ia-prio.urgent { background: rgba(241,163,22,0.3); }
        .ia-prio.critical { background: rgba(216,49,49,0.35); }
        .ia-foot { border-top: 1px solid #d7e2e5; background: #f4f7f7; padding: 0.9rem; display: grid; gap: 0.52rem; }
        .ia-send { display: grid; grid-template-columns: 140px 1fr auto; gap: 0.65rem; }
        .ia-foot select, .ia-foot input { border: 1px solid #b4c7ce; border-radius: 10px; background: #fff; font-size: 1rem; color: #173245; height: 44px; padding: 0 0.75rem; }
        .ia-foot input:focus, .ia-foot select:focus { outline: 2px solid #84c9c4; outline-offset: 0; border-color: #2b9c96; }
        .ia-foot button { border: 0; border-radius: 11px; background: #0f827e; color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0 1rem; }
        .ia-tip { margin: 0; color: #537084; font-size: 0.9rem; }
        .ia-toast { position: fixed; top: 26px; right: 28px; z-index: 9999; padding: 0.7rem 1rem; border-radius: 10px; color: #fff; font-weight: 700; box-shadow: 0 5px 18px rgba(0,0,0,0.2); background: #1f7eb0; }
        .ia-toast.error { background: #cb3d33; }
        @media (max-width: 1250px) {
            .ia-main { grid-template-columns: 335px 1fr; }
        }
        @media (max-width: 980px) {
            .ia-stats { grid-template-columns: 1fr; }
            .ia-main { grid-template-columns: 1fr; min-height: auto; }
            .ia-pane { min-height: 380px; }
            .ia-chat { min-height: 65vh; }
        }
        @media (max-width: 680px) {
            .ia-head { flex-direction: column; align-items: flex-start; }
            .ia-send { grid-template-columns: 1fr; }
            .ia-foot button { height: 44px; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container">
            <div class="ia-wrap">
                <section class="ia-stats">
                    <article class="ia-card"><p>TOTAL THREADS</p><h3 id="statThreads">0</h3></article>
                    <article class="ia-card"><p>ACTIVE RESPONDERS</p><h3 id="statResponders">0</h3></article>
                    <article class="ia-card"><p>UNREAD MESSAGES</p><h3 id="statUnread">0</h3></article>
                </section>

                <section class="ia-main">
                    <aside class="ia-pane">
                        <div class="ia-pane-top">
                            <label class="ia-search" for="threadSearch">
                                <i class="fas fa-search"></i>
                                <input id="threadSearch" type="search" placeholder="Search department or responder...">
                            </label>
                            <div class="ia-filters">
                                <button class="ia-filter active" data-filter="all" type="button">All</button>
                                <button class="ia-filter" data-filter="department" type="button">Departments</button>
                                <button class="ia-filter" data-filter="responder" type="button">Responders</button>
                            </div>
                        </div>
                        <div id="threadList" class="ia-list"></div>
                    </aside>

                    <section class="ia-chat">
                        <header class="ia-head">
                            <div>
                                <h2 id="chatTitle">Police Command Center</h2>
                                <p id="chatSubtitle">Department channel - Status: Online</p>
                            </div>
                            <span id="chatLast" class="ia-last">Last activity just now</span>
                        </header>
                        <div id="chatMessages" class="ia-messages"></div>
                        <footer class="ia-foot">
                            <div class="ia-send">
                                <select id="messagePriority">
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="critical">Critical</option>
                                </select>
                                <input id="messageInput" type="text" placeholder="Type update for selected thread...">
                                <button id="sendBtn" type="button"><i class="fas fa-paper-plane"></i>Send</button>
                            </div>
                            <p class="ia-tip">Tip: choose thread sa kaliwa, then send incident update directly to that department/responder.</p>
                        </footer>
                    </section>
                </section>
            </div>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
    (function () {
        const DEPT_LABEL = { police: 'Police', fire: 'Fire', medical: 'EMS', coordinator: 'Coordinator' };
        const state = {
            threads: [],
            filter: 'all',
            search: '',
            selected: '',
            lastIdByDept: {},
            currentUser: { id: 0, name: 'Dispatcher' },
            poll: null
        };

        const $ = (id) => document.getElementById(id);
        const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c] || c));

        function rel(dateLike) {
            const d = new Date(dateLike);
            if (isNaN(d.getTime())) return 'just now';
            const m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
            if (!m) return 'just now';
            if (m < 60) return m + 'm ago';
            if (m < 1440) return Math.round(m / 60) + 'h ago';
            return Math.round(m / 1440) + 'd ago';
        }

        function time(dateLike) {
            const d = new Date(dateLike);
            if (isNaN(d.getTime())) return 'Now';
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function selectedThread() {
            return state.threads.find((thread) => thread.id === state.selected) || null;
        }

        function subtitle(thread) {
            const base = thread.kind === 'department' ? 'Department channel' : 'Responder channel';
            const stat = thread.status === 'online' ? 'Online' : (thread.status || 'Busy');
            return base + ' - Status: ' + stat.charAt(0).toUpperCase() + stat.slice(1);
        }

        function threadPreview(thread) {
            const text = String(thread.last_text || '').trim();
            const sender = String(thread.last_sender_name || '').trim();
            if (!text) return 'No messages yet.';
            if (!sender) return text;
            const name = sender === state.currentUser.name ? 'You' : sender;
            return name + ': ' + text;
        }

        function setStats(stats) {
            $('statThreads').textContent = String((stats && stats.total_threads) || state.threads.length || 0);
            $('statResponders').textContent = String((stats && stats.active_responders) || 0);
            $('statUnread').textContent = String((stats && stats.unread_messages) || 0);
        }

        function visibleThreads() {
            return state.threads.filter((thread) => {
                const kindPass = state.filter === 'all' || thread.kind === state.filter;
                const targetText = (thread.title + ' ' + threadPreview(thread)).toLowerCase();
                const searchPass = !state.search || targetText.includes(state.search);
                return kindPass && searchPass;
            });
        }

        function drawThreads() {
            const list = $('threadList');
            const rows = visibleThreads();
            if (!rows.length) {
                list.innerHTML = '<div class="ia-empty-list">No matching threads.</div>';
                return;
            }
            list.innerHTML = rows.map((thread) => {
                const isActive = state.selected === thread.id;
                const tone = thread.tone || thread.department || 'police';
                const icon = thread.icon || 'fa-comments';
                const unreadBadge = thread.unread > 0 ? '<span class="ia-badge">' + thread.unread + '</span>' : '';
                return '' +
                    '<button class="ia-thread' + (isActive ? ' active' : '') + '" type="button" data-id="' + esc(thread.id) + '">' +
                        '<span class="ia-icon tone-' + esc(tone) + '"><i class="fas ' + esc(icon) + '"></i></span>' +
                        '<span class="ia-thread-main">' +
                            '<span class="ia-row"><strong>' + esc(thread.title || DEPT_LABEL[thread.department] || thread.id) + '</strong><span class="ia-time">' + esc(rel(thread.last_at)) + '</span></span>' +
                            '<span class="ia-meta"><span class="ia-dot online"></span>' + esc(thread.kind === 'department' ? 'Department Channel' : 'Responder') + '</span>' +
                            '<span class="ia-preview">' + esc(threadPreview(thread)) + '</span>' +
                        '</span>' +
                        unreadBadge +
                    '</button>';
            }).join('');

            list.querySelectorAll('.ia-thread').forEach((button) => {
                button.addEventListener('click', () => switchThread(button.dataset.id || ''));
            });
        }

        function drawHeader(thread) {
            $('chatTitle').textContent = thread.title || DEPT_LABEL[thread.department] || 'Interagency';
            $('chatSubtitle').textContent = subtitle(thread);
            $('chatLast').textContent = 'Last activity ' + rel(thread.last_at);
            $('messageInput').placeholder = 'Type update for ' + (thread.title || 'selected thread') + '...';
        }

        function appendMessage(item) {
            const outgoing = !!item.is_self;
            const sender = outgoing ? 'You' : (item.sender_name || DEPT_LABEL[item.department] || 'System');
            const row = document.createElement('div');
            row.className = 'ia-msg ' + (outgoing ? 'out' : 'in');
            row.innerHTML =
                '<div class="ia-msg-meta">' + esc(sender + ' - ' + time(item.created_at)) + '</div>' +
                '<div class="ia-msg-bubble">' + esc(item.text) + '</div>';
            $('chatMessages').appendChild(row);
        }

        function toast(msg, type) {
            if (!msg) return;
            const old = document.querySelector('.ia-toast');
            if (old) old.remove();
            const node = document.createElement('div');
            node.className = 'ia-toast' + (type ? ' ' + type : '');
            node.textContent = msg;
            document.body.appendChild(node);
            setTimeout(() => node.remove(), 2800);
        }

        async function loadThreads() {
            const res = await fetch('api/interagency_chat_threads.php', { cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.ok) return;

            state.threads = Array.isArray(data.threads) ? data.threads : [];
            if (data.current_user && data.current_user.id) {
                state.currentUser = {
                    id: Number(data.current_user.id) || 0,
                    name: String(data.current_user.name || 'Dispatcher')
                };
            }
            if (!state.selected || !state.threads.some((thread) => thread.id === state.selected)) {
                state.selected = state.threads.length ? state.threads[0].id : '';
            }
            setStats(data.stats || null);
            drawThreads();
            const active = selectedThread();
            if (active) drawHeader(active);
        }

        async function fetchChat(initial, markRead) {
            const thread = selectedThread();
            if (!thread) {
                $('chatMessages').innerHTML = '<div class="ia-empty">No thread selected.</div>';
                return;
            }

            const dept = String(thread.department || '');
            const params = new URLSearchParams({ department: dept });
            const lastId = Number(state.lastIdByDept[dept] || 0);
            if (lastId > 0) params.set('since_id', String(lastId));
            if (markRead) params.set('mark_read', '1');

            const res = await fetch('api/interagency_chat_feed.php?' + params.toString(), { cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.ok) return;

            const box = $('chatMessages');
            const items = Array.isArray(data.items) ? data.items : [];
            if (initial) box.innerHTML = '';

            if (!items.length) {
                if (initial) box.innerHTML = '<div class="ia-empty">No messages yet for this thread.</div>';
                return;
            }

            const list = lastId === 0 ? items.slice().reverse() : items;
            list.forEach((item) => {
                if (item.id > (state.lastIdByDept[dept] || 0)) {
                    state.lastIdByDept[dept] = item.id;
                }
                appendMessage(item);
            });

            const latest = list[list.length - 1];
            if (latest) {
                thread.last_text = latest.text;
                thread.last_sender_name = latest.sender_name;
                thread.last_sender_role = latest.sender_role;
                thread.last_at = latest.created_at;
                thread.unread = 0;
            }

            box.scrollTop = box.scrollHeight;
            drawThreads();
            drawHeader(thread);
        }

        async function switchThread(id) {
            if (!id) return;
            if (!state.threads.some((thread) => thread.id === id)) return;
            state.selected = id;
            drawThreads();
            const active = selectedThread();
            if (!active) return;
            drawHeader(active);
            $('chatMessages').innerHTML = '';
            try {
                await fetchChat(true, true);
                await loadThreads();
            } catch (_) {}
            $('messageInput').focus();
        }

        async function send() {
            const thread = selectedThread();
            if (!thread) return;
            const input = $('messageInput');
            const raw = String(input.value || '').trim();
            if (!raw) return;

            const prio = String($('messagePriority').value || 'routine').toLowerCase();
            const payload = prio === 'routine' ? raw : '[' + prio.toUpperCase() + '] ' + raw;

            try {
                const res = await fetch('api/activity_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'chat',
                        entity_type: 'agency_chat',
                        entity_id: thread.entity_id,
                        details: payload
                    })
                });
                const data = await res.json();
                if (!data || !data.ok) {
                    const reason = (data && (data.detail || data.error)) ? String(data.detail || data.error) : 'Unknown send error';
                    toast('Failed to send message: ' + reason, 'error');
                    return;
                }
                input.value = '';
                await fetchChat(false, true);
                await loadThreads();
            } catch (_) {
                toast('Network error while sending message.', 'error');
            }
        }

        function bind() {
            $('threadSearch').addEventListener('input', (event) => {
                state.search = String(event.target.value || '').trim().toLowerCase();
                drawThreads();
            });
            document.querySelectorAll('.ia-filter').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('.ia-filter').forEach((btn) => btn.classList.remove('active'));
                    button.classList.add('active');
                    state.filter = button.dataset.filter || 'all';
                    drawThreads();
                });
            });
            $('sendBtn').addEventListener('click', send);
            $('messageInput').addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    send();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', async () => {
            bind();
            try {
                await loadThreads();
                if (state.selected) {
                    await switchThread(state.selected);
                } else {
                    $('chatMessages').innerHTML = '<div class="ia-empty">No threads available yet.</div>';
                }
            } catch (_) {
                $('chatMessages').innerHTML = '<div class="ia-empty">Unable to load interagency chat.</div>';
            }
            state.poll = setInterval(async () => {
                try {
                    await loadThreads();
                    await fetchChat(false, false);
                } catch (_) {}
            }, 5000);
        });
    })();
    </script>
</body>
</html>
