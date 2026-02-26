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
            padding: 2rem;
        }

        .resource-shell {
            padding: 1.25rem 0 2rem;
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
            z-index: 4000;
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
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .form-group.full {
            grid-column: 1 / -1;
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
            .resource-head {
                flex-direction: column;
                align-items: flex-start;
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
                <button type="button" class="btn-primary" id="addResourceBtn">
                    <i class="fas fa-plus"></i> Add Resource
                </button>
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

    <div class="modal" id="resourceModal" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-head">
                <h2 id="modalTitle">Add Resource</h2>
                <button type="button" class="action-btn" id="closeModalBtn" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="resourceForm">
                <div class="modal-body">
                    <input type="hidden" id="resourceIdHidden">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="resourceCodeInput">Resource ID</label>
                            <input id="resourceCodeInput" class="form-input" required maxlength="20" placeholder="e.g. VEH-010">
                        </div>
                        <div class="form-group">
                            <label for="resourceNameInput">Resource Name</label>
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
                        <div class="form-group">
                            <label for="assignmentInput">Assignment / Details</label>
                            <input id="assignmentInput" class="form-input" maxlength="90" placeholder="e.g. On standby">
                        </div>
                        <div class="form-group full">
                            <label for="notesInput">Notes</label>
                            <textarea id="notesInput" class="form-textarea" maxlength="250" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn-outline" id="cancelModalBtn">Cancel</button>
                    <button type="submit" class="btn-primary" id="saveResourceBtn">Save Resource</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const resources = [
            { id: 1, code: 'VEH-001', name: 'Ambulance Unit 1', category: 'vehicles', status: 'available', location: 'Station 1', assignment: 'Rapid Response', notes: 'Fully equipped ALS unit', updatedAt: '2026-02-26 14:10' },
            { id: 2, code: 'VEH-002', name: 'Fire Truck Bravo', category: 'vehicles', status: 'in_use', location: 'North Zone', assignment: 'Incident INC-0342', notes: 'Responding to structural fire', updatedAt: '2026-02-26 13:52' },
            { id: 3, code: 'PER-007', name: 'Paramedic Lea Santos', category: 'personnel', status: 'available', location: 'Station 2', assignment: 'Medical Team A', notes: 'Certified trauma lead', updatedAt: '2026-02-26 12:05' },
            { id: 4, code: 'PER-011', name: 'Dispatcher Ramon Cruz', category: 'personnel', status: 'in_use', location: 'Control Room', assignment: 'Dispatch Desk 2', notes: 'Handling active incident queue', updatedAt: '2026-02-26 13:47' },
            { id: 5, code: 'EQP-020', name: 'Portable Defibrillator', category: 'equipment', status: 'maintenance', location: 'Biomedical Lab', assignment: 'Calibration check', notes: 'Battery replacement ongoing', updatedAt: '2026-02-25 18:20' },
            { id: 6, code: 'EQP-031', name: 'Rescue Cutter Set', category: 'equipment', status: 'available', location: 'Station 3', assignment: 'Technical Rescue Cache', notes: 'Ready for deployment', updatedAt: '2026-02-26 11:40' },
            { id: 7, code: 'VEH-010', name: 'Rescue Van Echo', category: 'vehicles', status: 'offline', location: 'Garage Bay 4', assignment: 'Awaiting clearance', notes: 'Pending inspection release', updatedAt: '2026-02-24 09:15' }
        ];

        let selectedId = null;
        let nextId = resources.reduce((max, item) => Math.max(max, item.id), 0) + 1;

        const tableBody = document.getElementById('resourceTableBody');
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const statusFilter = document.getElementById('statusFilter');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const resourceModal = document.getElementById('resourceModal');
        const modalTitle = document.getElementById('modalTitle');
        const resourceForm = document.getElementById('resourceForm');
        const resourceIdHidden = document.getElementById('resourceIdHidden');
        const resourceCodeInput = document.getElementById('resourceCodeInput');
        const resourceNameInput = document.getElementById('resourceNameInput');
        const categoryInput = document.getElementById('categoryInput');
        const statusInput = document.getElementById('statusInput');
        const locationInput = document.getElementById('locationInput');
        const assignmentInput = document.getElementById('assignmentInput');
        const notesInput = document.getElementById('notesInput');
        const addResourceBtn = document.getElementById('addResourceBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const toastEl = document.getElementById('toast');

        const ovTotal = document.getElementById('ovTotal');
        const ovVehicles = document.getElementById('ovVehicles');
        const ovPersonnel = document.getElementById('ovPersonnel');
        const ovEquipment = document.getElementById('ovEquipment');
        const ovAvailable = document.getElementById('ovAvailable');

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
            const date = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleString();
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function nowStamp() {
            const dt = new Date();
            const yyyy = dt.getFullYear();
            const mm = String(dt.getMonth() + 1).padStart(2, '0');
            const dd = String(dt.getDate()).padStart(2, '0');
            const hh = String(dt.getHours()).padStart(2, '0');
            const mi = String(dt.getMinutes()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
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
                return `
                    <tr>
                        <td>${escapeHtml(item.code)}</td>
                        <td class="name-cell">
                            <strong>${escapeHtml(item.name)}</strong>
                            <span>${escapeHtml(item.notes || 'No notes')}</span>
                        </td>
                        <td>${escapeHtml(formatCategory(item.category))}</td>
                        <td>
                            <span class="status-chip status-${escapeHtml(item.status)}">${escapeHtml(formatStatus(item.status))}</span>
                        </td>
                        <td>${escapeHtml(item.location)} <br><span style="color:#64748b;font-size:0.8rem;">${escapeHtml(item.assignment || 'N/A')}</span></td>
                        <td>${escapeHtml(formatDate(item.updatedAt))}</td>
                        <td class="actions-cell">
                            <button type="button" class="action-btn" title="Edit" onclick="handleEdit(${item.id})">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="action-btn delete" title="Delete" onclick="handleDelete(${item.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
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
        }

        function openModal(mode, id) {
            if (mode === 'add') {
                clearForm();
                modalTitle.textContent = 'Add Resource';
                resourceCodeInput.focus();
            } else {
                const target = resources.find((item) => item.id === id);
                if (!target) return;

                selectedId = target.id;
                resourceIdHidden.value = String(target.id);
                modalTitle.textContent = 'Edit Resource';

                resourceCodeInput.value = target.code;
                resourceNameInput.value = target.name;
                categoryInput.value = target.category;
                statusInput.value = target.status;
                locationInput.value = target.location;
                assignmentInput.value = target.assignment;
                notesInput.value = target.notes;
            }

            resourceModal.classList.add('show');
            resourceModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            resourceModal.classList.remove('show');
            resourceModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        window.handleEdit = function handleEdit(id) {
            openModal('edit', id);
        };

        window.handleDelete = function handleDelete(id) {
            const index = resources.findIndex((item) => item.id === id);
            if (index === -1) return;

            const item = resources[index];
            const allow = window.confirm(`Delete resource ${item.code} - ${item.name}?`);
            if (!allow) return;

            resources.splice(index, 1);
            renderOverview();
            renderTable();
            showToast('Resource deleted.');
        };

        resourceForm.addEventListener('submit', (event) => {
            event.preventDefault();

            const payload = {
                code: resourceCodeInput.value.trim(),
                name: resourceNameInput.value.trim(),
                category: categoryInput.value,
                status: statusInput.value,
                location: locationInput.value.trim(),
                assignment: assignmentInput.value.trim(),
                notes: notesInput.value.trim(),
                updatedAt: nowStamp()
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

            if (selectedId === null) {
                payload.id = nextId++;
                resources.unshift(payload);
                showToast('Resource added.');
            } else {
                const index = resources.findIndex((item) => item.id === selectedId);
                if (index === -1) return;
                resources[index] = { ...resources[index], ...payload };
                showToast('Resource updated.');
            }

            renderOverview();
            renderTable();
            closeModal();
        });

        addResourceBtn.addEventListener('click', () => openModal('add'));
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);

        resourceModal.addEventListener('click', (event) => {
            if (event.target === resourceModal) closeModal();
        });

        document.addEventListener('keydown', (event) => {
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
        });

        renderOverview();
        renderTable();
    </script>
</body>
</html>
