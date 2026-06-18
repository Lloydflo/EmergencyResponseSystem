<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/user_management.php');

$pageTitle = 'User Management';
$adminName = $_SESSION['user_name'] ?? 'Admin';
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
            --um-bg: #f3f7fb;
            --um-card: #ffffff;
            --um-text: #16263a;
            --um-muted: #5f7287;
            --um-border: #dbe4ee;
            --um-primary: #0f766e;
            --um-primary-dark: #115e59;
            --um-danger: #b91c1c;
            --um-soft: #eef3f8;
        }

        .main-content {
            background:
                radial-gradient(circle at 95% 0%, rgba(14, 165, 233, 0.12), transparent 34%),
                var(--um-bg);
            padding: 3rem 1.5rem;
            flex: 1 0 auto;
            min-height: calc(100vh - 180px);
        }

        .um-shell {
            margin-top: 0.8rem;
        }

        .um-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .um-head h1 {
            margin: 0;
            color: var(--um-text);
            font-size: 1.65rem;
            line-height: 1.2;
        }

        .um-head p {
            margin: 0.35rem 0 0;
            color: var(--um-muted);
            font-size: 0.94rem;
        }

        .um-add-btn {
            border: none;
            border-radius: 10px;
            padding: 0.68rem 1rem;
            background: var(--um-primary);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .um-add-btn:hover {
            background: var(--um-primary-dark);
        }

        .um-card {
            background: var(--um-card);
            border: 1px solid var(--um-border);
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .um-toolbar {
            padding: 0.8rem;
            border-bottom: 1px solid var(--um-border);
            background: var(--um-soft);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.65rem;
            align-items: center;
        }

        .um-search {
            border: 1px solid #c3cfdb;
            border-radius: 9px;
            background: #fff;
            color: var(--um-text);
            padding: 0.62rem 0.72rem;
            font-size: 0.9rem;
            width: 100%;
        }

        .um-search:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .um-summary {
            color: #334155;
            font-size: 0.82rem;
            font-weight: 700;
            background: #e2e8f0;
            border-radius: 999px;
            padding: 0.28rem 0.62rem;
        }

        .um-table-wrap {
            max-height: 560px;
            overflow: auto;
            border-top: 1px solid #edf2f7;
        }

        .um-table {
            width: 100%;
            min-width: 1580px;
            border-collapse: collapse;
        }

        .um-table th,
        .um-table td {
            padding: 0.72rem 0.64rem;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: middle;
            font-size: 0.84rem;
        }

        .um-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.74rem;
        }

        .um-table tr:hover td {
            background: #f8fbff;
        }

        .um-code {
            font-weight: 700;
            color: #0f172a;
        }

        .um-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.2rem 0.52rem;
            font-size: 0.71rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .um-chip.dispatcher {
            color: #0c4a6e;
            border-color: #7dd3fc;
            background: #f0f9ff;
        }

        .um-chip.responder {
            color: #134e4a;
            border-color: #99f6e4;
            background: #f0fdfa;
        }

        .um-chip.active {
            color: #166534;
            border-color: #86efac;
            background: #f0fdf4;
        }

        .um-chip.inactive {
            color: #7f1d1d;
            border-color: #fca5a5;
            background: #fff1f2;
        }

        .um-actions {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: nowrap;
        }

        .um-action {
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

        .um-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 7px rgba(15, 23, 42, 0.12);
        }

        .um-action.edit:hover {
            color: #0f766e;
            border-color: #99f6e4;
            background: #f0fdfa;
        }

        .um-action.delete:hover {
            color: var(--um-danger);
            border-color: #fecaca;
            background: #fff1f2;
        }

        .um-action.save:hover {
            color: #166534;
            border-color: #86efac;
            background: #f0fdf4;
        }

        .um-action.save[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .um-input,
        .um-select {
            width: 100%;
            border: 1px solid #c7d2df;
            border-radius: 8px;
            background: #fff;
            color: var(--um-text);
            font-size: 0.82rem;
            padding: 0.42rem 0.5rem;
        }

        .um-password-wrap {
            position: relative;
        }

        .um-password-wrap .um-input {
            padding-right: 2.2rem;
        }

        .um-password-toggle {
            position: absolute;
            top: 50%;
            right: 0.45rem;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            width: 1.6rem;
            height: 1.6rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .um-password-toggle:hover {
            color: #334155;
            background: #f1f5f9;
        }

        .um-password-hint {
            margin-top: 0.6rem;
            padding: 0.7rem 0.8rem;
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            background: #f8fafc;
        }

        .um-password-hint[hidden] {
            display: none;
        }

        .um-password-hint p {
            margin: 0 0 0.45rem;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .um-password-rules {
            margin: 0;
            padding-left: 1rem;
            color: #64748b;
            font-size: 0.76rem;
        }

        .um-password-rules li + li {
            margin-top: 0.24rem;
        }

        .um-password-rules li.valid {
            color: #166534;
        }

        .um-password-rules li.invalid {
            color: #b91c1c;
        }

        .um-field-note {
            color: var(--um-muted);
            font-size: 0.76rem;
            line-height: 1.35;
        }

        .um-empty {
            text-align: center;
            color: var(--um-muted);
            padding: 1.6rem;
            font-size: 0.9rem;
        }

        .um-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 5000;
            padding: 1rem;
        }

        .um-modal.show {
            display: flex;
        }

        .um-modal-card {
            width: min(620px, 100%);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(2, 8, 23, 0.34);
            overflow: hidden;
            border: 1px solid #dbe3ec;
        }

        .um-modal-head {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #e5edf5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            background: #f8fafc;
        }

        .um-modal-head h2 {
            margin: 0;
            color: var(--um-text);
            font-size: 1.03rem;
        }

        .um-close {
            border: 1px solid #d5dee8;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            width: 30px;
            height: 30px;
            cursor: pointer;
        }

        .um-modal-body {
            padding: 1rem;
        }

        .um-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.72rem;
        }

        .um-field {
            display: flex;
            flex-direction: column;
            gap: 0.32rem;
        }

        .um-field[hidden] {
            display: none;
        }

        .um-field.full {
            grid-column: 1 / -1;
        }

        .um-field label {
            color: #334155;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .um-modal-foot {
            padding: 0.85rem 1rem;
            border-top: 1px solid #e5edf5;
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 0.55rem;
        }

        .um-btn {
            border-radius: 9px;
            padding: 0.56rem 0.85rem;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid #c9d5e2;
            background: #fff;
            color: #0f172a;
        }

        .um-btn.primary {
            border-color: var(--um-primary);
            background: var(--um-primary);
            color: #fff;
        }

        .um-btn.primary:hover {
            background: var(--um-primary-dark);
        }

        .um-toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 6000;
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 0.68rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.26);
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px);
            transition: 0.22s ease;
        }

        .um-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        html[data-theme="dark"] .um-head h1 {
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-head p {
            color: #cbd5e1;
        }

        html[data-theme="dark"] .um-toolbar {
            background: #0f172a;
            border-bottom-color: #334155;
        }

        html[data-theme="dark"] .um-search {
            background: #111827;
            border-color: #334155;
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-search::placeholder {
            color: #94a3b8;
        }

        html[data-theme="dark"] .um-summary {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
        }

        html[data-theme="dark"] .um-table-wrap {
            border-top-color: #334155;
        }

        html[data-theme="dark"] .um-table th {
            background: #111827;
            color: #cbd5e1;
            border-bottom-color: #334155;
        }

        html[data-theme="dark"] .um-table td {
            color: #e5e7eb;
            border-bottom-color: #1f2937;
        }

        html[data-theme="dark"] .um-table tr:hover td {
            background: #172033;
        }

        html[data-theme="dark"] .um-code {
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-action {
            background: #0f172a;
            border-color: #334155;
            color: #e5e7eb;
        }

        html[data-theme="dark"] .um-modal .um-modal-card {
            background: #111827;
            border-color: #334155;
        }

        html[data-theme="dark"] .um-modal .um-modal-head,
        html[data-theme="dark"] .um-modal .um-modal-foot {
            background: #0f172a;
            border-color: #334155;
        }

        html[data-theme="dark"] .um-modal .um-modal-head h2 {
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-modal .um-modal-body {
            color: #e5e7eb;
        }

        html[data-theme="dark"] .um-close {
            background: #111827;
            border-color: #334155;
            color: #e5e7eb;
        }

        html[data-theme="dark"] .um-close:hover {
            background: #1e293b;
            color: #ffffff;
        }

        html[data-theme="dark"] .um-field label {
            color: #cbd5e1;
        }

        html[data-theme="dark"] .um-input,
        html[data-theme="dark"] .um-select {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-input::placeholder {
            color: #94a3b8;
        }

        html[data-theme="dark"] .um-password-toggle {
            color: #94a3b8;
        }

        html[data-theme="dark"] .um-password-toggle:hover {
            color: #f8fafc;
            background: #1e293b;
        }

        html[data-theme="dark"] .um-password-hint {
            background: #0f172a;
            border-color: #334155;
        }

        html[data-theme="dark"] .um-password-hint p {
            color: #e5e7eb;
        }

        html[data-theme="dark"] .um-password-rules {
            color: #cbd5e1;
        }

        html[data-theme="dark"] .um-field-note {
            color: #94a3b8;
        }

        html[data-theme="dark"] .um-btn {
            background: #0f172a;
            border-color: #334155;
            color: #e5e7eb;
        }

        html[data-theme="dark"] .um-btn:hover {
            background: #1e293b;
            color: #ffffff;
        }

        html[data-theme="dark"] .um-btn.primary {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        html[data-theme="dark"] .um-btn.primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        html[data-theme="dark"] .um-empty {
            color: #cbd5e1;
        }

        @media (max-width: 880px) {
            .um-toolbar {
                grid-template-columns: 1fr;
            }

            .um-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .um-add-btn {
                width: 100%;
            }
        }

        @media (max-width: 680px) {
            .main-content {
                padding: 1rem 0.75rem;
            }

            .um-head h1 {
                font-size: 1.35rem;
            }

            .um-head p {
                font-size: 0.86rem;
            }

            .um-form-grid {
                grid-template-columns: 1fr;
            }

            .um-table th,
            .um-table td {
                font-size: 0.78rem;
                padding: 0.62rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container um-shell">
            <section class="um-head">
                <div>
                    <h1>User Management</h1>
                    <p>Hi <?php echo htmlspecialchars($adminName); ?>. Manage dispatcher and responder accounts in one panel.</p>
                </div>
                <button type="button" class="um-add-btn" id="openAddUserBtn">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
            </section>

            <section class="um-card">
                <div class="um-toolbar">
                    <input
                        type="text"
                        id="userSearchInput"
                        class="um-search"
                        placeholder="Search name, email, contact, role, or department...">
                    <span class="um-summary" id="userCountBadge">0 account(s)</span>
                </div>

                <div class="um-table-wrap">
                    <table class="um-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Unit Code</th>
                                <th>Unit Type</th>
                                <th>Vehicle Plate</th>
                                <th>Unit Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <div class="um-modal" id="addUserModal" aria-hidden="true">
        <div class="um-modal-card">
            <div class="um-modal-head">
                <h2 id="accountModalTitle">Add New Account</h2>
                <button type="button" class="um-close" id="closeAddUserModal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="addUserForm">
                <div class="um-modal-body">
                    <div class="um-form-grid">
                        <div class="um-field">
                            <label for="newUserName">Full Name</label>
                            <input id="newUserName" class="um-input" maxlength="80" required>
                        </div>
                        <div class="um-field">
                            <label for="newUserEmail">Email</label>
                            <input id="newUserEmail" type="email" class="um-input" maxlength="120" required>
                        </div>
                        <div class="um-field">
                            <label for="newUserContact">Contact Number</label>
                            <input id="newUserContact" class="um-input" maxlength="50" required>
                        </div>
                        <div class="um-field" id="newUserPasswordField">
                            <label for="newUserPassword">Password</label>
                            <div class="um-password-wrap">
                                <input id="newUserPassword" type="password" class="um-input" minlength="8" maxlength="128" placeholder="Minimum 8 characters" required>
                                <button type="button" class="um-password-toggle" id="toggleNewUserPassword" aria-label="Show password" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="um-password-hint" id="passwordRequirements" hidden>
                                <p>Password requirements:</p>
                                <ul class="um-password-rules">
                                    <li data-rule="length">At least 8 characters</li>
                                    <li data-rule="upper">At least 1 uppercase letter</li>
                                    <li data-rule="lower">At least 1 lowercase letter</li>
                                    <li data-rule="number">At least 1 number</li>
                                    <li data-rule="special">At least 1 special character</li>
                                </ul>
                            </div>
                        </div>
                        <div class="um-field">
                            <label for="newUserRole">Role</label>
                            <select id="newUserRole" class="um-select" required>
                                <option value="dispatcher">Dispatcher</option>
                                <option value="responder">Responder</option>
                            </select>
                        </div>
                        <div class="um-field full" id="newUserUnitField" hidden>
                            <label for="newUserAssignedUnit">Available Unit</label>
                            <select id="newUserAssignedUnit" class="um-select">
                                <option value="">Select available unit</option>
                            </select>
                            <div class="um-field-note" id="newUserUnitHint">Only available units are listed.</div>
                        </div>
                        <div class="um-field" id="newUserUnitCodeField" hidden>
                            <label for="newUserUnitCode">Unit Code</label>
                            <input id="newUserUnitCode" class="um-input" maxlength="50" placeholder="Select a unit">
                        </div>
                        <div class="um-field" id="newUserPlateField" hidden>
                            <label for="newUserPlateNumber">Plate Number</label>
                            <input id="newUserPlateNumber" class="um-input" maxlength="50" placeholder="Select a unit">
                        </div>
                        <div class="um-field" id="newUserUnitTypeField" hidden>
                            <label for="newUserUnitType">Unit Type</label>
                            <input id="newUserUnitType" class="um-input" maxlength="50" placeholder="Select a unit">
                        </div>
                        <div class="um-field" id="newUserUnitStatusField" hidden>
                            <label for="newUserUnitStatus">Unit Status</label>
                            <input id="newUserUnitStatus" class="um-input" maxlength="50" placeholder="Select a unit">
                        </div>
                        <div class="um-field">
                            <label for="newUserStatus">Status</label>
                            <select id="newUserStatus" class="um-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="um-field full">
                            <label for="newUserDepartment">Department / Assignment</label>
                            <input id="newUserDepartment" class="um-input" maxlength="80" placeholder="e.g. EMS - Team Bravo" required>
                        </div>
                    </div>
                </div>
                <div class="um-modal-foot">
                    <button type="button" class="um-btn" id="cancelAddUserBtn">Cancel</button>
                    <button type="submit" class="um-btn primary" id="accountModalSubmitBtn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="um-toast" id="userToast"></div>

    <script>
        const adminUsersApiUrl = 'api/admin_users.php';
        const availableUnitsApiUrl = 'api/units_list.php?status=available';
        const userRows = [];
        let availableUnits = [];
        let availableUnitsLoaded = false;
        let availableUnitsLoading = false;
        let editingId = null;

        const usersTableBody = document.getElementById('usersTableBody');
        const userCountBadge = document.getElementById('userCountBadge');
        const userSearchInput = document.getElementById('userSearchInput');
        const addUserModal = document.getElementById('addUserModal');
        const accountModalTitle = document.getElementById('accountModalTitle');
        const openAddUserBtn = document.getElementById('openAddUserBtn');
        const closeAddUserModal = document.getElementById('closeAddUserModal');
        const cancelAddUserBtn = document.getElementById('cancelAddUserBtn');
        const addUserForm = document.getElementById('addUserForm');
        const newUserName = document.getElementById('newUserName');
        const newUserEmail = document.getElementById('newUserEmail');
        const newUserContact = document.getElementById('newUserContact');
        const newUserPasswordField = document.getElementById('newUserPasswordField');
        const newUserPassword = document.getElementById('newUserPassword');
        const toggleNewUserPassword = document.getElementById('toggleNewUserPassword');
        const newUserRole = document.getElementById('newUserRole');
        const newUserUnitField = document.getElementById('newUserUnitField');
        const newUserAssignedUnit = document.getElementById('newUserAssignedUnit');
        const newUserUnitHint = document.getElementById('newUserUnitHint');
        const newUserUnitCodeField = document.getElementById('newUserUnitCodeField');
        const newUserUnitCode = document.getElementById('newUserUnitCode');
        const newUserPlateField = document.getElementById('newUserPlateField');
        const newUserPlateNumber = document.getElementById('newUserPlateNumber');
        const newUserUnitTypeField = document.getElementById('newUserUnitTypeField');
        const newUserUnitType = document.getElementById('newUserUnitType');
        const newUserUnitStatusField = document.getElementById('newUserUnitStatusField');
        const newUserUnitStatus = document.getElementById('newUserUnitStatus');
        const newUserStatus = document.getElementById('newUserStatus');
        const newUserDepartment = document.getElementById('newUserDepartment');
        const passwordRequirements = document.getElementById('passwordRequirements');
        const userToast = document.getElementById('userToast');
        const addUserSubmitBtn = document.getElementById('accountModalSubmitBtn');
        const passwordRuleElements = passwordRequirements
            ? Array.from(passwordRequirements.querySelectorAll('[data-rule]'))
            : [];

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showToast(message) {
            userToast.textContent = message;
            userToast.classList.add('show');
            window.clearTimeout(showToast._timer);
            showToast._timer = window.setTimeout(() => {
                userToast.classList.remove('show');
            }, 2000);
        }

        function validatePassword(value) {
            return {
                length: value.length >= 8,
                upper: /[A-Z]/.test(value),
                lower: /[a-z]/.test(value),
                number: /\d/.test(value),
                special: /[^A-Za-z0-9]/.test(value)
            };
        }

        function passwordMeetsRequirements(value) {
            const rules = validatePassword(value);
            return Object.values(rules).every(Boolean);
        }

        function isPasswordRequiredForRole(role) {
            return editingId === null && role !== 'responder';
        }

        function formatUnitLabel(unit) {
            const identifier = String(unit.identifier || '').trim() || ('Unit #' + unit.id);
            const resourceName = String(unit.resource_name || '').trim();
            const unitType = String(unit.unit_type || '').trim();
            const labelParts = [identifier];
            if (resourceName) labelParts.push(resourceName);
            if (unitType) labelParts.push(unitType.charAt(0).toUpperCase() + unitType.slice(1));
            return labelParts.join(' - ');
        }

        function formatUnitValue(value) {
            const raw = String(value || '').replace(/_/g, ' ').trim();
            if (!raw) return '';
            return raw.charAt(0).toUpperCase() + raw.slice(1);
        }

        function getEditingRow() {
            return editingId !== null ? (userRows.find((row) => row.id === editingId) || null) : null;
        }

        function unitFromUserRow(row) {
            if (!row || !row.assigned_unit_id) return null;
            return {
                id: Number(row.assigned_unit_id) || 0,
                identifier: String(row.unit_code || '').trim(),
                unit_type: String(row.unit_type || '').trim(),
                plate_number: String(row.vehicle_plate || '').trim(),
                resource_name: '',
                status: String(row.unit_status || '').trim()
            };
        }

        function ensureCurrentAssignedUnitOption(pendingValue) {
            const currentUnit = unitFromUserRow(getEditingRow());
            if (!currentUnit || currentUnit.id <= 0) return;
            if (pendingValue && String(currentUnit.id) !== String(pendingValue)) return;
            if (!availableUnits.some((unit) => unit.id === currentUnit.id)) {
                availableUnits.unshift(currentUnit);
            }
        }

        function setResponderUnitDetailVisibility(isVisible) {
            [newUserUnitCodeField, newUserPlateField, newUserUnitTypeField, newUserUnitStatusField].forEach((field) => {
                if (field) field.hidden = !isVisible;
            });
        }

        function updateResponderUnitDetails() {
            if (!newUserAssignedUnit) return;

            const selectedId = Number(newUserAssignedUnit.value) || 0;
            const selectedUnit = availableUnits.find((unit) => unit.id === selectedId) || null;

            if (!selectedUnit && editingId !== null) {
                return;
            }

            if (newUserUnitCode) {
                newUserUnitCode.value = selectedUnit ? (selectedUnit.identifier || 'N/A') : '';
            }
            if (newUserPlateNumber) {
                newUserPlateNumber.value = selectedUnit ? (selectedUnit.plate_number || 'N/A') : '';
            }
            if (newUserUnitType) {
                newUserUnitType.value = selectedUnit ? formatUnitValue(selectedUnit.unit_type) : '';
            }
            if (newUserUnitStatus) {
                newUserUnitStatus.value = selectedUnit ? formatUnitValue(selectedUnit.status) : '';
            }
        }

        function renderAvailableUnitOptions() {
            if (!newUserAssignedUnit || !newUserUnitHint) return;

            const pendingValue = newUserAssignedUnit.dataset.pendingValue || newUserAssignedUnit.value || '';
            ensureCurrentAssignedUnitOption(pendingValue);

            if (availableUnitsLoading) {
                newUserAssignedUnit.innerHTML = '<option value="">Loading available units...</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'Loading currently available units.';
                updateResponderUnitDetails();
                return;
            }

            if (!availableUnits.length) {
                newUserAssignedUnit.innerHTML = '<option value="">No available units</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'No available units are ready right now.';
                updateResponderUnitDetails();
                return;
            }

            const options = availableUnits.map((unit) => (
                `<option value="${unit.id}">${escapeHtml(formatUnitLabel(unit))}</option>`
            )).join('');

            newUserAssignedUnit.disabled = false;
            newUserAssignedUnit.required = newUserRole && newUserRole.value === 'responder';
            newUserAssignedUnit.innerHTML = '<option value="">Select available unit</option>' + options;

            if (pendingValue && availableUnits.some((unit) => String(unit.id) === String(pendingValue))) {
                newUserAssignedUnit.value = String(pendingValue);
            }
            delete newUserAssignedUnit.dataset.pendingValue;
            newUserUnitHint.textContent = editingId === null
                ? 'Only units with Available status are listed.'
                : 'Change the available unit to update unit code, plate, type, and status.';
            updateResponderUnitDetails();
        }

        async function loadAvailableUnits() {
            if (availableUnitsLoading) return;
            availableUnitsLoading = true;
            renderAvailableUnitOptions();

            try {
                const response = await fetch(availableUnitsApiUrl, {
                    headers: {
                        'Accept': 'application/json'
                    },
                    cache: 'no-store'
                });
                const result = await response.json();

                if (!response.ok || !result.ok || !Array.isArray(result.items)) {
                    throw new Error(result.error || 'Unable to load available units.');
                }

                availableUnits = result.items.map((item) => ({
                    id: Number(item.id) || 0,
                    identifier: String(item.identifier || '').trim(),
                    unit_type: String(item.unit_type || '').trim(),
                    plate_number: String(item.plate_number || '').trim(),
                    resource_name: String(item.resource_name || '').trim(),
                    status: String(item.status || '').trim()
                })).filter((item) => item.id > 0);
                availableUnitsLoaded = true;
            } catch (error) {
                availableUnits = [];
                availableUnitsLoaded = false;
                if (newUserUnitHint) {
                    newUserUnitHint.textContent = error.message || 'Unable to load available units.';
                }
            } finally {
                availableUnitsLoading = false;
                renderAvailableUnitOptions();
            }
        }

        function syncResponderUnitField() {
            if (!newUserRole || !newUserUnitField || !newUserAssignedUnit) return;

            const isResponder = newUserRole.value === 'responder';
            newUserUnitField.hidden = !isResponder;
            setResponderUnitDetailVisibility(isResponder);

            if (!isResponder) {
                newUserAssignedUnit.required = false;
                newUserAssignedUnit.value = '';
                delete newUserAssignedUnit.dataset.pendingValue;
                updateResponderUnitDetails();
                return;
            }

            renderAvailableUnitOptions();
            if (!availableUnitsLoaded && !availableUnitsLoading) {
                loadAvailableUnits();
            }
        }

        function syncPasswordFieldForRole() {
            if (!newUserRole || !newUserPassword) return;

            const passwordRequired = isPasswordRequiredForRole(newUserRole.value);

            if (newUserPasswordField) {
                newUserPasswordField.hidden = !passwordRequired;
            }

            newUserPassword.required = passwordRequired;

            if (passwordRequired) {
                newUserPassword.setAttribute('minlength', '8');
                newUserPassword.placeholder = 'Minimum 8 characters';
            } else {
                newUserPassword.removeAttribute('minlength');
                newUserPassword.value = '';
                newUserPassword.placeholder = 'Not required for responder';
                resetPasswordToggle();
            }

            updatePasswordRequirements(false);
            syncResponderUnitField();
        }

        function updatePasswordRequirements(forceVisible = false) {
            if (!passwordRequirements || !newUserPassword) return;

            const passwordValue = newUserPassword.value || '';
            const rules = validatePassword(passwordValue);
            const passwordRequired = newUserRole ? isPasswordRequiredForRole(newUserRole.value) : true;
            const shouldShow = passwordRequired && (forceVisible || passwordValue.length > 0);

            passwordRequirements.hidden = !shouldShow;

            passwordRuleElements.forEach((item) => {
                const ruleName = item.getAttribute('data-rule');
                const isValid = Boolean(ruleName && rules[ruleName]);
                item.classList.toggle('valid', isValid);
                item.classList.toggle('invalid', shouldShow && !isValid);
            });
        }

        function roleChip(role) {
            const safe = (role || '').toLowerCase();
            const label = safe.charAt(0).toUpperCase() + safe.slice(1);
            return '<span class="um-chip ' + safe + '">' + escapeHtml(label) + '</span>';
        }

        function statusChip(status) {
            const safe = (status || '').toLowerCase();
            const label = safe.charAt(0).toUpperCase() + safe.slice(1);
            return '<span class="um-chip ' + safe + '">' + escapeHtml(label) + '</span>';
        }

        function displayUnitValue(value) {
            const raw = String(value || '').replace(/_/g, ' ').trim();
            if (!raw) return 'N/A';
            return raw.charAt(0).toUpperCase() + raw.slice(1);
        }

        function filteredRows() {
            const needle = userSearchInput.value.trim().toLowerCase();
            if (!needle) return userRows.slice();
            return userRows.filter((row) => {
                const hay = [
                    row.name,
                    row.email,
                    row.contact_number,
                    row.role,
                    row.department,
                    row.status,
                    row.unit_code,
                    row.unit_type,
                    row.vehicle_plate,
                    row.unit_status
                ].join(' ').toLowerCase();
                return hay.includes(needle);
            });
        }

        function renderRows() {
            const rows = filteredRows();
            userCountBadge.textContent = rows.length + ' account(s)';

            if (!rows.length) {
                usersTableBody.innerHTML = '<tr><td colspan="13" class="um-empty">No user accounts found.</td></tr>';
                return;
            }

            usersTableBody.innerHTML = rows.map((row, index) => {
                return `
                    <tr data-row-id="${row.id}">
                        <td class="um-code">${index + 1}</td>
                        <td>${escapeHtml(row.name)}</td>
                        <td>${escapeHtml(row.email)}</td>
                        <td>${escapeHtml(row.contact_number || '')}</td>
                        <td>${roleChip(row.role)}</td>
                        <td>${escapeHtml(row.department)}</td>
                        <td>${statusChip(row.status)}</td>
                        <td>${escapeHtml(row.unit_code || 'N/A')}</td>
                        <td>${escapeHtml(displayUnitValue(row.unit_type))}</td>
                        <td>${escapeHtml(row.vehicle_plate || 'N/A')}</td>
                        <td>${escapeHtml(displayUnitValue(row.unit_status))}</td>
                        <td>${escapeHtml(row.created)}</td>
                        <td>
                            <div class="um-actions">
                                <button type="button" class="um-action edit" data-action="edit" data-id="${row.id}" title="Edit" aria-label="Edit">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="um-action delete" data-action="delete" data-id="${row.id}" title="Delete" aria-label="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function setAccountModalMode(mode) {
            const isEdit = mode === 'edit';
            if (accountModalTitle) {
                accountModalTitle.textContent = isEdit ? 'Edit Account' : 'Add New Account';
            }
            if (addUserSubmitBtn) {
                addUserSubmitBtn.textContent = isEdit ? 'Save Changes' : 'Save User';
            }
        }

        function openModal() {
            setAccountModalMode(editingId === null ? 'create' : 'edit');
            addUserModal.classList.add('show');
            addUserModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            availableUnits = [];
            availableUnitsLoaded = false;
            syncPasswordFieldForRole();
            updatePasswordRequirements(false);
            newUserName.focus();
        }

        function resetPasswordToggle() {
            if (!newUserPassword || !toggleNewUserPassword) return;
            newUserPassword.type = 'password';
            const icon = toggleNewUserPassword.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            toggleNewUserPassword.setAttribute('aria-label', 'Show password');
            toggleNewUserPassword.setAttribute('title', 'Show password');
        }

        function closeModal() {
            addUserModal.classList.remove('show');
            addUserModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            addUserForm.reset();
            editingId = null;
            resetPasswordToggle();
            syncPasswordFieldForRole();
            syncResponderUnitField();
            updatePasswordRequirements(false);
            setAccountModalMode('create');
        }

        function restoreAddUserForm(payload) {
            if (!payload) return;

            newUserName.value = payload.name || '';
            newUserEmail.value = payload.email || '';
            newUserContact.value = payload.contact_number || '';
            newUserRole.value = payload.role || 'dispatcher';
            newUserDepartment.value = payload.department || '';
            newUserStatus.value = payload.status || 'active';
            newUserPassword.value = payload.password || '';
            if (newUserUnitCode) {
                newUserUnitCode.value = payload.unit_code || '';
            }
            if (newUserPlateNumber) {
                newUserPlateNumber.value = payload.vehicle_plate || '';
            }
            if (newUserUnitType) {
                newUserUnitType.value = formatUnitValue(payload.unit_type);
            }
            if (newUserUnitStatus) {
                newUserUnitStatus.value = formatUnitValue(payload.unit_status);
            }
            if (newUserAssignedUnit) {
                newUserAssignedUnit.dataset.pendingValue = payload.assigned_unit_id ? String(payload.assigned_unit_id) : '';
            }
            resetPasswordToggle();
            syncPasswordFieldForRole();
            updatePasswordRequirements(Boolean(payload.password));
        }

        async function loadUsers() {
            usersTableBody.innerHTML = '<tr><td colspan="13" class="um-empty">Loading user accounts...</td></tr>';

            try {
                const response = await fetch(adminUsersApiUrl, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const result = await response.json();

                if (!response.ok || !result.success || !Array.isArray(result.users)) {
                    throw new Error(result.message || 'Unable to load users.');
                }

                userRows.splice(0, userRows.length, ...result.users);
                editingId = null;
                renderRows();
            } catch (error) {
                usersTableBody.innerHTML = '<tr><td colspan="13" class="um-empty">Unable to load user accounts.</td></tr>';
                showToast(error.message || 'Unable to load users.');
            }
        }

        usersTableBody.addEventListener('click', async (event) => {
            const button = event.target.closest('.um-action');
            if (!button) return;

            const action = button.getAttribute('data-action');
            const id = Number(button.getAttribute('data-id'));
            const target = userRows.find((row) => row.id === id);
            if (!target) return;

            if (action === 'edit') {
                editingId = id;
                addUserForm.reset();
                restoreAddUserForm(target);
                openModal();
                return;
            }

            if (action === 'delete') {
                const ok = window.confirm('Permanently delete account for ' + target.name + '?');
                if (!ok) return;

                try {
                    const response = await fetch(adminUsersApiUrl, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ id })
                    });
                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to delete user.');
                    }

                    const index = userRows.findIndex((row) => row.id === id);
                    if (index >= 0) userRows.splice(index, 1);
                    if (editingId === id) editingId = null;
                    renderRows();
                    showToast(result.message || 'User account permanently deleted.');
                } catch (error) {
                    showToast(error.message || 'Unable to delete user.');
                }
                return;
            }
        });

        openAddUserBtn.addEventListener('click', () => {
            editingId = null;
            addUserForm.reset();
            openModal();
        });
        closeAddUserModal.addEventListener('click', closeModal);
        cancelAddUserBtn.addEventListener('click', closeModal);

        addUserModal.addEventListener('click', (event) => {
            if (event.target === addUserModal) closeModal();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && addUserModal.classList.contains('show')) {
                closeModal();
            }
        });

        addUserForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const editId = editingId;
            const isEdit = editId !== null;

            const payload = {
                ...(isEdit ? { id: editId } : {}),
                name: newUserName.value.trim(),
                email: newUserEmail.value.trim(),
                contact_number: newUserContact.value.trim(),
                role: newUserRole.value,
                assigned_unit_id: newUserRole.value === 'responder' && newUserAssignedUnit && !newUserAssignedUnit.disabled
                    ? (Number(newUserAssignedUnit.value) || null)
                    : null,
                unit_code: newUserRole.value === 'responder' && newUserUnitCode ? newUserUnitCode.value.trim() : '',
                unit_type: newUserRole.value === 'responder' && newUserUnitType ? newUserUnitType.value.trim() : '',
                vehicle_plate: newUserRole.value === 'responder' && newUserPlateNumber ? newUserPlateNumber.value.trim() : '',
                unit_status: newUserRole.value === 'responder' && newUserUnitStatus ? newUserUnitStatus.value.trim() : '',
                department: newUserDepartment.value.trim(),
                status: newUserStatus.value
            };
            if (!isEdit) {
                payload.password = newUserPassword.value;
            }
            const passwordRequired = isPasswordRequiredForRole(payload.role);

            if (!payload.name || !payload.email || !payload.contact_number || !payload.department || (passwordRequired && !payload.password)) {
                showToast('Please complete required fields.');
                return;
            }
            if (payload.role === 'responder' && newUserAssignedUnit && newUserAssignedUnit.required && !payload.assigned_unit_id) {
                showToast('Please select an available unit.');
                return;
            }
            if (payload.password && !passwordMeetsRequirements(payload.password)) {
                updatePasswordRequirements(true);
                showToast('Password does not meet the requirements.');
                return;
            }

            const emailExists = userRows.some((row) => row.id !== editId && row.email.toLowerCase() === payload.email.toLowerCase());
            if (emailExists) {
                showToast('Email is already in use.');
                return;
            }

            if (addUserSubmitBtn) {
                addUserSubmitBtn.disabled = true;
            }

            try {
                const response = await fetch(adminUsersApiUrl, {
                    method: isEdit ? 'PATCH' : 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (!response.ok || !result.success || !result.user) {
                    throw new Error(result.message || 'Unable to save user.');
                }

                if (isEdit) {
                    const targetIndex = userRows.findIndex((row) => row.id === editId);
                    if (targetIndex >= 0) {
                        userRows[targetIndex] = result.user;
                    }
                } else {
                    userRows.unshift(result.user);
                }
                closeModal();
                renderRows();
                showToast(result.message || (isEdit ? 'User account updated.' : 'New user account added.'));
            } catch (error) {
                showToast(error.message || (isEdit ? 'Unable to update user.' : 'Unable to save user.'));
            } finally {
                if (addUserSubmitBtn) {
                    addUserSubmitBtn.disabled = false;
                }
            }
        });

        if (toggleNewUserPassword && newUserPassword) {
            toggleNewUserPassword.addEventListener('click', () => {
                const isHidden = newUserPassword.type === 'password';
                newUserPassword.type = isHidden ? 'text' : 'password';

                const icon = toggleNewUserPassword.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !isHidden);
                    icon.classList.toggle('fa-eye-slash', isHidden);
                }

                const nextLabel = isHidden ? 'Hide password' : 'Show password';
                toggleNewUserPassword.setAttribute('aria-label', nextLabel);
                toggleNewUserPassword.setAttribute('title', nextLabel);
            });

            newUserPassword.addEventListener('focus', () => {
                updatePasswordRequirements(newUserPassword.value.length > 0);
            });

            newUserPassword.addEventListener('input', () => {
                updatePasswordRequirements(true);
            });

            newUserPassword.addEventListener('blur', () => {
                updatePasswordRequirements(false);
            });
        }

        if (newUserRole) {
            newUserRole.addEventListener('change', syncPasswordFieldForRole);
            syncPasswordFieldForRole();
        }

        if (newUserAssignedUnit) {
            newUserAssignedUnit.addEventListener('change', updateResponderUnitDetails);
        }

        userSearchInput.addEventListener('input', renderRows);

        loadUsers();
    </script>
</body>
</html>
