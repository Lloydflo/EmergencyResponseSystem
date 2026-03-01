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
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <style>
        :root{
            --ss-bg:#f6f8fc;--ss-card:#fff;--ss-border:#dbe4ef;--ss-text:#0f2238;--ss-muted:#5f7489;--ss-primary:#0f766e;
        }
        .main-content{background:radial-gradient(circle at 88% 6%,rgba(14,165,233,.14),transparent 34%),var(--ss-bg);padding:4rem 1.2rem 3rem}
        .settings-shell{display:grid;gap:.9rem}
        .settings-head{background:linear-gradient(120deg,#0f766e,#0d9488,#0369a1);color:#f8fafc;border-radius:14px;padding:1rem 1.1rem;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .settings-head h1{margin:0;font-size:1.65rem}
        .settings-head p{margin:.35rem 0 0;font-size:.9rem;max-width:720px}
        .save-state{font-size:.8rem;font-weight:700;border:1px solid rgba(255,255,255,.4);padding:.35rem .65rem;border-radius:999px;align-self:flex-start;background:rgba(255,255,255,.14)}
        .settings-layout{display:grid;grid-template-columns:1fr 300px;gap:.9rem}
        .settings-form{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
        .card{background:var(--ss-card);border:1px solid var(--ss-border);border-radius:12px;padding:.95rem;box-shadow:0 4px 14px rgba(15,23,42,.06)}
        .card.full{grid-column:1 / -1}
        .card h2{margin:0;font-size:1rem;color:var(--ss-text);display:flex;align-items:center;gap:.45rem}
        .card p{margin:.35rem 0 .85rem;color:var(--ss-muted);font-size:.82rem}
        .fields{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}
        .field{display:flex;flex-direction:column;gap:.3rem}
        .field.full{grid-column:1 / -1}
        .field label{font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#334a60}
        .field input,.field select,.field textarea{border:1px solid #c9d8e8;border-radius:9px;padding:.6rem .68rem;font-size:.9rem;color:var(--ss-text);font-family:inherit}
        .field textarea{min-height:84px;resize:vertical}
        .field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#14b8a6;box-shadow:0 0 0 3px rgba(20,184,166,.15)}
        .toggles{display:grid;gap:.55rem}
        .toggle{display:flex;gap:.55rem;align-items:flex-start;background:#f8fbff;border:1px solid #d8e3ef;border-radius:9px;padding:.58rem .62rem}
        .toggle input{margin-top:.2rem}
        .toggle strong{display:block;font-size:.84rem;color:#10263b}
        .toggle small{display:block;font-size:.76rem;color:#5e748a;line-height:1.35}
        .actions{grid-column:1 / -1;background:#fff;border:1px solid var(--ss-border);border-radius:12px;padding:.75rem .9rem;display:flex;justify-content:flex-end;gap:.6rem}
        .btn{border:1px solid transparent;border-radius:9px;padding:.56rem .88rem;font-weight:700;font-size:.86rem;cursor:pointer}
        .btn.secondary{border-color:#d1dbe7;background:#fff;color:#17324d}
        .btn.primary{border-color:var(--ss-primary);background:var(--ss-primary);color:#fff}
        .aside{display:grid;gap:.8rem;align-content:start}
        .aside .card h3{margin:0;font-size:.92rem;color:var(--ss-text)}
        .meta{display:grid;gap:.45rem;margin-top:.6rem}
        .meta div{display:flex;justify-content:space-between;gap:.45rem;border:1px solid #dce6f1;border-radius:8px;background:#f8fbff;padding:.46rem .54rem;font-size:.8rem;color:#415c76}
        .meta strong{color:#10263b}
        .banner{margin-top:.6rem;padding:.62rem .68rem;border-radius:8px;border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;font-size:.8rem}
        .toast{position:fixed;right:16px;bottom:16px;z-index:6000;background:#0f172a;color:#fff;border-radius:10px;padding:.66rem .86rem;font-size:.84rem;display:none}
        .toast.show{display:block}
        @media (max-width:1100px){.settings-layout{grid-template-columns:1fr}.aside{grid-template-columns:1fr 1fr}}
        @media (max-width:860px){.settings-form{grid-template-columns:1fr}.fields{grid-template-columns:1fr}}
        @media (max-width:620px){.main-content{padding:1rem .75rem 1.4rem}.aside{grid-template-columns:1fr}.actions{flex-direction:column-reverse}.btn{width:100%}}
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container settings-shell">
            <section class="settings-head">
                <div>
                    <h1>System Settings</h1>
                    <p>Admin panel para sa default behavior ng system: dispatch rules, notifications, security policy, at backup controls.</p>
                </div>
                <div class="save-state" id="saveState">Saved</div>
            </section>

            <div class="settings-layout">
                <form id="settingsForm" class="settings-form">
                    <section class="card">
                        <h2><i class="fas fa-sliders-h"></i> General</h2>
                        <p>Core values used across pages and reports.</p>
                        <div class="fields">
                            <div class="field">
                                <label for="systemName">System Name</label>
                                <input id="systemName" name="systemName" value="Emergency Response System">
                            </div>
                            <div class="field">
                                <label for="dispatchCenter">Dispatch Center Name</label>
                                <input id="dispatchCenter" name="dispatchCenter" value="LGU #4 Command Center">
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
                        </div>
                    </section>

                    <section class="card">
                        <h2><i class="fas fa-truck-medical"></i> Dispatch Rules</h2>
                        <p>Incident defaults and escalation timers.</p>
                        <div class="fields">
                            <div class="field">
                                <label for="defaultPriority">Default Priority</label>
                                <select id="defaultPriority" name="defaultPriority">
                                    <option>High</option><option selected>Medium</option><option>Low</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="incidentPrefix">Incident Prefix</label>
                                <input id="incidentPrefix" name="incidentPrefix" value="INC">
                            </div>
                            <div class="field">
                                <label for="responseTarget">Response Target (mins)</label>
                                <input type="number" id="responseTarget" name="responseTarget" min="1" max="120" value="8">
                            </div>
                            <div class="field">
                                <label for="escalationDelay">Escalation Delay (mins)</label>
                                <input type="number" id="escalationDelay" name="escalationDelay" min="1" max="180" value="12">
                            </div>
                        </div>
                        <div class="toggles" style="margin-top:.7rem;">
                            <label class="toggle"><input type="checkbox" name="autoDispatch" id="autoDispatch" checked><span><strong>Auto Dispatch Suggestions</strong><small>Recommend nearest available units.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="allowManualOverride" id="allowManualOverride" checked><span><strong>Allow Manual Override</strong><small>Dispatchers can change AI suggestions.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="enableAiAssist" id="enableAiAssist" checked><span><strong>AI Incident Assistant</strong><small>Enable AI analysis endpoints.</small></span></label>
                        </div>
                    </section>

                    <section class="card">
                        <h2><i class="fas fa-bell"></i> Notifications</h2>
                        <p>Alert and digest behavior by channel.</p>
                        <div class="fields">
                            <div class="field">
                                <label for="digestFrequency">Digest Frequency</label>
                                <select id="digestFrequency" name="digestFrequency">
                                    <option>Off</option><option>Every 30 minutes</option><option selected>Hourly</option><option>Daily</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="webhookUrl">Webhook URL</label>
                                <input type="url" id="webhookUrl" name="webhookUrl" placeholder="https://hooks.example.com/ers">
                            </div>
                        </div>
                        <div class="toggles" style="margin-top:.7rem;">
                            <label class="toggle"><input type="checkbox" name="emailAlerts" id="emailAlerts" checked><span><strong>Email Alerts</strong><small>Send critical notifications to agency inboxes.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="smsAlerts" id="smsAlerts"><span><strong>SMS Alerts</strong><small>Push urgent incident notices through SMS gateway.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="inAppAlerts" id="inAppAlerts" checked><span><strong>In-App Alerts</strong><small>Show alerts directly in dashboard modules.</small></span></label>
                        </div>
                    </section>

                    <section class="card">
                        <h2><i class="fas fa-shield-alt"></i> Security</h2>
                        <p>Session and login controls for user safety.</p>
                        <div class="fields">
                            <div class="field">
                                <label for="sessionTimeout">Session Timeout</label>
                                <select id="sessionTimeout" name="sessionTimeout">
                                    <option>15</option><option selected>30</option><option>45</option><option>60</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="maxLoginAttempts">Max Login Attempts</label>
                                <input type="number" id="maxLoginAttempts" name="maxLoginAttempts" min="1" max="10" value="5">
                            </div>
                            <div class="field full">
                                <label for="ipWhitelist">IP Whitelist</label>
                                <textarea id="ipWhitelist" name="ipWhitelist" placeholder="192.168.1.10&#10;203.177.88.22"></textarea>
                            </div>
                        </div>
                        <div class="toggles" style="margin-top:.7rem;">
                            <label class="toggle"><input type="checkbox" name="enforce2fa" id="enforce2fa" checked><span><strong>Require OTP for Admin Login</strong><small>Admin login requires one-time passcode verification.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="auditLogging" id="auditLogging" checked><span><strong>Detailed Audit Logging</strong><small>Track access and critical system changes.</small></span></label>
                        </div>
                    </section>

                    <section class="card full">
                        <h2><i class="fas fa-database"></i> Data, Backup, Maintenance</h2>
                        <p>Retention policy and service mode controls.</p>
                        <div class="fields">
                            <div class="field">
                                <label for="retentionDays">Retention (days)</label>
                                <input type="number" id="retentionDays" name="retentionDays" min="30" max="3650" value="365">
                            </div>
                            <div class="field">
                                <label for="backupSchedule">Backup Schedule</label>
                                <select id="backupSchedule" name="backupSchedule">
                                    <option>Every 6 hours</option><option selected>Daily at 01:00</option><option>Daily at 03:00</option><option>Weekly</option>
                                </select>
                            </div>
                            <div class="field full">
                                <label for="maintenanceMessage">Maintenance Message</label>
                                <textarea id="maintenanceMessage" name="maintenanceMessage">System is under scheduled maintenance. Please try again later.</textarea>
                            </div>
                        </div>
                        <div class="toggles" style="margin-top:.7rem;">
                            <label class="toggle"><input type="checkbox" name="backupEncryption" id="backupEncryption" checked><span><strong>Encrypt Backups</strong><small>Secure backup snapshots before storage.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="maintenanceMode" id="maintenanceMode"><span><strong>Maintenance Mode</strong><small>Temporarily limit access during updates.</small></span></label>
                            <label class="toggle"><input type="checkbox" name="readOnlyMode" id="readOnlyMode"><span><strong>Read-Only Lock</strong><small>Disable create/update actions; keep view access.</small></span></label>
                        </div>
                    </section>

                    <div class="actions">
                        <button type="button" class="btn secondary" id="resetBtn"><i class="fas fa-undo"></i> Reset Defaults</button>
                        <button type="submit" class="btn primary"><i class="fas fa-save"></i> Save Settings</button>
                    </div>
                </form>

                <aside class="aside">
                    <section class="card">
                        <h3>Summary</h3>
                        <div class="meta">
                            <div><span>Enabled Toggles</span><strong id="enabledCount">0</strong></div>
                            <div><span>Critical Controls</span><strong id="criticalCount">0</strong></div>
                            <div><span>Maintenance State</span><strong id="maintenanceState">Inactive</strong></div>
                        </div>
                    </section>
                    <section class="card">
                        <h3>Operation Mode</h3>
                        <div class="banner" id="modeBanner">System is running in normal mode. Dispatch and write operations are active.</div>
                    </section>
                </aside>
            </div>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <div id="settingsToast" class="toast" role="status" aria-live="polite"></div>
    <script>
        const STORAGE_KEY = 'ers_system_settings_ui_v1';
        const form = document.getElementById('settingsForm');
        const resetBtn = document.getElementById('resetBtn');
        const saveState = document.getElementById('saveState');
        const settingsToast = document.getElementById('settingsToast');
        const enabledCount = document.getElementById('enabledCount');
        const criticalCount = document.getElementById('criticalCount');
        const maintenanceState = document.getElementById('maintenanceState');
        const modeBanner = document.getElementById('modeBanner');
        const maintenanceMode = document.getElementById('maintenanceMode');
        const readOnlyMode = document.getElementById('readOnlyMode');

        function toast(message) {
            settingsToast.textContent = message;
            settingsToast.classList.add('show');
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => settingsToast.classList.remove('show'), 2200);
        }

        function saveStateText(saved) {
            saveState.textContent = saved ? 'Saved' : 'Unsaved Changes';
        }

        function getPayload() {
            const payload = {};
            form.querySelectorAll('input, select, textarea').forEach((el) => {
                if (!el.name) return;
                payload[el.name] = el.type === 'checkbox' ? el.checked : el.value;
            });
            return payload;
        }

        function applyPayload(payload) {
            form.querySelectorAll('input, select, textarea').forEach((el) => {
                if (!el.name || !(el.name in payload)) return;
                if (el.type === 'checkbox') el.checked = Boolean(payload[el.name]);
                else el.value = String(payload[el.name] ?? '');
            });
        }

        function updateSummary() {
            const checks = Array.from(form.querySelectorAll('input[type="checkbox"]'));
            enabledCount.textContent = String(checks.filter((c) => c.checked).length);

            const criticalIds = ['enforce2fa', 'auditLogging', 'backupEncryption', 'autoDispatch', 'inAppAlerts'];
            const cEnabled = criticalIds.filter((id) => {
                const node = document.getElementById(id);
                return node && node.checked;
            }).length;
            criticalCount.textContent = cEnabled + '/' + criticalIds.length;

            if (maintenanceMode.checked && readOnlyMode.checked) {
                maintenanceState.textContent = 'Maintenance + Read-Only';
                modeBanner.textContent = 'Strict maintenance mode is active. Create/update actions should be suspended.';
                modeBanner.style.background = '#fff7ed';
                modeBanner.style.borderColor = '#fdba74';
                modeBanner.style.color = '#9a3412';
                return;
            }
            if (maintenanceMode.checked) {
                maintenanceState.textContent = 'Maintenance Active';
                modeBanner.textContent = 'Maintenance mode is enabled. Access should be limited to essential teams.';
                modeBanner.style.background = '#fff7ed';
                modeBanner.style.borderColor = '#fdba74';
                modeBanner.style.color = '#9a3412';
                return;
            }
            if (readOnlyMode.checked) {
                maintenanceState.textContent = 'Read-Only';
                modeBanner.textContent = 'Read-only lock is active. Users can view data but cannot submit updates.';
                modeBanner.style.background = '#fff7ed';
                modeBanner.style.borderColor = '#fdba74';
                modeBanner.style.color = '#9a3412';
                return;
            }

            maintenanceState.textContent = 'Inactive';
            modeBanner.textContent = 'System is running in normal mode. Dispatch and write operations are active.';
            modeBanner.style.background = '#ecfdf5';
            modeBanner.style.borderColor = '#bbf7d0';
            modeBanner.style.color = '#166534';
        }

        function loadFromStorage() {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                updateSummary();
                saveStateText(true);
                return;
            }
            try {
                const parsed = JSON.parse(raw);
                applyPayload(parsed);
            } catch (err) {
                console.warn('Failed to parse saved settings', err);
            }
            updateSummary();
            saveStateText(true);
        }

        form.addEventListener('input', () => {
            saveStateText(false);
            updateSummary();
        });

        form.addEventListener('change', () => {
            saveStateText(false);
            updateSummary();
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(getPayload()));
            saveStateText(true);
            updateSummary();
            toast('Settings saved locally.');
        });

        resetBtn.addEventListener('click', () => {
            form.reset();
            localStorage.removeItem(STORAGE_KEY);
            saveStateText(false);
            updateSummary();
            toast('Defaults restored.');
        });

        loadFromStorage();
    </script>
</body>
</html>
