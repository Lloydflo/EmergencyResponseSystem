// Theme Switcher Logic for ERS
(function() {
    const THEME_KEY = 'ers-theme';
    const root = document.documentElement;
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)');

    function getStoredTheme() {
        try {
            return localStorage.getItem(THEME_KEY) || 'system';
        } catch (_) {
            return 'system';
        }
    }

    function persistTheme(theme) {
        try {
            localStorage.setItem(THEME_KEY, theme);
        } catch (_) {}
    }

    function resolveTheme(theme) {
        return theme === 'system'
            ? (systemDark.matches ? 'dark' : 'light')
            : theme;
    }

    function applyTheme(theme) {
        const resolvedTheme = resolveTheme(theme);
        root.setAttribute('data-theme', resolvedTheme);
        root.style.colorScheme = resolvedTheme;
    }

    function updateActiveButton(theme) {
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.theme === theme) {
                btn.classList.add('active');
            }
        });
    }

    function setTheme(theme, options) {
        const settings = Object.assign({
            persist: true,
            dispatch: true
        }, options);

        applyTheme(theme);

        if (settings.persist) {
            persistTheme(theme);
        }

        updateActiveButton(theme);

        if (settings.dispatch) {
            document.dispatchEvent(new CustomEvent('themeChanged', { detail: theme }));
        }
    }

    function initTheme() {
        const theme = getStoredTheme();
        setTheme(theme, { persist: false, dispatch: false });
    }

    function bindThemeButtons() {
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            if (btn.dataset.themeBound === '1') {
                return;
            }

            btn.dataset.themeBound = '1';
            btn.addEventListener('click', () => {
                const theme = btn.dataset.theme || 'system';
                setTheme(theme);
            });
        });

        updateActiveButton(getStoredTheme());
    }

    function initializeThemeControls() {
        initTheme();
        bindThemeButtons();
    }

    const onSystemThemeChange = () => {
        if (getStoredTheme() === 'system') {
            setTheme('system', { persist: false });
        }
    };

    if (typeof systemDark.addEventListener === 'function') {
        systemDark.addEventListener('change', onSystemThemeChange);
    } else if (typeof systemDark.addListener === 'function') {
        systemDark.addListener(onSystemThemeChange);
    }

    window.ersSetTheme = setTheme;
    window.ersInitTheme = initTheme;

    // Apply the saved theme as soon as this shared script loads
    // so refreshes stay in the selected mode across admin and dispatcher pages.
    initTheme();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeThemeControls);
    } else {
        initializeThemeControls();
    }
})();
