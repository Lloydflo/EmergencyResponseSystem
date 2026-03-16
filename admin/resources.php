<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/resources.php');

$pageTitle = 'Resources Status';
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
        :root {
            --rs-bg: #f5f7fb;
            --rs-card: #ffffff;
            --rs-text: #1f2937;
            --rs-muted: #64748b;
            --rs-border: #dfe4ea;
            --rs-primary: #0f766e;
            --rs-primary-dark: #115e59;
            --rs-danger: #b91c1c;
            --rs-warning: #b45309;
            --rs-ok-bg: #dcfce7;
            --rs-ok-text: #166534;
            --rs-use-bg: #fef3c7;
            --rs-use-text: #92400e;
            --rs-maint-bg: #fee2e2;
            --rs-maint-text: #991b1b;
            --rs-off-bg: #e5e7eb;
            --rs-off-text: #374151;
        }

        .main-content {
            background: radial-gradient(circle at 0% 0%, #ebf5ff, transparent 30%), var(--rs-bg);
            padding: calc(70px + 2rem) 2rem 2rem;
            flex: 1;
            display: flex;
            padding: 3.5rem;
        }

        .resource-shell {
            padding: 1.25rem 0 2rem;
            flex: 1;
            width: 100%;
        }

        .resource-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .resource-head h1 {
            margin: 0;
            color: var(--rs-text);
            font-size: 1.75rem;
        }

        .resource-head p {
            margin: 0.25rem 0 0;
            color: var(--rs-muted);
            font-size: 0.95rem;
        }

        .resource-head-actions {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin-left: auto;
            flex-wrap: wrap;
        }

        .archive-btn-wrap {
            position: relative;
            display: inline-flex;
        }

        .btn-primary {
            border: none;
            background: var(--rs-primary);
            color: #fff;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn-primary:hover {
            background: var(--rs-primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            border: 1px solid #b7d8d5;
            background: #ecfdf5;
            color: #115e59;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }

        .btn-secondary:hover {
            background: #d1fae5;
            border-color: #7dd3c7;
            transform: translateY(-1px);
        }

        .archive-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 0.74rem;
            font-weight: 800;
            line-height: 22px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.28);
            display: none;
        }

        .archive-badge.show {
            display: inline-block;
        }

        .resource-head-actions .btn-primary,
        .resource-head-actions .btn-secondary {
            flex-shrink: 0;
            pointer-events: auto;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(120px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .overview-item {
            background: var(--rs-card);
            border: 1px solid var(--rs-border);
            border-radius: 12px;
            padding: 0.9rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        }

        .overview-label {
            color: var(--rs-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 700;
        }

        .overview-value {
            margin-top: 0.35rem;
            color: var(--rs-text);
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1;
        }

        .resource-controls {
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr auto;
            gap: 0.65rem;
            background: var(--rs-card);
            border: 1px solid var(--rs-border);
            border-radius: 12px;
            padding: 0.8rem;
            margin-bottom: 1rem;
        }

        .control-input,
        .control-select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0.62rem 0.72rem;
            font-size: 0.93rem;
            background: #fff;
            color: var(--rs-text);
        }

        .control-input:focus,
        .control-select:focus {
            border-color: #0ea5a4;
            outline: none;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .btn-outline {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            border-radius: 8px;
            padding: 0.62rem 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }

        .table-wrap {
            background: var(--rs-card);
            border: 1px solid var(--rs-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.05);
        }

        .resource-table {
            width: 100%;
            border-collapse: collapse;
        }

        .resource-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--rs-border);
        }

        .resource-table td {
            padding: 0.72rem 0.75rem;
            border-bottom: 1px solid #eef2f7;
            color: var(--rs-text);
            font-size: 0.93rem;
            vertical-align: middle;
        }

        .resource-table tbody tr:hover {
            background: #f8fbff;
        }

        .name-cell strong {
            display: block;
            font-size: 0.93rem;
        }

        .name-cell span {
            display: block;
            color: var(--rs-muted);
            font-size: 0.8rem;
            margin-top: 2px;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 0.3rem 0.55rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-available {
            background: var(--rs-ok-bg);
            color: var(--rs-ok-text);
        }

        .status-in_use {
            background: var(--rs-use-bg);
            color: var(--rs-use-text);
        }

        .status-maintenance {
            background: var(--rs-maint-bg);
            color: var(--rs-maint-text);
        }

        .status-offline {
            background: var(--rs-off-bg);
            color: var(--rs-off-text);
        }

        .actions-cell {
            display: flex;
            gap: 0.38rem;
            align-items: center;
        }

        .action-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            background: #f8fafc;
        }

        .action-btn.delete:hover {
            color: #fff;
            background: var(--rs-danger);
            border-color: var(--rs-danger);
        }

        .action-btn.restore {
            width: auto;
            padding: 0 0.75rem;
            gap: 0.4rem;
            font-weight: 700;
            color: #166534;
            border-color: #86efac;
            background: #f0fdf4;
        }

        .action-btn.restore:hover {
            background: #dcfce7;
            border-color: #4ade80;
            color: #166534;
        }

        .empty-row td {
            text-align: center;
            color: var(--rs-muted);
            padding: 1.2rem;
        }

        .modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.52);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 12000;
            padding: 1rem;
        }

        .modal.show {
            display: flex;
        }

        .modal-card {
            width: min(680px, 100%);
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(2, 8, 23, 0.34);
            animation: cardIn 0.18s ease-out;
        }

        @keyframes cardIn {
            from { transform: translateY(8px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--rs-border);
        }

        .modal-head h2 {
            margin: 0;
            font-size: 1.07rem;
            color: var(--rs-text);
        }

        .modal-body {
            padding: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .form-group label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.45rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .label-inline-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            border-radius: 999px;
            padding: 0.15rem 0.5rem;
            font-size: 0.68rem;
            font-weight: 700;
            cursor: pointer;
            text-transform: none;
            letter-spacing: normal;
        }

        .label-inline-btn:hover {
            background: #f8fafc;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group[hidden] {
            display: none;
        }

        .modal-helper {
            border: 1px solid #dbeafe;
            background: #f0f9ff;
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
            margin-bottom: 0.8rem;
        }

        .modal-helper p {
            margin: 0 0 0.55rem;
            color: #0f3b69;
            font-size: 0.82rem;
            line-height: 1.35;
        }

        .preset-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .preset-btn {
            border: 1px solid #bfdbfe;
            background: #fff;
            color: #0f3b69;
            border-radius: 999px;
            padding: 0.28rem 0.6rem;
            font-size: 0.74rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .preset-btn:hover {
            background: #dbeafe;
        }

        .form-input,
        .form-select,
        .form-textarea {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0.62rem 0.72rem;
            font-size: 0.92rem;
            font-family: inherit;
        }

        .form-textarea {
            min-height: 88px;
            resize: vertical;
        }

        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 0.55rem;
            padding: 0.9rem 1rem;
            border-top: 1px solid var(--rs-border);
            background: #f8fafc;
        }

        .modal-card.archive-card {
            width: min(900px, 100%);
        }

        .archive-summary {
            border: 1px solid #dbeafe;
            background: linear-gradient(135deg, #eff6ff, #f8fafc);
            border-radius: 10px;
            padding: 0.75rem 0.85rem;
            margin-bottom: 0.9rem;
        }

        .archive-summary strong {
            display: block;
            color: #0f172a;
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
        }

        .archive-summary span {
            color: #475569;
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .archive-table-wrap {
            border: 1px solid var(--rs-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .archive-table {
            width: 100%;
            border-collapse: collapse;
        }

        .archive-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 0.79rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.78rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--rs-border);
        }

        .archive-table td {
            padding: 0.78rem 0.75rem;
            border-bottom: 1px solid #eef2f7;
            color: var(--rs-text);
            font-size: 0.9rem;
            vertical-align: top;
        }

        .archive-table tbody tr:last-child td {
            border-bottom: none;
        }

        .countdown-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.28rem 0.58rem;
            background: #fff7ed;
            color: #9a3412;
            font-size: 0.76rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 5000;
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 0.68rem 0.9rem;
            font-size: 0.88rem;
            display: none;
        }

        .toast.show {
            display: block;
        }

        @media (max-width: 980px) {
            .overview-grid {
                grid-template-columns: repeat(3, minmax(120px, 1fr));
            }

            .resource-controls {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: calc(70px + 1rem) 1rem 1rem;
            }

            .resource-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .resource-head-actions {
                margin-left: 0;
                width: 100%;
            }

            .resource-head-actions .btn-primary,
            .resource-head-actions .btn-secondary {
                width: 100%;
            }

            .archive-btn-wrap {
                width: 100%;
            }

            .archive-table {
                min-width: 760px;
            }

            .overview-grid {
                grid-template-columns: repeat(2, minmax(120px, 1fr));
            }

            .resource-controls {
                grid-template-columns: 1fr;
            }

            .table-wrap {
                overflow-x: auto;
            }

            .resource-table {
                min-width: 780px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container resource-shell">
            <section class="resource-head">
                <div>
                    <h1>Resources Status</h1>
                    <p>Admin panel for managing vehicles, personnel, and equipment in emergency operations.</p>
                </div>
                <div class="resource-head-actions">
                    <div class="archive-btn-wrap">
                        <button
                            type="button"
                            class="btn-secondary"
                            id="archiveResourceBtn"
                            aria-haspopup="dialog"
                            aria-controls="archiveModal"
                        >
                            <i class="fas fa-box-archive"></i> Archive
                        </button>
                        <span class="archive-badge" id="archiveBadge" aria-hidden="true">0</span>
                    </div>
                    <button
                        type="button"
                        class="btn-primary"
                        id="addResourceBtn"
                        data-open-resource-modal
                        aria-haspopup="dialog"
                        aria-controls="resourceModal"
                        onclick="document.getElementById('resourceModal').classList.add('show');document.getElementById('resourceModal').setAttribute('aria-hidden','false');document.body.style.overflow='hidden';if(document.getElementById('modalTitle')){document.getElementById('modalTitle').textContent='Add Resource';}if(document.getElementById('modalHelperText')){document.getElementById('modalHelperText').textContent='Fill out the details below to register a new resource entry.';}if(document.getElementById('saveResourceBtn')){document.getElementById('saveResourceBtn').textContent='Save Resource';}if(document.getElementById('resourceForm')){document.getElementById('resourceForm').reset();}if(document.getElementById('resourceIdHidden')){document.getElementById('resourceIdHidden').value='';}if(document.getElementById('categoryInput')){document.getElementById('categoryInput').value='vehicles';}if(document.getElementById('statusInput')){document.getElementById('statusInput').value='available';}if(document.getElementById('resourceCodeInput')){document.getElementById('resourceCodeInput').focus();}if (typeof window.openAdminResourceModal === 'function') { window.openAdminResourceModal(); }"
                    >
                        <i class="fas fa-plus"></i> Add Resource
                    </button>
                </div>
            </section>

            <section class="overview-grid">
                <article class="overview-item">
                    <div class="overview-label">Total Resources</div>
                    <div class="overview-value" id="ovTotal">0</div>
                </article>
                <article class="overview-item">
                    <div class="overview-label">Vehicles</div>
                    <div class="overview-value" id="ovVehicles">0</div>
                </article>
                <article class="overview-item">
                    <div class="overview-label">Personnel</div>
                    <div class="overview-value" id="ovPersonnel">0</div>
                </article>
                <article class="overview-item">
                    <div class="overview-label">Equipment</div>
                    <div class="overview-value" id="ovEquipment">0</div>
                </article>
                <article class="overview-item">
                    <div class="overview-label">Available</div>
                    <div class="overview-value" id="ovAvailable">0</div>
                </article>
            </section>

            <section class="resource-controls">
                <input type="text" id="searchInput" class="control-input" placeholder="Search resource name, id, or location...">
                <select id="categoryFilter" class="control-select">
                    <option value="">All Categories</option>
                    <option value="vehicles">Vehicles</option>
                    <option value="personnel">Personnel</option>
                    <option value="equipment">Equipment</option>
                </select>
                <select id="statusFilter" class="control-select">
                    <option value="">All Status</option>
                    <option value="available">Available</option>
                    <option value="in_use">In Use</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="offline">Offline</option>
                </select>
                <button type="button" class="btn-outline" id="resetFiltersBtn">Reset</button>
            </section>

            <section class="table-wrap">
                <table class="resource-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resource</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Location / Assignment</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resourceTableBody"></tbody>
                </table>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <div class="modal" id="resourceModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modalTitle" onclick="if (event.target === this) { this.classList.remove('show'); this.setAttribute('aria-hidden', 'true'); document.body.style.overflow=''; }">
        <div class="modal-card">
            <div class="modal-head">
                <h2 id="modalTitle">Add Resource</h2>
                <button type="button" class="action-btn" id="closeModalBtn" aria-label="Close" onclick="document.getElementById('resourceModal').classList.remove('show');document.getElementById('resourceModal').setAttribute('aria-hidden','true');document.body.style.overflow='';">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="resourceForm">
                <div class="modal-body">
                    <input type="hidden" id="resourceIdHidden">
                    <div class="modal-helper">
                        <p id="modalHelperText">Fill out the details below to register a new resource entry.</p>
                        <div class="preset-row">
                            <button type="button" class="preset-btn" data-preset="vehicles">Vehicle Preset</button>
                            <button type="button" class="preset-btn" data-preset="personnel">Personnel Preset</button>
                            <button type="button" class="preset-btn" data-preset="equipment">Equipment Preset</button>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="resourceCodeInput">
                                <span>Resource ID</span>
                                <button type="button" class="label-inline-btn" id="generateCodeBtn">Generate</button>
                            </label>
                            <input id="resourceCodeInput" class="form-input" required maxlength="20" placeholder="e.g. VEH-010">
                        </div>
                        <div class="form-group">
                            <label for="resourceNameInput" id="resourceNameLabel">Resource Name</label>
                            <input id="resourceNameInput" class="form-input" required maxlength="90" placeholder="e.g. Ambulance Unit 5">
                        </div>
                        <div class="form-group">
                            <label for="categoryInput">Category</label>
                            <select id="categoryInput" class="form-select" required>
                                <option value="vehicles">Vehicles</option>
                                <option value="personnel">Personnel</option>
                                <option value="equipment">Equipment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="statusInput">Status</label>
                            <select id="statusInput" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="in_use">In Use</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="locationInput">Location</label>
                            <input id="locationInput" class="form-input" required maxlength="70" placeholder="e.g. Station 2">
                        </div>
                        <div class="form-group" id="driverNameGroup">
                            <label for="driverNameInput">Driver Name</label>
                            <input id="driverNameInput" class="form-input" maxlength="90" placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="form-group" id="plateNumberGroup">
                            <label for="plateNumberInput">Plate Number</label>
                            <input id="plateNumberInput" class="form-input" maxlength="30" placeholder="e.g. ABC-1234">
                        </div>
                        <div class="form-group" id="positionTitleGroup" hidden>
                            <label for="positionTitleInput">Position</label>
                            <input id="positionTitleInput" class="form-input" maxlength="90" placeholder="e.g. EMT / Nurse / Dispatcher">
                        </div>
                        <div class="form-group" id="assignmentGroup">
                            <label for="assignmentInput" id="assignmentLabel">Assignment / Details</label>
                            <input id="assignmentInput" class="form-input" maxlength="90" placeholder="e.g. On standby">
                        </div>
                        <div class="form-group full">
                            <label for="notesInput">Notes</label>
                            <textarea id="notesInput" class="form-textarea" maxlength="250" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn-outline" id="cancelModalBtn" onclick="document.getElementById('resourceModal').classList.remove('show');document.getElementById('resourceModal').setAttribute('aria-hidden','true');document.body.style.overflow='';">Cancel</button>
                    <button type="submit" class="btn-primary" id="saveResourceBtn">Save Resource</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="archiveModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="archiveModalTitle">
        <div class="modal-card archive-card">
            <div class="modal-head">
                <h2 id="archiveModalTitle">Archived Resources</h2>
                <button type="button" class="action-btn" id="closeArchiveBtn" aria-label="Close archive">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="archive-summary">
                    <strong><span id="archiveCount">0</span> archived resources</strong>
                    <span>Deleted resources stay here for 60 days, then are permanently removed automatically.</span>
                </div>
                <div class="archive-table-wrap">
                    <table class="archive-table">
                        <thead>
                            <tr>
                                <th>Resource</th>
                                <th>Category</th>
                                <th>Last Location</th>
                                <th>Archived On</th>
                                <th>Permanent Delete</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="archiveTableBody">
                            <tr class="empty-row">
                                <td colspan="6">No archived resources.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-outline" id="archiveDoneBtn">Close</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const API_ENDPOINT = 'api/admin_resources.php';
        const ARCHIVE_ENDPOINT = `${API_ENDPOINT}?archived=1`;
        let resources = [];
        let archivedResources = [];

        let selectedId = null;
        let lastFocusedTrigger = null;

        const tableBody = document.getElementById('resourceTableBody');
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const statusFilter = document.getElementById('statusFilter');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const resourceModal = document.getElementById('resourceModal');
        const archiveModal = document.getElementById('archiveModal');
        const modalTitle = document.getElementById('modalTitle');
        const resourceForm = document.getElementById('resourceForm');
        const resourceIdHidden = document.getElementById('resourceIdHidden');
        const resourceCodeInput = document.getElementById('resourceCodeInput');
        const resourceNameLabel = document.getElementById('resourceNameLabel');
        const resourceNameInput = document.getElementById('resourceNameInput');
        const categoryInput = document.getElementById('categoryInput');
        const statusInput = document.getElementById('statusInput');
        const locationInput = document.getElementById('locationInput');
        const driverNameGroup = document.getElementById('driverNameGroup');
        const driverNameInput = document.getElementById('driverNameInput');
        const plateNumberGroup = document.getElementById('plateNumberGroup');
        const plateNumberInput = document.getElementById('plateNumberInput');
        const positionTitleGroup = document.getElementById('positionTitleGroup');
        const positionTitleInput = document.getElementById('positionTitleInput');
        const assignmentGroup = document.getElementById('assignmentGroup');
        const assignmentLabel = document.getElementById('assignmentLabel');
        const assignmentInput = document.getElementById('assignmentInput');
        const notesInput = document.getElementById('notesInput');
        const modalHelperText = document.getElementById('modalHelperText');
        const generateCodeBtn = document.getElementById('generateCodeBtn');
        const saveResourceBtn = document.getElementById('saveResourceBtn');
        const addResourceBtn = document.getElementById('addResourceBtn');
        const archiveResourceBtn = document.getElementById('archiveResourceBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const closeArchiveBtn = document.getElementById('closeArchiveBtn');
        const archiveDoneBtn = document.getElementById('archiveDoneBtn');
        const archiveTableBody = document.getElementById('archiveTableBody');
        const archiveCount = document.getElementById('archiveCount');
        const archiveBadge = document.getElementById('archiveBadge');
        const toastEl = document.getElementById('toast');
        const presetButtons = Array.from(document.querySelectorAll('[data-preset]'));

        const ovTotal = document.getElementById('ovTotal');
        const ovVehicles = document.getElementById('ovVehicles');
        const ovPersonnel = document.getElementById('ovPersonnel');
        const ovEquipment = document.getElementById('ovEquipment');
        const ovAvailable = document.getElementById('ovAvailable');

        function setSaveLoading(isLoading) {
            saveResourceBtn.disabled = !!isLoading;
            saveResourceBtn.style.opacity = isLoading ? '0.75' : '1';
            saveResourceBtn.textContent = isLoading
                ? (selectedId === null ? 'Saving...' : 'Updating...')
                : (selectedId === null ? 'Save Resource' : 'Update Resource');
        }

        function normalizeItem(raw) {
            return {
                id: Number(raw.id) || 0,
                code: String(raw.code || '').trim(),
                name: String(raw.name || '').trim(),
                category: String(raw.category || 'equipment').trim(),
                status: String(raw.status || 'available').trim(),
                location: String(raw.location || '').trim(),
                driverName: String(raw.driverName || '').trim(),
                plateNumber: String(raw.plateNumber || '').trim(),
                positionTitle: String(raw.positionTitle || '').trim(),
                assignment: String(raw.assignment || '').trim(),
                notes: String(raw.notes || '').trim(),
                updatedAt: String(raw.updatedAt || '')
            };
        }

        function normalizeArchiveItem(raw) {
            return {
                id: Number(raw.id) || 0,
                resourceId: Number(raw.resourceId) || 0,
                code: String(raw.code || '').trim(),
                name: String(raw.name || '').trim(),
                category: String(raw.category || 'equipment').trim(),
                status: String(raw.status || 'available').trim(),
                location: String(raw.location || '').trim(),
                driverName: String(raw.driverName || '').trim(),
                plateNumber: String(raw.plateNumber || '').trim(),
                positionTitle: String(raw.positionTitle || '').trim(),
                assignment: String(raw.assignment || '').trim(),
                notes: String(raw.notes || '').trim(),
                updatedAt: String(raw.updatedAt || ''),
                deletedAt: String(raw.deletedAt || ''),
                purgeAt: String(raw.purgeAt || '')
            };
        }

        async function loadResources() {
            const res = await fetch(API_ENDPOINT, { cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to load resources');
            }
            resources = Array.isArray(data.items) ? data.items.map(normalizeItem) : [];
            renderOverview();
            renderTable();
        }

        async function loadArchivedResources() {
            const res = await fetch(ARCHIVE_ENDPOINT, { cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to load archive');
            }
            archivedResources = Array.isArray(data.items) ? data.items.map(normalizeArchiveItem) : [];
            updateArchiveBadge();
            renderArchiveTable();
        }

        async function createResource(payload) {
            const res = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to create resource');
            }
            return data.item ? normalizeItem(data.item) : null;
        }

        async function updateResource(id, payload) {
            const res = await fetch(API_ENDPOINT, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, ...payload })
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to update resource');
            }
            return data.item ? normalizeItem(data.item) : null;
        }

        async function deleteResource(id) {
            const res = await fetch(API_ENDPOINT, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to delete resource');
            }
            return true;
        }

        async function restoreArchivedResource(archiveId) {
            const res = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'restore', archive_id: archiveId })
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? String(data.error) : 'Failed to restore resource');
            }
            return data.item ? normalizeItem(data.item) : null;
        }

        function formatStatus(status) {
            if (status === 'in_use') return 'In Use';
            return status.charAt(0).toUpperCase() + status.slice(1);
        }

        function formatCategory(category) {
            if (category === 'vehicles') return 'Vehicles';
            if (category === 'personnel') return 'Personnel';
            if (category === 'equipment') return 'Equipment';
            return category;
        }

        function formatDate(value) {
            if (!value) return 'N/A';
            const date = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleString();
        }

        function formatResourceMeta(item) {
            if (item.category === 'vehicles') {
                const meta = [];
                if (item.driverName) meta.push(`Driver: ${item.driverName}`);
                if (item.plateNumber) meta.push(`Plate: ${item.plateNumber}`);
                if (meta.length > 0) return meta.join(' | ');
            }
            if (item.category === 'personnel' && item.positionTitle) {
                return `Position: ${item.positionTitle}`;
            }
            return item.notes || '';
        }

        function formatAssignmentDisplay(item) {
            if (item.category === 'vehicles') {
                const parts = [];
                if (item.assignment) parts.push(item.assignment);
                if (item.driverName) parts.push(`Driver: ${item.driverName}`);
                if (item.plateNumber) parts.push(`Plate: ${item.plateNumber}`);
                return parts.join(' | ') || 'N/A';
            }
            if (item.category === 'personnel') {
                return item.positionTitle || 'No position';
            }
            return item.assignment || 'N/A';
        }

        function formatDaysLeft(value) {
            if (!value) return 'Pending';
            const date = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return 'Pending';
            const diffMs = date.getTime() - Date.now();
            const daysLeft = Math.max(0, Math.ceil(diffMs / 86400000));
            if (daysLeft === 0) return 'Deletes today';
            if (daysLeft === 1) return '1 day left';
            return `${daysLeft} days left`;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function codePrefixByCategory(category) {
            if (category === 'vehicles') return 'VEH';
            if (category === 'personnel') return 'PER';
            return 'EQU';
        }

        function nextCodeForCategory(category) {
            const prefix = codePrefixByCategory(category);
            const re = category === 'equipment'
                ? /^(?:EQU|EQP)-(\d{3,})$/i
                : new RegExp(`^${prefix}-(\\d{3,})$`, 'i');
            const maxSeq = resources.reduce((max, item) => {
                const match = String(item.code || '').trim().match(re);
                if (!match) return max;
                const value = Number.parseInt(match[1], 10);
                if (!Number.isInteger(value)) return max;
                return Math.max(max, value);
            }, 0);
            return `${prefix}-${String(maxSeq + 1).padStart(3, '0')}`;
        }

        function setFormCategoryState(category, options = {}) {
            const clearIrrelevant = !!options.clearIrrelevant;
            const currentCategory = category || 'equipment';
            const isVehicle = currentCategory === 'vehicles';
            const isPersonnel = currentCategory === 'personnel';

            resourceNameLabel.textContent = isVehicle
                ? 'Vehicle Name'
                : (isPersonnel ? 'Person Name' : 'Resource Name');
            resourceNameInput.placeholder = isVehicle
                ? 'e.g. Ambulance Unit 5'
                : (isPersonnel ? 'e.g. Maria Santos' : 'e.g. Portable Defibrillator');

            driverNameGroup.hidden = !isVehicle;
            plateNumberGroup.hidden = !isVehicle;
            positionTitleGroup.hidden = !isPersonnel;
            assignmentGroup.hidden = isPersonnel;

            assignmentLabel.textContent = isVehicle ? 'Assignment / Details' : 'Assignment / Details';
            assignmentInput.placeholder = isVehicle ? 'e.g. On standby' : 'e.g. Stock monitoring';

            if (clearIrrelevant) {
                if (!isVehicle) {
                    driverNameInput.value = '';
                    plateNumberInput.value = '';
                }
                if (!isPersonnel) {
                    positionTitleInput.value = '';
                }
                if (isPersonnel) {
                    assignmentInput.value = '';
                }
            }
        }

        function applyPreset(category) {
            if (!categoryInput || !statusInput) return;
            categoryInput.value = category;
            setFormCategoryState(category, { clearIrrelevant: false });
            if (!resourceCodeInput.value.trim()) {
                resourceCodeInput.value = nextCodeForCategory(category);
            }
            if (category === 'vehicles') {
                if (!resourceNameInput.value.trim()) resourceNameInput.value = 'New Vehicle Unit';
                if (!locationInput.value.trim()) locationInput.value = 'Central Garage';
                if (!driverNameInput.value.trim()) driverNameInput.value = 'Assigned Driver';
                if (!plateNumberInput.value.trim()) plateNumberInput.value = '';
                if (!assignmentInput.value.trim()) assignmentInput.value = 'Ready for dispatch';
                if (!notesInput.value.trim()) notesInput.value = 'Vehicle checklist completed.';
                statusInput.value = 'available';
            } else if (category === 'personnel') {
                if (!resourceNameInput.value.trim()) resourceNameInput.value = 'Responder Name';
                if (!locationInput.value.trim()) locationInput.value = 'Command Center';
                if (!positionTitleInput.value.trim()) positionTitleInput.value = 'Responder';
                if (!notesInput.value.trim()) notesInput.value = 'On shift and ready for deployment.';
                statusInput.value = 'available';
            } else {
                if (!resourceNameInput.value.trim()) resourceNameInput.value = 'Equipment Asset';
                if (!locationInput.value.trim()) locationInput.value = 'Equipment Room';
                if (!assignmentInput.value.trim()) assignmentInput.value = 'Stock monitoring';
                if (!notesInput.value.trim()) notesInput.value = 'Inspected and ready for use.';
                statusInput.value = 'available';
            }
        }

        function updateModalMeta(mode) {
            if (mode === 'add') {
                modalTitle.textContent = 'Add Resource';
                saveResourceBtn.textContent = 'Save Resource';
                modalHelperText.textContent = 'Fill out the details below to register a new resource entry.';
                return;
            }
            modalTitle.textContent = 'Edit Resource';
            saveResourceBtn.textContent = 'Update Resource';
            modalHelperText.textContent = 'Update the selected resource details then click Update Resource.';
        }

        function getFilteredResources() {
            const query = searchInput.value.trim().toLowerCase();
            const category = categoryFilter.value;
            const status = statusFilter.value;

            return resources.filter((item) => {
                if (category && item.category !== category) return false;
                if (status && item.status !== status) return false;
                if (!query) return true;

                const haystack = [
                    item.code,
                    item.name,
                    item.category,
                    item.status,
                    item.location,
                    item.assignment,
                    item.notes
                ].join(' ').toLowerCase();

                return haystack.includes(query);
            });
        }

        function renderOverview() {
            const total = resources.length;
            const vehicles = resources.filter((item) => item.category === 'vehicles').length;
            const personnel = resources.filter((item) => item.category === 'personnel').length;
            const equipment = resources.filter((item) => item.category === 'equipment').length;
            const available = resources.filter((item) => item.status === 'available').length;

            ovTotal.textContent = total;
            ovVehicles.textContent = vehicles;
            ovPersonnel.textContent = personnel;
            ovEquipment.textContent = equipment;
            ovAvailable.textContent = available;
        }

        function renderTable() {
            const items = getFilteredResources();

            if (items.length === 0) {
                tableBody.innerHTML = '<tr class="empty-row"><td colspan="7">No resources found for the current filters.</td></tr>';
                return;
            }

            tableBody.innerHTML = items.map((item) => {
                const detailLine = formatResourceMeta(item);
                return `
                    <tr>
                        <td>${escapeHtml(item.code)}</td>
                        <td class="name-cell">
                            <strong>${escapeHtml(item.name)}</strong>
                            <span>${escapeHtml(detailLine || item.notes || 'No details')}</span>
                        </td>
                        <td>${escapeHtml(formatCategory(item.category))}</td>
                        <td>
                            <span class="status-chip status-${escapeHtml(item.status)}">${escapeHtml(formatStatus(item.status))}</span>
                        </td>
                        <td>${escapeHtml(item.location)} <br><span style="color:#64748b;font-size:0.8rem;">${escapeHtml(formatAssignmentDisplay(item))}</span></td>
                        <td>${escapeHtml(formatDate(item.updatedAt))}</td>
                        <td class="actions-cell">
                            <button type="button" class="action-btn" title="Edit" data-action="edit" data-id="${item.id}">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="action-btn delete" title="Delete" data-action="delete" data-id="${item.id}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderArchiveTable() {
            archiveCount.textContent = String(archivedResources.length);

            if (archivedResources.length === 0) {
                archiveTableBody.innerHTML = '<tr class="empty-row"><td colspan="6">No archived resources.</td></tr>';
                return;
            }

            archiveTableBody.innerHTML = archivedResources.map((item) => {
                const detailLine = formatResourceMeta(item);
                return `
                    <tr>
                        <td class="name-cell">
                            <strong>${escapeHtml(item.code)} - ${escapeHtml(item.name)}</strong>
                            <span>${escapeHtml(detailLine || item.notes || 'No details')}</span>
                        </td>
                        <td>${escapeHtml(formatCategory(item.category))}</td>
                        <td>${escapeHtml(item.location || 'N/A')}<br><span style="color:#64748b;font-size:0.8rem;">${escapeHtml(formatAssignmentDisplay(item))}</span></td>
                        <td>${escapeHtml(formatDate(item.deletedAt))}</td>
                        <td>
                            ${escapeHtml(formatDate(item.purgeAt))}<br>
                            <span class="countdown-chip">${escapeHtml(formatDaysLeft(item.purgeAt))}</span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="action-btn restore" data-action="restore" data-archive-id="${item.id}">
                                <i class="fas fa-rotate-left"></i>
                                <span>Restore</span>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function updateArchiveBadge() {
            const total = archivedResources.length;
            archiveBadge.textContent = total > 99 ? '99+' : String(total);
            archiveBadge.classList.toggle('show', total > 0);
            archiveResourceBtn.setAttribute('aria-label', total > 0
                ? `Archive, ${total} deleted resources waiting in archive`
                : 'Archive');
        }

        function showToast(message) {
            toastEl.textContent = message;
            toastEl.classList.add('show');
            window.clearTimeout(showToast.timerId);
            showToast.timerId = window.setTimeout(() => {
                toastEl.classList.remove('show');
            }, 2200);
        }

        function clearForm() {
            resourceForm.reset();
            resourceIdHidden.value = '';
            selectedId = null;
            categoryInput.value = 'vehicles';
            statusInput.value = 'available';
            setFormCategoryState('vehicles', { clearIrrelevant: true });
        }

        function rememberTrigger(fallback) {
            if (document.activeElement instanceof HTMLElement) {
                lastFocusedTrigger = document.activeElement;
                return;
            }
            lastFocusedTrigger = fallback || null;
        }

        function openModal(mode, id) {
            rememberTrigger(addResourceBtn);
            if (mode === 'add') {
                clearForm();
                updateModalMeta('add');
                applyPreset('vehicles');
                setSaveLoading(false);
                resourceCodeInput.focus();
            } else {
                const target = resources.find((item) => item.id === id);
                if (!target) return;

                selectedId = target.id;
                resourceIdHidden.value = String(target.id);
                updateModalMeta('edit');

                resourceCodeInput.value = target.code;
                resourceNameInput.value = target.name;
                categoryInput.value = target.category;
                statusInput.value = target.status;
                locationInput.value = target.location;
                driverNameInput.value = target.driverName || '';
                plateNumberInput.value = target.plateNumber || '';
                positionTitleInput.value = target.positionTitle || '';
                assignmentInput.value = target.assignment;
                notesInput.value = target.notes;
                setFormCategoryState(target.category, { clearIrrelevant: false });
                setSaveLoading(false);
            }

            resourceModal.classList.add('show');
            resourceModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            resourceModal.classList.remove('show');
            resourceModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (lastFocusedTrigger && typeof lastFocusedTrigger.focus === 'function') {
                lastFocusedTrigger.focus();
            } else {
                addResourceBtn.focus();
            }
        }

        function openAddResourceModal() {
            openModal('add');
        }

        window.openAdminResourceModal = openAddResourceModal;

        async function openArchiveModal() {
            rememberTrigger(archiveResourceBtn);
            await loadArchivedResources();
            archiveModal.classList.add('show');
            archiveModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            closeArchiveBtn.focus();
        }

        function closeArchiveModal() {
            archiveModal.classList.remove('show');
            archiveModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (lastFocusedTrigger && typeof lastFocusedTrigger.focus === 'function') {
                lastFocusedTrigger.focus();
            } else {
                archiveResourceBtn.focus();
            }
        }

        function handleEdit(id) {
            openModal('edit', id);
        }

        async function handleDelete(id) {
            const index = resources.findIndex((item) => item.id === id);
            if (index === -1) return;

            const item = resources[index];
            const allow = window.confirm(`Archive resource ${item.code} - ${item.name}? It will stay in archive for 60 days before permanent deletion.`);
            if (!allow) return;

            try {
                await deleteResource(id);
                await loadResources();
                if (archiveModal.classList.contains('show')) {
                    await loadArchivedResources();
                }
                showToast('Resource moved to archive.');
            } catch (err) {
                showToast((err && err.message) ? String(err.message) : 'Failed to archive resource.');
            }
        }

        async function handleRestoreArchive(archiveId) {
            const item = archivedResources.find((entry) => entry.id === archiveId);
            if (!item) return;

            const allow = window.confirm(`Restore resource ${item.code} - ${item.name} back to the active resources table?`);
            if (!allow) return;

            try {
                await restoreArchivedResource(archiveId);
                await loadResources();
                await loadArchivedResources();
                showToast('Resource restored to active table.');
            } catch (err) {
                showToast((err && err.message) ? String(err.message) : 'Failed to restore resource.');
            }
        }

        resourceForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const payload = {
                code: resourceCodeInput.value.trim().toUpperCase(),
                name: resourceNameInput.value.trim(),
                category: categoryInput.value,
                status: statusInput.value,
                location: locationInput.value.trim(),
                driverName: driverNameInput.value.trim(),
                plateNumber: plateNumberInput.value.trim().toUpperCase(),
                positionTitle: positionTitleInput.value.trim(),
                assignment: assignmentInput.value.trim(),
                notes: notesInput.value.trim()
            };

            if (!payload.code || !payload.name || !payload.location) {
                showToast('Please complete required fields.');
                return;
            }

            const codeExists = resources.some((item) => item.code.toLowerCase() === payload.code.toLowerCase() && item.id !== selectedId);
            if (codeExists) {
                showToast('Resource ID already exists.');
                return;
            }

            try {
                setSaveLoading(true);
                if (selectedId === null) {
                    await createResource(payload);
                    showToast('Resource added.');
                } else {
                    await updateResource(selectedId, payload);
                    showToast('Resource updated.');
                }
                await loadResources();
                clearForm();
                closeModal();
            } catch (err) {
                showToast((err && err.message) ? String(err.message) : 'Failed to save resource.');
            } finally {
                setSaveLoading(false);
            }
        });

        tableBody.addEventListener('click', async (event) => {
            const btn = event.target.closest('[data-action][data-id]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const id = Number.parseInt(btn.getAttribute('data-id') || '', 10);
            if (!Number.isInteger(id) || id <= 0) return;
            if (action === 'edit') {
                handleEdit(id);
            } else if (action === 'delete') {
                await handleDelete(id);
            }
        });

        archiveTableBody.addEventListener('click', async (event) => {
            const btn = event.target.closest('[data-action][data-archive-id]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const archiveId = Number.parseInt(btn.getAttribute('data-archive-id') || '', 10);
            if (action !== 'restore' || !Number.isInteger(archiveId) || archiveId <= 0) return;
            await handleRestoreArchive(archiveId);
        });

        addResourceBtn.addEventListener('click', openAddResourceModal);
        archiveResourceBtn.addEventListener('click', async () => {
            try {
                await openArchiveModal();
            } catch (err) {
                showToast((err && err.message) ? String(err.message) : 'Unable to load archive.');
            }
        });
        document.querySelectorAll('[data-open-resource-modal]').forEach((trigger) => {
            if (trigger === addResourceBtn) return;
            trigger.addEventListener('click', openAddResourceModal);
        });
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);
        closeArchiveBtn.addEventListener('click', closeArchiveModal);
        archiveDoneBtn.addEventListener('click', closeArchiveModal);

        presetButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const preset = btn.getAttribute('data-preset') || 'equipment';
                applyPreset(preset);
            });
        });

        if (generateCodeBtn) {
            generateCodeBtn.addEventListener('click', () => {
                resourceCodeInput.value = nextCodeForCategory(categoryInput.value || 'equipment');
                resourceCodeInput.focus();
            });
        }

        categoryInput.addEventListener('change', () => {
            resourceCodeInput.value = nextCodeForCategory(categoryInput.value || 'equipment');
            setFormCategoryState(categoryInput.value || 'equipment', { clearIrrelevant: true });
        });

        resourceModal.addEventListener('click', (event) => {
            if (event.target === resourceModal) closeModal();
        });

        archiveModal.addEventListener('click', (event) => {
            if (event.target === archiveModal) closeArchiveModal();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && archiveModal.classList.contains('show')) {
                closeArchiveModal();
                return;
            }
            if (event.key === 'Escape' && resourceModal.classList.contains('show')) {
                closeModal();
            }
        });

        searchInput.addEventListener('input', renderTable);
        categoryFilter.addEventListener('change', renderTable);
        statusFilter.addEventListener('change', renderTable);

        resetFiltersBtn.addEventListener('click', () => {
            searchInput.value = '';
            categoryFilter.value = '';
            statusFilter.value = '';
            renderTable();
            showToast('Filters reset.');
        });

        (async () => {
            try {
                await loadResources();
                await loadArchivedResources();
            } catch (err) {
                tableBody.innerHTML = '<tr class="empty-row"><td colspan="7">Unable to load resources from database.</td></tr>';
                showToast((err && err.message) ? String(err.message) : 'Unable to load resources.');
            }
        })();
    </script>
</body>
</html>
