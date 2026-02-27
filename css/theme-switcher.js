// Theme Switcher Logic for ERS
(function() {
    const THEME_KEY = 'ers-theme';
    const root = document.documentElement;
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)');

    function setTheme(theme) {
        if (theme === 'system') {
            root.removeAttribute('data-theme');
            if (systemDark.matches) {
                root.setAttribute('data-theme', 'dark');
            } else {
                root.setAttribute('data-theme', 'light');
            }
        } else {
            root.setAttribute('data-theme', theme);
        }
        localStorage.setItem(THEME_KEY, theme);
        updateActiveButton(theme);
    }

    function updateActiveButton(theme) {
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.theme === theme) {
                btn.classList.add('active');
            }
        });
    }

    function initTheme() {
        let theme = localStorage.getItem(THEME_KEY) || 'system';
        setTheme(theme);
    }

    // Listen for system theme changes
    systemDark.addEventListener('change', () => {
        if ((localStorage.getItem(THEME_KEY) || 'system') === 'system') {
            setTheme('system');
        }
    });

    // Expose to window for button onclick
    window.ersSetTheme = setTheme;
    window.ersInitTheme = initTheme;

    document.addEventListener('DOMContentLoaded', initTheme);
})();
