<?php
/**
 * Reusable Sidebar Component
 * Include this file in your pages where you want a sidebar: <?php include 'sidebar/sidebar.php'; ?>
 * 
 * Features:
 * - Responsive design with mobile toggle
 * - Admin-style navigation
 * - Collapsible sections
 * - Dark mode support
 * - Multiple layout options
 */

$sidebarUser = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
$sidebarRoleRaw = (string)($sidebarUser['role'] ?? ($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? ''));
$sidebarRole = function_exists('canonical_role')
    ? canonical_role($sidebarRoleRaw)
    : strtolower(trim($sidebarRoleRaw));

// Backward compatibility: some legacy records/sessions still use "operator"
if ($sidebarRole === 'operator') {
    $sidebarRole = 'dispatcher';
}

$isAdminSidebar = $sidebarRole === 'admin';
$isDispatcherSidebar = $sidebarRole === 'dispatcher';
?>

<!-- Sidebar Component -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <img src="images/logo.svg" alt="" class="logo-img">
            </div>
        </div>
    </div>
    
    <div class="sidebar-content">
        <!-- Navigation Menu -->
        <nav class="sidebar-nav" role="navigation" aria-label="Primary">
            <?php if ($isAdminSidebar): ?>
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Overview</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="admin/index.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-gauge"></i>
                                <span>Admin Dashboard</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Insights</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="admin/analytics.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-robot"></i>
                                <span>Predictive Analytics</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/report.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'report.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'report.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-chart-area"></i>
                                <span>Report Analytics</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Management</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="admin/resources.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-truck-medical"></i>
                                <span>Resources Status</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/interagency.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'interagency.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'interagency.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-people-arrows"></i>
                                <span>Inter-Agency</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/review.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-comment-dots"></i>
                                <span>Review &amp; Feedback</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/user_management.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'user_management.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'user_management.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-users-gear"></i>
                                <span>User Management</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/system_settings.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'system_settings.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'system_settings.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-sliders"></i>
                                <span>System Settings</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="admin/audit.php?ui=20260806-grouped-v3" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>Operational Audit</span>
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($isDispatcherSidebar): ?>
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Overview</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/dashboard.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-headset"></i>
                                <span>Dispatcher Dashboard</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Operations</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/call.php" id="dispatcherCallReceivingLink" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'call.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'call.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-phone"></i>
                                <span>Call Receiving & Logs</span>
                                <span class="sidebar-incoming-call-badge" id="dispatcherIncomingCallBadge" hidden aria-label="Incoming calls">0</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/incident.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'incident.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'incident.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-crutch"></i>
                                <span>Incident Priority</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/dispatch.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'dispatch.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'dispatch.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-bell"></i>
                                <span>Dispatch Center</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/gps.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'gps.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'gps.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-map"></i>
                                <span>GPS Tracking</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/resources.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'resources.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-truck-medical"></i>
                                <span>Resources Status</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Collaboration</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/interagency.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'interagency.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'interagency.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-envelope"></i>
                                <span>Inter-Agency</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Insights</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="dispatcher/review.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'page' : 'false'; ?>">
                                <i class="fa-solid fa-comment"></i>
                                <span>Review & Feedback</span>
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </nav>
    </div>
    <!-- Sidebar Footer with Account Settings -->
    <div class="sidebar-footer">
        <a href="account_settings.php" class="sidebar-link sidebar-footer-link">
            <i class="fa-solid fa-user-cog"></i>
            <span>Account Settings</span>
        </a>
    </div>
</aside>

<!-- Sidebar Overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php if ($isDispatcherSidebar): ?>
<aside class="dispatcher-incoming-call-alert" id="dispatcherIncomingCallAlert" role="alert" aria-live="assertive" aria-atomic="true" hidden>
    <span class="dispatcher-incoming-call-icon" aria-hidden="true"><i class="fa-solid fa-phone"></i></span>
    <span class="dispatcher-incoming-call-copy">
        <span class="dispatcher-incoming-call-eyebrow">Incoming partner-app call</span>
        <strong id="dispatcherIncomingCallTitle">Emergency caller</strong>
        <small id="dispatcherIncomingCallMeta">Open Call Receiving to answer.</small>
        <small class="dispatcher-incoming-call-more" id="dispatcherIncomingCallMore" hidden></small>
    </span>
    <a class="dispatcher-incoming-call-open" id="dispatcherIncomingCallOpen" href="dispatcher/call.php">
        Open Calls <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
    </a>
</aside>

<div class="floating-call-widget" id="floatingCallWidget" aria-live="polite" hidden>
    <button type="button" class="floating-call-summary" id="floatingCallSummary" title="Open Dispatch Center">
        <span class="floating-call-label">Live Call</span>
        <span class="floating-call-timer" id="floatingCallTimer">00:00</span>
        <span class="floating-call-name" id="floatingCallName">Caller</span>
        <span class="floating-call-incident" id="floatingCallIncident">Dispatch standby</span>
    </button>
    <div class="floating-call-actions">
        <button type="button" class="floating-call-action" id="floatingCallSpeaker" title="Toggle speaker" aria-pressed="false">
            <i class="fas fa-volume-up"></i>
        </button>
        <button type="button" class="floating-call-action" id="floatingCallMute" title="Toggle mute" aria-pressed="false">
            <i class="fas fa-microphone"></i>
        </button>
        <button type="button" class="floating-call-action floating-call-action-end" id="floatingCallEnd" title="End call">
            <i class="fas fa-phone-slash"></i>
        </button>
    </div>
</div>

<style>
.sidebar-incoming-call-badge {
    margin-left: auto;
    min-width: 1.45rem;
    height: 1.45rem;
    padding: 0 .38rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #dc2626;
    color: #fff;
    font-size: .68rem;
    font-weight: 800;
    line-height: 1;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, .12);
}

.sidebar-incoming-call-badge[hidden],
.dispatcher-incoming-call-alert[hidden] {
    display: none !important;
}

.dispatcher-incoming-call-alert {
    position: fixed;
    z-index: 2100;
    top: 4.75rem;
    right: 1.25rem;
    width: min(25rem, calc(100vw - 2rem));
    display: grid;
    grid-template-columns: 2.75rem minmax(0, 1fr) auto;
    gap: .75rem;
    align-items: center;
    padding: .85rem;
    border: 1px solid rgba(220, 38, 38, .3);
    border-left: 4px solid #dc2626;
    border-radius: .9rem;
    background: #fff;
    color: #122033;
    box-shadow: 0 18px 44px rgba(15, 23, 42, .22);
    animation: dispatcherIncomingCallEnter .22s ease-out both;
}

.dispatcher-incoming-call-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: .8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fee2e2;
    color: #b91c1c;
    font-size: 1.05rem;
    animation: dispatcherIncomingCallPulse 1.4s ease-in-out infinite;
}

.dispatcher-incoming-call-copy {
    min-width: 0;
    display: grid;
    gap: .12rem;
}

.dispatcher-incoming-call-eyebrow {
    color: #b91c1c;
    font-size: .67rem;
    font-weight: 800;
    letter-spacing: .055em;
    text-transform: uppercase;
}

.dispatcher-incoming-call-copy strong,
.dispatcher-incoming-call-copy small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dispatcher-incoming-call-copy strong {
    font-size: .9rem;
}

.dispatcher-incoming-call-copy small {
    color: #64748b;
    font-size: .72rem;
}

.dispatcher-incoming-call-more {
    color: #b91c1c !important;
    font-weight: 700;
}

.dispatcher-incoming-call-open {
    min-height: 2.75rem;
    padding: .62rem .78rem;
    border-radius: .7rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    background: #0f766e;
    color: #fff;
    font-size: .74rem;
    font-weight: 800;
    text-decoration: none;
    white-space: nowrap;
}

.dispatcher-incoming-call-open:hover,
.dispatcher-incoming-call-open:focus-visible {
    background: #115e59;
    color: #fff;
}

[data-theme="dark"] .dispatcher-incoming-call-alert {
    border-color: rgba(248, 113, 113, .42);
    border-left-color: #f87171;
    background: #111827;
    color: #f8fafc;
    box-shadow: 0 20px 48px rgba(0, 0, 0, .46);
}

[data-theme="dark"] .dispatcher-incoming-call-icon {
    background: rgba(220, 38, 38, .18);
    color: #fca5a5;
}

[data-theme="dark"] .dispatcher-incoming-call-copy small {
    color: #cbd5e1;
}

@keyframes dispatcherIncomingCallEnter {
    from { opacity: 0; transform: translateY(-.5rem); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes dispatcherIncomingCallPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, .25); }
    50% { box-shadow: 0 0 0 .45rem rgba(220, 38, 38, 0); }
}

@media (max-width: 640px) {
    .dispatcher-incoming-call-alert {
        top: auto;
        right: 1rem;
        bottom: 1rem;
        left: 1rem;
        width: auto;
        grid-template-columns: 2.6rem minmax(0, 1fr);
    }

    .dispatcher-incoming-call-open {
        grid-column: 1 / -1;
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dispatcher-incoming-call-alert,
    .dispatcher-incoming-call-icon {
        animation: none;
    }
}
</style>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar functionality
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    // Toggle sidebar
    function toggleSidebar() {
        sidebar.classList.toggle('sidebar-open');
        sidebarOverlay.classList.toggle('sidebar-overlay-open');
        document.body.classList.toggle('sidebar-open');
    }
    
    // Close sidebar
    function closeSidebar() {
        sidebar.classList.remove('sidebar-open');
        sidebarOverlay.classList.remove('sidebar-overlay-open');
        document.body.classList.remove('sidebar-open');
    }

    // Expose functions globally so other scripts
    // can trigger the sidebar without duplicating logic.
    window.sidebarToggle = toggleSidebar;
    window.sidebarClose = closeSidebar;

    const prefetchedSidebarUrls = new Set();

    function isPrefetchableSidebarUrl(url) {
        if (!url || url.origin !== window.location.origin) {
            return false;
        }
        if (url.href === window.location.href) {
            return false;
        }
        const protocol = url.protocol.toLowerCase();
        if (protocol !== 'http:' && protocol !== 'https:') {
            return false;
        }
        const path = url.pathname.toLowerCase();
        return !path.endsWith('/logout.php') && !path.endsWith('/download.php');
    }

    function prefetchSidebarLink(link) {
        if (!link || link.target || link.hasAttribute('download')) {
            return;
        }

        let url;
        try {
            url = new URL(link.getAttribute('href') || '', window.location.href);
        } catch (_) {
            return;
        }

        if (!isPrefetchableSidebarUrl(url) || prefetchedSidebarUrls.has(url.href)) {
            return;
        }

        prefetchedSidebarUrls.add(url.href);
        const hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.as = 'document';
        hint.href = url.href;
        hint.crossOrigin = 'use-credentials';
        document.head.appendChild(hint);
    }

    document.querySelectorAll('.sidebar a[href]').forEach(function(link) {
        link.addEventListener('pointerenter', function() {
            window.setTimeout(function() {
                prefetchSidebarLink(link);
            }, 80);
        }, { passive: true });
        link.addEventListener('focus', function() {
            prefetchSidebarLink(link);
        });
        link.addEventListener('touchstart', function() {
            prefetchSidebarLink(link);
        }, { passive: true });
    });
    
    // Close sidebar when clicking overlay
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }
    
    // Close sidebar on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    });
    
    // Handle submenu toggles
    const submenuToggles = document.querySelectorAll('.sidebar-submenu-toggle');
    submenuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            const icon = this.querySelector('.submenu-icon');
            
            if (submenu) {
                const isOpen = submenu.classList.contains('sidebar-submenu-open');
                submenu.classList.toggle('sidebar-submenu-open');
                this.classList.toggle('active', !isOpen);
                
                // Toggle icon based on new state
                if (icon) {
                    if (submenu.classList.contains('sidebar-submenu-open')) {
                        // Now open - show up chevron
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-up');
                    } else {
                        // Now closed - show down chevron
                        icon.classList.remove('fa-chevron-up');
                        icon.classList.add('fa-chevron-down');
                    }
                }
            }
        });
    });
    
    // Auto-open submenu if it contains active item
    const activeLinks = document.querySelectorAll('.sidebar-submenu .sidebar-link.active');
    activeLinks.forEach(activeLink => {
        const submenu = activeLink.closest('.sidebar-submenu');
        const toggle = submenu ? submenu.previousElementSibling : null;
        
        if (submenu && toggle && toggle.classList.contains('sidebar-submenu-toggle')) {
            submenu.classList.add('sidebar-submenu-open');
            toggle.classList.add('active');
            
            const icon = toggle.querySelector('.submenu-icon');
            if (icon) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
        }
    });
});
</script>
<?php if ($isDispatcherSidebar): ?>
<script>
(function() {
    const STORAGE_KEY = 'ersActiveCallSessionV1';
    let memorySession = null;
    let widgetTimer = null;

    function clone(value) {
        return value ? JSON.parse(JSON.stringify(value)) : null;
    }

    function readSession() {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return clone(memorySession);
            }
            return JSON.parse(raw);
        } catch (error) {
            return clone(memorySession);
        }
    }

    function writeSession(session) {
        memorySession = clone(session);
        try {
            if (!session) {
                window.localStorage.removeItem(STORAGE_KEY);
            } else {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
            }
        } catch (error) {
            // Storage can be unavailable in private or locked-down browsers.
        }
    }

    function normalizeSession(session) {
        if (!session || session.active !== true) {
            return null;
        }
        const start = Number(session.start);
        const acceptedAt = Number(session.acceptedAt);
        return {
            active: true,
            name: String(session.name || 'Unknown Caller'),
            phone: String(session.phone || ''),
            start: Number.isFinite(start) && start > 0 ? start : Date.now(),
            acceptedAt: Number.isFinite(acceptedAt) && acceptedAt > 0 ? acceptedAt : null,
            auditSessionId: String(session.auditSessionId || ''),
            muted: session.muted === true,
            speaker: session.speaker === true,
            incidentId: session.incidentId !== null && session.incidentId !== undefined && session.incidentId !== ''
                ? Number(session.incidentId)
                : null,
            incidentReferenceNo: String(session.incidentReferenceNo || ''),
            incidentStatus: String(session.incidentStatus || ''),
            incidentType: String(session.incidentType || ''),
            location: String(session.location || ''),
            priority: String(session.priority || ''),
            latitude: session.latitude !== null && session.latitude !== undefined && session.latitude !== ''
                ? Number(session.latitude)
                : null,
            longitude: session.longitude !== null && session.longitude !== undefined && session.longitude !== ''
                ? Number(session.longitude)
                : null,
            description: String(session.description || ''),
            isTransfer: session.isTransfer === true,
            transferId: String(session.transferId || ''),
            callId: String(session.callId || ''),
            conversationId: String(session.conversationId || ''),
            room: String(session.room || ''),
            transferType: String(session.transferType || ''),
            socketUrl: String(session.socketUrl || ''),
            socketPath: String(session.socketPath || ''),
            sourceSystem: String(session.sourceSystem || '')
        };
    }

    function formatElapsed(start) {
        const elapsed = Math.max(0, Math.floor((Date.now() - Number(start || Date.now())) / 1000));
        const hours = Math.floor(elapsed / 3600);
        const minutes = Math.floor((elapsed % 3600) / 60);
        const seconds = elapsed % 60;
        if (hours > 0) {
            return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }
        return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }

    function recordCallSessionEnded(session) {
        if (!session || typeof session !== 'object') {
            return;
        }
        const auditSessionId = String(session.auditSessionId || '').trim();
        if (!/^[A-Za-z0-9.:-]{8,96}$/.test(auditSessionId)) {
            return;
        }
        const payload = {
            audit_session_id: auditSessionId,
            event: 'ended',
            occurred_at: new Date().toISOString(),
            reference_no: String(session.incidentReferenceNo || '').trim(),
            is_transfer: session.isTransfer === true,
            source_system: String(session.sourceSystem || '').trim(),
            reason: 'dispatcher-session-ended'
        };
        fetch('api/call_audit_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(function(error) {
            console.warn('Call end audit failed:', error);
        });
    }

    function emitChange(session) {
        document.dispatchEvent(new CustomEvent('ers:call-session-change', {
            detail: { session: clone(session) }
        }));
    }

    function getState() {
        return normalizeSession(readSession());
    }

    function setState(session) {
        const normalized = normalizeSession(session);
        writeSession(normalized);
        renderWidget(normalized);
        emitChange(normalized);
        return normalized;
    }

    function updateActionButton(button, active, iconOn, iconOff) {
        if (!button) {
            return;
        }
        button.classList.toggle('is-active', !!active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        const icon = button.querySelector('i');
        if (!icon) {
            return;
        }
        icon.className = active ? iconOn : iconOff;
    }

    function renderWidget(session) {
        const widget = document.getElementById('floatingCallWidget');
        if (!widget) {
            return;
        }

        const summary = document.getElementById('floatingCallSummary');
        const timer = document.getElementById('floatingCallTimer');
        const name = document.getElementById('floatingCallName');
        const incident = document.getElementById('floatingCallIncident');
        const speaker = document.getElementById('floatingCallSpeaker');
        const mute = document.getElementById('floatingCallMute');

        if (!session) {
            widget.hidden = true;
            widget.classList.remove('is-visible');
            if (summary) {
                summary.removeAttribute('data-has-incident');
            }
            if (timer) timer.textContent = '00:00';
            if (name) name.textContent = 'Caller';
            if (incident) incident.textContent = 'Dispatch standby';
            updateActionButton(speaker, false, 'fas fa-volume-up', 'fas fa-volume-up');
            updateActionButton(mute, false, 'fas fa-microphone-slash', 'fas fa-microphone');
            return;
        }

        widget.hidden = false;
        widget.classList.add('is-visible');
        if (timer) timer.textContent = formatElapsed(session.start);
        if (name) name.textContent = session.name || 'Unknown Caller';
        if (incident) {
            if (session.incidentReferenceNo) {
                incident.textContent = session.incidentReferenceNo;
            } else if (session.incidentType) {
                incident.textContent = session.incidentType;
            } else {
                incident.textContent = 'Dispatch standby';
            }
        }
        if (summary) {
            if (session.incidentReferenceNo) {
                summary.setAttribute('data-has-incident', 'true');
            } else {
                summary.removeAttribute('data-has-incident');
            }
        }
        updateActionButton(speaker, session.speaker === true, 'fas fa-volume-up', 'fas fa-volume-up');
        updateActionButton(mute, session.muted === true, 'fas fa-microphone-slash', 'fas fa-microphone');
    }

    function ensureWidgetBindings() {
        const summary = document.getElementById('floatingCallSummary');
        const speaker = document.getElementById('floatingCallSpeaker');
        const mute = document.getElementById('floatingCallMute');
        const end = document.getElementById('floatingCallEnd');

        if (summary && !summary.dataset.bound) {
            summary.dataset.bound = 'true';
            summary.addEventListener('click', function() {
                const session = getState();
                const params = new URLSearchParams();
                if (session && session.incidentId !== null) {
                    params.set('incident_id', String(session.incidentId));
                }
                if (session && session.incidentReferenceNo) {
                    params.set('code', session.incidentReferenceNo);
                }
                if (session) {
                    params.set('call', 'active');
                }
                const target = 'dispatcher/dispatch.php' + (params.toString() ? ('?' + params.toString()) : '');
                window.location.href = target;
            });
        }

        if (speaker && !speaker.dataset.bound) {
            speaker.dataset.bound = 'true';
            speaker.addEventListener('click', function() {
                window.ersCallSession.toggleSpeaker();
            });
        }

        if (mute && !mute.dataset.bound) {
            mute.dataset.bound = 'true';
            mute.addEventListener('click', function() {
                window.ersCallSession.toggleMute();
            });
        }

        if (end && !end.dataset.bound) {
            end.dataset.bound = 'true';
            end.addEventListener('click', function() {
                window.ersCallSession.end();
            });
        }
    }

    function ensureWidgetTimer() {
        if (widgetTimer) {
            window.clearInterval(widgetTimer);
        }
        widgetTimer = window.setInterval(function() {
            renderWidget(getState());
        }, 1000);
    }

    window.ersCallSession = {
        getState: getState,
        start: function(payload) {
            const current = getState() || {};
            return setState({
                active: true,
                name: payload && payload.name ? payload.name : current.name,
                phone: payload && payload.phone ? payload.phone : current.phone,
                start: payload && payload.start ? payload.start : (current.start || Date.now()),
                acceptedAt: payload && Object.prototype.hasOwnProperty.call(payload, 'acceptedAt') ? payload.acceptedAt : current.acceptedAt,
                auditSessionId: payload && Object.prototype.hasOwnProperty.call(payload, 'auditSessionId') ? payload.auditSessionId : current.auditSessionId,
                muted: payload && Object.prototype.hasOwnProperty.call(payload, 'muted') ? payload.muted === true : (current.muted === true),
                speaker: payload && Object.prototype.hasOwnProperty.call(payload, 'speaker') ? payload.speaker === true : (current.speaker === true),
                incidentId: payload && Object.prototype.hasOwnProperty.call(payload, 'incidentId') ? payload.incidentId : current.incidentId,
                incidentReferenceNo: payload && Object.prototype.hasOwnProperty.call(payload, 'incidentReferenceNo') ? payload.incidentReferenceNo : current.incidentReferenceNo,
                incidentStatus: payload && Object.prototype.hasOwnProperty.call(payload, 'incidentStatus') ? payload.incidentStatus : current.incidentStatus,
                incidentType: payload && Object.prototype.hasOwnProperty.call(payload, 'incidentType') ? payload.incidentType : current.incidentType,
                location: payload && Object.prototype.hasOwnProperty.call(payload, 'location') ? payload.location : current.location,
                priority: payload && Object.prototype.hasOwnProperty.call(payload, 'priority') ? payload.priority : current.priority,
                latitude: payload && Object.prototype.hasOwnProperty.call(payload, 'latitude') ? payload.latitude : current.latitude,
                longitude: payload && Object.prototype.hasOwnProperty.call(payload, 'longitude') ? payload.longitude : current.longitude,
                description: payload && Object.prototype.hasOwnProperty.call(payload, 'description') ? payload.description : current.description,
                isTransfer: payload && Object.prototype.hasOwnProperty.call(payload, 'isTransfer') ? payload.isTransfer === true : current.isTransfer === true,
                transferId: payload && Object.prototype.hasOwnProperty.call(payload, 'transferId') ? payload.transferId : current.transferId,
                callId: payload && Object.prototype.hasOwnProperty.call(payload, 'callId') ? payload.callId : current.callId,
                conversationId: payload && Object.prototype.hasOwnProperty.call(payload, 'conversationId') ? payload.conversationId : current.conversationId,
                room: payload && Object.prototype.hasOwnProperty.call(payload, 'room') ? payload.room : current.room,
                transferType: payload && Object.prototype.hasOwnProperty.call(payload, 'transferType') ? payload.transferType : current.transferType,
                socketUrl: payload && Object.prototype.hasOwnProperty.call(payload, 'socketUrl') ? payload.socketUrl : current.socketUrl,
                socketPath: payload && Object.prototype.hasOwnProperty.call(payload, 'socketPath') ? payload.socketPath : current.socketPath,
                sourceSystem: payload && Object.prototype.hasOwnProperty.call(payload, 'sourceSystem') ? payload.sourceSystem : current.sourceSystem
            });
        },
        update: function(payload) {
            const current = getState();
            if (!current) {
                return null;
            }
            return setState(Object.assign({}, current, payload || {}, { active: true }));
        },
        end: function() {
            const current = getState();
            recordCallSessionEnded(current);
            return setState(null);
        },
        toggleMute: function() {
            const current = getState();
            if (!current) {
                return null;
            }
            return setState(Object.assign({}, current, { muted: !current.muted }));
        },
        toggleSpeaker: function() {
            const current = getState();
            if (!current) {
                return null;
            }
            return setState(Object.assign({}, current, { speaker: !current.speaker }));
        }
    };

    function initWidget() {
        ensureWidgetBindings();
        ensureWidgetTimer();
        renderWidget(getState());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

    window.addEventListener('storage', function(event) {
        if (event.key === STORAGE_KEY) {
            const session = getState();
            renderWidget(session);
            emitChange(session);
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($isDispatcherSidebar): ?>
<script>
(function() {
    const isCallReceivingPage = /\/dispatcher\/call\.php\/?$/i.test(window.location.pathname);
    if (isCallReceivingPage) {
        return;
    }

    const endpoint = 'api/dispatcher_incoming_call_alert.php';
    const pollIntervalMs = 5000;
    const originalDocumentTitle = document.title;
    let pollTimer = null;
    let activeRequest = null;

    function hasActiveCallSession() {
        try {
            return !!(window.ersCallSession && window.ersCallSession.getState());
        } catch (error) {
            return false;
        }
    }

    function scheduleNextPoll(delay) {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }
        pollTimer = window.setTimeout(pollIncomingCalls, Number(delay) || pollIntervalMs);
    }

    function hideIncomingCallAlert() {
        const alert = document.getElementById('dispatcherIncomingCallAlert');
        const badge = document.getElementById('dispatcherIncomingCallBadge');
        if (alert) {
            alert.hidden = true;
        }
        if (badge) {
            badge.hidden = true;
            badge.textContent = '0';
        }
        if (document.title.indexOf('Incoming Call • ') === 0) {
            document.title = originalDocumentTitle;
        }
    }

    function renderIncomingCallAlert(calls) {
        const incomingCalls = Array.isArray(calls) ? calls.filter(Boolean) : [];
        if (!incomingCalls.length || hasActiveCallSession()) {
            hideIncomingCallAlert();
            return;
        }

        const alert = document.getElementById('dispatcherIncomingCallAlert');
        const badge = document.getElementById('dispatcherIncomingCallBadge');
        const title = document.getElementById('dispatcherIncomingCallTitle');
        const meta = document.getElementById('dispatcherIncomingCallMeta');
        const more = document.getElementById('dispatcherIncomingCallMore');
        if (!alert || !badge || !title || !meta || !more) {
            return;
        }

        const newest = incomingCalls[0] || {};
        const callerName = String(newest.caller_name || 'Emergency caller').trim();
        const sourceSystem = String(newest.source_system || 'Partner emergency app').trim();
        const referenceNo = String(newest.reference_no || 'Transferred call').trim();
        const location = String(newest.location || 'Location pending').trim();

        title.textContent = callerName + ' · ' + sourceSystem;
        meta.textContent = referenceNo + ' · ' + location;
        more.hidden = incomingCalls.length <= 1;
        more.textContent = incomingCalls.length > 1 ? ('+' + (incomingCalls.length - 1) + ' more incoming call' + (incomingCalls.length > 2 ? 's' : '')) : '';
        badge.textContent = incomingCalls.length > 9 ? '9+' : String(incomingCalls.length);
        badge.hidden = false;
        alert.hidden = false;
        document.title = 'Incoming Call • ' + originalDocumentTitle;
    }

    async function pollIncomingCalls() {
        if (hasActiveCallSession()) {
            hideIncomingCallAlert();
            scheduleNextPoll(pollIntervalMs);
            return;
        }

        if (activeRequest) {
            activeRequest.abort();
        }
        activeRequest = new AbortController();

        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
                signal: activeRequest.signal
            });
            if (!response.ok) {
                throw new Error('Incoming-call alert request failed');
            }
            const payload = await response.json();
            if (payload && payload.ok === true) {
                renderIncomingCallAlert(payload.calls);
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            // Keep an already visible alert during a temporary network failure.
            console.warn('Incoming-call alert is temporarily unavailable.');
        } finally {
            activeRequest = null;
            scheduleNextPoll(document.hidden ? 15000 : pollIntervalMs);
        }
    }

    document.addEventListener('ers:call-session-change', function(event) {
        if (event && event.detail && event.detail.session) {
            hideIncomingCallAlert();
            return;
        }
        pollIncomingCalls();
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            pollIncomingCalls();
        }
    });

    window.addEventListener('beforeunload', function() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }
        if (activeRequest) {
            activeRequest.abort();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            scheduleNextPoll(300);
        }, { once: true });
    } else {
        scheduleNextPoll(300);
    }
})();
</script>
<?php endif; ?>
