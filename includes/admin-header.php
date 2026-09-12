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
$avatar_cache_ttl = 300;
$avatar_checked_at = (int)($_SESSION['user_avatar_checked_at'] ?? 0);
$should_refresh_avatar = !empty($_SESSION['user_id'])
    && ($avatar_checked_at <= 0 || (time() - $avatar_checked_at) > $avatar_cache_ttl);

if ($should_refresh_avatar) {
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
                $_SESSION['user_avatar_checked_at'] = time();
            }
        } catch (Throwable $e) {
            $_SESSION['user_avatar_checked_at'] = time();
        }
    }
}

$avatar_words = preg_split('/\s+/', trim((string)$user_name)) ?: [];
$avatar_initials = '';
foreach ($avatar_words as $word) {
    if ($word === '') {
        continue;
    }
    $avatar_initials .= strtoupper(substr($word, 0, 1));
    if (strlen($avatar_initials) >= 2) {
        break;
    }
}
$avatar_initials = $avatar_initials !== '' ? $avatar_initials : 'U';
$fallback_avatar_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128"><rect width="128" height="128" rx="64" fill="#4c8a89"/><text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="Arial, Helvetica, sans-serif" font-size="44" font-weight="700" fill="#ffffff">' . htmlspecialchars($avatar_initials, ENT_QUOTES, 'UTF-8') . '</text></svg>';
$avatar_source = $user_avatar !== ''
    ? $user_avatar
    : 'data:image/svg+xml;base64,' . base64_encode($fallback_avatar_svg);
?>

<link rel="stylesheet" href="css/admin-header.css">
<link rel="stylesheet" href="css/notification-modal.css">
<link rel="stylesheet" href="css/message-modal.css">
<link rel="stylesheet" href="css/message-content-modal.css">
<link rel="stylesheet" href="css/admin-dark-theme.css">
<style>
    .user-profile {
        cursor: pointer;
        user-select: none;
    }

    .user-profile-dropdown {
        position: fixed;
        top: var(--app-header-height-1, 70px);
        right: 2rem;
        width: 320px;
        max-width: calc(100vw - 2rem);
        background-color: var(--card-bg-1, #ffffff);
        border: 1px solid var(--border-color-1, #e2e8f0);
        border-radius: 0.75rem;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        z-index: 2100;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease;
        overflow: hidden;
    }

    .user-profile-dropdown.show {
        opacity: 1 !important;
        visibility: visible !important;
        transform: translateY(0) !important;
        pointer-events: auto !important;
    }

    .notification-modal {
        z-index: 2100;
    }

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

    .message-content-item {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.75rem;
        border-radius: 0.5rem;
        transition: background-color 0.2s ease, transform 0.15s ease;
        cursor: pointer;
        width: 100%;
        border: 0;
        background: transparent;
        text-align: left;
        font: inherit;
        color: inherit;
        box-sizing: border-box;
    }

    .message-content-item:hover {
        background-color: var(--sidebar-hover-bg-1, rgba(255, 255, 255, 0.06));
    }

    .message-content-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0f766e 0%, #0ea5e9 100%);
        color: #ffffff;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        flex-shrink: 0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.12);
        letter-spacing: 0.5px;
    }

    .message-content-body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .message-content-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .message-content-author {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--text-color-1, #0f172a);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .message-content-time {
        font-size: 0.6875rem;
        color: var(--text-secondary-1, #64748b);
        flex-shrink: 0;
        white-space: nowrap;
        opacity: 0.85;
    }

    .message-content-preview {
        font-size: 0.75rem;
        color: var(--text-secondary-1, #64748b);
        line-height: 1.4;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .message-content-unread {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.25rem;
        height: 1.25rem;
        padding: 0 0.35rem;
        border-radius: 999px;
        background: #dc2626;
        color: #ffffff;
        font-size: 0.6875rem;
        font-weight: 700;
        flex-shrink: 0;
        align-self: center;
    }

    [data-theme="dark"] .message-content-author {
        color: var(--text-color-1, #e5eef9);
    }

    [data-theme="dark"] .message-content-time,
    [data-theme="dark"] .message-content-preview {
        color: var(--text-secondary-1, #94a3b8);
    }

    [data-theme="dark"] .message-content-item:hover {
        background-color: var(--sidebar-hover-bg-1, rgba(255, 255, 255, 0.08));
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
                <img src="<?php echo htmlspecialchars($avatar_source); ?>" alt="<?php echo htmlspecialchars($user_name); ?>" class="avatar-img" decoding="async">
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
                <img src="<?php echo htmlspecialchars($avatar_source); ?>" alt="<?php echo htmlspecialchars($user_name); ?>" decoding="async">
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
            <button type="button" class="view-all-link" data-show-all-notifications="1">Show All</button>
        </div>
    </div>
</div>

<!-- All Notifications Modal -->
<div class="notification-modal all-notifications-modal" id="allNotificationsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>All Notifications</h3>
            <button class="modal-close" onclick="closeModal('allNotificationsModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body all-notifications-body" id="allNotificationsList">
            <div class="header-empty-state">
                <i class="fas fa-bell-slash"></i>
                <span>No notifications yet.</span>
            </div>
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
(function() {
function initAdminHeader() {
    const menuToggle = document.getElementById('menuToggle');
    const notificationBtn = document.getElementById('headerNotificationBtn');
    const messageBtn = document.getElementById('headerMessageBtn');
    const notificationBadge = document.getElementById('headerNotificationBadge');
    const messageBadge = document.getElementById('headerMessageBadge');
    const notificationModal = document.getElementById('notificationModal');
    const allNotificationsModal = document.getElementById('allNotificationsModal');
    const messageModal = document.getElementById('messageModal');
    const messageContentModal = document.getElementById('messageContentModal');
    const notificationList = document.getElementById('headerNotificationList');
    const allNotificationsList = document.getElementById('allNotificationsList');
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
    const resolvedSeenKey = 'ers.adminReviewNotificationSeen.' + userRole;
    const incidentCardSeenKey = 'ers.interagencyIncidentCardSeen.' + userRole;
    const resourceAdditionSeenKey = 'ers.resourceAdditionSeen.' + userRole;
    const resourceRequestSeenKey = 'ers.resourceRequestSeen.' + userRole;
    const zoneNotificationSeenKey = 'ers.responderZoneNotificationSeen.' + userRole;
    const broadcastAckSeenKey = 'ers.broadcastAckSeen.' + userRole;
    const presenceEndpoint = 'api/user_presence.php';
    const state = {
        lastUnreadCount: null,
        lastBackupCount: null,
        lastPendingRequestId: null,
        lastResourceAdditionNotificationId: null,
        lastResolvedNotificationId: null,
        lastIncidentCardNotificationId: null,
        lastZoneNotificationId: null,
        lastBroadcastAckNotificationId: null,
        resolvedSeenId: Number(window.localStorage.getItem(resolvedSeenKey) || 0) || 0,
        incidentCardSeenId: Number(window.localStorage.getItem(incidentCardSeenKey) || 0) || 0,
        resourceAdditionSeenId: Number(window.localStorage.getItem(resourceAdditionSeenKey) || 0) || 0,
        resourceRequestSeenId: Number(window.localStorage.getItem(resourceRequestSeenKey) || 0) || 0,
        zoneNotificationSeenId: Number(window.localStorage.getItem(zoneNotificationSeenKey) || 0) || 0,
        broadcastAckSeenId: Number(window.localStorage.getItem(broadcastAckSeenKey) || 0) || 0,
        resolvedNotifications: [],
        resolvedUnreadCount: 0,
        incidentCardNotifications: [],
        incidentCardUnreadCount: 0,
        zoneNotifications: [],
        zoneNotificationUnreadCount: 0,
        resourceAdditionNotifications: [],
        resourceAdditionUnreadCount: 0,
        broadcastAckNotifications: [],
        broadcastAckUnreadCount: 0,
        resourceRequestUnreadCount: 0,
        recentThreads: [],
        unreadThreads: [],
        backupRequests: [],
        toastTimer: null,
        poller: null
    };

    function markPresenceOfflineNow() {
        const payload = new FormData();
        payload.append('action', 'offline');

        if (navigator.sendBeacon) {
            navigator.sendBeacon(presenceEndpoint, payload);
            return;
        }

        fetch(presenceEndpoint, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            keepalive: true
        }).catch(function() {});
    }

    document.querySelectorAll('a.logout-item[href]').forEach(function(link) {
        link.addEventListener('click', markPresenceOfflineNow);
    });

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
        if (count <= 0) return 'No pending resource requests';
        if (count === 1) return '1 pending resource request';
        return count + ' pending resource requests';
    }

    function resourceAdditionUnreadText(count) {
        if (count <= 0) return 'No new resources';
        if (count === 1) return '1 new resource added';
        return count + ' new resources added';
    }

    function incidentCardUnreadText(count) {
        if (count <= 0) return 'No new incident cards';
        if (count === 1) return '1 new incident card';
        return count + ' new incident cards';
    }

    function zoneNotificationUnreadText(count) {
        if (count <= 0) return 'No zone movement alerts';
        if (count === 1) return '1 zone movement alert';
        return count + ' zone movement alerts';
    }

    function resolvedUnreadText(count) {
        if (count <= 0) return 'No resolved incident reviews';
        if (count === 1) return '1 resolved incident review';
        return count + ' resolved incident reviews';
    }

    function broadcastAckUnreadText(count) {
        if (count <= 0) return 'No broadcast acknowledgements';
        if (count === 1) return '1 broadcast acknowledged';
        return count + ' broadcasts acknowledged';
    }

    function incidentCardLabel(item) {
        const card = item && item.incident_card ? item.incident_card : {};
        const ref = String(card.reference_no || '').trim();
        if (ref) return ref;
        const id = Number(card.incident_id || 0);
        return id > 0 ? ('#' + id) : 'Incident';
    }

    function incidentCardText(item) {
        const card = item && item.incident_card ? item.incident_card : {};
        const label = incidentCardLabel(item);
        const type = String(card.type || '').trim();
        const priority = String(card.priority || '').trim();
        const sender = String(item && item.sender_name ? item.sender_name : 'Someone');
        const pieces = [];
        if (type) pieces.push(type);
        if (priority) pieces.push(priority);
        return sender + ' sent incident card ' + label + (pieces.length ? ' (' + pieces.join(', ') + ')' : '');
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

    function notificationBadgeTotal() {
        return state.resourceRequestUnreadCount
            + state.resolvedUnreadCount
            + state.incidentCardUnreadCount
            + state.zoneNotificationUnreadCount
            + state.resourceAdditionUnreadCount
            + state.broadcastAckUnreadCount;
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
        var combinedNotificationParts = collectAllNotifications().map(function(item) {
            return `
                <button type="button" class="notification-item header-reset-button" ${item.hrefAttr}>
                    <div class="notification-icon notification-icon-${escapeHtml(item.kind)}">
                        <i class="fas ${escapeHtml(item.icon)}"></i>
                    </div>
                    <div class="notification-details">
                        <div class="notification-title">${escapeHtml(item.title)}</div>
                        <div class="notification-text">${escapeHtml(item.text)}</div>
                        <div class="notification-time">${escapeHtml(item.meta)}</div>
                    </div>
                </button>
            `;
        });

        if (!combinedNotificationParts.length) {
            notificationList.innerHTML = emptyState('fa-bell-slash', 'No new notifications.');
            return;
        }

        notificationList.innerHTML = combinedNotificationParts.join('');
    }

    function backupRequestLabel(item) {
        if (!item) return 'Incident';
        if (item.incident_code) {
            return item.incident_code + (item.incident_id ? ' (#' + item.incident_id + ')' : '');
        }
        return item.incident_id ? ('Incident #' + item.incident_id) : 'Incident';
    }

    function backupRequestText(item) {
        if (!item) return 'Resource request needs review.';
        const name = item.resource_name || 'resources';
        const requestor = item.requestor || 'Responder';
        if (item.request_kind === 'backup') {
            return requestor + ' requested backup: ' + name + ' for ' + backupRequestLabel(item);
        }
        return requestor + ' requested ' + name;
    }

    function resourceAdditionText(item) {
        if (!item) return 'A resource was added.';
        const code = String(item.resource_code || '').trim();
        const name = String(item.resource_name || '').trim() || 'Resource';
        const category = String(item.category || '').trim();
        const addedBy = String(item.added_by || '').trim();
        const label = code ? (code + ' - ' + name) : name;
        const pieces = [];
        if (category) pieces.push(category);
        if (addedBy) pieces.push('Added by ' + addedBy);
        return label + (pieces.length ? ' (' + pieces.join(', ') + ')' : '');
    }

    function zoneNotificationText(item) {
        if (!item) return 'Responder changed zone status.';
        const message = String(item.message || '').trim();
        if (message) return message;
        const unit = String(item.unit_code || item.responder_name || 'Responder').trim();
        const transition = String(item.transition || 'entered').trim();
        const zone = String(item.zone_name || 'a zone').trim();
        return unit + ' ' + transition + ' ' + zone + '.';
    }

    function notificationSortTime(value) {
        const date = parseDate(value);
        return date ? date.getTime() : 0;
    }

    function requestNotificationSortValue(item) {
        const seconds = Math.floor(notificationSortTime(item && item.date_requested) / 1000);
        const id = Math.max(0, Number(item && (item.notification_id || item.id)) || 0) % 1000000;
        return (seconds * 1000000) + id;
    }

    function collectAllNotifications() {
        const entries = [];

        (Array.isArray(state.zoneNotifications) ? state.zoneNotifications : []).forEach(function(item) {
            const transition = String(item.transition || '').toLowerCase();
            const isUnread = Number(item.notification_id || 0) > state.zoneNotificationSeenId;
            entries.push({
                kind: 'zone',
                hrefAttr: 'data-zone-notification="1"',
                icon: transition === 'left' ? 'fa-location-arrow' : 'fa-location-dot',
                title: isUnread
                    ? (transition === 'left' ? 'Responder left zone' : 'Responder entered zone')
                    : 'Zone movement',
                text: zoneNotificationText(item),
                meta: relativeTime(item.notified_at),
                time: item.notified_at,
                id: Number(item.notification_id || 0)
            });
        });

        (Array.isArray(state.incidentCardNotifications) ? state.incidentCardNotifications : []).forEach(function(item) {
            const label = incidentCardLabel(item);
            const isUnread = Number(item.notification_id || 0) > state.incidentCardSeenId;
            entries.push({
                kind: 'incident-card',
                hrefAttr: 'data-open-interagency="1" data-incident-card-notification="1"',
                icon: isUnread ? 'fa-triangle-exclamation' : 'fa-clipboard-list',
                title: isUnread ? 'Incident card sent' : 'Incident card',
                text: incidentCardText(item),
                meta: label + ' - ' + relativeTime(item.notified_at),
                time: item.notified_at,
                id: Number(item.notification_id || 0)
            });
        });

        (Array.isArray(state.resolvedNotifications) ? state.resolvedNotifications : []).forEach(function(item) {
            const incident = item && item.incident ? item.incident : {};
            const incidentLabel = incident.label || incident.reference_no || (incident.id ? ('#' + incident.id) : 'Incident');
            const detailText = item.details || (incidentLabel + ' has been resolved.');
            const isUnread = Number(item.notification_id || 0) > state.resolvedSeenId;
            const time = item.notified_at || incident.resolved_at;
            const isAdminReviewNotification = userRole === 'admin';
            entries.push({
                kind: 'resolved',
                hrefAttr: 'data-open-review="1"',
                icon: isUnread ? 'fa-circle-check' : 'fa-check',
                title: isAdminReviewNotification
                    ? (isUnread ? 'Resolved review sent' : 'Resolved review')
                    : (isUnread ? 'Incident resolved' : 'Resolved incident'),
                text: detailText,
                meta: relativeTime(time),
                time: time,
                id: Number(item.notification_id || 0)
            });
        });

        (Array.isArray(state.resourceAdditionNotifications) ? state.resourceAdditionNotifications : []).forEach(function(item) {
            const isUnread = Number(item.notification_id || 0) > state.resourceAdditionSeenId;
            entries.push({
                kind: 'resource-added',
                hrefAttr: 'data-open-resources="1" data-resource-addition-notification="1"',
                icon: isUnread ? 'fa-circle-plus' : 'fa-box',
                title: isUnread ? 'Resource added' : 'Added resource',
                text: resourceAdditionText(item),
                meta: relativeTime(item.notified_at),
                time: item.notified_at,
                id: Number(item.notification_id || 0)
            });
        });

        (Array.isArray(state.broadcastAckNotifications) ? state.broadcastAckNotifications : []).forEach(function(item) {
            const incident = item && item.incident ? item.incident : {};
            const isUnread = Number(item.notification_id || 0) > state.broadcastAckSeenId;
            entries.push({
                kind: 'broadcast-ack',
                hrefAttr: 'data-open-interagency="1"',
                icon: isUnread ? 'fa-check-double' : 'fa-check',
                title: isUnread ? 'Broadcast acknowledged' : 'Broadcast ack',
                text: item.details || (String(item.responder_name || 'A responder') + ' acknowledged a broadcast.'),
                meta: relativeTime(item.notified_at),
                time: item.notified_at,
                id: Number(item.notification_id || 0)
            });
        });

        (Array.isArray(state.backupRequests) ? state.backupRequests : []).forEach(function(item) {
            entries.push({
                kind: 'resource',
                hrefAttr: 'data-open-resources="1"',
                icon: item.request_kind === 'backup' ? 'fa-truck-medical' : 'fa-hand-holding-medical',
                title: item.request_kind === 'backup' ? 'Pending backup request' : 'Pending resource request',
                text: backupRequestText(item),
                meta: relativeTime(item.date_requested),
                time: item.date_requested,
                id: Number(item.notification_id || item.id || 0)
            });
        });

        (Array.isArray(state.unreadThreads) ? state.unreadThreads : []).forEach(function(item) {
            const unread = Math.max(0, Number(item.unread) || 0);
            entries.push({
                kind: 'message',
                hrefAttr: 'data-open-interagency="1"',
                icon: 'fa-envelope',
                title: unreadText(unread),
                text: threadTitle(item) + ': ' + previewText(item),
                meta: relativeTime(item.last_at),
                time: item.last_at,
                id: unread
            });
        });

        entries.sort(function(a, b) {
            const timeDiff = notificationSortTime(b.time) - notificationSortTime(a.time);
            if (timeDiff !== 0) return timeDiff;
            return (Number(b.id) || 0) - (Number(a.id) || 0);
        });

        return entries;
    }

    function renderAllNotificationsList() {
        if (!allNotificationsList) return;
        const entries = collectAllNotifications();
        if (!entries.length) {
            allNotificationsList.innerHTML = emptyState('fa-bell-slash', 'No notifications yet.');
            return;
        }

        allNotificationsList.innerHTML = entries.map(function(item) {
            return `
                <button type="button" class="notification-item header-reset-button all-notification-item" ${item.hrefAttr}>
                    <div class="notification-icon notification-icon-${escapeHtml(item.kind)}">
                        <i class="fas ${escapeHtml(item.icon)}"></i>
                    </div>
                    <div class="notification-details">
                        <div class="notification-title">${escapeHtml(item.title)}</div>
                        <div class="notification-text">${escapeHtml(item.text)}</div>
                        <div class="notification-time">${escapeHtml(item.meta)}</div>
                    </div>
                </button>
            `;
        }).join('');
    }

    function renderMessageList() {
        if (!messageList) return;
        if (!state.unreadThreads.length && !state.recentThreads.length) {
            messageList.innerHTML = emptyState('fa-comment-slash', 'No conversations found.');
            return;
        }

        const renderedIds = new Set();
        const items = [];
        state.unreadThreads.forEach(function(thread) {
            const id = String(thread.id || '');
            if (!renderedIds.has(id)) {
                renderedIds.add(id);
                items.push(thread);
            }
        });
        state.recentThreads.forEach(function(thread) {
            const id = String(thread.id || '');
            if (!renderedIds.has(id)) {
                renderedIds.add(id);
                items.push(thread);
            }
        });

        messageList.innerHTML = items.map(function(thread) {
            const unread = Math.max(0, Number(thread.unread) || 0);
            const title = threadTitle(thread);
            return `
                <button type="button" class="message-content-item header-reset-button" data-open-interagency="1">
                    <div class="message-content-avatar">${escapeHtml(avatarInitials(title))}</div>
                    <div class="message-content-body">
                        <div class="message-content-top">
                            <span class="message-content-author">${escapeHtml(title)}</span>
                            <span class="message-content-time">${escapeHtml(relativeTime(thread.last_at))}</span>
                        </div>
                        <div class="message-content-preview">${escapeHtml(previewText(thread))}</div>
                    </div>
                    ${unread > 0 ? `<span class="message-content-unread">${escapeHtml(String(unread))}</span>` : ''}
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
        }, 220);
    }

    function showMessageToast(thread) {
        if (!liveToast || !thread) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-envelope';
        if (liveToastTitle) liveToastTitle.textContent = threadTitle(thread);
        if (liveToastText) liveToastText.textContent = previewText(thread);
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
        if (liveToastTitle) liveToastTitle.textContent = 'Resource Requests';
        if (liveToastText) liveToastText.textContent = backupUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'backup_requests');
        if (Array.isArray(state.backupRequests) && state.backupRequests.length > 0) {
            const firstReq = state.backupRequests[0];
            liveToast.setAttribute('data-request-id', String(firstReq.id || ''));
            liveToast.setAttribute('data-incident-id', String(firstReq.incident_id || ''));
        }
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showResourceAdditionToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-circle-plus';
        if (liveToastTitle) liveToastTitle.textContent = 'Resources';
        if (liveToastText) liveToastText.textContent = resourceAdditionUnreadText(count);
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
        if (liveToastTitle) liveToastTitle.textContent = 'Resolved Incidents';
        if (liveToastText) liveToastText.textContent = resolvedUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'review');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showBroadcastAckToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-check-double';
        if (liveToastTitle) liveToastTitle.textContent = 'Broadcast Acknowledged';
        if (liveToastText) liveToastText.textContent = broadcastAckUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'interagency');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showIncidentCardToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-clipboard-list';
        if (liveToastTitle) liveToastTitle.textContent = 'Incident Cards';
        if (liveToastText) liveToastText.textContent = incidentCardUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'incident-card');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
    }

    function showZoneNotificationToast(count) {
        if (!liveToast || count <= 0) return;
        if (liveToastIcon) liveToastIcon.className = 'fas fa-location-dot';
        if (liveToastTitle) liveToastTitle.textContent = 'Responder Zones';
        if (liveToastText) liveToastText.textContent = zoneNotificationUnreadText(count);
        liveToast.setAttribute('data-toast-target', 'zone');
        liveToast.hidden = false;
        window.clearTimeout(state.toastTimer);
        window.requestAnimationFrame(function() {
            liveToast.classList.add('show');
        });
        state.toastTimer = window.setTimeout(hideLiveToast, 4000);
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

    function closeModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (!modal) return;
        modal.classList.remove('show');
        if (modal === notificationModal && notificationBtn) notificationBtn.classList.remove('active');
        if (modal === messageModal && messageBtn) messageBtn.classList.remove('active');
        if (modal === allNotificationsModal && notificationBtn) notificationBtn.classList.remove('active');
        if (modal === messageContentModal) modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function closeAllModals() {
        if (notificationModal) notificationModal.classList.remove('show');
        if (messageModal) messageModal.classList.remove('show');
        if (allNotificationsModal) allNotificationsModal.classList.remove('show');
        if (messageContentModal) messageContentModal.classList.remove('show');
        if (userProfileDropdown) userProfileDropdown.classList.remove('show');
        if (notificationBtn) notificationBtn.classList.remove('active');
        if (messageBtn) messageBtn.classList.remove('active');
        if (userProfileBtn) userProfileBtn.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleModal(modal, button, otherModal, otherButton) {
        if (!modal || !button) return;
        const willShow = !modal.classList.contains('show');
        if (otherModal) otherModal.classList.remove('show');
        if (otherButton) otherButton.classList.remove('active');
        if (messageContentModal) messageContentModal.classList.remove('show');
        if (allNotificationsModal) allNotificationsModal.classList.remove('show');
        if (userProfileDropdown) userProfileDropdown.classList.remove('show');
        if (userProfileBtn) userProfileBtn.classList.remove('active');
        modal.classList.toggle('show', willShow);
        button.classList.toggle('active', willShow);
        document.body.style.overflow = '';
    }

    function openBackupRequestFlow(reqId, incId) {
        if (typeof openRequestBackupModalWithContext === 'function') {
            openRequestBackupModalWithContext({ requestId: reqId, incidentId: incId });
            return;
        }
        const targetUrl = new URL(resourcesUrl, window.location.href);
        targetUrl.searchParams.set('open_request_backup', '1');
        if (reqId) targetUrl.searchParams.set('request_id', String(reqId));
        if (incId) targetUrl.searchParams.set('incident_id', String(incId));
        window.location.href = targetUrl.toString();
    }

    async function openAllNotifications() {
        await loadHeaderSummary(50);
        markResolvedNotificationsSeen();
        markIncidentCardNotificationsSeen();
        markZoneNotificationsSeen();
        markResourceAdditionNotificationsSeen();
        markResourceRequestNotificationsSeen();
        markBroadcastAckNotificationsSeen();
        const unreadCount = Math.max(0, Number(state.lastUnreadCount) || 0);
        setBadge(notificationBadge, notificationBadgeTotal());
        renderNotificationList(unreadCount);
        renderAllNotificationsList();
        if (notificationModal) notificationModal.classList.remove('show');
        if (messageModal) messageModal.classList.remove('show');
        if (messageBtn) messageBtn.classList.remove('active');
        if (userProfileDropdown) userProfileDropdown.classList.remove('show');
        if (allNotificationsModal) allNotificationsModal.classList.add('show');
        if (notificationBtn) notificationBtn.classList.add('active');
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

    function markIncidentCardNotificationsSeen() {
        const latestId = Math.max(0, ...state.incidentCardNotifications.map(function(item) {
            return Number(item.notification_id || 0);
        }));
        if (latestId > state.incidentCardSeenId) {
            state.incidentCardSeenId = latestId;
            state.incidentCardUnreadCount = 0;
            window.localStorage.setItem(incidentCardSeenKey, String(latestId));
        }
    }

    function markZoneNotificationsSeen() {
        const latestId = Math.max(0, ...state.zoneNotifications.map(function(item) {
            return Number(item.notification_id || 0);
        }));
        if (latestId > state.zoneNotificationSeenId) {
            state.zoneNotificationSeenId = latestId;
            state.zoneNotificationUnreadCount = 0;
            window.localStorage.setItem(zoneNotificationSeenKey, String(latestId));
        }
    }

    function markResourceAdditionNotificationsSeen() {
        const latestId = Math.max(0, ...state.resourceAdditionNotifications.map(function(item) {
            return Number(item.notification_id || 0);
        }));
        if (latestId > state.resourceAdditionSeenId) {
            state.resourceAdditionSeenId = latestId;
            state.resourceAdditionUnreadCount = 0;
            window.localStorage.setItem(resourceAdditionSeenKey, String(latestId));
        }
    }

    function markBroadcastAckNotificationsSeen() {
        const latestId = Math.max(0, ...state.broadcastAckNotifications.map(function(item) {
            return Number(item.notification_id || 0);
        }));
        if (latestId > state.broadcastAckSeenId) {
            state.broadcastAckSeenId = latestId;
            state.broadcastAckUnreadCount = 0;
            window.localStorage.setItem(broadcastAckSeenKey, String(latestId));
        }
    }

    function markResourceRequestNotificationsSeen() {
        const latestId = Math.max(0, ...state.backupRequests.map(function(item) {
            return requestNotificationSortValue(item);
        }));
        if (latestId > state.resourceRequestSeenId) {
            state.resourceRequestSeenId = latestId;
            state.resourceRequestUnreadCount = 0;
            window.localStorage.setItem(resourceRequestSeenKey, String(latestId));
        }
    }

    async function loadBackupRequestSummary(limit) {
        if (!['dispatcher', 'admin'].includes(userRole)) {
            state.backupRequests = [];
            state.lastBackupCount = 0;
            state.resourceRequestUnreadCount = 0;
            return;
        }

        try {
            const requestLimit = Math.max(1, Math.min(100, Number(limit) || 10));
            const response = await fetch('api/resource_notifications.php?limit=' + requestLimit, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.success) return;

            const backupRequests = Array.isArray(data.requests) ? data.requests : [];
            const backupCount = backupRequests.length;
            const latestRequestId = Math.max(0, ...backupRequests.map(function(item) {
                return requestNotificationSortValue(item);
            }));
            const resourceAdditions = Array.isArray(data.resource_additions) ? data.resource_additions : [];
            const latestResourceAdditionId = Math.max(0, ...resourceAdditions.map(function(item) {
                return Number(item.notification_id || 0);
            }));

            if (state.lastPendingRequestId !== null && latestRequestId > state.lastPendingRequestId) {
                const newCount = backupRequests.filter(function(item) {
                    return requestNotificationSortValue(item) > state.lastPendingRequestId;
                }).length;
                showBackupToast(newCount);
            } else if (state.lastBackupCount !== null && backupCount > state.lastBackupCount) {
                showBackupToast(backupCount - state.lastBackupCount);
            }

            if (state.lastResourceAdditionNotificationId !== null && latestResourceAdditionId > state.lastResourceAdditionNotificationId) {
                const newCount = resourceAdditions.filter(function(item) {
                    return Number(item.notification_id || 0) > state.lastResourceAdditionNotificationId;
                }).length;
                showResourceAdditionToast(newCount);
            }

            state.lastBackupCount = backupCount;
            state.lastPendingRequestId = latestRequestId;
            state.lastResourceAdditionNotificationId = latestResourceAdditionId;
            state.backupRequests = backupRequests;
            state.resourceRequestUnreadCount = backupRequests.filter(function(item) {
                return requestNotificationSortValue(item) > state.resourceRequestSeenId;
            }).length;
            state.resourceAdditionNotifications = resourceAdditions;
            state.resourceAdditionUnreadCount = resourceAdditions.filter(function(item) {
                return Number(item.notification_id || 0) > state.resourceAdditionSeenId;
            }).length;
        } catch (_) {}
    }

    async function loadResolvedIncidentSummary(limit) {
        try {
            const requestLimit = Math.max(1, Math.min(50, Number(limit) || 10));
            const response = await fetch('api/resolved_incident_notifications.php?limit=' + requestLimit, {
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

    async function loadBroadcastAckSummary(limit) {
        try {
            const requestLimit = Math.max(1, Math.min(50, Number(limit) || 10));
            const response = await fetch('api/broadcast_ack_notifications.php?limit=' + requestLimit, {
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

            if (state.lastBroadcastAckNotificationId !== null && latestId > state.lastBroadcastAckNotificationId) {
                const newCount = notifications.filter(function(item) {
                    const id = Number(item.notification_id || 0);
                    return id > state.lastBroadcastAckNotificationId;
                }).length;
                showBroadcastAckToast(newCount);
            }

            state.lastBroadcastAckNotificationId = latestId;
            state.broadcastAckNotifications = notifications;
            state.broadcastAckUnreadCount = notifications.filter(function(item) {
                return Number(item.notification_id || 0) > state.broadcastAckSeenId;
            }).length;
        } catch (_) {}
    }

    async function loadIncidentCardSummary(limit) {
        if (userRole !== 'admin') {
            state.incidentCardNotifications = [];
            state.incidentCardUnreadCount = 0;
            state.lastIncidentCardNotificationId = 0;
            return;
        }

        try {
            const requestLimit = Math.max(1, Math.min(50, Number(limit) || 10));
            const response = await fetch('api/interagency_incident_card_notifications.php?limit=' + requestLimit, {
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

            if (state.lastIncidentCardNotificationId !== null && latestId > state.lastIncidentCardNotificationId) {
                const newCount = notifications.filter(function(item) {
                    const id = Number(item.notification_id || 0);
                    return id > state.lastIncidentCardNotificationId;
                }).length;
                showIncidentCardToast(newCount);
            }

            state.lastIncidentCardNotificationId = latestId;
            state.incidentCardNotifications = notifications;
            state.incidentCardUnreadCount = notifications.filter(function(item) {
                return Number(item.notification_id || 0) > state.incidentCardSeenId;
            }).length;
        } catch (_) {}
    }

    async function loadZoneNotificationSummary(limit) {
        if (userRole !== 'admin') {
            state.zoneNotifications = [];
            state.zoneNotificationUnreadCount = 0;
            state.lastZoneNotificationId = 0;
            return;
        }

        try {
            const requestLimit = Math.max(1, Math.min(50, Number(limit) || 10));
            const response = await fetch('api/zone_notifications.php?limit=' + requestLimit, {
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

            if (state.lastZoneNotificationId !== null && latestId > state.lastZoneNotificationId) {
                const newCount = notifications.filter(function(item) {
                    const id = Number(item.notification_id || 0);
                    return id > state.lastZoneNotificationId;
                }).length;
                showZoneNotificationToast(newCount);
            }

            state.lastZoneNotificationId = latestId;
            state.zoneNotifications = notifications;
            state.zoneNotificationUnreadCount = notifications.filter(function(item) {
                return Number(item.notification_id || 0) > state.zoneNotificationSeenId;
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

            setBadge(notificationBadge, notificationBadgeTotal());
            setBadge(messageBadge, unreadCount);
            renderNotificationList(unreadCount);
            renderMessageList();
        } catch (_) {}
    }

    let headerSummaryRequest = null;
    let headerSummaryLimit = 0;
    async function loadHeaderSummary(limit) {
        const requestedLimit = Math.max(0, Number(limit) || 0);
        if (headerSummaryRequest) {
            if (requestedLimit <= headerSummaryLimit) {
                return headerSummaryRequest;
            }
            await headerSummaryRequest.catch(() => {});
        }

        headerSummaryLimit = requestedLimit;
        headerSummaryRequest = Promise.all([
            loadBackupRequestSummary(limit),
            loadResolvedIncidentSummary(limit),
            loadIncidentCardSummary(limit),
            loadZoneNotificationSummary(limit),
            loadBroadcastAckSummary(limit),
            loadInteragencySummary()
        ]).finally(() => {
            setBadge(notificationBadge, notificationBadgeTotal());
            renderNotificationList(Math.max(0, Number(state.lastUnreadCount) || 0));
            renderAllNotificationsList();
            headerSummaryRequest = null;
            headerSummaryLimit = 0;
        });

        return headerSummaryRequest;
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            if (typeof window.sidebarToggle === 'function') {
                window.sidebarToggle();
            }
        });
    }

    if (notificationBtn) {
        notificationBtn.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleModal(notificationModal, notificationBtn, messageModal, messageBtn);
            if (notificationModal && notificationModal.classList.contains('show')) {
                loadHeaderSummary(100).then(function() {
                    markResolvedNotificationsSeen();
                    markIncidentCardNotificationsSeen();
                    markZoneNotificationsSeen();
                    markResourceAdditionNotificationsSeen();
                    markResourceRequestNotificationsSeen();
                    setBadge(notificationBadge, notificationBadgeTotal());
                    renderNotificationList(Math.max(0, Number(state.lastUnreadCount) || 0));
                }).catch(function() {});
            }
        });
    }

    if (messageBtn) {
        messageBtn.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleModal(messageModal, messageBtn, notificationModal, notificationBtn);
            if (messageModal && messageModal.classList.contains('show')) {
                loadInteragencySummary().catch(function() {});
            }
        });
    }

    if (userProfileBtn && userProfileDropdown) {
        userProfileBtn.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            const isOpen = userProfileDropdown.classList.contains('show');
            closeAllModals();
            if (!isOpen) {
                userProfileDropdown.classList.add('show');
                userProfileBtn.classList.add('active');
            }
        });
    }

    document.addEventListener('click', function(event) {
        const showAllTrigger = event.target.closest('[data-show-all-notifications]');
        if (showAllTrigger) {
            event.preventDefault();
            event.stopPropagation();
            openAllNotifications();
            return;
        }

        const zoneTrigger = event.target.closest('[data-zone-notification]');
        if (zoneTrigger) {
            event.preventDefault();
            markZoneNotificationsSeen();
            setBadge(notificationBadge, notificationBadgeTotal());
            renderNotificationList(Math.max(0, Number(state.lastUnreadCount) || 0));
            renderAllNotificationsList();
            return;
        }

        const trigger = event.target.closest('[data-open-interagency]');
        if (trigger) {
            event.preventDefault();
            if (trigger.hasAttribute('data-incident-card-notification')) {
                markIncidentCardNotificationsSeen();
            }
            openInteragency();
            return;
        }

        const requestBackupTrigger = event.target.closest('[data-open-request-backup]');
        if (requestBackupTrigger) {
            event.preventDefault();
            markResourceRequestNotificationsSeen();
            setBadge(notificationBadge, notificationBadgeTotal());
            closeAllModals();
            openBackupRequestFlow(
                requestBackupTrigger.getAttribute('data-request-id') || '',
                requestBackupTrigger.getAttribute('data-incident-id') || ''
            );
            return;
        }

        const resourceTrigger = event.target.closest('[data-open-resources]');
        if (resourceTrigger) {
            event.preventDefault();
            markResourceRequestNotificationsSeen();
            if (resourceTrigger.hasAttribute('data-resource-addition-notification')) {
                markResourceAdditionNotificationsSeen();
            }
            setBadge(notificationBadge, notificationBadgeTotal());
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

        if (allNotificationsModal && allNotificationsModal.classList.contains('show')) {
            if (!allNotificationsModal.contains(event.target) && !event.target.closest('#headerNotificationBtn')) {
                closeModal('allNotificationsModal');
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
            if (liveToast.getAttribute('data-toast-target') === 'zone') {
                markZoneNotificationsSeen();
                setBadge(notificationBadge, notificationBadgeTotal());
                hideLiveToast();
                return;
            }
            if (liveToast.getAttribute('data-toast-target') === 'incident-card') {
                markIncidentCardNotificationsSeen();
                openInteragency();
                return;
            }
            if (liveToast.getAttribute('data-toast-target') === 'review') {
                markResolvedNotificationsSeen();
                openReview();
                return;
            }
            if (liveToast.getAttribute('data-toast-target') === 'backup_requests') {
                markResourceRequestNotificationsSeen();
                setBadge(notificationBadge, notificationBadgeTotal());
                hideLiveToast();
                openBackupRequestFlow(
                    liveToast.getAttribute('data-request-id') || '',
                    liveToast.getAttribute('data-incident-id') || ''
                );
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

    function runWhenPageSettled(callback) {
        const scheduleIdle = function() {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(callback, { timeout: 3500 });
                return;
            }
            window.setTimeout(callback, 1200);
        };

        if (document.readyState === 'complete') {
            scheduleIdle();
        } else {
            window.addEventListener('load', scheduleIdle, { once: true });
        }
    }

    const pollHeaderSummary = () => {
        if (document.hidden) return;
        loadHeaderSummary();
    };
    const startHeaderSummaryPolling = () => {
        if (state.poller) return;
        loadHeaderSummary();
        state.poller = window.setInterval(pollHeaderSummary, 15000);
    };

    runWhenPageSettled(startHeaderSummaryPolling);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        if (state.poller) {
            loadHeaderSummary();
            return;
        }
        runWhenPageSettled(startHeaderSummaryPolling);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminHeader);
} else {
    initAdminHeader();
}
})();
</script>
