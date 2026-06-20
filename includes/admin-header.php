<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/media_storage.php';
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
$user_role = $_SESSION['user_role'] ?? 'admin';
$normalized_user_role = strtolower((string)$user_role);
$interagency_page = $normalized_user_role === 'dispatcher'
    ? 'dispatcher/interagency.php'
    : 'admin/interagency.php';
$resources_page = $normalized_user_role === 'dispatcher'
    ? 'dispatcher/resources.php'
    : 'admin/resources.php';
$review_page = $normalized_user_role === 'dispatcher'
    ? 'dispatcher/incident.php?view=resolved'
    : 'admin/review.php';
$profile_page = 'profile.php';
$account_settings_page = 'account_settings.php';
$user_avatar = trim((string)($_SESSION['user_avatar'] ?? ''));

if (!empty($_SESSION['user_id'])) {
    if (!function_exists('get_db_connection') && is_file(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    }

    if (function_exists('get_db_connection')) {
        try {
            $pdo = get_db_connection();
            if ($pdo instanceof PDO) {
                $image = get_active_profile_image($pdo, (int)$_SESSION['user_id']);
                if (is_array($image) && !empty($image['url'])) {
                    $user_avatar = (string)$image['url'];
                    $_SESSION['user_avatar'] = $user_avatar;
                } else {
                    $user_avatar = '';
                    unset($_SESSION['user_avatar']);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

$avatar_source = $user_avatar !== ''
    ? $user_avatar
    : 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=4c8a89&color=fff&size=128';
?>

<link rel="stylesheet" href="css/notification-modal.css">;
<link rel="stylesheet" href="css/message-modal.css">;
<link rel="stylesheet" href="css/message-content-modal.css">;
<link rel="stylesheet" href="css/admin-dark-theme.css">;
<style>
    .header-empty-state {
        display: grid;
        place-items: center;
        gap: 0.5rem;
        padding: 1.5rem 1rem;
        color: var(--text-secondary-1, #64748b);
        text-align: center;
        font-size: 0.875rem;
    }

    .header-empty-state i {
        font-size: 1.1rem;
        opacity: 0.7;
    }

    .header-reset-button,
    .header-message-item {
        width: 100%;
        border: 0;
        background: transparent;
        text-align: left;
        font: inherit;
    }

    .header-message-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0f766e 0%, #0ea5e9 100%);
        color: #fff;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        flex-shrink: 0;
    }

    .header-message-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.25rem;
        font-size: 0.75rem;
        color: var(--text-secondary-1, #64748b);
    }

    .header-message-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.4rem;
        height: 1.4rem;
        padding: 0 0.4rem;
        border-radius: 999px;
        background: #fee2e2;
        color: #b91c1c;
        font-size: 0.6875rem;
        font-weight: 700;
    }

    .header-live-toast {
        position: fixed;
        top: 88px;
        right: 2rem;
        z-index: 1300;
        min-width: 240px;
        max-width: min(360px, calc(100vw - 2rem));
        padding: 0.85rem 1rem;
        border-radius: 14px;
        background: #111827;
        color: #fff;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.25);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        opacity: 0;
        pointer-events: none;
        transform: translate3d(0, -10px, 0);
        transition: opacity 0.2s ease, transform 0.2s ease;
        cursor: pointer;
    }

    .header-live-toast.show {
        opacity: 1;
        pointer-events: auto;
        transform: translate3d(0, 0, 0);
    }

    .header-live-toast i {
        color: #34d399;
    }

    .header-live-toast strong {
        display: block;
        margin-bottom: 0.125rem;
        font-size: 0.9rem;
    }

    .header-live-toast span {
        display: block;
        font-size: 0.8rem;
        color: rgba(255, 255, 255, 0.85);
    }

    @media (max-width: 767px) {
        .header-live-toast {
            top: 76px;
            right: 1rem;
            left: 1rem;
            max-width: none;
        }
    }
</style>

<!-- Admin Header Component -->
<header class="admin-header">
    <div class="admin-header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <div class="admin-header-right">
        <div class="header-actions" style="display: flex; align-items: center; gap: 0.75rem;">
            <div class="notification-item">
                <button class="notification-btn" id="headerNotificationBtn" aria-label="Notifications" type="button">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="headerNotificationBadge" hidden>0</span>
                </button>
            </div>
            <div class="notification-item">
                <button class="notification-btn" id="headerMessageBtn" aria-label="Messages" type="button">
                    <i class="fas fa-envelope"></i>
                    <span class="notification-badge" id="headerMessageBadge" hidden>0</span>
                </button>
            </div>
            <div class="theme-toggle" style="margin-left: 0.75rem;">
                <button class="theme-toggle-btn" data-theme="light" aria-label="Light Mode" type="button">
                    <i class="fas fa-sun"></i>
                </button>
                <button class="theme-toggle-btn" data-theme="dark" aria-label="Dark Mode" type="button">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
        
        <div class="header-divider"></div>
        
        <div class="user-profile" id="userProfileBtn">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
            <div class="user-avatar">
                <img src="<?php echo htmlspecialchars($avatar_source); ?>" alt="<?php echo htmlspecialchars($user_name); ?>" class="avatar-img">
            </div>
            <i class="fas fa-chevron-down dropdown-icon"></i>
        </div>
    </div>
    <script src="css/theme-switcher.js"></script>
</header>

<!-- User Profile Dropdown -->
<div class="user-profile-dropdown" id="userProfileDropdown">
    <div class="dropdown-header">
        <div class="dropdown-user-info">
            <div class="dropdown-user-avatar">
                <img src="<?php echo htmlspecialchars($avatar_source); ?>" alt="<?php echo htmlspecialchars($user_name); ?>">
            </div>
            <div class="dropdown-user-details">
                <div class="dropdown-user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="dropdown-user-email"><?php echo htmlspecialchars($user_email); ?></div>
            </div>
        </div>
    </div>
    
    <div class="dropdown-body">
        <a href="<?php echo htmlspecialchars($profile_page); ?>" class="dropdown-item">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="<?php echo htmlspecialchars($account_settings_page); ?>" class="dropdown-item">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </div>
    
    <div class="dropdown-footer">
        <a href="logout.php" class="dropdown-item logout-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- Notification Modal -->
<div class="notification-modal" id="notificationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Notifications</h3>
            <button class="modal-close" onclick="closeModal('notificationModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="headerNotificationList">
            <div class="header-empty-state">
                <i class="fas fa-bell-slash"></i>
                <span>No new notifications.</span>
            </div>
        </div>
        <div class="modal-footer">
            <a href="<?php echo htmlspecialchars($interagency_page); ?>" class="view-all-link" data-open-interagency="1">Open Interagency</a>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div class="notification-modal" id="messageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Messages</h3>
            <button class="modal-close" onclick="closeModal('messageModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="headerMessageList">
            <div class="header-empty-state">
                <i class="fas fa-inbox"></i>
                <span>No interagency messages yet.</span>
            </div>
        </div>
        <div class="modal-footer">
            <a href="<?php echo htmlspecialchars($interagency_page); ?>" class="view-all-link" data-open-interagency="1">Open Interagency</a>
        </div>
    </div>
</div>

<!-- Message Content Modal -->
<div class="message-content-modal" id="messageContentModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="message-header-info">
                <img id="messageUserAvatar" src="" alt="" class="message-user-avatar">
                <div class="message-user-info">
                    <h3 id="messageUserName"></h3>
                    <span id="messageUserStatus"></span>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('messageContentModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body message-chat-body">
            <div id="messageContent"></div>
        </div>
        <div class="modal-footer message-reply-footer">
            <div class="message-reply-box">
                <input type="text" id="messageReplyInput" placeholder="Type a message..." class="message-input">
                <button class="send-message-btn">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="header-live-toast" id="headerLiveToast" hidden role="status" aria-live="polite">
    <i class="fas fa-envelope"></i>
    <div>
        <strong id="headerLiveToastTitle">Interagency</strong>
        <span id="headerLiveToastText">1 new messages</span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const notificationBtn = document.getElementById('headerNotificationBtn');
    const messageBtn = document.getElementById('headerMessageBtn');
    const notificationBadge = document.getElementById('headerNotificationBadge');
    const messageBadge = document.getElementById('headerMessageBadge');
    const notificationModal = document.getElementById('notificationModal');
    const messageModal = document.getElementById('messageModal');
    const messageContentModal = document.getElementById('messageContentModal');
    const notificationList = document.getElementById('headerNotificationList');
    const messageList = document.getElementById('headerMessageList');
    const userProfileBtn = document.getElementById('userProfileBtn');
    const userProfileDropdown = document.getElementById('userProfileDropdown');
    const liveToast = document.getElementById('headerLiveToast');
    const liveToastIcon = liveToast ? liveToast.querySelector('i') : null;
    const liveToastTitle = document.getElementById('headerLiveToastTitle');
    const liveToastText = document.getElementById('headerLiveToastText');
    const interagencyUrl = <?php echo json_encode($interagency_page); ?>;
    const resourcesUrl = <?php echo json_encode($resources_page); ?>;
    const reviewUrl = <?php echo json_encode($review_page); ?>;
    const userRole = <?php echo json_encode($normalized_user_role); ?>;
    const resolvedSeenKey = 'ers.resolvedNotificationSeen.' + userRole;
    const state = {
        lastUnreadCount: null,
        lastBackupCount: null,
        lastResolvedNotificationId: null,
        resolvedSeenId: Number(window.localStorage.getItem(resolvedSeenKey) || 0) || 0,
        resolvedNotifications: [],
        resolvedUnreadCount: 0,
        recentThreads: [],
        unreadThreads: [],
        backupRequests: [],
        toastTimer: null,
        poller: null
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return null;
        const normalized = raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw;
        const parsed = Date.parse(normalized);
        return Number.isFinite(parsed) ? new Date(parsed) : null;
    }

    function relativeTime(value) {
        const date = parseDate(value);
        if (!date) return 'Just now';
        const diffSeconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (diffSeconds < 60) return 'Just now';
        if (diffSeconds < 3600) {
            const minutes = Math.floor(diffSeconds / 60);
            return minutes + ' minute' + (minutes === 1 ? '' : 's') + ' ago';
        }
        if (diffSeconds < 86400) {
            const hours = Math.floor(diffSeconds / 3600);
            return hours + ' hour' + (hours === 1 ? '' : 's') + ' ago';
        }
        const days = Math.floor(diffSeconds / 86400);
        return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    }

    function unreadText(count) {
        if (count <= 0) return 'No new messages';
        if (count === 1) return '1 new messages';
        return count + ' new messages';
    }

    function backupUnreadText(count) {
        if (userRole === 'dispatcher') {
            if (count <= 0) return 'No backup requests';
            if (count === 1) return '1 pending backup request';
            return count + ' pending backup requests';
        }
        if (count <= 0) return 'No pending resource requests';
        if (count === 1) return '1 pending resource request';
        return count + ' pending resource requests';
    }

    function threadTitle(item) {
        if (!item || typeof item !== 'object') return 'Interagency';
        return String(
            item.title ||
            item.counterpart_name ||
            item.department_name ||
            item.department ||
            item.id ||
            'Interagency'
        );
    }

    function previewText(item) {
        if (!item || typeof item !== 'object') return 'There is a new interagency message.';
        const cleanText = String(item.last_text || '')
            .replace(/^\[[^\]]+\]\s*/, '')
            .trim();
        if (cleanText) return cleanText;
        if ((Number(item.unread) || 0) > 0) return 'There is a new interagency message.';
        return 'No messages yet.';
    }

    function avatarInitials(label) {
        const parts = String(label || 'IA').trim().split(/\s+/).filter(Boolean).slice(0, 2);
        if (!parts.length) return 'IA';
        return parts.map((part) => part.charAt(0)).join('').toUpperCase();
    }

    function setBadge(el, count) {
        if (!el) return;
        const nextCount = Math.max(0, Number(count) || 0);
        el.hidden = nextCount <= 0;
        el.textContent = nextCount > 99 ? '99+' : String(nextCount);
    }

    function emptyState(icon, text) {
        return `
            <div class="header-empty-state">
                <i class="fas ${icon}"></i>
                <span>${escapeHtml(text)}</span>
            </div>
        `;
    }

    function renderNotificationList(unreadCount) {
        if (!notificationList) return;
        const latest = state.unreadThreads[0] || state.recentThreads[0] || null;
        const backupCount = Array.isArray(state.backupRequests) ? state.backupRequests.length : 0;
        const latestBackup = backupCount > 0 ? state.backupRequests[0] : null;
        const resolvedItems = Array.isArray(state.resolvedNotifications) ? state.resolvedNotifications.slice(0, 3) : [];
        const parts = [];

        resolvedItems.forEach(function(item) {
            const incident = item && item.incident ? item.incident : {};
            const incidentLabel = incident.label || incident.reference_no || (incident.id ? ('#' + incident.id) : 'Incident');
            const detailText = item.details || `${incidentLabel} has been resolved.`;
            const isUnread = Number(item.notification_id || 0) > state.resolvedSeenId;
            parts.push(`
                <button type="button" class="notification-item header-reset-button" data-open-review="1">
                    <div class="notification-icon">
                        <i class="fas ${isUnread ? 'fa-circle-check' : 'fa-check'}"></i>
                    </div>
                    <div class="notification-details">
                        <div class="notification-title">${escapeHtml(isUnread ? 'Incident resolved' : 'Resolved incident')}</div>
                        <div class="notification-text">${escapeHtml(detailText)}</div>
                        <div class="notification-time">${escapeHtml(relativeTime(item.notified_at || incident.resolved_at))}</div>
                    </div>
                </button>
            `);
        });

        if (latestBackup) {
            const incidentLabel = latestBackup.incident_code
                ? `${latestBackup.incident_code}${latestBackup.incident_id ? ` (#${latestBackup.incident_id})` : ''}`
                : `Incident #${latestBackup.incident_id || ''}`;
            const notificationText = userRole === 'dispatcher'
                ? `${latestBackup.resource_name || 'Backup request'} for ${incidentLabel}`
                : `${latestBackup.requestor || 'Responder'} requested ${latestBackup.resource_name || 'resources'}`;
            const iconClass = userRole === 'dispatcher' ? 'fa-truck-medical' : 'fa-hand-holding-medical';
            parts.push(`
                <button type="button" class="notification-item header-reset-button" data-open-resources="1">
                    <div class="notification-icon">
                        <i class="fas ${iconClass}"></i>
                    </div>
                    <div class="notification-details">
                        <div class="notification-title">${escapeHtml(backupUnreadText(backupCount))}</div>
                        <div class="notification-text">${escapeHtml(notificationText)}</div>
                        <div class="notification-time">${escapeHtml(relativeTime(latestBackup.date_requested))}</div>
                    </div>
                </button>
            `);
        }

        if (unreadCount > 0 && latest) {
            parts.push(`
                <button type="button" class="notification-item header-reset-button" data-open-interagency="1">
                    <div class="notification-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="notification-details">
                        <div class="notification-title">${escapeHtml(unreadText(unreadCount))}</div>
                        <div class="notification-text">${escapeHtml(threadTitle(latest))}: ${escapeHtml(previewText(latest))}</div>
                        <div class="notification-time">${escapeHtml(relativeTime(latest.last_at))}</div>
                    </div>
                </button>
            `);
        }

        if (!parts.length) {
            notificationList.innerHTML = emptyState('fa-bell-slash', 'No new notifications.');
            return;
        }

        notificationList.innerHTML = parts.join('');
    }

    function renderMessageList() {
        if (!messageList) return;
        const items = state.recentThreads.slice(0, 6);
        if (!items.length) {
            messageList.innerHTML = emptyState('fa-inbox', 'No interagency messages yet.');
            return;
        }

        messageList.innerHTML = items.map((item) => {
            const unread = Math.max(0, Number(item.unread) || 0);
            return `
                <button type="button" class="message-item header-message-item" data-open-interagency="1">
                    <div class="message-avatar header-message-avatar">${escapeHtml(avatarInitials(threadTitle(item)))}</div>
                    <div class="message-details">
                        <div class="message-title">${escapeHtml(threadTitle(item))}</div>
                        <div class="message-text">${escapeHtml(previewText(item))}</div>
                        <div class="header-message-meta">
                            <span class="message-time">${escapeHtml(relativeTime(item.last_at))}</span>
                            ${unread > 0 ? `<span class="header-message-count">${escapeHtml(unreadText(unread))}</span>` : ''}
                        </div>
                    </div>
                    ${unread > 0 ? '<div class="message-status unread"></div>' : ''}
                </button>
            `;
        }).join('');
    }

    function hideLiveToast() {
        if (!liveToast) return;
        liveToast.classList.remove('show');
        window.clearTimeout(state.toastTimer);
        state.toastTimer = window.setTimeout(function() {
            liveToast.hidden = true;
        }, 200);
    }

    function showLiveToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-envelope';
        if (liveToastTitle) liveToastTitle.textContent = 'Interagency';
        if (liveToastText) liveToastText.textContent = unreadText(count);
        liveToast.setAttribute('data-toast-target', 'interagency');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showBackupToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-truck-medical';
        if (liveToastTitle) liveToastTitle.textContent = userRole === 'dispatcher' ? 'Backup Requests' : 'Resource Requests';
        if (liveToastText) liveToastText.textContent = backupUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'resources');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showResolvedToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-circle-check';
        if (liveToastTitle) liveToastTitle.textContent = 'Incident Resolved';
        if (liveToastText) liveToastText.textContent = count === 1 ? '1 incident has been resolved' : count + ' incidents have been resolved';
        liveToast.setAttribute('data-toast-target', 'review');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.remove('show');
        if (modalId === 'notificationModal' && notificationBtn) notificationBtn.classList.remove('active');
        if (modalId === 'messageModal' && messageBtn) messageBtn.classList.remove('active');
        document.body.style.overflow = '';
    }

    function closeAllModals() {
        document.querySelectorAll('.notification-modal, .message-content-modal').forEach(function(modal) {
            modal.classList.remove('show');
        });
        if (notificationBtn) notificationBtn.classList.remove('active');
        if (messageBtn) messageBtn.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleModal(modal, button, otherModal, otherButton) {
        if (!modal || !button) return;
        const willShow = !modal.classList.contains('show');
        if (otherModal) otherModal.classList.remove('show');
        if (otherButton) otherButton.classList.remove('active');
        if (messageContentModal) messageContentModal.classList.remove('show');
        modal.classList.toggle('show', willShow);
        button.classList.toggle('active', willShow);
        document.body.style.overflow = '';
    }

    function openInteragency() {
        window.location.href = interagencyUrl;
    }

    function openResources() {
        window.location.href = resourcesUrl;
    }

    function openReview() {
        window.location.href = reviewUrl;
    }

    function markResolvedNotificationsSeen() {
        const latestId = Math.max(0, ...state.resolvedNotifications.map(function(item) {
            return Number(item.notification_id || 0);
        }));
        if (latestId > state.resolvedSeenId) {
            state.resolvedSeenId = latestId;
            state.resolvedUnreadCount = 0;
            window.localStorage.setItem(resolvedSeenKey, String(latestId));
        }
    }

    async function loadBackupRequestSummary() {
        if (!['dispatcher', 'admin'].includes(userRole)) {
            state.backupRequests = [];
            state.lastBackupCount = 0;
            return;
        }

        try {
            const endpoint = userRole === 'dispatcher'
                ? 'api/dispatcher_backup_requests.php?limit=10'
                : 'api/admin_resource_requests.php?limit=10';
            const response = await fetch(endpoint, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.success) return;

            const backupRequests = Array.isArray(data.requests) ? data.requests : [];
            const backupCount = backupRequests.length;

            if (state.lastBackupCount !== null && backupCount > state.lastBackupCount) {
                showBackupToast(backupCount - state.lastBackupCount);
            }

            state.lastBackupCount = backupCount;
            state.backupRequests = backupRequests;
        } catch (_) {}
    }

    async function loadResolvedIncidentSummary() {
        try {
            const response = await fetch('api/resolved_incident_notifications.php?limit=10', {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.ok || !Array.isArray(data.notifications)) return;

            const notifications = data.notifications;
            const latestId = notifications.length ? Math.max(...notifications.map(function(item) {
                return Number(item.notification_id || 0);
            })) : 0;

            if (state.lastResolvedNotificationId !== null && latestId > state.lastResolvedNotificationId) {
                const newCount = notifications.filter(function(item) {
                    const id = Number(item.notification_id || 0);
                    return id > state.lastResolvedNotificationId;
                }).length;
                showResolvedToast(newCount);
            }

            state.lastResolvedNotificationId = latestId;
            state.resolvedNotifications = notifications;
            state.resolvedUnreadCount = notifications.filter(function(item) {
                return Number(item.notification_id || 0) > state.resolvedSeenId;
            }).length;
        } catch (_) {}
    }

    async function loadInteragencySummary() {
        try {
            const response = await fetch('api/interagency_chat_threads.php', {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.ok) return;

            const threads = Array.isArray(data.threads) ? data.threads.slice() : [];
            threads.sort(function(a, b) {
                const unreadDiff = (Number(b.unread) || 0) - (Number(a.unread) || 0);
                if (unreadDiff !== 0) return unreadDiff;
                const aTime = parseDate(a.last_at);
                const bTime = parseDate(b.last_at);
                return (bTime ? bTime.getTime() : 0) - (aTime ? aTime.getTime() : 0);
            });

            const unreadCount = Math.max(0, Number(data.stats && data.stats.unread_messages) || 0);
            const unreadThreads = threads.filter(function(item) {
                return (Number(item.unread) || 0) > 0;
            });

            if (state.lastUnreadCount !== null && unreadCount > state.lastUnreadCount) {
                showLiveToast(unreadCount - state.lastUnreadCount);
            }

            state.lastUnreadCount = unreadCount;
            state.recentThreads = threads;
            state.unreadThreads = unreadThreads;

            const backupCount = Array.isArray(state.backupRequests) ? state.backupRequests.length : 0;
            setBadge(notificationBadge, unreadCount + backupCount + state.resolvedUnreadCount);
            setBadge(messageBadge, unreadCount);
            renderNotificationList(unreadCount);
            renderMessageList();
        } catch (_) {}
    }

    async function loadHeaderSummary() {
        await loadBackupRequestSummary();
        await loadResolvedIncidentSummary();
        await loadInteragencySummary();
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            if (typeof window.sidebarToggle === 'function') {
                window.sidebarToggle();
            }
        });
    }

    if (notificationBtn) {
        notificationBtn.addEventListener('click', async function(event) {
            event.preventDefault();
            event.stopPropagation();
            await loadHeaderSummary();
            markResolvedNotificationsSeen();
            const unreadCount = Math.max(0, Number(state.lastUnreadCount) || 0);
            const backupCount = Array.isArray(state.backupRequests) ? state.backupRequests.length : 0;
            setBadge(notificationBadge, unreadCount + backupCount + state.resolvedUnreadCount);
            renderNotificationList(unreadCount);
            toggleModal(notificationModal, notificationBtn, messageModal, messageBtn);
        });
    }

    if (messageBtn) {
        messageBtn.addEventListener('click', async function(event) {
            event.preventDefault();
            event.stopPropagation();
            await loadInteragencySummary();
            toggleModal(messageModal, messageBtn, notificationModal, notificationBtn);
        });
    }

    if (userProfileBtn && userProfileDropdown) {
        userProfileBtn.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            closeAllModals();
            const isOpen = userProfileDropdown.classList.contains('show');
            userProfileDropdown.classList.toggle('show', !isOpen);
            userProfileBtn.classList.toggle('active', !isOpen);
        });
    }

    document.addEventListener('click', function(event) {
        const trigger = event.target.closest('[data-open-interagency]');
        if (trigger) {
            event.preventDefault();
            openInteragency();
            return;
        }

        const resourceTrigger = event.target.closest('[data-open-resources]');
        if (resourceTrigger) {
            event.preventDefault();
            openResources();
            return;
        }

        const reviewTrigger = event.target.closest('[data-open-review]');
        if (reviewTrigger) {
            event.preventDefault();
            markResolvedNotificationsSeen();
            openReview();
            return;
        }

        if (notificationModal && notificationModal.classList.contains('show')) {
            if (!notificationModal.contains(event.target) && !event.target.closest('#headerNotificationBtn')) {
                closeModal('notificationModal');
            }
        }

        if (messageModal && messageModal.classList.contains('show')) {
            if (!messageModal.contains(event.target) && !event.target.closest('#headerMessageBtn')) {
                closeModal('messageModal');
            }
        }

        if (userProfileDropdown && userProfileDropdown.classList.contains('show')) {
            if (!userProfileDropdown.contains(event.target) && !event.target.closest('#userProfileBtn')) {
                userProfileDropdown.classList.remove('show');
                if (userProfileBtn) userProfileBtn.classList.remove('active');
            }
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeAllModals();
            hideLiveToast();
        }
    });

    if (liveToast) {
        liveToast.addEventListener('click', function(event) {
            event.preventDefault();
            if (liveToast.getAttribute('data-toast-target') === 'review') {
                markResolvedNotificationsSeen();
                openReview();
                return;
            }
            if (liveToast.getAttribute('data-toast-target') === 'resources') {
                openResources();
                return;
            }
            openInteragency();
        });
    }

    window.closeModal = closeModal;
    window.closeAllModals = closeAllModals;

    loadHeaderSummary();
    state.poller = window.setInterval(loadHeaderSummary, 5000);
});
</script>
