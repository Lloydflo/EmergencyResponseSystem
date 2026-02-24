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
            <!-- Overview -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Overview</div>
                <ul class="sidebar-menu">
                    <li class="sidebar-menu-item">
                        <a href="admin/index.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'page' : 'false'; ?>">
                            <i class="fa-solid fa-gauge"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="sidebar-menu-item">
                        <a href="dispatcher/dashboard.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'page' : 'false'; ?>">
                            <i class="fa-solid fa-headset"></i>
                            <span>Dispatcher Dashboard</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Operations -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Operations</div>
                <ul class="sidebar-menu">
                    <li class="sidebar-menu-item">
                        <a href="dispatcher/call.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'call.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'call.php' ? 'page' : 'false'; ?>">
                            <i class="fa-solid fa-phone"></i>
                            <span>Call Receiving & Logs</span>
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

            <!-- Collaboration -->
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

            <!-- Insights -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Insights</div>
                <ul class="sidebar-menu">
                    <li class="sidebar-menu-item">
                        <a href="admin/report.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'report.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'report.php' ? 'page' : 'false'; ?>">
                            <i class="fa-solid fa-chart-area"></i>
                            <span>Report Analytics</span>
                        </a>
                    </li>
                    <li class="sidebar-menu-item">
                        <a href="dispatcher/review.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'active' : ''; ?>" aria-current="<?php echo basename($_SERVER['PHP_SELF']) == 'review.php' ? 'page' : 'false'; ?>">
                            <i class="fa-solid fa-comment"></i>
                            <span>Review & Feedback</span>
                        </a>
                    </li>
                </ul>
            </div>
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
