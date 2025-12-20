<?php
/**
 * Simplified Admin Footer Component
 * Include this file in your pages: <?php include 'sidebar/admin-footer.php'; ?>
 */
?>

<!-- Admin Footer Component -->
 
<footer class="admin-footer">
    <div class="footer-bottom">

        <div class="footer-copyright">
            <p>&copy; <?php echo date('Y'); ?> LGU #4. All rights reserved.</p>
        </div>

        <div class="footer-legal">
            <a href="#" class="footer-link">Privacy Policy</a>
            <a href="#" class="footer-link">Terms of Service</a>
            <a href="#" class="footer-link">Cookie Policy</a>
        </div>

        <div class="theme-toggle">
            <button class="theme-toggle-btn" data-theme="system">
                <i class="fas fa-desktop"></i>
            </button>
            <button class="theme-toggle-btn" data-theme="light">
                <i class="fas fa-sun"></i>
            </button>
            <button class="theme-toggle-btn" data-theme="dark">
                <i class="fas fa-moon"></i>
            </button>
        </div>

    </div>
</footer>


<script>
    // Theme Toggle functionality - Fixed version
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggleBtns = document.querySelectorAll('.theme-toggle .theme-toggle-btn');
        const htmlElement = document.documentElement;
        
        if (themeToggleBtns.length === 0) {
            console.warn('Theme toggle buttons not found. Check your HTML structure.');
            return;
        }
        
        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'system';
        htmlElement.setAttribute('data-theme', savedTheme);
        updateThemeButtons(savedTheme);
        
        themeToggleBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const theme = btn.getAttribute('data-theme');
                htmlElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                updateThemeButtons(theme);
                
                // Apply system theme if selected
                if (theme === 'system') {
                    applySystemTheme();
                }
                
                // Dispatch custom event for other components
                document.dispatchEvent(new CustomEvent('themeChanged', { detail: theme }));
            });
        });
        
        function updateThemeButtons(theme) {
            themeToggleBtns.forEach(btn => {
                if (btn.getAttribute('data-theme') === theme) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        
        function applySystemTheme() {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            htmlElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
        }
        
        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (localStorage.getItem('theme') === 'system') {
                applySystemTheme();
            }
        });
        
        // Initialize system theme if needed
        if (savedTheme === 'system') {
            applySystemTheme();
        }
    });
</script>