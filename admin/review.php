<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/review.php');

$pageTitle = 'Review & Feedback';
$adminName = $_SESSION['user_name'] ?? 'Admin';
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
            --ar-bg: #f3f6fa;
            --ar-card: #ffffff;
            --ar-border: #d8e1ea;
            --ar-text: #15283c;
            --ar-muted: #5f7287;
            --ar-primary: #0f766e;
            --ar-primary-dark: #115e59;
            --ar-pending: #b45309;
            --ar-reviewed: #0369a1;
            --ar-resolved: #15803d;
            --ar-danger: #b91c1c;
            --ar-dark-card: #1f2329;
        }

        .main-content {
            background:
                radial-gradient(circle at 86% 0%, rgba(56, 189, 248, 0.12), transparent 34%),
                var(--ar-bg);
            padding: 3rem 1.5rem;
        }

        .ar-shell {
            margin-top: 0.8rem;
        }

        .ar-header {
            margin-bottom: 1rem;
        }

        .ar-title {
            margin: 0;
            font-size: 1.65rem;
            color: var(--ar-text);
            line-height: 1.2;
        }

        .ar-subtitle {
            margin: 0.35rem 0 0;
            color: var(--ar-muted);
            font-size: 0.94rem;
        }

        .ar-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.9rem;
            align-items: start;
        }

        .ar-filter-card {
            background: var(--ar-dark-card);
            color: #fff;
            border-radius: 14px;
            padding: 1rem;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.24);
            position: sticky;
            top: 90px;
        }

        .ar-filter-title {
            margin: 0 0 0.8rem;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .ar-filter-list {
            list-style: disc;
            margin: 0;
            padding-left: 1.2rem;
        }

        .ar-filter-item {
            margin-bottom: 0.58rem;
        }

        .ar-filter-item label {
            color: #f8fafc;
            font-size: 1.05rem;
            font-weight: 500;
            cursor: pointer;
        }

        .ar-filter-item label.resolved {
            color: #facc15;
        }

        .ar-filter-item input[type="radio"] {
            margin-right: 0.45rem;
            transform: translateY(1px);
        }

        .ar-filter-item input[type="text"],
        .ar-filter-item input[type="date"] {
            margin-top: 0.4rem;
            width: 100%;
            border: 1px solid #39414b;
            border-radius: 8px;
            background: #13171c;
            color: #fff;
            padding: 0.52rem 0.6rem;
            font-size: 0.86rem;
        }

        .ar-filter-item input[type="text"]::placeholder {
            color: #9fb0c2;
        }

        .ar-filter-actions {
            margin-top: 0.95rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .ar-btn {
            border: none;
            border-radius: 8px;
            padding: 0.52rem 0.65rem;
            font-size: 0.79rem;
            font-weight: 700;
            cursor: pointer;
        }

        .ar-btn-apply {
            background: #14b8a6;
            color: #052e2b;
        }

        .ar-btn-apply:hover {
            background: #2dd4bf;
        }

        .ar-btn-reset {
            background: #334155;
            color: #e2e8f0;
        }

        .ar-btn-reset:hover {
            background: #475569;
        }

        .ar-table-card {
            background: var(--ar-card);
            border: 1px solid var(--ar-border);
            border-radius: 14px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .ar-toolbar {
            display: grid;
            grid-template-columns: 1.45fr 1fr 1fr auto;
            gap: 0.65rem;
            padding: 0.8rem;
            background: #eef2f6;
            border-bottom: 1px solid var(--ar-border);
        }

        .ar-toolbar-input,
        .ar-toolbar-select {
            border: 1px solid #b8c3cf;
            border-radius: 9px;
            background: #fff;
            color: var(--ar-text);
            padding: 0.62rem 0.72rem;
            font-size: 0.9rem;
            width: 100%;
        }

        .ar-toolbar-input::placeholder {
            color: #677b91;
        }

        .ar-toolbar-input:focus,
        .ar-toolbar-select:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.14);
        }

        .ar-toolbar-reset {
            border: 1px solid #b8c3cf;
            border-radius: 9px;
            background: #fff;
            color: #0f172a;
            padding: 0.62rem 0.95rem;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .ar-toolbar-reset:hover {
            background: #f8fafc;
        }

        .ar-table-head {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--ar-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
            background: #f7fbff;
        }

        .ar-table-head h2 {
            margin: 0;
            color: var(--ar-text);
            font-size: 1rem;
        }

        .ar-count {
            color: #334155;
            font-size: 0.84rem;
            font-weight: 700;
            background: #e2e8f0;
            border-radius: 999px;
            padding: 0.3rem 0.65rem;
        }

        .ar-table-scroll {
            max-height: 560px;
            overflow: auto;
            border-top: 1px solid #edf2f7;
        }

        .ar-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1080px;
        }

        .ar-table th,
        .ar-table td {
            padding: 0.72rem 0.68rem;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: middle;
            font-size: 0.84rem;
        }

        .ar-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.74rem;
        }

        .ar-table tr:hover td {
            background: #f9fcff;
        }

        .ar-incident-code {
            font-weight: 700;
            color: #0f172a;
        }

        .ar-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
            text-transform: uppercase;
        }

        .ar-chip.pending {
            color: #92400e;
            border-color: #fcd34d;
            background: #fffbeb;
        }

        .ar-chip.reviewed {
            color: #075985;
            border-color: #7dd3fc;
            background: #f0f9ff;
        }

        .ar-chip.resolved {
            color: #166534;
            border-color: #86efac;
            background: #f0fdf4;
        }

        .ar-actions {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .ar-action-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #d7dee8;
            background: #fff;
            color: #1e293b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .ar-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.13);
        }

        .ar-action-btn.reviewed:hover {
            color: var(--ar-reviewed);
            border-color: #7dd3fc;
            background: #f0f9ff;
        }

        .ar-action-btn.resolved:hover {
            color: var(--ar-resolved);
            border-color: #86efac;
            background: #f0fdf4;
        }

        .ar-action-btn.remarks:hover {
            color: var(--ar-primary);
            border-color: #99f6e4;
            background: #f0fdfa;
        }

        .ar-action-btn.escalate:hover {
            color: var(--ar-pending);
            border-color: #fcd34d;
            background: #fffbeb;
        }

        .ar-action-btn.flag:hover {
            color: var(--ar-danger);
            border-color: #fca5a5;
            background: #fff1f2;
        }

        .ar-empty {
            text-align: center;
            color: var(--ar-muted);
            padding: 1.6rem;
            font-size: 0.9rem;
        }

        .ar-toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 9999;
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 0.7rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.25);
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px);
            transition: 0.22s ease;
        }

        .ar-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 1080px) {
            .ar-toolbar {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: 1rem 0.75rem;
            }

            .ar-title {
                font-size: 1.35rem;
            }

            .ar-subtitle {
                font-size: 0.86rem;
            }

            .ar-toolbar {
                grid-template-columns: 1fr;
            }

            .ar-table th,
            .ar-table td {
                font-size: 0.78rem;
                padding: 0.6rem 0.5rem;
            }

            .ar-action-btn {
                width: 28px;
                height: 28px;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container ar-shell">
            <section class="ar-header">
                <h1 class="ar-title">Review &amp; Feedback Console</h1>
                <p class="ar-subtitle">Hi <?php echo htmlspecialchars($adminName); ?>. Manage incident review flow and apply action controls per case.</p>
            </section>

            <section class="ar-layout">
                <div class="ar-table-card">
                    <div class="ar-toolbar">
                        <input
                            type="text"
                            id="searchFilterInput"
                            class="ar-toolbar-input"
                            placeholder="Search incident code, type, or location...">
                        <select id="categoryFilterSelect" class="ar-toolbar-select">
                            <option value="">All Categories</option>
                            <option value="fire">Fire</option>
                            <option value="medical">Medical</option>
                            <option value="traffic">Traffic</option>
                            <option value="police">Police</option>
                            <option value="rescue">Rescue</option>
                        </select>
                        <select id="statusFilterSelect" class="ar-toolbar-select">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="reviewed">Reviewed</option>
                            <option value="resolved">Resolved</option>
                        </select>
                        <button type="button" class="ar-toolbar-reset" id="resetFilterBtn">Reset</button>
                    </div>
                    <div class="ar-table-head">
                        <h2>Incident Review Table</h2>
                        <span class="ar-count" id="incidentCountBadge">0 incident(s)</span>
                    </div>
                    <div class="ar-table-scroll">
                        <table class="ar-table">
                            <thead>
                                <tr>
                                    <th>Incident</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Department</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="incidentTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <div class="ar-toast" id="reviewToast"></div>

    <script>
        const incidentRows = [
            { id: 1, code: 'INC-2026-0042', type: 'Fire', location: 'Barangay South Poblacion', department: 'Fire Dept', priority: 'High', status: 'pending', date: '2026-02-28' },
            { id: 2, code: 'INC-2026-0041', type: 'Medical', location: 'Rizal Street, Zone 2', department: 'EMS', priority: 'Medium', status: 'reviewed', date: '2026-02-28' },
            { id: 3, code: 'INC-2026-0040', type: 'Traffic', location: 'Maharlika Highway', department: 'Police', priority: 'Low', status: 'resolved', date: '2026-02-27' },
            { id: 4, code: 'INC-2026-0039', type: 'Police', location: 'Market Area', department: 'Police', priority: 'High', status: 'pending', date: '2026-02-27' },
            { id: 5, code: 'INC-2026-0038', type: 'Medical', location: 'District Hospital', department: 'EMS', priority: 'High', status: 'reviewed', date: '2026-02-26' },
            { id: 6, code: 'INC-2026-0037', type: 'Fire', location: 'Sitio Maligaya', department: 'Fire Dept', priority: 'Medium', status: 'pending', date: '2026-02-26' },
            { id: 7, code: 'INC-2026-0036', type: 'Rescue', location: 'Riverbank Crossing', department: 'Rescue Team', priority: 'High', status: 'resolved', date: '2026-02-25' },
            { id: 8, code: 'INC-2026-0035', type: 'Medical', location: 'National Road Km. 12', department: 'EMS', priority: 'Low', status: 'pending', date: '2026-02-25' },
            { id: 9, code: 'INC-2026-0034', type: 'Police', location: 'Town Plaza', department: 'Police', priority: 'Medium', status: 'reviewed', date: '2026-02-24' },
            { id: 10, code: 'INC-2026-0033', type: 'Fire', location: 'Industrial Park Block B', department: 'Fire Dept', priority: 'High', status: 'pending', date: '2026-02-24' },
            { id: 11, code: 'INC-2026-0032', type: 'Medical', location: 'School Gym Evacuation Area', department: 'EMS', priority: 'Medium', status: 'resolved', date: '2026-02-23' },
            { id: 12, code: 'INC-2026-0031', type: 'Traffic', location: 'Bypass Road Junction', department: 'Traffic Unit', priority: 'Low', status: 'pending', date: '2026-02-22' }
        ];

        const tableBody = document.getElementById('incidentTableBody');
        const countBadge = document.getElementById('incidentCountBadge');
        const searchFilterInput = document.getElementById('searchFilterInput');
        const categoryFilterSelect = document.getElementById('categoryFilterSelect');
        const statusFilterSelect = document.getElementById('statusFilterSelect');
        const resetFilterBtn = document.getElementById('resetFilterBtn');
        const toastEl = document.getElementById('reviewToast');

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function statusChip(status) {
            const safe = (status || '').toLowerCase();
            const label = safe.charAt(0).toUpperCase() + safe.slice(1);
            return '<span class="ar-chip ' + safe + '">' + escapeHtml(label) + '</span>';
        }

        function showToast(message) {
            toastEl.textContent = message;
            toastEl.classList.add('show');
            window.clearTimeout(showToast._t);
            showToast._t = window.setTimeout(() => {
                toastEl.classList.remove('show');
            }, 1900);
        }

        function getFilteredRows() {
            const searchNeedle = searchFilterInput.value.trim().toLowerCase();
            const categoryNeedle = categoryFilterSelect.value.trim().toLowerCase();
            const statusNeedle = statusFilterSelect.value.trim().toLowerCase();

            return incidentRows.filter((row) => {
                if (categoryNeedle && row.type.toLowerCase() !== categoryNeedle) return false;
                if (statusNeedle && row.status.toLowerCase() !== statusNeedle) return false;
                if (searchNeedle) {
                    const hay = (row.code + ' ' + row.type + ' ' + row.location + ' ' + row.department).toLowerCase();
                    if (!hay.includes(searchNeedle)) return false;
                }
                return true;
            });
        }

        function renderTable() {
            const rows = getFilteredRows();
            countBadge.textContent = rows.length + ' incident(s)';

            if (!rows.length) {
                tableBody.innerHTML = '<tr><td colspan="8" class="ar-empty">No incidents match the current filter.</td></tr>';
                return;
            }

            tableBody.innerHTML = rows.map((row) => {
                return `
                    <tr>
                        <td class="ar-incident-code">${escapeHtml(row.code)}</td>
                        <td>${escapeHtml(row.type)}</td>
                        <td>${escapeHtml(row.location)}</td>
                        <td>${escapeHtml(row.department)}</td>
                        <td>${escapeHtml(row.priority)}</td>
                        <td>${statusChip(row.status)}</td>
                        <td>${escapeHtml(row.date)}</td>
                        <td>
                            <div class="ar-actions">
                                <button type="button" class="ar-action-btn reviewed" data-action="mark-reviewed" data-id="${row.id}" title="Mark as reviewed" aria-label="Mark as reviewed">
                                    <i class="fas fa-check-circle"></i>
                                </button>
                                <button type="button" class="ar-action-btn resolved" data-action="mark-resolved" data-id="${row.id}" title="Mark as resolved" aria-label="Mark as resolved">
                                    <i class="fas fa-circle-check"></i>
                                </button>
                                <button type="button" class="ar-action-btn remarks" data-action="add-remarks" data-id="${row.id}" title="Add admin remarks" aria-label="Add admin remarks">
                                    <i class="fas fa-comment-dots"></i>
                                </button>
                                <button type="button" class="ar-action-btn escalate" data-action="escalate-maintenance" data-id="${row.id}" title="Escalate to maintenance" aria-label="Escalate to maintenance">
                                    <i class="fas fa-screwdriver-wrench"></i>
                                </button>
                                <button type="button" class="ar-action-btn flag" data-action="flag-serious-issue" data-id="${row.id}" title="Flag serious issue" aria-label="Flag serious issue">
                                    <i class="fas fa-flag"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function findRowById(idValue) {
            const id = Number(idValue);
            return incidentRows.find((row) => row.id === id) || null;
        }

        function handleActionClick(event) {
            const button = event.target.closest('.ar-action-btn');
            if (!button) return;

            const action = button.getAttribute('data-action');
            const row = findRowById(button.getAttribute('data-id'));
            if (!row) return;

            if (action === 'mark-reviewed') {
                row.status = 'reviewed';
                showToast(row.code + ' marked as reviewed.');
            } else if (action === 'mark-resolved') {
                row.status = 'resolved';
                showToast(row.code + ' marked as resolved.');
            } else if (action === 'add-remarks') {
                const remarks = window.prompt('Enter admin remarks for ' + row.code + ':');
                if (remarks && remarks.trim()) {
                    showToast('Remarks added to ' + row.code + '.');
                }
            } else if (action === 'escalate-maintenance') {
                showToast(row.code + ' escalated to maintenance.');
            } else if (action === 'flag-serious-issue') {
                showToast('Serious issue flagged for ' + row.code + '.');
            }

            renderTable();
        }

        resetFilterBtn.addEventListener('click', () => {
            searchFilterInput.value = '';
            categoryFilterSelect.value = '';
            statusFilterSelect.value = '';
            renderTable();
        });

        searchFilterInput.addEventListener('input', renderTable);
        categoryFilterSelect.addEventListener('change', renderTable);
        statusFilterSelect.addEventListener('change', renderTable);
        tableBody.addEventListener('click', handleActionClick);

        renderTable();
    </script>
</body>
</html>
