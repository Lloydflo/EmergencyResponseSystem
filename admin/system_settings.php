<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/system_settings.php');

$pageTitle = 'System Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <style>
        :root {
            --settings-bg: #f4f7fb;
            --settings-card: #ffffff;
            --settings-border: #d9e2ec;
            --settings-text: #102a43;
            --settings-muted: #627d98;
            --settings-primary: #1d4ed8;
            --settings-primary-dark: #1e40af;
            --settings-success-bg: #ecfdf3;
            --settings-success-border: #86efac;
            --settings-success-text: #166534;
            --settings-summary-bg: #eff6ff;
            --settings-summary-border: #bfdbfe;
            --settings-summary-text: #1e3a8a;
            --settings-toggle-bg: #f8fbff;
            --settings-button-secondary-bg: #ffffff;
            --settings-button-secondary-text: #1f2937;
        }

        html[data-theme="dark"] {
            --settings-bg: #0f172a;
            --settings-card: #111827;
            --settings-border: #334155;
            --settings-text: #e5eef9;
            --settings-muted: #94a3b8;
            --settings-primary: #60a5fa;
            --settings-primary-dark: #3b82f6;
            --settings-success-bg: #052e1f;
            --settings-success-border: #166534;
            --settings-success-text: #bbf7d0;
            --settings-summary-bg: #172554;
            --settings-summary-border: #1d4ed8;
            --settings-summary-text: #bfdbfe;
            --settings-toggle-bg: #0f1b2d;
            --settings-button-secondary-bg: #0f172a;
            --settings-button-secondary-text: #e2e8f0;
        }

        .main-content {
            padding:
                calc(var(--app-header-height-1) + 1.25rem)
                1.2rem
                3rem;
            background: linear-gradient(180deg, #f8fbff 0%, var(--settings-bg) 100%);
        }

        .settings-wrap {
            max-width: 980px;
            margin: 0 auto;
            display: grid;
            gap: 1rem;
        }

        .settings-hero,
        .settings-card {
            background: var(--settings-card);
            border: 1px solid var(--settings-border);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }

        .settings-hero {
            padding: 1.3rem 1.4rem;
        }

        .settings-hero h1 {
            margin: 0 0 .4rem;
            font-size: 1.6rem;
            color: var(--settings-text);
        }

        .settings-hero p {
            margin: 0;
            color: var(--settings-muted);
            font-size: .95rem;
            max-width: 720px;
        }

        .settings-card {
            padding: 1.2rem 1.4rem;
        }

        .settings-card h2 {
            margin: 0 0 .35rem;
            font-size: 1.05rem;
            color: var(--settings-text);
        }

        .settings-card p {
            margin: 0 0 1rem;
            color: var(--settings-muted);
            font-size: .88rem;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .9rem;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            font-size: .8rem;
            font-weight: 700;
            color: var(--settings-text);
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: .72rem .8rem;
            font: inherit;
            color: var(--settings-text);
            background: var(--settings-card);
        }

        .field textarea {
            min-height: 96px;
            resize: vertical;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--settings-primary);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.14);
        }

        .toggle-row {
            display: flex;
            align-items: flex-start;
            gap: .7rem;
            padding: .85rem .9rem;
            border: 1px solid var(--settings-border);
            border-radius: 12px;
            background: var(--settings-toggle-bg);
        }

        .toggle-row input {
            margin-top: .15rem;
        }

        .toggle-row strong {
            display: block;
            color: var(--settings-text);
            font-size: .92rem;
        }

        .toggle-row span {
            display: block;
            color: var(--settings-muted);
            font-size: .82rem;
            margin-top: .18rem;
        }

        .settings-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .status-badge {
            padding: .55rem .8rem;
            border-radius: 999px;
            background: var(--settings-success-bg);
            border: 1px solid var(--settings-success-border);
            color: var(--settings-success-text);
            font-size: .82rem;
            font-weight: 700;
        }

        .button-row {
            display: flex;
            gap: .7rem;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid transparent;
            border-radius: 10px;
            padding: .72rem 1rem;
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn.secondary {
            background: var(--settings-button-secondary-bg);
            color: var(--settings-button-secondary-text);
            border-color: #cbd5e1;
        }

        .btn.primary {
            background: var(--settings-primary);
            color: #fff;
        }

        .btn.primary:hover {
            background: var(--settings-primary-dark);
        }

        .settings-note {
            padding: .85rem .95rem;
            border-radius: 12px;
            background: var(--settings-summary-bg);
            border: 1px solid var(--settings-summary-border);
            color: var(--settings-summary-text);
            font-size: .85rem;
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 6000;
            display: none;
            padding: .75rem .9rem;
            border-radius: 10px;
            background: #0f172a;
            color: #fff;
            font-size: .84rem;
        }

        .toast.show {
            display: block;
        }

        @media (max-width: 768px) {
            .main-content {
                padding:
                    calc(var(--app-header-height-mobile-1) + 1rem)
                    .75rem
                    1.5rem;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }

            .settings-footer {
                align-items: stretch;
            }

            .button-row {
                width: 100%;
            }

            .btn {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="settings-wrap">
            <section class="settings-hero">
                <h1>System Settings</h1>
                <p>These are admin settings for the system name, center details, language, timezone, and maintenance notice.</p>
            </section>

            <form id="settingsForm" class="settings-card">
                <h2>General Settings</h2>
                <p>Core information that the admin can update.</p>

                <div class="settings-grid">
                    <div class="field">
                        <label for="systemName">System Name</label>
                        <input id="systemName" name="systemName" type="text" value="Emergency Response System">
                    </div>

                    <div class="field">
                        <label for="dispatchCenter">Dispatch Center</label>
                        <input id="dispatchCenter" name="dispatchCenter" type="text" value="LGU #4 Command Center">
                    </div>

                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone">
                            <option value="Asia/Manila" selected>Asia/Manila</option>
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">America/New_York</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="defaultLanguage">Default Language</label>
                        <select id="defaultLanguage" name="defaultLanguage">
                            <option value="en" selected>English</option>
                            <option value="fil">Filipino</option>
                            <option value="en-fil">English + Filipino</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="themeMode">Theme Mode</label>
                        <select id="themeMode" name="themeMode">
                            <option value="light">Light Mode</option>
                            <option value="dark">Dark Mode</option>
                            <option value="system" selected>System Default</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label for="maintenanceMessage">Maintenance Message</label>
                        <textarea id="maintenanceMessage" name="maintenanceMessage">System is available and running normally.</textarea>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <label class="toggle-row" for="maintenanceMode">
                        <input type="checkbox" id="maintenanceMode" name="maintenanceMode">
                        <div>
                            <strong>Enable Maintenance Mode</strong>
                            <span>When enabled, the summary will show that the system is under maintenance.</span>
                        </div>
                    </label>
                </div>

                <div class="settings-footer">
                    <div class="status-badge" id="saveState">Saved</div>
                    <div class="button-row">
                        <button type="button" class="btn secondary" id="resetBtn">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </div>
            </form>

            <section class="settings-card">
                <h2>Current Status</h2>
                <p>A quick view of the current basic configuration.</p>
                <div class="settings-note" id="summaryBox">
                    System is available and running normally.
                </div>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <div id="settingsToast" class="toast" role="status" aria-live="polite"></div>

    <script>
        const SETTINGS_API = 'api/system_settings.php';
        const form = document.getElementById('settingsForm');
        const resetBtn = document.getElementById('resetBtn');
        const saveState = document.getElementById('saveState');
        const settingsToast = document.getElementById('settingsToast');
        const summaryBox = document.getElementById('summaryBox');
        const maintenanceMode = document.getElementById('maintenanceMode');
        const maintenanceMessage = document.getElementById('maintenanceMessage');
        const systemName = document.getElementById('systemName');
        const dispatchCenter = document.getElementById('dispatchCenter');
        const themeMode = document.getElementById('themeMode');

        function showToast(message) {
            settingsToast.textContent = message;
            settingsToast.classList.add('show');
            clearTimeout(showToast.timer);
            showToast.timer = setTimeout(() => settingsToast.classList.remove('show'), 2200);
        }

        function setSavedState(isSaved) {
            saveState.textContent = isSaved ? 'Saved' : 'Unsaved Changes';
        }

        function getPayload() {
            const payload = {};
            form.querySelectorAll('input, select, textarea').forEach((field) => {
                if (!field.name) return;
                payload[field.name] = field.type === 'checkbox' ? field.checked : field.value;
            });
            return payload;
        }

        function applyPayload(payload) {
            form.querySelectorAll('input, select, textarea').forEach((field) => {
                if (!field.name || !(field.name in payload)) return;
                if (field.type === 'checkbox') {
                    field.checked = Boolean(payload[field.name]);
                    return;
                }
                field.value = String(payload[field.name] ?? '');
            });
        }

        function updateSummary() {
            if (maintenanceMode.checked) {
                summaryBox.textContent = (maintenanceMessage.value || 'System is under maintenance.') + ' (' + systemName.value + ' - ' + dispatchCenter.value + ')';
                summaryBox.style.background = '#fff7ed';
                summaryBox.style.borderColor = '#fdba74';
                summaryBox.style.color = '#9a3412';
                return;
            }

            summaryBox.textContent = systemName.value + ' is active under ' + dispatchCenter.value + '.';
            summaryBox.style.background = 'var(--settings-summary-bg)';
            summaryBox.style.borderColor = 'var(--settings-summary-border)';
            summaryBox.style.color = 'var(--settings-summary-text)';
        }

        function applyThemeMode(theme) {
            if (typeof window.ersSetTheme === 'function') {
                window.ersSetTheme(theme);
                return;
            }

            document.documentElement.setAttribute('data-theme', theme === 'system' ? 'light' : theme);
        }

        async function loadSettings() {
            const currentTheme = localStorage.getItem('ers-theme') || 'system';
            themeMode.value = currentTheme;

            try {
                const response = await fetch(SETTINGS_API, {
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                if (!response.ok || !data.ok || !data.settings) {
                    throw new Error(data.error || 'Unable to load settings.');
                }
                applyPayload(data.settings);
            } catch (error) {
                console.warn('Failed to load system settings.', error);
                showToast('Using default settings. Server settings unavailable.');
            }

            if (!themeMode.value) {
                themeMode.value = currentTheme;
            }

            applyThemeMode(themeMode.value);
            updateSummary();
            setSavedState(true);
        }

        form.addEventListener('input', () => {
            setSavedState(false);
            updateSummary();
        });

        form.addEventListener('change', () => {
            setSavedState(false);
            applyThemeMode(themeMode.value);
            updateSummary();
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
            fetch(SETTINGS_API, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(getPayload())
            })
                .then((response) => response.json().then((data) => ({ response, data })))
                .then(({ response, data }) => {
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || 'Unable to save settings.');
                    }
                    if (data.settings) {
                        applyPayload(data.settings);
                    }
                    applyThemeMode(themeMode.value);
                    setSavedState(true);
                    updateSummary();
                    showToast('System settings saved to server.');
                })
                .catch((error) => {
                    showToast(error.message || 'Unable to save settings.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText || '<i class="fas fa-save"></i> Save Settings';
                    }
                });
        });

        resetBtn.addEventListener('click', () => {
            form.reset();
            themeMode.value = 'system';
            applyThemeMode('system');
            setSavedState(false);
            updateSummary();
            showToast('Default settings restored. Click Save Settings to apply.');
        });

        loadSettings();
    </script>
</body>
</html>
