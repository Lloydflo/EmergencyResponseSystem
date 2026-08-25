<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/interagency.php');

$pageTitle = 'Inter-Agency Conversations';
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
    <link rel="stylesheet" href="css/interagency-command.css">
    <link rel="stylesheet" href="css/interagency-events.css">
    <link rel="stylesheet" href="css/interagency-tips.css">
    <link rel="stylesheet" href="css/admin-anonymous-tip.css?v=20260808-admin-tip-details-v2">
    <style>
        :root {
            --ia-bg: #f4f7fb;
            --ia-card: #ffffff;
            --ia-border: #dfe6ee;
            --ia-text: #122031;
            --ia-muted: #5f7286;
            --ia-primary: #0f766e;
            --ia-primary-dark: #115e59;
            --ia-alert: #b45309;
            --ia-danger: #b91c1c;
            --ia-soft: #edf3f8;
        }

        .main-content {
            background:
                radial-gradient(circle at 85% 5%, rgba(14, 165, 233, 0.1), transparent 32%),
                radial-gradient(circle at 10% 5%, rgba(16, 185, 129, 0.12), transparent 35%),
                var(--ia-bg);
            padding: calc(var(--app-header-height-1) + 1.25rem) 1.5rem 3rem;
        }

        .ia-shell {
            padding-top: 0.75rem;
        }

        .ia-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .ia-head h1 {
            margin: 0;
            color: var(--ia-text);
            font-size: 1.7rem;
            line-height: 1.2;
        }

        .ia-head p {
            margin: 0.3rem 0 0;
            color: var(--ia-muted);
            font-size: 0.94rem;
        }

        .ia-pill {
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
            border-radius: 999px;
            padding: 0.45rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .ia-overview {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .ia-stat {
            background: var(--ia-card);
            border: 1px solid var(--ia-border);
            border-radius: 12px;
            padding: 0.8rem 0.9rem;
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.05);
        }

        .ia-stat-label {
            color: var(--ia-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .ia-stat-value {
            margin-top: 0.35rem;
            color: var(--ia-text);
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1;
        }

        .ia-ops-desk {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 0.9rem;
            margin-bottom: 1rem;
        }

        .ia-ops-panel {
            background: var(--ia-card);
            border: 1px solid var(--ia-border);
            border-radius: 8px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .ia-ops-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--ia-border);
            background: #f8fbff;
        }

        .ia-ops-title {
            margin: 0;
            color: var(--ia-text);
            font-size: 1rem;
            font-weight: 900;
        }

        .ia-ops-sub {
            margin: 0.2rem 0 0;
            color: var(--ia-muted);
            font-size: 0.82rem;
        }

        .ia-ops-live {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 0.34rem 0.62rem;
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ia-ops-metrics {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .ia-ops-metric {
            border-right: 1px solid var(--ia-border);
            padding: 0.95rem 1rem;
            min-width: 0;
        }

        .ia-ops-metric:last-child {
            border-right: 0;
        }

        .ia-ops-metric-label {
            color: var(--ia-muted);
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .ia-ops-metric-value {
            margin-top: 0.35rem;
            color: var(--ia-text);
            font-size: 1.55rem;
            font-weight: 900;
            line-height: 1;
        }

        .ia-ops-metric-note {
            margin-top: 0.35rem;
            color: var(--ia-muted);
            font-size: 0.74rem;
            line-height: 1.3;
        }

        .ia-ops-lanes {
            display: grid;
            gap: 0.55rem;
            padding: 0.75rem;
        }

        .ia-ops-lane {
            display: grid;
            grid-template-columns: 36px minmax(0, 1fr) auto;
            align-items: center;
            gap: 0.65rem;
            border: 1px solid #dbe5ef;
            border-radius: 8px;
            background: #fff;
            padding: 0.65rem;
        }

        .ia-ops-lane-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: #0f766e;
        }

        .ia-ops-lane:nth-child(2) .ia-ops-lane-icon {
            background: #2563eb;
        }

        .ia-ops-lane:nth-child(3) .ia-ops-lane-icon {
            background: #b45309;
        }

        .ia-ops-lane:nth-child(4) .ia-ops-lane-icon {
            background: #7c3aed;
        }

        .ia-ops-lane-title {
            margin: 0;
            color: var(--ia-text);
            font-size: 0.86rem;
            font-weight: 900;
        }

        .ia-ops-lane-sub {
            margin: 0.14rem 0 0;
            color: var(--ia-muted);
            font-size: 0.75rem;
            line-height: 1.3;
        }

        .ia-ops-jump {
            width: 34px;
            height: 34px;
            border: 1px solid var(--ia-border);
            border-radius: 8px;
            background: #fff;
            color: var(--ia-text);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .ia-ops-jump:hover {
            border-color: #0f766e;
            color: #0f766e;
            background: #ecfdf5;
        }

        .ia-board {
            display: grid;
            grid-template-columns: 330px minmax(0, 1fr) 270px;
            gap: 0.9rem;
            min-height: 620px;
        }

        .ia-list-panel,
        .ia-chat-panel,
        .ia-user-status-panel {
            background: var(--ia-card);
            border: 1px solid var(--ia-border);
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .ia-user-status-panel {
            border-right: 4px solid #0f766e;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .ia-user-status-head {
            padding: 0.85rem;
            border-bottom: 1px solid var(--ia-border);
            background: #f8fbff;
        }

        .ia-user-status-title {
            margin: 0;
            color: var(--ia-text);
            font-size: 0.95rem;
            font-weight: 800;
        }

        .ia-user-status-sub {
            margin: 0.2rem 0 0;
            color: var(--ia-muted);
            font-size: 0.76rem;
        }

        .ia-user-status-list {
            max-height: 545px;
            overflow-y: auto;
        }

        .ia-user-status-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.8rem 0.85rem;
            border-bottom: 1px solid #edf2f7;
            min-width: 0;
        }

        .ia-user-status-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.82rem;
        }

        .ia-user-status-main {
            flex: 1;
            min-width: 0;
        }

        .ia-user-status-name {
            margin: 0;
            color: #102132;
            font-size: 0.86rem;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-user-status-role {
            margin: 0.15rem 0 0;
            color: #5f7286;
            font-size: 0.73rem;
            text-transform: capitalize;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-user-status-state {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid #d9e5ef;
            border-radius: 999px;
            padding: 0.22rem 0.45rem;
            color: #475569;
            background: #f8fafc;
            font-size: 0.7rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .ia-user-status-state.responding,
        .ia-user-status-state.online {
            border-color: #bbf7d0;
            background: #ecfdf5;
            color: #166534;
        }

        .ia-user-status-state.available {
            border-color: #fde68a;
            background: #fffbeb;
            color: #92400e;
        }

        .ia-user-status-state.busy {
            border-color: #fecdd3;
            background: #fff1f2;
            color: #be123c;
        }

        .ia-user-status-state.offline {
            border-color: #e2e8f0;
            background: #f1f5f9;
            color: #64748b;
        }

        [data-theme="dark"] .ia-user-status-panel {
            background: #111827;
            border-color: #334155;
            border-right-color: #14b8a6;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.24);
        }

        [data-theme="dark"] .ia-ops-panel,
        [data-theme="dark"] .ia-ops-lane {
            background: #111827;
            border-color: #334155;
        }

        [data-theme="dark"] .ia-ops-panel-head {
            background: #1f2937;
            border-bottom-color: #334155;
        }

        [data-theme="dark"] .ia-ops-title,
        [data-theme="dark"] .ia-ops-metric-value,
        [data-theme="dark"] .ia-ops-lane-title {
            color: #f8fafc;
        }

        [data-theme="dark"] .ia-ops-sub,
        [data-theme="dark"] .ia-ops-metric-label,
        [data-theme="dark"] .ia-ops-metric-note,
        [data-theme="dark"] .ia-ops-lane-sub {
            color: #cbd5e1;
        }

        [data-theme="dark"] .ia-ops-metric {
            border-right-color: #334155;
        }

        [data-theme="dark"] .ia-ops-jump {
            background: #0f172a;
            border-color: #475569;
            color: #e2e8f0;
        }

        [data-theme="dark"] .ia-user-status-head {
            background: #1f2937;
            border-bottom-color: #334155;
        }

        [data-theme="dark"] .ia-user-status-title,
        [data-theme="dark"] .ia-user-status-name {
            color: #f8fafc;
        }

        [data-theme="dark"] .ia-user-status-sub,
        [data-theme="dark"] .ia-user-status-role,
        [data-theme="dark"] .ia-empty-list {
            color: #cbd5e1;
        }

        [data-theme="dark"] .ia-user-status-item {
            border-bottom-color: #334155;
        }

        [data-theme="dark"] .ia-user-status-avatar {
            background: #0f172a;
            border: 1px solid #334155;
            color: #7dd3fc;
        }

        [data-theme="dark"] .ia-user-status-state {
            background: #1e293b;
            border-color: #475569;
            color: #e2e8f0;
        }

        [data-theme="dark"] .ia-user-status-state.responding,
        [data-theme="dark"] .ia-user-status-state.online {
            background: rgba(34, 197, 94, 0.16);
            border-color: rgba(34, 197, 94, 0.45);
            color: #bbf7d0;
        }

        [data-theme="dark"] .ia-user-status-state.available {
            background: rgba(245, 158, 11, 0.16);
            border-color: rgba(245, 158, 11, 0.45);
            color: #fde68a;
        }

        [data-theme="dark"] .ia-user-status-state.busy {
            background: rgba(244, 63, 94, 0.16);
            border-color: rgba(244, 63, 94, 0.45);
            color: #fecdd3;
        }

        [data-theme="dark"] .ia-user-status-state.offline {
            background: #1e293b;
            border-color: #475569;
            color: #cbd5e1;
        }

        .ia-list-top {
            padding: 0.85rem;
            border-bottom: 1px solid var(--ia-border);
            background: #f8fbff;
        }

        .ia-search {
            position: relative;
            margin-bottom: 0.65rem;
        }

        .ia-search i {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #7b8fa4;
            font-size: 0.8rem;
        }

        .ia-search input {
            width: 100%;
            border: 1px solid #cfdae6;
            border-radius: 9px;
            padding: 0.62rem 0.7rem 0.62rem 2rem;
            font-size: 0.9rem;
            color: var(--ia-text);
            background: #fff;
        }

        .ia-search input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
            outline: none;
        }

        .ia-tabs {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.45rem;
            min-width: 0;
        }
        
        .ia-list-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 34px 34px;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
        }

        .ia-list-actions .ia-tabs {
            min-width: 0;
        }

        .ia-add-thread-btn {
            width: 34px;
            height: 34px;
            border: 1px solid #cfe0ed;
            border-radius: 9px;
            background: #fff;
            color: #0f766e;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            font-weight: 700;
            transition: 0.2s ease;
            flex-shrink: 0;
        }

        .ia-add-thread-btn:hover {
            background: #ecfdf5;
            border-color: #99e6b8;
        }

        .ia-group-thread-btn {
            background: #eef6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .ia-group-thread-btn:hover {
            background: #dbeafe;
            border-color: #93c5fd;
        }

        .ia-tab {
            border: 1px solid #d4dde8;
            background: #fff;
            color: #35516d;
            border-radius: 8px;
            padding: 0.45rem 0.25rem;
            font-size: 0.79rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .ia-tab:hover {
            background: #f1f6fb;
        }

        .ia-tab.active {
            border-color: #0f766e;
            background: #0f766e;
            color: #fff;
        }

        .ia-thread-list {
            max-height: 545px;
            overflow-y: auto;
        }

        .ia-thread {
            width: 100%;
            border: none;
            border-bottom: 1px solid #edf2f7;
            background: #fff;
            text-align: left;
            padding: 0.85rem;
            cursor: pointer;
            transition: 0.2s ease;
            display: flex;
            gap: 0.7rem;
        }

        .ia-thread:hover {
            background: #f8fbff;
        }

        .ia-thread.active {
            background: #ebf8f6;
            border-left: 4px solid var(--ia-primary);
            padding-left: calc(0.85rem - 4px);
        }

        .ia-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .ia-avatar.department {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
        }

        .ia-avatar.responder {
            background: linear-gradient(135deg, #14b8a6 0%, #0f766e 100%);
        }

        .ia-thread-main {
            flex: 1;
            min-width: 0;
        }

        .ia-thread-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .ia-thread-name {
            margin: 0;
            color: #102132;
            font-size: 0.91rem;
            font-weight: 700;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-thread-title-wrap {
            min-width: 0;
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .ia-thread-edit {
            width: 22px;
            height: 22px;
            border: 1px solid #d7e2ee;
            border-radius: 7px;
            background: #fff;
            color: #3f5c78;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.66rem;
            flex-shrink: 0;
            transition: 0.2s ease;
        }

        .ia-thread-edit:hover {
            background: #f1f6fb;
            border-color: #bdd0e2;
            color: #23425f;
        }

        .ia-thread-edit:focus-visible {
            outline: 2px solid #38bdf8;
            outline-offset: 1px;
        }

        .ia-thread-time {
            color: #7b8fa4;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .ia-thread-sub {
            margin: 0.18rem 0 0;
            color: #47627b;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .ia-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        .ia-dot.responding,
        .ia-dot.online {
            background: #22c55e;
        }

        .ia-dot.available {
            background: #f59e0b;
        }

        .ia-dot.busy {
            background: #f43f5e;
        }

        .ia-dot.offline {
            background: #94a3b8;
        }

        .ia-thread-preview {
            margin: 0.3rem 0 0;
            color: #5f7286;
            font-size: 0.78rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-unread {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 19px;
            height: 19px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 0.69rem;
            font-weight: 700;
            padding: 0 0.3rem;
        }

        .ia-empty-list {
            padding: 2rem 1rem;
            color: var(--ia-muted);
            text-align: center;
            font-size: 0.88rem;
        }

        .ia-chat-head {
            border-bottom: 1px solid var(--ia-border);
            padding: 0.85rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            background: #f8fbff;
        }

        .ia-chat-head-main {
            min-width: 0;
        }

        .ia-chat-actions {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-shrink: 0;
        }

        .ia-chat-title {
            margin: 0;
            color: var(--ia-text);
            font-size: 1rem;
            font-weight: 800;
        }

        .ia-chat-meta {
            margin: 0.2rem 0 0;
            color: #56708a;
            font-size: 0.79rem;
        }

        .ia-chat-info-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #cfe0ed;
            border-radius: 999px;
            background: #ffffff;
            color: #0f766e;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.92rem;
            font-weight: 900;
            line-height: 1;
            transition: 0.2s ease;
        }

        .ia-chat-info-btn:hover,
        .ia-chat-info-btn[aria-expanded="true"] {
            background: #ecfdf5;
            border-color: #99e6b8;
            color: #0f766e;
        }

        .ia-chat-settings {
            position: absolute;
            top: calc(100% + 0.55rem);
            right: 0;
            z-index: 20;
            width: min(280px, calc(100vw - 2rem));
            border: 1px solid #d7e2ee;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.16);
            overflow: hidden;
            text-align: left;
        }

        .ia-chat-settings-head {
            padding: 0.85rem 0.95rem;
            border-bottom: 1px solid #e6edf5;
        }

        .ia-chat-settings-title {
            margin: 0;
            color: #102a43;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .ia-chat-settings-sub {
            margin: 0.25rem 0 0;
            color: #64748b;
            font-size: 0.76rem;
        }

        .ia-chat-settings-list {
            display: grid;
            padding: 0.35rem;
        }

        .ia-chat-settings-item {
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #1f3852;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.72rem 0.7rem;
            text-align: left;
            font-size: 0.84rem;
            font-weight: 700;
        }

        .ia-chat-settings-item i {
            width: 16px;
            color: #0f766e;
            text-align: center;
        }

        .ia-chat-settings-item:hover {
            background: #f1f6fb;
        }

        .ia-chat-body {
            height: 440px;
            overflow-y: auto;
            padding: 1rem;
            background:
                linear-gradient(to bottom, rgba(248, 250, 252, 0.9), rgba(241, 245, 249, 0.9));
        }

        .ia-active-incident-wrap {
            padding: 0.9rem 1rem 0;
            background: linear-gradient(to bottom, #f8fbff 0%, #f1f5f9 100%);
        }

        .ia-active-incident-wrap[hidden] {
            display: none;
        }

        .ia-active-incident-card {
            position: relative;
            border: 1px solid #334155;
            border-radius: 14px;
            background: #1f2933;
            color: #f8fafc;
            padding: 0.9rem 3rem 0.9rem 1rem;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.16);
            min-height: 132px;
        }

        .ia-active-incident-kicker {
            margin: 0 0 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .ia-active-incident-kicker i {
            color: #fb7185;
        }

        .ia-active-incident-title {
            margin: 0 0 0.25rem;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 900;
            line-height: 1.25;
            word-break: break-word;
        }

        .ia-active-incident-grid {
            display: grid;
            gap: 0.22rem;
            color: #e2e8f0;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1.45;
        }

        .ia-active-incident-grid span {
            color: #ffffff;
            font-weight: 900;
        }

        .ia-active-incident-grid p {
            margin: 0;
        }

        .ia-active-incident-status {
            margin-top: 1.1rem;
        }

        .ia-active-incident-open {
            position: absolute;
            top: 0.8rem;
            right: 0.8rem;
            width: 30px;
            height: 30px;
            border: 1px solid rgba(226, 232, 240, 0.35);
            border-radius: 9px;
            background: rgba(15, 23, 42, 0.18);
            color: #f8fafc;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s ease;
        }

        .ia-active-incident-open:hover,
        .ia-active-incident-open:focus-visible {
            background: rgba(248, 250, 252, 0.14);
            border-color: rgba(248, 250, 252, 0.7);
            outline: none;
        }

        .ia-typing-indicator {
            padding: 0.65rem 1rem 0;
            background: #f1f5f9;
        }

        .ia-typing-indicator[hidden] {
            display: none;
        }

        .ia-typing-pill {
            min-height: 42px;
            border: 1px solid #2f3a46;
            border-radius: 999px;
            background: #222426;
            color: #ffffff;
            padding: 0.68rem 3rem 0.68rem 1rem;
            display: flex;
            align-items: center;
            position: relative;
            font-size: 0.82rem;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
        }

        .ia-typing-pill i {
            position: absolute;
            right: 1rem;
            color: #e5e7eb;
            font-size: 0.95rem;
        }

        .ia-message {
            margin-bottom: 0.78rem;
            display: flex;
            flex-direction: column;
            max-width: 80%;
        }

        .ia-message .meta {
            color: #64748b;
            font-size: 0.72rem;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }

        .ia-message .bubble {
            border-radius: 12px;
            padding: 0.68rem 0.78rem;
            font-size: 0.9rem;
            line-height: 1.42;
            box-shadow: 0 2px 7px rgba(15, 23, 42, 0.07);
            word-break: break-word;
        }

        .ia-message-row {
            display: flex;
            align-items: flex-end;
            gap: 0.42rem;
        }

        .ia-message-actions {
            position: relative;
            flex: 0 0 auto;
        }

        .ia-message-menu-toggle {
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s ease;
        }

        .ia-message-menu-toggle:hover,
        .ia-message-menu-toggle:focus-visible,
        .ia-message-actions.open .ia-message-menu-toggle {
            background: rgba(15, 23, 42, 0.15);
            color: #0f172a;
            outline: none;
        }

        .ia-message-menu {
            position: absolute;
            bottom: calc(100% + 0.45rem);
            min-width: 150px;
            background: #1f2937;
            color: #fff;
            border-radius: 14px;
            padding: 0.45rem;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.2);
            display: none;
            z-index: 12;
        }

        .ia-message-actions.open .ia-message-menu {
            display: block;
        }

        .ia-message.outgoing .ia-message-menu {
            right: 0;
        }

        .ia-message.incoming .ia-message-menu {
            left: 0;
        }

        .ia-message-menu button {
            width: 100%;
            border: none;
            background: transparent;
            color: inherit;
            text-align: left;
            font-size: 0.94rem;
            font-weight: 600;
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
            cursor: pointer;
        }

        .ia-message-menu button:hover,
        .ia-message-menu button:focus-visible {
            background: rgba(255, 255, 255, 0.1);
            outline: none;
        }

        .ia-message-state {
            margin-top: 0.28rem;
            font-size: 0.68rem;
            font-weight: 700;
            color: #0f766e;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .ia-message.is-pinned .bubble {
            box-shadow: 0 0 0 2px rgba(20, 184, 166, 0.18), 0 10px 22px rgba(15, 23, 42, 0.12);
        }

        @media (hover: hover) {
            .ia-message .ia-message-menu-toggle {
                opacity: 0;
                transform: translateY(4px);
            }

            .ia-message:hover .ia-message-menu-toggle,
            .ia-message:focus-within .ia-message-menu-toggle,
            .ia-message-actions.open .ia-message-menu-toggle {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .ia-message-text {
            margin-bottom: 0.45rem;
            white-space: pre-wrap;
        }

        .ia-message-text:last-child {
            margin-bottom: 0;
        }

        .ia-reply-chip {
            margin-bottom: 0.55rem;
            padding: 0.48rem 0.62rem;
            border-radius: 10px;
            border-left: 3px solid rgba(15, 118, 110, 0.88);
            background: rgba(15, 23, 42, 0.08);
        }

        .ia-message.incoming .ia-reply-chip {
            background: rgba(15, 23, 42, 0.06);
            border-left-color: #0f766e;
            color: #0f172a;
        }

        .ia-reply-author {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            margin-bottom: 0.18rem;
            opacity: 0.95;
        }

        .ia-reply-snippet {
            display: block;
            font-size: 0.8rem;
            line-height: 1.35;
            opacity: 0.92;
            word-break: break-word;
        }

        .ia-attachments {
            display: grid;
            gap: 0.45rem;
        }

        .ia-attachment-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #0f172a;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.14);
            border-radius: 8px;
            padding: 0.4rem 0.55rem;
            width: fit-content;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ia-attachment-link i {
            color: #0f766e;
            flex-shrink: 0;
        }

        .ia-attachment-link span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ia-message.incoming .ia-attachment-link {
            background: #f8fbff;
            border-color: #dce6f1;
            color: #1f3852;
        }

        .ia-message.outgoing .ia-attachment-link {
            background: #ffffff;
            border-color: rgba(255, 255, 255, 0.78);
            color: #0f172a;
        }

        .ia-attachment-image {
            max-width: min(300px, 100%);
            max-height: 220px;
            border-radius: 8px;
            border: 1px solid rgba(14, 23, 42, 0.08);
            display: block;
        }

        .ia-message.incoming {
            align-items: flex-start;
        }

        .ia-message.incoming .bubble {
            background: #fff;
            border: 1px solid #dce6f1;
            color: #17283a;
        }

        .ia-message.outgoing {
            margin-left: auto;
            align-items: flex-end;
        }

        .ia-message.outgoing .bubble {
            background: linear-gradient(135deg, #0f766e 0%, #0f766e 65%, #0d9488 100%);
            color: #fff;
            border: 1px solid #0f766e;
        }

        .ia-chat-compose {
            border-top: 1px solid var(--ia-border);
            padding: 0.85rem 1rem;
            background: #fff;
        }

        .ia-form-row {
            display: grid;
            grid-template-columns: 160px minmax(0, 1fr) auto auto auto;
            gap: 0.55rem;
            align-items: center;
        }

        .ia-input {
            padding: 0.5rem 0.62rem;
        }

        .ia-select,
        .ia-input {
            border: 1px solid #cdd9e5;
            border-radius: 9px;
            font-size: 0.9rem;
            padding: 0.63rem 0.72rem;
            color: var(--ia-text);
            background: #fff;
            width: 100%;
        }

        .ia-select:focus,
        .ia-input:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .ia-send {
            border: none;
            background: var(--ia-primary);
            color: #fff;
            border-radius: 9px;
            padding: 0.63rem 0.92rem;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .ia-send:hover {
            background: var(--ia-primary-dark);
        }

        .ia-attach {
            border: 1px solid #cdd9e5;
            background: #fff;
            color: #35516d;
            border-radius: 9px;
            padding: 0.63rem 0.78rem;
            cursor: pointer;
            font-size: 0.9rem;
            transition: 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .ia-attach:hover {
            background: #f1f6fb;
            border-color: #a9bfd5;
        }

        .ia-file-preview {
            margin-top: 0.55rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .ia-reply-preview {
            margin-top: 0.6rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.68rem 0.75rem;
            border-radius: 12px;
            border: 1px solid #cfe2f3;
            background: linear-gradient(135deg, #f7fbff 0%, #edf6fb 100%);
        }

        .ia-reply-preview[hidden] {
            display: none;
        }

        .ia-reply-preview-main {
            min-width: 0;
        }

        .ia-reply-preview-label {
            margin: 0 0 0.18rem;
            font-size: 0.72rem;
            font-weight: 800;
            color: #0f766e;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .ia-reply-preview-text {
            margin: 0;
            color: #1f2937;
            font-size: 0.83rem;
            line-height: 1.38;
            word-break: break-word;
        }

        .ia-reply-preview-close {
            flex: 0 0 auto;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            color: #475569;
            cursor: pointer;
        }

        .ia-reply-preview-close:hover,
        .ia-reply-preview-close:focus-visible {
            background: rgba(15, 23, 42, 0.14);
            outline: none;
        }

        .ia-file-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            border-radius: 999px;
            padding: 0.24rem 0.5rem;
            font-size: 0.74rem;
            font-weight: 700;
            max-width: 100%;
        }

        .ia-file-chip span {
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ia-file-chip button {
            border: none;
            background: transparent;
            color: #b42318;
            font-size: 0.8rem;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .ia-note {
            margin-top: 0.55rem;
            font-size: 0.75rem;
            color: #64748b;
        }

        .ia-modal-shell {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1100;
        }

        .ia-modal-shell.show {
            display: flex;
        }

        .ia-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
        }

        .ia-modal {
            position: relative;
            width: min(560px, calc(100vw - 2rem));
            max-height: calc(100vh - 2rem);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border: 1px solid #dbe6ef;
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
        }

        .ia-modal-head,
        .ia-modal-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid #e7edf4;
        }

        .ia-modal-actions {
            justify-content: flex-end;
            border-top: 1px solid #e7edf4;
            border-bottom: none;
        }

        .ia-modal-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
            color: #102a43;
        }

        .ia-modal-subtitle {
            margin: 0.25rem 0 0;
            font-size: 0.82rem;
            color: #64748b;
        }

        .ia-modal-close {
            width: 34px;
            height: 34px;
            border: 1px solid #d7e1ea;
            border-radius: 999px;
            background: #fff;
            color: #35516d;
            cursor: pointer;
        }

        .ia-modal-body {
            padding: 1rem 1.1rem;
            overflow: auto;
        }

        .ia-modal-field {
            margin-bottom: 0.9rem;
        }

        .ia-modal-field label {
            display: block;
            margin-bottom: 0.35rem;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .ia-modal-field input {
            width: 100%;
            border: 1px solid #d7e1ea;
            border-radius: 12px;
            padding: 0.8rem 0.95rem;
            font-size: 0.9rem;
            outline: none;
        }

        .ia-modal-field input:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .ia-modal-search {
            position: relative;
            margin-bottom: 0.9rem;
        }

        .ia-modal-search i {
            position: absolute;
            top: 50%;
            left: 0.85rem;
            transform: translateY(-50%);
            color: #7b8794;
        }

        .ia-modal-search input {
            width: 100%;
            border: 1px solid #d7e1ea;
            border-radius: 12px;
            padding: 0.8rem 0.95rem 0.8rem 2.45rem;
            font-size: 0.9rem;
            outline: none;
        }

        .ia-modal-search input:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .ia-user-picker-list {
            display: grid;
            gap: 0.65rem;
        }

        .ia-user-option {
            width: 100%;
            border: 1px solid #dce6ef;
            border-radius: 14px;
            background: #fff;
            padding: 0.85rem 0.95rem;
            text-align: left;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .ia-user-option:hover {
            border-color: #98d7c2;
            background: #f7fffb;
        }

        .ia-user-option.selected {
            border-color: #0f766e;
            background: #ecfdf5;
            box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.08);
        }

        .ia-user-option-top,
        .ia-user-option-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .ia-user-option-name {
            margin: 0;
            font-size: 0.92rem;
            font-weight: 800;
            color: #102a43;
        }

        .ia-user-option-meta {
            margin: 0.35rem 0 0;
            font-size: 0.78rem;
            color: #64748b;
        }

        .ia-user-option-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.55rem;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .ia-user-option-status {
            font-size: 0.74rem;
            font-weight: 700;
            color: #0f766e;
        }

        .ia-user-picker-empty {
            padding: 1rem;
            border: 1px dashed #d7e1ea;
            border-radius: 14px;
            text-align: center;
            color: #64748b;
            background: #f8fbff;
        }

        .ia-media-modal {
            width: min(760px, calc(100vw - 2rem));
        }

        .ia-media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.75rem;
        }

        .ia-media-card {
            border: 1px solid #dce6ef;
            border-radius: 12px;
            background: #ffffff;
            color: #102a43;
            overflow: hidden;
            text-decoration: none;
            min-width: 0;
        }

        .ia-media-thumb {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            display: block;
            background: #eef5fb;
        }

        .ia-media-file {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.85rem;
        }

        .ia-media-file i {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ia-media-info {
            min-width: 0;
            padding: 0.65rem 0.75rem 0.75rem;
        }

        .ia-media-file .ia-media-info {
            padding: 0;
        }

        .ia-media-name {
            margin: 0;
            color: inherit;
            font-size: 0.82rem;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ia-media-meta {
            margin: 0.25rem 0 0;
            color: #64748b;
            font-size: 0.72rem;
        }

        .ia-media-empty {
            border: 1px dashed #d7e1ea;
            border-radius: 14px;
            background: #f8fbff;
            color: #64748b;
            padding: 1.25rem;
            text-align: center;
            font-size: 0.88rem;
        }

        .ia-member-list {
            display: grid;
            gap: 0.7rem;
        }

        .ia-member-card {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            border: 1px solid #dce6ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 0.8rem 0.9rem;
            min-width: 0;
        }

        .ia-member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #0f766e 100%);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 900;
            flex-shrink: 0;
            text-transform: uppercase;
        }

        .ia-member-main {
            min-width: 0;
            flex: 1;
        }

        .ia-member-name {
            margin: 0;
            color: #102a43;
            font-size: 0.9rem;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ia-member-meta {
            margin: 0.22rem 0 0;
            color: #64748b;
            font-size: 0.76rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ia-member-side {
            display: grid;
            gap: 0.3rem;
            justify-items: end;
            flex-shrink: 0;
        }

        .ia-member-badge,
        .ia-member-status {
            border-radius: 999px;
            padding: 0.24rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .ia-member-badge {
            background: #eef2ff;
            color: #3730a3;
        }

        .ia-member-status {
            background: #ecfdf5;
            color: #047857;
        }

        .ia-member-status.inactive {
            background: #f1f5f9;
            color: #64748b;
        }

        .ia-member-remove {
            border: 1px solid #fecaca;
            border-radius: 999px;
            background: #fff5f5;
            color: #b91c1c;
            cursor: pointer;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 0.28rem 0.6rem;
            white-space: nowrap;
        }

        .ia-member-remove:hover {
            background: #fee2e2;
            border-color: #fca5a5;
        }

        .ia-modal-btn {
            border: 1px solid #d7e1ea;
            border-radius: 10px;
            padding: 0.72rem 1rem;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .ia-modal-btn.secondary {
            background: #fff;
            color: #35516d;
        }

        .ia-modal-btn.primary {
            background: #0f766e;
            border-color: #0f766e;
            color: #fff;
        }

        .ia-modal-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        @media (max-width: 1280px) {
            .ia-ops-desk {
                grid-template-columns: 1fr;
            }

            .ia-board {
                grid-template-columns: 320px minmax(0, 1fr);
            }

            .ia-user-status-panel {
                grid-column: 1 / -1;
                border-right-width: 1px;
                border-top: 4px solid #0f766e;
            }

            .ia-user-status-list {
                max-height: 260px;
            }
        }

        @media (max-width: 1080px) {
            .ia-ops-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ia-ops-metric {
                border-bottom: 1px solid var(--ia-border);
            }

            .ia-board {
                grid-template-columns: 1fr;
            }

            .ia-thread-list,
            .ia-user-status-list {
                max-height: 320px;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: calc(var(--app-header-height-mobile-1) + 1rem) 0.8rem 1rem;
            }

            .ia-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .ia-ops-panel-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .ia-ops-metrics,
            .ia-ops-lane {
                grid-template-columns: 1fr;
            }

            .ia-ops-metric {
                border-right: 0;
            }

            .ia-overview {
                grid-template-columns: 1fr;
            }

            .ia-form-row {
                grid-template-columns: 1fr;
            }

            .ia-chat-body {
                height: 390px;
            }

            .ia-active-incident-wrap {
                padding: 0.75rem 0.75rem 0;
            }

            .ia-active-incident-card {
                min-height: 0;
                padding-right: 2.6rem;
            }

            .ia-typing-indicator {
                padding: 0.6rem 0.75rem 0;
            }

            .ia-tabs {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ia-modal-head,
            .ia-modal-actions,
            .ia-user-option-top,
            .ia-user-option-bottom {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* Responsive command room: keep all three workspace columns usable without horizontal clipping. */
        .ia-board,
        .ia-list-panel,
        .ia-chat-panel,
        .ia-user-status-panel,
        .ia-chat-compose,
        .ia-form-row {
            min-width: 0;
        }

        .ia-list-panel,
        .ia-chat-panel,
        .ia-user-status-panel {
            max-width: 100%;
        }

        .ia-form-row > * {
            min-width: 0;
        }

        .ia-input,
        .ia-select,
        .ia-send,
        .ia-attach {
            max-width: 100%;
        }

        @media (max-width: 1180px) and (min-width: 1081px) {
            .ia-board {
                grid-template-columns: 270px minmax(0, 1fr) 240px;
                gap: 0.7rem;
            }

            .ia-form-row {
                grid-template-columns: 125px minmax(0, 1fr) auto auto auto;
            }

            .ia-send {
                padding-left: 0.7rem;
                padding-right: 0.7rem;
            }
        }

        @media (max-width: 1080px) {
            .ia-board {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1.55fr);
                gap: 0.75rem;
            }

            .ia-user-status-panel {
                grid-column: 1 / -1;
                border-right-width: 1px;
                border-top: 4px solid #0f766e;
            }

            .ia-user-status-list {
                max-height: 280px;
            }

            .ia-form-row {
                grid-template-columns: 125px minmax(0, 1fr) auto auto auto;
            }
        }

        @media (max-width: 820px) {
            .ia-board {
                grid-template-columns: 1fr;
            }

            .ia-list-panel,
            .ia-chat-panel,
            .ia-user-status-panel {
                width: 100%;
            }

            .ia-thread-list,
            .ia-user-status-list {
                max-height: 300px;
            }

            .ia-chat-body {
                height: min(55vh, 440px);
            }

            .ia-form-row {
                grid-template-columns: 1fr 1fr;
            }

            .ia-form-row .ia-select,
            .ia-form-row .ia-input {
                grid-column: 1 / -1;
            }

            .ia-form-row .ia-send {
                grid-column: 1 / -1;
                width: 100%;
            }

            .ia-form-row .ia-attach {
                width: 100%;
            }
        }

        @media (max-width: 560px) {
            .main-content {
                padding-left: 0.65rem;
                padding-right: 0.65rem;
            }

            .ia-shell {
                width: 100%;
            }

            .ia-head h1 {
                font-size: 1.35rem;
                overflow-wrap: anywhere;
            }

            .ia-head p {
                font-size: 0.82rem;
                line-height: 1.45;
            }

            .ia-pill {
                max-width: 100%;
                white-space: normal;
            }

            .ia-list-actions {
                grid-template-columns: minmax(0, 1fr) 32px !important;
            }

            .ia-chat-head {
                align-items: flex-start;
            }

            .ia-chat-title {
                font-size: 0.92rem;
            }

            .ia-chat-meta {
                overflow-wrap: anywhere;
            }

            .ia-chat-body {
                height: 52vh;
                min-height: 300px;
            }

            .ia-chat-compose {
                padding: 0.7rem;
            }

            .ia-note {
                font-size: 0.72rem;
                line-height: 1.45;
            }
        }

        .ia-incident-card {
            border: 1px solid #fed7aa;
            border-radius: 12px;
            background: linear-gradient(135deg, #fffbeb 0%, #fff7ed 100%);
            padding: 0.7rem 0.8rem;
            margin-bottom: 0.45rem;
            max-width: 320px;
        }

        .ia-message.outgoing .ia-incident-card {
            border-color: #99e6d0;
            background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 100%);
        }

        .ia-incident-card-head {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 0.4rem;
        }

        .ia-incident-card-head i {
            color: #b45309;
            font-size: 0.85rem;
        }

        .ia-incident-card-head .ia-incident-card-label {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #b45309;
        }

        .ia-incident-status {
            margin-left: auto;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .ia-incident-status.status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .ia-incident-status.status-accepted {
            background: #dcfce7;
            color: #166534;
        }

        .ia-incident-status.status-declined {
            background: #fee2e2;
            color: #991b1b;
        }

        .ia-incident-id {
            color: #7c2d12;
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.1;
            word-break: break-word;
        }

        .ia-incident-card-head + .ia-incident-id {
            margin-top: -0.1rem;
        }

        .ia-incident-meta {
            margin: 0.35rem 0 0.6rem;
            color: #78350f;
            font-size: 0.8rem;
            line-height: 1.4;
        }

        .ia-incident-meta .ia-incident-meta-type {
            font-weight: 700;
        }

        .ia-incident-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0.4rem;
        }

        .ia-incident-btn {
            border: 1px solid #fdba74;
            background: #fff;
            color: #9a3412;
            border-radius: 9px;
            padding: 0.5rem 0.3rem;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .ia-incident-btn:hover:not(:disabled) {
            background: #fff7ed;
            border-color: #fb923c;
        }

        .ia-incident-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .ia-incident-btn.accept {
            border-color: #86efac;
            color: #166534;
        }

        .ia-incident-btn.accept:hover:not(:disabled) {
            background: #f0fdf4;
            border-color: #4ade80;
        }

        .ia-incident-btn.decline {
            border-color: #fca5a5;
            color: #991b1b;
        }

        .ia-incident-btn.decline:hover:not(:disabled) {
            background: #fef2f2;
            border-color: #f87171;
        }

        .ia-incident-detail-grid {
            display: grid;
            gap: 0.7rem;
        }

        .ia-incident-detail-row {
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 0.5rem;
            align-items: start;
        }

        .ia-incident-detail-key {
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .ia-incident-detail-value {
            margin: 0;
            color: #102a43;
            font-size: 0.88rem;
            line-height: 1.4;
            word-break: break-word;
        }

        .ia-incident-detail-badge {
            display: inline-block;
            border-radius: 999px;
            padding: 0.2rem 0.6rem;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: capitalize;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal {
            background: #111827;
            border-color: #334155;
            color: #e5e7eb;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal-head {
            background: #0f172a;
            border-bottom-color: #475569;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal-title {
            color: #f8fafc;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal-subtitle {
            color: #cbd5e1;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal-close {
            background: #1e293b;
            border-color: #475569;
            color: #e2e8f0;
        }

        [data-theme="dark"] #incidentDetailModal .ia-modal-close:hover,
        [data-theme="dark"] #incidentDetailModal .ia-modal-close:focus-visible {
            background: #334155;
            color: #ffffff;
        }

        [data-theme="dark"] #incidentDetailModal .ia-media-empty {
            background: #0f172a;
            border-color: #475569;
            color: #cbd5e1;
        }

        [data-theme="dark"] #incidentDetailModal .ia-incident-detail-key {
            color: #94a3b8;
        }

        [data-theme="dark"] #incidentDetailModal .ia-incident-detail-value {
            color: #e2e8f0;
        }

        [data-theme="dark"] #incidentDetailModal .ia-incident-detail-badge {
            border: 1px solid rgba(226, 232, 240, 0.28);
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.18);
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container ia-shell">
            <section class="ia-head">
                <div>
                    <h1>Inter-Agency Conversations</h1>
                    <p>Unified communication panel para sa departments at responders during active incidents.</p>
                </div>
                <div class="ia-pill">
                    <i class="fas fa-signal"></i> Coordination Hub Live
                </div>
            </section>

            <section class="ia-overview">
                <article class="ia-stat">
                    <div class="ia-stat-label">Total Threads</div>
                    <div class="ia-stat-value" id="iaTotalThreads">0</div>
                </article>
                <article class="ia-stat">
                    <div class="ia-stat-label">Active Responders</div>
                    <div class="ia-stat-value" id="iaActiveResponders">0</div>
                </article>
                <article class="ia-stat">
                    <div class="ia-stat-label">Unread Messages</div>
                    <div class="ia-stat-value" id="iaUnreadCount">0</div>
                </article>
                <article class="ia-stat">
                    <div class="ia-stat-label">External Inbox</div>
                    <div class="ia-stat-value" id="iaExternalInboxCount">0</div>
                </article>
            </section>

            <section class="ia-ops-desk ia-ops-desk-compact" id="iaOperationsDesk" aria-label="Inter-agency operations desk">
                <div class="ia-ops-panel ia-ops-overview">
                    <div class="ia-ops-panel-head">
                        <div>
                            <span class="ia-ops-eyebrow">Operations</span>
                            <h2 class="ia-ops-title">Command Operations</h2>
                            <p class="ia-ops-sub">Open the operational workspace that needs attention.</p>
                        </div>
                        <span class="ia-ops-live" id="iaOpsUpdated"><i class="fas fa-rotate"></i> Syncing</span>
                    </div>
                    <div class="ia-ops-metrics">
                        <article class="ia-ops-metric">
                            <div class="ia-ops-metric-label">External Queue</div>
                            <div class="ia-ops-metric-value" id="iaOpsExternal">0</div>
                            <div class="ia-ops-metric-note">Incoming reports waiting</div>
                        </article>
                        <article class="ia-ops-metric">
                            <div class="ia-ops-metric-label">Active Incidents</div>
                            <div class="ia-ops-metric-value" id="iaOpsActiveIncidents">0</div>
                            <div class="ia-ops-metric-note">Open command candidates</div>
                        </article>
                        <article class="ia-ops-metric">
                            <div class="ia-ops-metric-label">New Tips</div>
                            <div class="ia-ops-metric-value" id="iaOpsNewTips">0</div>
                            <div class="ia-ops-metric-note">Need verification</div>
                        </article>
                        <article class="ia-ops-metric">
                            <div class="ia-ops-metric-label">High-Risk Events</div>
                            <div class="ia-ops-metric-value" id="iaOpsHighEvents">0</div>
                            <div class="ia-ops-metric-note">High or critical hazard</div>
                        </article>
                        <article class="ia-ops-metric">
                            <div class="ia-ops-metric-label">Standby Units</div>
                            <div class="ia-ops-metric-value" id="iaOpsStandbyUnits">0</div>
                            <div class="ia-ops-metric-note">Required for events</div>
                        </article>
                    </div>

                <section class="ia-module-launcher ia-ops-module-grid" aria-label="Command Operations modules">
                    <button type="button" class="ia-module-btn" data-module-open="iaCommandCenter" data-module-title="Inter-Agency Command Center" data-module-subtitle="Incident intelligence, tasking, broadcasts, acknowledgements, and audit trail.">
                        <span class="ia-module-btn-icon"><i class="fas fa-tower-broadcast"></i></span>
                        <span class="ia-module-btn-main">
                            <span class="ia-module-btn-title">Command Center</span>
                            <span class="ia-module-btn-sub">Active incident rooms</span>
                        </span>
                        <span class="ia-module-badge" data-module-badge="iaCommandCenter">0</span>
                    </button>
                    <button type="button" class="ia-module-btn" data-module-open="iaEventCoordination" data-module-title="Event Coordination" data-module-subtitle="Shared event profiles, standby needs, hazards, and readiness checklist.">
                        <span class="ia-module-btn-icon"><i class="fas fa-calendar-check"></i></span>
                        <span class="ia-module-btn-main">
                            <span class="ia-module-btn-title">Event Coordination</span>
                            <span class="ia-module-btn-sub">High-risk and standby events</span>
                        </span>
                        <span class="ia-module-badge" data-module-badge="iaEventCoordination">0</span>
                    </button>
                    <button type="button" class="ia-module-btn" data-module-open="iaAnonymousTipInbox" data-module-title="Anonymous Tip Inbox" data-module-subtitle="Incoming anonymous tips, evidence, review status, and outcomes.">
                        <span class="ia-module-btn-icon"><i class="fas fa-user-secret"></i></span>
                        <span class="ia-module-btn-main">
                            <span class="ia-module-btn-title">Anonymous Tip Box</span>
                            <span class="ia-module-btn-sub">Tips needing review</span>
                        </span>
                        <span class="ia-module-badge" data-module-badge="iaAnonymousTipInbox">0</span>
                    </button>
                    <button type="button" class="ia-module-btn" data-module-open="iaExternalIncidentInbox" data-module-title="External Incident Inbox" data-module-subtitle="Incoming incidents and call transfers from connected systems.">
                        <span class="ia-module-btn-icon"><i class="fas fa-inbox"></i></span>
                        <span class="ia-module-btn-main">
                            <span class="ia-module-btn-title">External Incident Inbox</span>
                            <span class="ia-module-btn-sub">Incoming external reports</span>
                        </span>
                        <span class="ia-module-badge" data-module-badge="iaExternalIncidentInbox">0</span>
                    </button>
                </section>

                </div>

                <aside class="ia-ops-panel ia-incident-monitor" id="iaIncidentMonitorPanel" aria-label="Incident Monitor"></aside>
            </section>

            <div class="ia-module-stage" id="iaModuleStage">
                <section class="ia-command-center" id="iaCommandCenter" aria-label="Inter-Agency Command Center"></section>

                <section class="ia-event-section" id="iaEventCoordination" aria-label="Event Coordination"></section>

                <section class="ia-tip-section" id="iaAnonymousTipInbox" aria-label="Anonymous Tip Inbox"></section>

                <section class="ia-external-inbox" id="iaExternalIncidentInbox" aria-label="Incoming external incidents">
                    <div class="ia-external-inbox-head">
                        <button type="button" class="ia-external-inbox-toggle" id="iaExternalInboxToggle" aria-expanded="false" aria-controls="iaExternalInboxDropdown">
                            <span class="ia-external-inbox-title-wrap">
                                <span class="ia-external-inbox-title">External Incident Inbox</span>
                                <span class="ia-external-inbox-sub">Incoming incidents and call transfers from connected systems.</span>
                            </span>
                            <span class="ia-external-inbox-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                        </button>
                    </div>
                    <div class="ia-external-inbox-dropdown" id="iaExternalInboxDropdown" hidden>
                        <div class="ia-external-inbox-actions">
                            <span class="ia-external-inbox-badge" id="iaExternalInboxBadge">Loading</span>
                            <button type="button" class="ia-external-inbox-refresh" id="iaExternalInboxRefresh" title="Refresh external inbox" aria-label="Refresh external inbox">
                                <i class="fas fa-rotate-right"></i>
                            </button>
                        </div>
                        <div class="ia-external-inbox-list" id="iaExternalInboxList" aria-live="polite">
                            <div class="ia-external-inbox-empty">Loading external incidents...</div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="ia-module-modal" id="iaModuleModal" aria-hidden="true">
                <button type="button" class="ia-module-modal-backdrop" data-module-close aria-label="Close module modal"></button>
                <div class="ia-module-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="iaModuleModalTitle">
                    <div class="ia-module-modal-head">
                        <div>
                            <h2 class="ia-module-modal-title" id="iaModuleModalTitle">Inter-Agency Module</h2>
                            <p class="ia-module-modal-sub" id="iaModuleModalSub">Operational tools and inboxes.</p>
                        </div>
                        <button type="button" class="ia-module-modal-close" data-module-close aria-label="Close module modal">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="ia-module-modal-body" id="iaModuleModalBody"></div>
                </div>
            </div>
            <div class="ia-module-toast" id="iaModuleToast" aria-live="polite"></div>

            <section class="ia-board">
                <aside class="ia-list-panel">
                    <div class="ia-list-top">
                        <div class="ia-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="threadSearchInput" placeholder="Search department, responder, or group...">
                        </div>
                        <div class="ia-list-actions">
                            <div class="ia-tabs">
                                <button type="button" class="ia-tab active" data-filter="all">All</button>
                                <button type="button" class="ia-tab" data-filter="department">Departments</button>
                                <button type="button" class="ia-tab" data-filter="responder">Responders</button>
                                <button type="button" class="ia-tab" data-filter="group">Groups</button>
                            </div>
                            <button type="button" class="ia-add-thread-btn" id="addThreadBtn" title="Add user conversation" aria-label="Add user conversation">
                                <i class="fas fa-plus"></i>
                            </button>
                            <button type="button" class="ia-add-thread-btn ia-group-thread-btn" id="createGroupBtn" title="Create group chat" aria-label="Create group chat">
                                <i class="fas fa-users"></i>
                            </button>
                        </div>
                    </div>
                    <div class="ia-thread-list" id="threadList" aria-live="polite"></div>
                </aside>

                <section class="ia-chat-panel">
                    <div class="ia-chat-head" id="chatHeader"></div>
                    <div class="ia-active-incident-wrap" id="activeIncidentBanner" hidden></div>
                    <div class="ia-typing-indicator" id="typingIndicator" hidden></div>
                    <div class="ia-chat-body" id="chatTimeline"></div>
                    <div class="ia-chat-compose">
                        <form id="chatForm">
                            <div class="ia-form-row">
                                <select id="messagePriority" class="ia-select">
                                    <option value="Routine">Routine</option>
                                    <option value="Urgent">Urgent</option>
                                    <option value="Critical">Critical</option>
                                </select>
                                <input type="text" id="messageInput" class="ia-input" maxlength="260" placeholder="Type a message">
                                <button type="button" class="ia-attach" id="sendIncidentBtn" title="Send incident card to conversation">
                                    <i class="fas fa-bullhorn"></i>
                                </button>
                                <button type="button" class="ia-attach" id="attachFileBtn" title="Attach files/images">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <button type="submit" class="ia-send">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                                <input type="file" id="messageFiles" multiple hidden>
                            </div>
                            <div id="replyPreview" class="ia-reply-preview" hidden></div>
                            <div id="filePreview" class="ia-file-preview"></div>
                        </form>
                        <div class="ia-note">Tip: choose thread sa kaliwa, then send incident update directly to that department/responder.</div>
                    </div>
                </section>

                <aside class="ia-user-status-panel" aria-label="User status list">
                    <div class="ia-user-status-head">
                        <p class="ia-user-status-title">Users</p>
                        <p class="ia-user-status-sub">Responder availability status</p>
                    </div>
                    <div class="ia-user-status-list" id="userStatusList" aria-live="polite">
                        <div class="ia-empty-list">Loading users...</div>
                    </div>
                </aside>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <div class="ia-modal-shell" id="addThreadModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-add-thread></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="addThreadModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="addThreadModalTitle">Add Conversation</p>
                    <p class="ia-modal-subtitle">Choose which active user will be added to the thread list.</p>
                </div>
                <button type="button" class="ia-modal-close" id="addThreadModalCloseBtn" aria-label="Close add thread modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="addThreadSearchInput" placeholder="Search by name, email, or role...">
                </div>
                <div class="ia-user-picker-list" id="addThreadUserList"></div>
            </div>
            <div class="ia-modal-actions">
                <button type="button" class="ia-modal-btn secondary" id="addThreadCancelBtn">Cancel</button>
                <button type="button" class="ia-modal-btn primary" id="addThreadConfirmBtn" disabled>Add</button>
            </div>
        </div>
    </div>

    <div class="ia-modal-shell" id="groupChatModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-group-chat></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="groupChatModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="groupChatModalTitle">Create Group Chat</p>
                    <p class="ia-modal-subtitle">Name the group and choose active users to include.</p>
                </div>
                <button type="button" class="ia-modal-close" id="groupChatModalCloseBtn" aria-label="Close group chat modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-modal-field">
                    <label for="groupChatNameInput">Group name</label>
                    <input type="text" id="groupChatNameInput" maxlength="120" placeholder="e.g. Night Shift Coordination">
                </div>
                <div class="ia-modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="groupChatSearchInput" placeholder="Search users to add...">
                </div>
                <div class="ia-user-picker-list" id="groupChatUserList"></div>
            </div>
            <div class="ia-modal-actions">
                <button type="button" class="ia-modal-btn secondary" id="groupChatCancelBtn">Cancel</button>
                <button type="button" class="ia-modal-btn primary" id="groupChatCreateBtn" disabled>Create Group</button>
            </div>
        </div>
    </div>

    <div class="ia-modal-shell" id="conversationMediaModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-conversation-media></div>
        <div class="ia-modal ia-media-modal" role="dialog" aria-modal="true" aria-labelledby="conversationMediaModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="conversationMediaModalTitle">Conversation Media</p>
                    <p class="ia-modal-subtitle" id="conversationMediaModalSubtitle">Images and files from this conversation.</p>
                </div>
                <button type="button" class="ia-modal-close" id="conversationMediaModalCloseBtn" aria-label="Close media modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body" id="conversationMediaModalBody"></div>
        </div>
    </div>

    <div class="ia-modal-shell" id="groupMembersModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-group-members></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="groupMembersModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="groupMembersModalTitle">Members</p>
                    <p class="ia-modal-subtitle" id="groupMembersModalSubtitle">People included in this group chat.</p>
                </div>
                <button type="button" class="ia-modal-close" id="groupMembersModalCloseBtn" aria-label="Close members modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body" id="groupMembersModalBody"></div>
        </div>
    </div>

    <div class="ia-modal-shell" id="addGroupMemberModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-add-group-member></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="addGroupMemberModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="addGroupMemberModalTitle">Add Member</p>
                    <p class="ia-modal-subtitle" id="addGroupMemberModalSubtitle">Choose an active user to include in this group chat.</p>
                </div>
                <button type="button" class="ia-modal-close" id="addGroupMemberModalCloseBtn" aria-label="Close add member modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="addGroupMemberSearchInput" placeholder="Search users to add...">
                </div>
                <div class="ia-user-picker-list" id="addGroupMemberUserList"></div>
            </div>
            <div class="ia-modal-actions">
                <button type="button" class="ia-modal-btn secondary" id="addGroupMemberCancelBtn">Cancel</button>
                <button type="button" class="ia-modal-btn primary" id="addGroupMemberConfirmBtn" disabled>Add Member</button>
            </div>
        </div>
    </div>

    <div class="ia-modal-shell" id="groupMemberRequestsModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-group-member-requests></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="groupMemberRequestsModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="groupMemberRequestsModalTitle">Member Requests</p>
                    <p class="ia-modal-subtitle" id="groupMemberRequestsModalSubtitle">Pending dispatcher requests for this group.</p>
                </div>
                <button type="button" class="ia-modal-close" id="groupMemberRequestsModalCloseBtn" aria-label="Close member requests modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body" id="groupMemberRequestsModalBody"></div>
        </div>
    </div>

    <div class="ia-modal-shell" id="incidentPickerModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-incident-picker></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="incidentPickerModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="incidentPickerModalTitle">Send Incident</p>
                    <p class="ia-modal-subtitle" id="incidentPickerModalSubtitle">Pick an incident to share as a card in this conversation.</p>
                </div>
                <button type="button" class="ia-modal-close" id="incidentPickerModalCloseBtn" aria-label="Close incident picker">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="incidentPickerSearchInput" placeholder="Search by ID, code, type, or location...">
                </div>
                <div class="ia-user-picker-list" id="incidentPickerList"></div>
            </div>
            <div class="ia-modal-actions">
                <button type="button" class="ia-modal-btn secondary" id="incidentPickerCancelBtn">Cancel</button>
            </div>
        </div>
    </div>

    <div class="ia-modal-shell" id="incidentDetailModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-incident-detail></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="incidentDetailModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="incidentDetailModalTitle">Incident Details</p>
                    <p class="ia-modal-subtitle" id="incidentDetailModalSubtitle">Shared incident information.</p>
                </div>
                <button type="button" class="ia-modal-close" id="incidentDetailModalCloseBtn" aria-label="Close incident details">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body" id="incidentDetailModalBody"></div>
        </div>
    </div>

    <script>
        (function () {
            const state = {
                threads: [],
                filter: 'all',
                query: '',
                activeId: '',
                lastIdByDept: {},
                currentUser: { id: 0, name: 'Admin' },
                pendingFiles: [],
                messageItems: {},
                pinnedMessages: {},
                replyTo: null,
                addThreadUsers: [],
                addThreadQuery: '',
                addThreadSelectedId: 0,
                addThreadLoading: false,
                groupUsers: [],
                groupQuery: '',
                groupSelectedIds: [],
                groupLoading: false,
                addGroupMemberUsers: [],
                addGroupMemberQuery: '',
                addGroupMemberSelectedId: 0,
                addGroupMemberGroupId: 0,
                addGroupMemberLoading: false,
                userStatuses: [],
                chatSettingsOpen: false,
                poller: null,
                presencePoller: null,
                activeIncident: null,
                typingUsers: [],
                typingTimer: null,
                typingHeartbeat: null,
                typingActive: false,
                typingPayload: null,
                incidentPickerItems: [],
                incidentPickerQuery: '',
                incidentPickerLoading: false
            };

            const threadListEl = document.getElementById('threadList');
            const chatHeaderEl = document.getElementById('chatHeader');
            const chatTimelineEl = document.getElementById('chatTimeline');
            const activeIncidentBannerEl = document.getElementById('activeIncidentBanner');
            const typingIndicatorEl = document.getElementById('typingIndicator');
            const threadSearchInput = document.getElementById('threadSearchInput');
            const messageInput = document.getElementById('messageInput');
            const messagePriority = document.getElementById('messagePriority');
            const chatForm = document.getElementById('chatForm');
            const attachFileBtn = document.getElementById('attachFileBtn');
            const messageFilesInput = document.getElementById('messageFiles');
            const sendIncidentBtn = document.getElementById('sendIncidentBtn');
            const incidentPickerModal = document.getElementById('incidentPickerModal');
            const incidentPickerSearchInput = document.getElementById('incidentPickerSearchInput');
            const incidentPickerList = document.getElementById('incidentPickerList');
            const incidentPickerCancelBtn = document.getElementById('incidentPickerCancelBtn');
            const incidentPickerModalCloseBtn = document.getElementById('incidentPickerModalCloseBtn');
            const incidentDetailModal = document.getElementById('incidentDetailModal');
            const incidentDetailModalBody = document.getElementById('incidentDetailModalBody');
            const incidentDetailModalSubtitle = document.getElementById('incidentDetailModalSubtitle');
            const incidentDetailModalCloseBtn = document.getElementById('incidentDetailModalCloseBtn');
            const replyPreviewEl = document.getElementById('replyPreview');
            const filePreviewEl = document.getElementById('filePreview');
            const addThreadBtn = document.getElementById('addThreadBtn');
            const addThreadModal = document.getElementById('addThreadModal');
            const addThreadSearchInput = document.getElementById('addThreadSearchInput');
            const addThreadUserList = document.getElementById('addThreadUserList');
            const addThreadCancelBtn = document.getElementById('addThreadCancelBtn');
            const addThreadConfirmBtn = document.getElementById('addThreadConfirmBtn');
            const addThreadModalCloseBtn = document.getElementById('addThreadModalCloseBtn');
            const createGroupBtn = document.getElementById('createGroupBtn');
            const groupChatModal = document.getElementById('groupChatModal');
            const groupChatNameInput = document.getElementById('groupChatNameInput');
            const groupChatSearchInput = document.getElementById('groupChatSearchInput');
            const groupChatUserList = document.getElementById('groupChatUserList');
            const groupChatCancelBtn = document.getElementById('groupChatCancelBtn');
            const groupChatCreateBtn = document.getElementById('groupChatCreateBtn');
            const groupChatModalCloseBtn = document.getElementById('groupChatModalCloseBtn');
            const conversationMediaModal = document.getElementById('conversationMediaModal');
            const conversationMediaModalTitle = document.getElementById('conversationMediaModalTitle');
            const conversationMediaModalSubtitle = document.getElementById('conversationMediaModalSubtitle');
            const conversationMediaModalBody = document.getElementById('conversationMediaModalBody');
            const conversationMediaModalCloseBtn = document.getElementById('conversationMediaModalCloseBtn');
            const groupMembersModal = document.getElementById('groupMembersModal');
            const groupMembersModalTitle = document.getElementById('groupMembersModalTitle');
            const groupMembersModalSubtitle = document.getElementById('groupMembersModalSubtitle');
            const groupMembersModalBody = document.getElementById('groupMembersModalBody');
            const groupMembersModalCloseBtn = document.getElementById('groupMembersModalCloseBtn');
            const addGroupMemberModal = document.getElementById('addGroupMemberModal');
            const addGroupMemberModalSubtitle = document.getElementById('addGroupMemberModalSubtitle');
            const addGroupMemberSearchInput = document.getElementById('addGroupMemberSearchInput');
            const addGroupMemberUserList = document.getElementById('addGroupMemberUserList');
            const addGroupMemberCancelBtn = document.getElementById('addGroupMemberCancelBtn');
            const addGroupMemberConfirmBtn = document.getElementById('addGroupMemberConfirmBtn');
            const addGroupMemberModalCloseBtn = document.getElementById('addGroupMemberModalCloseBtn');
            const groupMemberRequestsModal = document.getElementById('groupMemberRequestsModal');
            const groupMemberRequestsModalSubtitle = document.getElementById('groupMemberRequestsModalSubtitle');
            const groupMemberRequestsModalBody = document.getElementById('groupMemberRequestsModalBody');
            const groupMemberRequestsModalCloseBtn = document.getElementById('groupMemberRequestsModalCloseBtn');
            const totalThreadsEl = document.getElementById('iaTotalThreads');
            const activeRespondersEl = document.getElementById('iaActiveResponders');
            const unreadCountEl = document.getElementById('iaUnreadCount');
            const userStatusListEl = document.getElementById('userStatusList');

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function escapeAttr(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }

            function formatBytes(bytes) {
                const n = Number(bytes || 0);
                if (n < 1024) return `${n} B`;
                if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
                return `${(n / (1024 * 1024)).toFixed(1)} MB`;
            }

            function formatRole(value) {
                return String(value || 'user')
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, (char) => char.toUpperCase());
            }

            function initialsForName(value) {
                const words = String(value || 'User').trim().split(/\s+/).filter(Boolean);
                if (!words.length) return 'U';
                return words.slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase();
            }

            function threadChannelLabel(item) {
                if (String(item.thread_kind || '') === 'group') {
                    const count = Number(item.member_count || 0);
                    return count > 0 ? `Group Chat · ${count} member(s)` : 'Group Chat';
                }
                if (String(item.thread_kind || '') === 'external') {
                    return 'External System';
                }
                if (String(item.kind || '') === 'department') {
                    return 'Department Channel';
                }
                return `${formatRole(item.role || 'responder')} Channel`;
            }

            function isAddThreadModalOpen() {
                return !!(addThreadModal && addThreadModal.classList.contains('show'));
            }

            function isGroupChatModalOpen() {
                return !!(groupChatModal && groupChatModal.classList.contains('show'));
            }

            function selectedAddThreadUser() {
                return state.addThreadUsers.find((item) => Number(item.id) === Number(state.addThreadSelectedId)) || null;
            }

            function syncAddThreadConfirmState() {
                if (!addThreadConfirmBtn) return;
                addThreadConfirmBtn.disabled = state.addThreadLoading || !selectedAddThreadUser();
                addThreadConfirmBtn.textContent = state.addThreadLoading ? 'Adding...' : 'Add';
            }

            function renderAddThreadUsers() {
                if (!addThreadUserList) return;

                const query = String(state.addThreadQuery || '').trim().toLowerCase();
                const items = state.addThreadUsers.filter((item) => {
                    if (!query) return true;
                    const hay = `${item.name || ''} ${item.email || ''} ${item.role || ''}`.toLowerCase();
                    return hay.includes(query);
                });

                if (items.length && !items.some((item) => Number(item.id) === Number(state.addThreadSelectedId))) {
                    state.addThreadSelectedId = Number(items[0].id);
                }
                if (!items.length) {
                    state.addThreadSelectedId = 0;
                }

                if (state.addThreadLoading && !state.addThreadUsers.length) {
                    addThreadUserList.innerHTML = '<div class="ia-user-picker-empty">Loading active users...</div>';
                    syncAddThreadConfirmState();
                    return;
                }

                if (!items.length) {
                    const emptyLabel = state.addThreadUsers.length
                        ? 'No matching active users found.'
                        : 'All active users already have conversation threads.';
                    addThreadUserList.innerHTML = `<div class="ia-user-picker-empty">${escapeHtml(emptyLabel)}</div>`;
                    syncAddThreadConfirmState();
                    return;
                }

                addThreadUserList.innerHTML = items.map((item) => {
                    const isSelected = Number(item.id) === Number(state.addThreadSelectedId);
                    return `
                        <button type="button" class="ia-user-option ${isSelected ? 'selected' : ''}" data-add-thread-user="${escapeAttr(item.id)}" aria-pressed="${isSelected ? 'true' : 'false'}">
                            <div class="ia-user-option-top">
                                <div>
                                    <p class="ia-user-option-name">${escapeHtml(item.name || ('User #' + item.id))}</p>
                                    <p class="ia-user-option-meta">${escapeHtml(item.email || 'No email provided')}</p>
                                </div>
                                <span class="ia-user-option-badge">${escapeHtml(formatRole(item.role || 'user'))}</span>
                            </div>
                            <div class="ia-user-option-bottom">
                                <span class="ia-user-option-meta">ID ${escapeHtml(item.id)}</span>
                                <span class="ia-user-option-status">Active account</span>
                            </div>
                        </button>
                    `;
                }).join('');

                syncAddThreadConfirmState();
            }

            function openAddThreadModal() {
                if (!addThreadModal) return;
                addThreadModal.hidden = false;
                addThreadModal.setAttribute('aria-hidden', 'false');
                addThreadModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                syncAddThreadConfirmState();
                window.setTimeout(() => {
                    if (addThreadSearchInput) addThreadSearchInput.focus();
                }, 0);
            }

            function closeAddThreadModal() {
                if (!addThreadModal) return;
                addThreadModal.classList.remove('show');
                addThreadModal.setAttribute('aria-hidden', 'true');
                addThreadModal.hidden = true;
                document.body.style.overflow = '';
                state.addThreadLoading = false;
                state.addThreadQuery = '';
                state.addThreadSelectedId = 0;
                if (addThreadSearchInput) addThreadSearchInput.value = '';
                syncAddThreadConfirmState();
                if (addThreadBtn) addThreadBtn.focus();
            }

            function selectedGroupUserIds() {
                return state.groupSelectedIds.map((id) => Number(id)).filter((id) => id > 0);
            }

            function syncGroupCreateState() {
                if (!groupChatCreateBtn) return;
                const hasName = groupChatNameInput && groupChatNameInput.value.trim().length > 0;
                groupChatCreateBtn.disabled = state.groupLoading || !hasName || selectedGroupUserIds().length < 2;
                groupChatCreateBtn.textContent = state.groupLoading ? 'Creating...' : 'Create Group';
            }

            function renderGroupUsers() {
                if (!groupChatUserList) return;

                const query = String(state.groupQuery || '').trim().toLowerCase();
                const items = state.groupUsers.filter((item) => {
                    if (!query) return true;
                    const hay = `${item.name || ''} ${item.email || ''} ${item.role || ''}`.toLowerCase();
                    return hay.includes(query);
                });

                if (state.groupLoading && !state.groupUsers.length) {
                    groupChatUserList.innerHTML = '<div class="ia-user-picker-empty">Loading active users...</div>';
                    syncGroupCreateState();
                    return;
                }

                if (!items.length) {
                    groupChatUserList.innerHTML = '<div class="ia-user-picker-empty">No matching active users found.</div>';
                    syncGroupCreateState();
                    return;
                }

                groupChatUserList.innerHTML = items.map((item) => {
                    const isSelected = state.groupSelectedIds.some((id) => Number(id) === Number(item.id));
                    return `
                        <button type="button" class="ia-user-option ${isSelected ? 'selected' : ''}" data-group-user="${escapeAttr(item.id)}" aria-pressed="${isSelected ? 'true' : 'false'}">
                            <div class="ia-user-option-top">
                                <div>
                                    <p class="ia-user-option-name">${escapeHtml(item.name || ('User #' + item.id))}</p>
                                    <p class="ia-user-option-meta">${escapeHtml(item.email || 'No email provided')}</p>
                                </div>
                                <span class="ia-user-option-badge">${escapeHtml(formatRole(item.role || 'user'))}</span>
                            </div>
                            <div class="ia-user-option-bottom">
                                <span class="ia-user-option-meta">ID ${escapeHtml(item.id)}</span>
                                <span class="ia-user-option-status">${isSelected ? 'Selected' : 'Active account'}</span>
                            </div>
                        </button>
                    `;
                }).join('');
                syncGroupCreateState();
            }

            function openGroupChatModal() {
                if (!groupChatModal) return;
                groupChatModal.hidden = false;
                groupChatModal.setAttribute('aria-hidden', 'false');
                groupChatModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                syncGroupCreateState();
                window.setTimeout(() => {
                    if (groupChatNameInput) groupChatNameInput.focus();
                }, 0);
            }

            function closeGroupChatModal() {
                if (!groupChatModal) return;
                groupChatModal.classList.remove('show');
                groupChatModal.setAttribute('aria-hidden', 'true');
                groupChatModal.hidden = true;
                document.body.style.overflow = '';
                state.groupLoading = false;
                state.groupQuery = '';
                state.groupSelectedIds = [];
                if (groupChatNameInput) groupChatNameInput.value = '';
                if (groupChatSearchInput) groupChatSearchInput.value = '';
                syncGroupCreateState();
                if (createGroupBtn) createGroupBtn.focus();
            }

            function selectedAddGroupMemberUser() {
                return state.addGroupMemberUsers.find((item) => Number(item.id) === Number(state.addGroupMemberSelectedId)) || null;
            }

            function syncAddGroupMemberState() {
                if (!addGroupMemberConfirmBtn) return;
                addGroupMemberConfirmBtn.disabled = state.addGroupMemberLoading || !selectedAddGroupMemberUser();
                addGroupMemberConfirmBtn.textContent = state.addGroupMemberLoading ? 'Adding...' : 'Add Member';
            }

            function renderAddGroupMemberUsers() {
                if (!addGroupMemberUserList) return;

                const query = String(state.addGroupMemberQuery || '').trim().toLowerCase();
                const items = state.addGroupMemberUsers.filter((item) => {
                    if (!query) return true;
                    return `${item.name || ''} ${item.email || ''} ${item.role || ''}`.toLowerCase().includes(query);
                });

                if (items.length && !items.some((item) => Number(item.id) === Number(state.addGroupMemberSelectedId))) {
                    state.addGroupMemberSelectedId = Number(items[0].id);
                }
                if (!items.length) state.addGroupMemberSelectedId = 0;

                if (state.addGroupMemberLoading && !state.addGroupMemberUsers.length) {
                    addGroupMemberUserList.innerHTML = '<div class="ia-user-picker-empty">Loading active users...</div>';
                    syncAddGroupMemberState();
                    return;
                }

                if (!items.length) {
                    addGroupMemberUserList.innerHTML = '<div class="ia-user-picker-empty">All active users are already members of this group.</div>';
                    syncAddGroupMemberState();
                    return;
                }

                addGroupMemberUserList.innerHTML = items.map((item) => {
                    const isSelected = Number(item.id) === Number(state.addGroupMemberSelectedId);
                    return `
                        <button type="button" class="ia-user-option ${isSelected ? 'selected' : ''}" data-add-group-member-user="${escapeAttr(item.id)}" aria-pressed="${isSelected ? 'true' : 'false'}">
                            <div class="ia-user-option-top">
                                <div>
                                    <p class="ia-user-option-name">${escapeHtml(item.name || ('User #' + item.id))}</p>
                                    <p class="ia-user-option-meta">${escapeHtml(item.email || 'No email provided')}</p>
                                </div>
                                <span class="ia-user-option-badge">${escapeHtml(formatRole(item.role || 'user'))}</span>
                            </div>
                            <div class="ia-user-option-bottom">
                                <span class="ia-user-option-meta">ID ${escapeHtml(item.id)}</span>
                                <span class="ia-user-option-status">${isSelected ? 'Selected' : 'Active account'}</span>
                            </div>
                        </button>
                    `;
                }).join('');
                syncAddGroupMemberState();
            }

            function closeAddGroupMemberModal() {
                if (!addGroupMemberModal) return;
                addGroupMemberModal.classList.remove('show');
                addGroupMemberModal.setAttribute('aria-hidden', 'true');
                addGroupMemberModal.hidden = true;
                document.body.style.overflow = '';
                state.addGroupMemberUsers = [];
                state.addGroupMemberQuery = '';
                state.addGroupMemberSelectedId = 0;
                state.addGroupMemberGroupId = 0;
                state.addGroupMemberLoading = false;
                if (addGroupMemberSearchInput) addGroupMemberSearchInput.value = '';
                if (addGroupMemberUserList) addGroupMemberUserList.innerHTML = '';
                syncAddGroupMemberState();
            }

            async function openAddGroupMemberModal() {
                const active = activeThread();
                const groupId = active ? Number(active.group_id || active.entity_id || 0) : 0;
                if (!addGroupMemberModal || String(active && active.thread_kind || '') !== 'group' || groupId <= 0) {
                    alert('Members can be added to group chats only.');
                    return;
                }

                addGroupMemberModal.hidden = false;
                addGroupMemberModal.setAttribute('aria-hidden', 'false');
                addGroupMemberModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                state.addGroupMemberGroupId = groupId;
                state.addGroupMemberLoading = true;
                state.addGroupMemberUsers = [];
                state.addGroupMemberQuery = '';
                state.addGroupMemberSelectedId = 0;
                if (addGroupMemberSearchInput) addGroupMemberSearchInput.value = '';
                if (addGroupMemberModalSubtitle) addGroupMemberModalSubtitle.textContent = `Choose an active user to add to ${active.title || 'this group chat'}.`;
                renderAddGroupMemberUsers();

                try {
                    const [membersRes, usersRes] = await Promise.all([
                        fetch('api/interagency_group_members.php?group_id=' + encodeURIComponent(String(groupId)), { cache: 'no-store' }),
                        fetch('api/interagency_users.php', { cache: 'no-store' })
                    ]);
                    const [membersData, usersData] = await Promise.all([membersRes.json(), usersRes.json()]);
                    if (!membersData || !membersData.ok) {
                        throw new Error((membersData && membersData.error) ? String(membersData.error) : 'Unable to load group members.');
                    }
                    if (!membersData.group || !membersData.group.can_manage) {
                        throw new Error('Only the group creator can add members.');
                    }
                    if (!usersData || !usersData.ok) {
                        throw new Error('Unable to load active users.');
                    }

                    const memberIds = new Set((Array.isArray(membersData.members) ? membersData.members : []).map((member) => Number(member.id)));
                    state.addGroupMemberUsers = (Array.isArray(usersData.items) ? usersData.items : [])
                        .filter((item) => !memberIds.has(Number(item.id)));
                    state.addGroupMemberLoading = false;
                    renderAddGroupMemberUsers();
                    window.setTimeout(() => addGroupMemberSearchInput && addGroupMemberSearchInput.focus(), 0);
                } catch (err) {
                    const message = (err && err.message) ? String(err.message) : 'Unable to load users.';
                    closeAddGroupMemberModal();
                    alert(message);
                }
            }

            async function confirmAddGroupMember() {
                const user = selectedAddGroupMemberUser();
                const groupId = Number(state.addGroupMemberGroupId || 0);
                if (!user || groupId <= 0 || state.addGroupMemberLoading) return;

                state.addGroupMemberLoading = true;
                syncAddGroupMemberState();
                try {
                    const res = await fetch('api/interagency_group_member_add.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ group_id: groupId, user_id: Number(user.id) })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to add member.');
                    }

                    closeAddGroupMemberModal();
                    await loadThreads();
                    await openGroupMembersModal();
                } catch (err) {
                    state.addGroupMemberLoading = false;
                    syncAddGroupMemberState();
                    alert((err && err.message) ? String(err.message) : 'Unable to add member.');
                }
            }

            function closeGroupMemberRequestsModal() {
                if (!groupMemberRequestsModal) return;
                groupMemberRequestsModal.classList.remove('show');
                groupMemberRequestsModal.setAttribute('aria-hidden', 'true');
                groupMemberRequestsModal.hidden = true;
                document.body.style.overflow = '';
                if (groupMemberRequestsModalBody) groupMemberRequestsModalBody.innerHTML = '';
            }

            function renderGroupMemberRequests(group, requests) {
                if (!groupMemberRequestsModalBody) return;
                const items = Array.isArray(requests) ? requests : [];
                if (groupMemberRequestsModalSubtitle) {
                    groupMemberRequestsModalSubtitle.textContent = `${group && group.name ? group.name : 'Group chat'} · ${items.length} pending request${items.length === 1 ? '' : 's'}.`;
                }
                if (!items.length) {
                    groupMemberRequestsModalBody.innerHTML = '<div class="ia-media-empty">No pending member requests.</div>';
                    return;
                }

                groupMemberRequestsModalBody.innerHTML = `
                    <div class="ia-member-list">
                        ${items.map((item) => `
                            <article class="ia-member-card">
                                <div class="ia-member-avatar">${escapeHtml(initialsForName(item.user_name))}</div>
                                <div class="ia-member-main">
                                    <p class="ia-member-name">${escapeHtml(item.user_name || ('User #' + item.user_id))}</p>
                                    <p class="ia-member-meta">${escapeHtml(item.user_email || 'No email provided')}</p>
                                    <p class="ia-member-meta">Requested by ${escapeHtml(item.requested_by_name || ('User #' + item.requested_by_id))}</p>
                                </div>
                                <div class="ia-member-side">
                                    <span class="ia-member-badge">${escapeHtml(formatRole(item.user_role || 'user'))}</span>
                                    <button type="button" class="ia-modal-btn primary" data-review-member-request="approve" data-request-id="${escapeAttr(item.id)}" aria-label="Approve request" title="Approve"><i class="fas fa-check"></i></button>
                                    <button type="button" class="ia-member-remove" data-review-member-request="reject" data-request-id="${escapeAttr(item.id)}" aria-label="Reject request" title="Reject"><i class="fas fa-times"></i></button>
                                </div>
                            </article>
                        `).join('')}
                    </div>
                `;
            }

            async function openGroupMemberRequestsModal() {
                const active = activeThread();
                const groupId = active ? Number(active.group_id || active.entity_id || 0) : 0;
                if (!groupMemberRequestsModal || String(active && active.thread_kind || '') !== 'group' || groupId <= 0) {
                    alert('Member requests are available for group chats only.');
                    return;
                }

                groupMemberRequestsModal.hidden = false;
                groupMemberRequestsModal.setAttribute('aria-hidden', 'false');
                groupMemberRequestsModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                if (groupMemberRequestsModalSubtitle) groupMemberRequestsModalSubtitle.textContent = 'Loading pending member requests...';
                if (groupMemberRequestsModalBody) groupMemberRequestsModalBody.innerHTML = '<div class="ia-media-empty">Loading requests...</div>';

                try {
                    const res = await fetch('api/interagency_group_member_requests.php?group_id=' + encodeURIComponent(String(groupId)), { cache: 'no-store' });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to load member requests.');
                    }
                    renderGroupMemberRequests(data.group || active, data.items || []);
                } catch (err) {
                    if (groupMemberRequestsModalSubtitle) groupMemberRequestsModalSubtitle.textContent = 'Unable to load member requests.';
                    if (groupMemberRequestsModalBody) groupMemberRequestsModalBody.innerHTML = `<div class="ia-media-empty">${escapeHtml((err && err.message) ? err.message : 'Unable to load member requests.')}</div>`;
                }
            }

            async function reviewGroupMemberRequest(requestId, action) {
                const id = Number(requestId || 0);
                if (id <= 0 || !['approve', 'reject'].includes(action)) return;
                try {
                    const res = await fetch('api/interagency_group_member_request_review.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ request_id: id, action })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to review member request.');
                    }
                    await loadThreads();
                    await openGroupMemberRequestsModal();
                } catch (err) {
                    alert((err && err.message) ? String(err.message) : 'Unable to review member request.');
                }
            }

            function isConversationMediaModalOpen() {
                return !!(conversationMediaModal && conversationMediaModal.classList.contains('show'));
            }

            function closeConversationMediaModal() {
                if (!conversationMediaModal) return;
                conversationMediaModal.classList.remove('show');
                conversationMediaModal.setAttribute('aria-hidden', 'true');
                conversationMediaModal.hidden = true;
                document.body.style.overflow = '';
                if (conversationMediaModalBody) {
                    conversationMediaModalBody.innerHTML = '';
                }
            }

            function attachmentIsImage(attachment) {
                const mime = String(attachment && attachment.mime_type ? attachment.mime_type : '').toLowerCase();
                const url = String(attachment && attachment.url ? attachment.url : '').toLowerCase();
                return !!(attachment && attachment.is_image) || mime.startsWith('image/') || /\.(png|jpe?g|gif|webp|bmp|svg)(?:\?|#|$)/i.test(url);
            }

            function currentConversationAttachments(type) {
                const wantedImages = type === 'images';
                const seen = new Set();
                const items = [];
                Object.values(state.messageItems || {}).forEach((message) => {
                    const attachments = Array.isArray(message.attachments) ? message.attachments : [];
                    attachments.forEach((attachment) => {
                        const url = String(attachment.url || '').trim();
                        if (!url) return;
                        const isImage = attachmentIsImage(attachment);
                        if (wantedImages !== isImage) return;
                        const key = `${url}|${String(attachment.name || '')}`;
                        if (seen.has(key)) return;
                        seen.add(key);
                        items.push({
                            name: String(attachment.name || (isImage ? 'Image' : 'File')),
                            url,
                            size: Number(attachment.size || 0),
                            mime_type: String(attachment.mime_type || ''),
                            is_image: isImage,
                            sender_name: String(message.sender_name || 'Unknown'),
                            created_at: String(message.created_at || '')
                        });
                    });
                });
                return items;
            }

            function renderConversationMedia(type) {
                if (!conversationMediaModalBody) return;
                const items = currentConversationAttachments(type);
                const isImages = type === 'images';
                if (conversationMediaModalTitle) {
                    conversationMediaModalTitle.textContent = isImages ? 'Show Images' : 'Show Files';
                }
                if (conversationMediaModalSubtitle) {
                    conversationMediaModalSubtitle.textContent = `${items.length} ${isImages ? 'image' : 'file'}${items.length === 1 ? '' : 's'} in this conversation.`;
                }
                if (!items.length) {
                    conversationMediaModalBody.innerHTML = `<div class="ia-media-empty">No ${isImages ? 'images' : 'files'} found in this conversation.</div>`;
                    return;
                }

                conversationMediaModalBody.innerHTML = `
                    <div class="ia-media-grid">
                        ${items.map((item) => {
                            const meta = `${item.sender_name}${item.size > 0 ? ' · ' + formatBytes(item.size) : ''}`;
                            if (isImages) {
                                return `
                                    <a class="ia-media-card" href="${escapeAttr(item.url)}" target="_blank" rel="noopener">
                                        <img class="ia-media-thumb" src="${escapeAttr(item.url)}" alt="${escapeAttr(item.name)}">
                                        <div class="ia-media-info">
                                            <p class="ia-media-name" title="${escapeAttr(item.name)}">${escapeHtml(item.name)}</p>
                                            <p class="ia-media-meta">${escapeHtml(meta)}</p>
                                        </div>
                                    </a>
                                `;
                            }
                            return `
                                <a class="ia-media-card ia-media-file" href="${escapeAttr(item.url)}" target="_blank" rel="noopener">
                                    <i class="fas fa-file"></i>
                                    <div class="ia-media-info">
                                        <p class="ia-media-name" title="${escapeAttr(item.name)}">${escapeHtml(item.name)}</p>
                                        <p class="ia-media-meta">${escapeHtml(meta)}</p>
                                    </div>
                                </a>
                            `;
                        }).join('')}
                    </div>
                `;
            }

            async function openConversationMediaModal(type) {
                if (!conversationMediaModal) return;
                await loadMessages(true, false);
                renderConversationMedia(type);
                conversationMediaModal.hidden = false;
                conversationMediaModal.setAttribute('aria-hidden', 'false');
                conversationMediaModal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            function isGroupMembersModalOpen() {
                return !!(groupMembersModal && groupMembersModal.classList.contains('show'));
            }

            function closeGroupMembersModal() {
                if (!groupMembersModal) return;
                groupMembersModal.classList.remove('show');
                groupMembersModal.setAttribute('aria-hidden', 'true');
                groupMembersModal.hidden = true;
                document.body.style.overflow = '';
                if (groupMembersModalBody) {
                    groupMembersModalBody.innerHTML = '';
                }
            }

            function renderGroupMembers(group, members) {
                if (!groupMembersModalBody) return;
                const list = Array.isArray(members) ? members : [];
                const creator = group && group.creator && typeof group.creator === 'object' ? group.creator : null;
                const creatorName = creator && creator.name ? String(creator.name) : 'Unknown creator';
                if (groupMembersModalTitle) {
                    groupMembersModalTitle.textContent = 'Members';
                }
                if (groupMembersModalSubtitle) {
                    const groupName = group && group.name ? String(group.name) : 'Group chat';
                    groupMembersModalSubtitle.textContent = `${groupName} · ${list.length} member${list.length === 1 ? '' : 's'} · Created by ${creatorName}`;
                }
                if (!list.length) {
                    groupMembersModalBody.innerHTML = '<div class="ia-media-empty">No members found for this group chat.</div>';
                    return;
                }

                groupMembersModalBody.innerHTML = `
                    <div class="ia-member-list">
                        ${list.map((member) => {
                            const rawStatus = String(member.availability_status || member.user_status || member.status || '').toLowerCase();
                            const statusKey = rawStatus === 'active' || rawStatus === 'online' ? 'available' : (rawStatus || 'offline');
                            const statusLabels = { responding: 'Responding', available: 'Available', busy: 'Busy', offline: 'Offline' };
                            const status = statusLabels[statusKey] || 'Offline';
                            const creatorBadge = member.is_creator ? '<span class="ia-member-badge">Creator</span>' : '';
                            const removeButton = member.can_remove ? `<button type="button" class="ia-member-remove" data-remove-group-member="${escapeAttr(member.id)}" data-member-name="${escapeAttr(member.name || ('User #' + member.id))}">Remove</button>` : '';
                            return `
                                <article class="ia-member-card">
                                    <div class="ia-member-avatar">${escapeHtml(initialsForName(member.name))}</div>
                                    <div class="ia-member-main">
                                        <p class="ia-member-name">${escapeHtml(member.name || ('User #' + member.id))}</p>
                                        <p class="ia-member-meta">${escapeHtml(member.email || 'No email provided')}</p>
                                    </div>
                                    <div class="ia-member-side">
                                        <span class="ia-member-badge">${escapeHtml(formatRole(member.role || 'user'))}</span>
                                        ${creatorBadge}
                                        <span class="ia-member-status ${status === 'Offline' ? 'inactive' : ''}">${escapeHtml(status)}</span>
                                        ${removeButton}
                                    </div>
                                </article>
                            `;
                        }).join('')}
                    </div>
                `;
            }

            async function openGroupMembersModal() {
                const active = activeThread();
                const groupId = active ? Number(active.group_id || active.entity_id || 0) : 0;
                if (!groupMembersModal || String(active && active.thread_kind || '') !== 'group' || groupId <= 0) {
                    alert('Members are available for group chats only.');
                    return;
                }

                groupMembersModal.hidden = false;
                groupMembersModal.setAttribute('aria-hidden', 'false');
                groupMembersModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                if (groupMembersModalTitle) groupMembersModalTitle.textContent = 'Members';
                if (groupMembersModalSubtitle) groupMembersModalSubtitle.textContent = 'Loading group members...';
                if (groupMembersModalBody) groupMembersModalBody.innerHTML = '<div class="ia-media-empty">Loading members...</div>';

                try {
                    const res = await fetch('api/interagency_group_members.php?group_id=' + encodeURIComponent(String(groupId)), { cache: 'no-store' });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to load members.');
                    }
                    renderGroupMembers(data.group || active, data.members || []);
                } catch (err) {
                    if (groupMembersModalSubtitle) groupMembersModalSubtitle.textContent = 'Unable to load members.';
                    if (groupMembersModalBody) {
                        groupMembersModalBody.innerHTML = `<div class="ia-media-empty">${escapeHtml((err && err.message) ? err.message : 'Unable to load members.')}</div>`;
                    }
                }
            }

            async function removeGroupMember(userId, memberName) {
                const active = activeThread();
                const groupId = active ? Number(active.group_id || active.entity_id || 0) : 0;
                if (!groupId || !userId) return;
                const label = memberName ? String(memberName) : 'this member';
                if (!confirm(`Remove ${label} from this group chat?`)) {
                    return;
                }

                try {
                    const res = await fetch('api/interagency_group_member_remove.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ group_id: groupId, user_id: Number(userId) })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to remove member.');
                    }
                    await openGroupMembersModal();
                    await loadThreads();
                } catch (err) {
                    alert((err && err.message) ? err.message : 'Unable to remove member.');
                }
            }

            function syncFileInput() {
                if (!messageFilesInput) return;
                const dt = new DataTransfer();
                state.pendingFiles.forEach((file) => dt.items.add(file));
                messageFilesInput.files = dt.files;
            }

            function clearPendingFiles() {
                state.pendingFiles = [];
                if (messageFilesInput) messageFilesInput.value = '';
                renderPendingFiles();
            }

            function removePendingFile(index) {
                state.pendingFiles = state.pendingFiles.filter((_, i) => i !== index);
                syncFileInput();
                renderPendingFiles();
            }

            function renderPendingFiles() {
                if (!filePreviewEl) return;
                if (!state.pendingFiles.length) {
                    filePreviewEl.innerHTML = '';
                    return;
                }
                filePreviewEl.innerHTML = state.pendingFiles.map((file, index) => `
                    <div class="ia-file-chip">
                        <i class="fas ${file.type.startsWith('image/') ? 'fa-image' : 'fa-file'}"></i>
                        <span title="${escapeAttr(file.name)}">${escapeHtml(file.name)} (${formatBytes(file.size)})</span>
                        <button type="button" data-remove-file="${index}" aria-label="Remove file">&times;</button>
                    </div>
                `).join('');
                filePreviewEl.querySelectorAll('button[data-remove-file]').forEach((btn) => {
                    btn.addEventListener('click', () => removePendingFile(Number(btn.getAttribute('data-remove-file')) || 0));
                });
            }

            async function uploadPendingFiles() {
                if (!state.pendingFiles.length) {
                    return [];
                }
                const fd = new FormData();
                state.pendingFiles.forEach((file) => {
                    fd.append('files[]', file);
                });
                const res = await fetch('api/interagency_upload.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data || !data.ok) {
                    throw new Error((data && data.error) ? String(data.error) : 'File upload failed');
                }
                return Array.isArray(data.attachments) ? data.attachments : [];
            }

            function renderIncidentCard(item) {
                const card = item && item.incident_card && typeof item.incident_card === 'object' ? item.incident_card : null;
                if (!card) return '';
                const incidentId = Number(card.incident_id || 0);
                const refNo = String(card.reference_no || '').trim();
                const label = refNo !== '' ? escapeHtml(refNo) : `#${incidentId}`;
                const type = String(card.type || '').trim();
                const priority = String(card.priority || '').trim();
                const location = String(card.location || '').trim();
                const status = String(item.incident_status || 'pending').toLowerCase();
                const statusLabel = status === 'accepted' ? 'Accepted' : (status === 'declined' ? 'Declined' : 'Pending');
                const metaParts = [];
                if (type) metaParts.push(`<span class="ia-incident-meta-type">${escapeHtml(type)}</span>`);
                if (priority) metaParts.push(escapeHtml(priority));
                if (location) metaParts.push(escapeHtml(location));
                const metaHtml = metaParts.length ? `<div class="ia-incident-meta">${metaParts.join(' · ')}</div>` : '';
                const buttonsDisabled = status !== 'pending' ? 'disabled' : '';
                return `
                    <div class="ia-incident-card">
                        <div class="ia-incident-card-head">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span class="ia-incident-card-label">Incident</span>
                            <span class="ia-incident-status status-${escapeAttr(status)}" data-incident-status>${escapeHtml(statusLabel)}</span>
                        </div>
                        <div class="ia-incident-id">${label}</div>
                        ${metaHtml}
                        <div class="ia-incident-actions">
                            <button type="button" class="ia-incident-btn view" data-incident-view="${escapeAttr(incidentId)}">
                                <i class="fas fa-eye"></i> View incident
                            </button>
                            <button type="button" class="ia-incident-btn accept" data-incident-accept ${buttonsDisabled}>
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button type="button" class="ia-incident-btn decline" data-incident-decline ${buttonsDisabled}>
                                <i class="fas fa-times"></i> Decline
                            </button>
                        </div>
                    </div>
                `;
            }

            function renderMessageBody(item) {
                const incidentHtml = renderIncidentCard(item);
                const text = String(item.text || '').trim();
                const attachments = Array.isArray(item.attachments) ? item.attachments : [];
                const replyTo = item && item.reply_to && typeof item.reply_to === 'object' ? item.reply_to : null;
                const replyHtml = replyTo ? `
                    <div class="ia-reply-chip">
                        <span class="ia-reply-author">${escapeHtml(replyTo.sender_name || 'Message')}</span>
                        <span class="ia-reply-snippet">${escapeHtml(messageSnippet(replyTo))}</span>
                    </div>
                ` : '';
                const textHtml = text ? `<div class="ia-message-text">${escapeHtml(text)}</div>` : '';
                const filesHtml = attachments.length ? `
                    <div class="ia-attachments">
                        ${attachments.map((a) => {
                            const url = String(a.url || '').trim();
                            const name = String(a.name || 'Attachment');
                            const isImage = !!a.is_image;
                            if (!url) return '';
                            if (isImage) {
                                return `<a class="ia-attachment-link" href="${escapeAttr(url)}" target="_blank" rel="noopener"><img class="ia-attachment-image" src="${escapeAttr(url)}" alt="${escapeAttr(name)}"></a>`;
                            }
                            return `<a class="ia-attachment-link" href="${escapeAttr(url)}" target="_blank" rel="noopener"><i class="fas fa-file"></i><span>${escapeHtml(name)}</span></a>`;
                        }).join('')}
                    </div>
                ` : '';
                return incidentHtml + replyHtml + textHtml + filesHtml;
            }

            function stripPriorityPrefix(text) {
                return String(text || '').replace(/^\[[^\]]+\]\s*/, '').trim();
            }

            function isEmojiOnlyMessage(text) {
                const compact = String(text || '').replace(/\s+/g, '');
                if (!compact) return false;
                try {
                    return /^(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|\uFE0F|\u200D)+$/u.test(compact);
                } catch (_) {
                    return false;
                }
            }

            function shouldShowMessageActions(item) {
                const attachments = Array.isArray(item.attachments) ? item.attachments : [];
                if (attachments.length) {
                    return true;
                }
                const plainText = stripPriorityPrefix(item.text || '');
                if (!plainText) {
                    return false;
                }
                return !isEmojiOnlyMessage(plainText);
            }

            function buildForwardPayload(item) {
                const lines = [];
                const plainText = stripPriorityPrefix(item.text || '');
                if (plainText) {
                    lines.push(plainText);
                }
                const attachments = Array.isArray(item.attachments) ? item.attachments : [];
                attachments.forEach((attachment) => {
                    const name = String(attachment.name || 'Attachment').trim() || 'Attachment';
                    const url = String(attachment.url || '').trim();
                    lines.push(url ? `${name}: ${url}` : name);
                });
                return lines.join('\n');
            }

            function messageSnippet(item) {
                const plainText = stripPriorityPrefix(item && item.text ? item.text : '');
                if (plainText) {
                    return plainText.length > 100 ? `${plainText.slice(0, 100)}...` : plainText;
                }
                const attachments = (item && Array.isArray(item.attachments)) ? item.attachments : [];
                if (attachments.length === 1) {
                    const attachment = attachments[0] || {};
                    return attachment.is_image ? 'Photo attachment' : `Attachment: ${String(attachment.name || 'File')}`;
                }
                const attachmentCount = Number(item && item.attachment_count ? item.attachment_count : 0);
                if (!attachments.length && attachmentCount === 1) {
                    return 'Attachment';
                }
                if (attachments.length > 1) {
                    return `${attachments.length} attachments`;
                }
                if (!attachments.length && attachmentCount > 1) {
                    return `${attachmentCount} attachments`;
                }
                return 'Message';
            }

            function renderReplyPreview() {
                if (!replyPreviewEl) return;
                const reply = state.replyTo;
                if (!reply) {
                    replyPreviewEl.hidden = true;
                    replyPreviewEl.innerHTML = '';
                    return;
                }
                replyPreviewEl.hidden = false;
                replyPreviewEl.innerHTML = `
                    <div class="ia-reply-preview-main">
                        <p class="ia-reply-preview-label">Replying to ${escapeHtml(reply.sender_name || 'Message')}</p>
                        <p class="ia-reply-preview-text">${escapeHtml(reply.snippet || 'Message')}</p>
                    </div>
                    <button type="button" class="ia-reply-preview-close" data-clear-reply aria-label="Cancel reply">
                        <i class="fas fa-times"></i>
                    </button>
                `;
            }

            function clearReplyTarget() {
                state.replyTo = null;
                renderReplyPreview();
            }

            function startReply(item) {
                if (!item) return;
                state.replyTo = {
                    message_id: Number(item.id) || 0,
                    sender_name: String(item.is_self ? 'You' : (item.sender_name || 'System')),
                    text: stripPriorityPrefix(item.text || ''),
                    attachment_count: Array.isArray(item.attachments) ? item.attachments.length : 0,
                    snippet: messageSnippet(item)
                };
                renderReplyPreview();
                if (messageInput) {
                    messageInput.focus();
                }
            }

            async function copyTextToClipboard(text) {
                if (!text) {
                    return false;
                }
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    try {
                        await navigator.clipboard.writeText(text);
                        return true;
                    } catch (_) {}
                }
                const helper = document.createElement('textarea');
                helper.value = text;
                helper.setAttribute('readonly', 'readonly');
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                let copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (_) {
                    copied = false;
                }
                document.body.removeChild(helper);
                return copied;
            }

            function setMessageMenuOpen(actionsEl, open) {
                if (!actionsEl) return;
                actionsEl.classList.toggle('open', open);
                const toggle = actionsEl.querySelector('[data-message-menu-toggle]');
                const menu = actionsEl.querySelector('.ia-message-menu');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                if (menu) {
                    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
                }
            }

            function closeMessageMenus(exceptEl) {
                chatTimelineEl.querySelectorAll('.ia-message-actions.open').forEach((actionsEl) => {
                    if (actionsEl !== exceptEl) {
                        setMessageMenuOpen(actionsEl, false);
                    }
                });
            }

            function syncPinnedMessageState(article, pinned) {
                if (!article) return;
                article.classList.toggle('is-pinned', pinned);
                const stateEl = article.querySelector('[data-message-state]');
                if (stateEl) {
                    stateEl.hidden = !pinned;
                }
                const pinBtn = article.querySelector('[data-message-action="pin"]');
                if (pinBtn) {
                    pinBtn.textContent = pinned ? 'Unpin' : 'Pin';
                }
            }

            function renderMessageActions(item, messageId) {
                if (!shouldShowMessageActions(item)) {
                    return '';
                }
                const pinned = !!state.pinnedMessages[messageId];
                const actions = [];
                actions.push({ key: 'reply', label: 'Reply' });
                if (item.is_self) {
                    actions.push({ key: 'unsend', label: 'Unsend' });
                }
                actions.push({ key: 'forward', label: 'Forward' });
                actions.push({ key: 'pin', label: pinned ? 'Unpin' : 'Pin' });
                if (!item.is_self) {
                    actions.push({ key: 'report', label: 'Report' });
                }
                return `
                    <div class="ia-message-actions" data-message-actions>
                        <button type="button" class="ia-message-menu-toggle" data-message-menu-toggle aria-label="Open message actions" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <div class="ia-message-menu" role="menu" aria-hidden="true">
                            ${actions.map((action) => `<button type="button" data-message-action="${escapeAttr(action.key)}">${escapeHtml(action.label)}</button>`).join('')}
                        </div>
                    </div>
                `;
            }

            async function handleMessageAction(action, article, item) {
                if (!article || !item) {
                    return;
                }
                const messageId = String(article.getAttribute('data-message-id') || '');
                if (!messageId) {
                    return;
                }
                if (action === 'reply') {
                    startReply(item);
                    return;
                }
                if (action === 'forward') {
                    const payload = buildForwardPayload(item);
                    if (!payload) {
                        alert('Nothing to forward for this message.');
                        return;
                    }
                    const copied = await copyTextToClipboard(payload);
                    if (copied) {
                        alert('Message copied. You can paste it into another chat.');
                    } else {
                        window.prompt('Copy this message:', payload);
                    }
                    return;
                }
                if (action === 'pin') {
                    const nextPinned = !state.pinnedMessages[messageId];
                    if (nextPinned) {
                        state.pinnedMessages[messageId] = true;
                    } else {
                        delete state.pinnedMessages[messageId];
                    }
                    syncPinnedMessageState(article, nextPinned);
                    return;
                }
                if (action === 'unsend') {
                    if (!confirm('Unsend this message for everyone?')) {
                        return;
                    }
                    try {
                        const res = await fetch('api/interagency_message_action.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ action: 'unsend', message_id: Number(messageId) || 0 }),
                        });
                        const data = await res.json();
                        if (!res.ok || !data.ok) {
                            throw new Error(data.error || 'Unable to unsend message.');
                        }
                        await loadMessages(false, false);
                    } catch (error) {
                        alert((error && error.message) ? error.message : 'Unable to unsend message.');
                    }
                    return;
                }
                if (action === 'report') {
                    const reason = window.prompt('Reason for reporting this message:', 'Needs admin review');
                    if (reason === null) {
                        return;
                    }
                    try {
                        const res = await fetch('api/interagency_message_action.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ action: 'report', message_id: Number(messageId) || 0, reason }),
                        });
                        const data = await res.json();
                        if (!res.ok || !data.ok) {
                            throw new Error(data.error || 'Unable to report message.');
                        }
                        alert('Message reported for review.');
                    } catch (error) {
                        alert((error && error.message) ? error.message : 'Unable to report message.');
                    }
                }
            }

            function parsePhilippineDate(dateLike) {
                const raw = String(dateLike || '').trim();
                if (!raw) return new Date(NaN);
                const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
                const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
                return new Date(hasTimezone ? normalized : `${normalized}+08:00`);
            }

            function rel(dateLike) {
                const d = parsePhilippineDate(dateLike);
                if (isNaN(d.getTime())) return 'just now';
                const mins = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
                if (!mins) return 'just now';
                if (mins < 60) return `${mins}m ago`;
                if (mins < 1440) return `${Math.round(mins / 60)}h ago`;
                return `${Math.round(mins / 1440)}d ago`;
            }

            function time(dateLike) {
                const d = parsePhilippineDate(dateLike);
                if (isNaN(d.getTime())) return 'Now';
                return d.toLocaleString('en-PH', {
                    timeZone: 'Asia/Manila',
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function firstText(...values) {
                for (const value of values) {
                    const text = String(value || '').trim();
                    if (text) return text;
                }
                return '';
            }

            function formatIncidentStatus(value) {
                const raw = String(value || '').trim();
                if (!raw) return 'Responding';
                const normalized = raw.toLowerCase().replace(/[_-]+/g, ' ');
                const labels = {
                    pending: 'Pending',
                    dispatched: 'Responding',
                    assigned: 'Responding',
                    acknowledged: 'Responding',
                    enroute: 'En Route',
                    'en route': 'En Route',
                    'on scene': 'On Scene',
                    resolved: 'Resolved',
                    closed: 'Closed',
                    cancelled: 'Cancelled'
                };
                return labels[normalized] || normalized.replace(/\b\w/g, (char) => char.toUpperCase());
            }

            function activeIncidentTitle(incident) {
                const id = Number(incident && incident.id) || 0;
                return firstText(
                    incident && incident.title,
                    incident && incident.type,
                    incident && incident.incident_code,
                    id > 0 ? `Incident #${id}` : 'Active Incident'
                );
            }

            function activeIncidentStartedAt(incident) {
                return firstText(
                    incident && incident.assigned_at,
                    incident && incident.acknowledged_at,
                    incident && incident.enroute_at,
                    incident && incident.on_scene_at,
                    incident && incident.created_at
                );
            }

            function activeIncidentTime(dateLike) {
                const d = parsePhilippineDate(dateLike);
                if (isNaN(d.getTime())) return 'Now';
                return d.toLocaleString('en-PH', {
                    timeZone: 'Asia/Manila',
                    hour: 'numeric',
                    minute: '2-digit'
                });
            }

            function renderActiveIncidentBanner() {
                if (!activeIncidentBannerEl) return;
                const incident = state.activeIncident;
                if (!incident) {
                    activeIncidentBannerEl.hidden = true;
                    activeIncidentBannerEl.innerHTML = '';
                    return;
                }

                const id = Number(incident.id) || 0;
                const priority = firstText(incident.priority, 'Routine');
                const handler = firstText(
                    incident.dispatcher_name,
                    incident.dispatcher,
                    incident.assigned_by,
                    incident.operator_name,
                    incident.assigned_unit,
                    incident.vehicle_name,
                    incident.driver_name,
                    'Unassigned'
                );
                const status = formatIncidentStatus(firstText(incident.latest_dispatch_status, incident.dispatch_status, incident.status));
                const startedAt = activeIncidentTime(activeIncidentStartedAt(incident));
                const openButton = id > 0
                    ? `<button type="button" class="ia-active-incident-open" data-active-incident-open="${escapeAttr(id)}" title="View active incident" aria-label="View active incident"><i class="fas fa-up-right-from-square"></i></button>`
                    : '';

                activeIncidentBannerEl.innerHTML = `
                    <section class="ia-active-incident-card" aria-label="Active incident">
                        <p class="ia-active-incident-kicker"><i class="fas fa-triangle-exclamation"></i> Active Incident</p>
                        ${openButton}
                        <h2 class="ia-active-incident-title">${escapeHtml(activeIncidentTitle(incident))}</h2>
                        <div class="ia-active-incident-grid">
                            <p><span>Priority:</span> ${escapeHtml(priority)}</p>
                            <p><span>Handled by:</span> ${escapeHtml(handler)}</p>
                            <p><span>Started:</span> ${escapeHtml(startedAt)}</p>
                            <p class="ia-active-incident-status"><span>Status:</span> ${escapeHtml(status)}</p>
                        </div>
                    </section>
                `;
                activeIncidentBannerEl.hidden = false;
            }

            async function loadActiveIncidentBanner() {
                if (!activeIncidentBannerEl) return;
                try {
                    const res = await fetch('api/incidents_list.php?status=active', { cache: 'no-store' });
                    const data = await res.json();
                    state.activeIncident = data && data.ok && Array.isArray(data.items) && data.items.length
                        ? data.items[0]
                        : null;
                } catch (_) {
                    state.activeIncident = null;
                }
                renderActiveIncidentBanner();
            }

            function activeThread() {
                return state.threads.find((item) => item.id === state.activeId) || null;
            }

            function threadKey(thread) {
                if (!thread) return '';
                if (String(thread.thread_kind || '') === 'group') {
                    return `group:${thread.group_id || thread.entity_id || 0}`;
                }
                if (String(thread.thread_kind || '') === 'external') {
                    return `external:${thread.external_message_id || thread.entity_id || 0}`;
                }
                if (String(thread.thread_kind || '') === 'user') {
                    return `user:${thread.user_id || thread.entity_id || 0}`;
                }
                return `dept:${thread.department || ''}`;
            }

            function typingThreadPayload(thread = activeThread()) {
                if (!thread) return null;
                const kind = String(thread.thread_kind || 'department');
                if (kind === 'external') return null;
                if (kind === 'group') {
                    const groupId = Number(thread.group_id || thread.entity_id || 0);
                    return groupId > 0 ? { thread_kind: 'group', group_id: groupId } : null;
                }
                if (kind === 'user') {
                    const userId = Number(thread.user_id || thread.entity_id || 0);
                    return userId > 0 ? { thread_kind: 'user', user_id: userId } : null;
                }
                const department = String(thread.department || '').trim().toLowerCase();
                return department ? { thread_kind: 'department', department } : null;
            }

            function typingQuery(payload) {
                const params = new URLSearchParams();
                Object.entries(payload || {}).forEach(([key, value]) => {
                    if (value !== null && value !== undefined && String(value) !== '') {
                        params.set(key, String(value));
                    }
                });
                return params;
            }

            function renderTypingIndicator() {
                if (!typingIndicatorEl) return;
                const names = (Array.isArray(state.typingUsers) ? state.typingUsers : [])
                    .map((item) => String(item.name || 'Someone').trim())
                    .filter(Boolean);
                if (!names.length) {
                    typingIndicatorEl.hidden = true;
                    typingIndicatorEl.innerHTML = '';
                    return;
                }
                const label = names.length === 1
                    ? `${names[0]} is typing...`
                    : (names.length === 2 ? `${names[0]} and ${names[1]} are typing...` : `${names[0]} and ${names.length - 1} others are typing...`);
                typingIndicatorEl.innerHTML = `<div class="ia-typing-pill">${escapeHtml(label)}<i class="far fa-copy" aria-hidden="true"></i></div>`;
                typingIndicatorEl.hidden = false;
            }

            async function loadTypingIndicator() {
                const payload = typingThreadPayload();
                if (!payload) {
                    state.typingUsers = [];
                    renderTypingIndicator();
                    return;
                }
                try {
                    const res = await fetch('api/interagency_typing.php?' + typingQuery(payload).toString(), { cache: 'no-store' });
                    const data = await res.json();
                    state.typingUsers = data && data.ok && Array.isArray(data.typing) ? data.typing : [];
                } catch (_) {
                    state.typingUsers = [];
                }
                renderTypingIndicator();
            }

            function postTypingStatus(isTyping, payload = null) {
                const threadPayload = payload || (isTyping ? typingThreadPayload() : state.typingPayload);
                if (!threadPayload) return;
                fetch('api/interagency_typing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...threadPayload, is_typing: !!isTyping })
                }).catch(() => {});
            }

            function stopTypingStatus() {
                if (state.typingTimer) {
                    window.clearTimeout(state.typingTimer);
                    state.typingTimer = null;
                }
                if (state.typingHeartbeat) {
                    window.clearInterval(state.typingHeartbeat);
                    state.typingHeartbeat = null;
                }
                if (state.typingActive || state.typingPayload) {
                    postTypingStatus(false, state.typingPayload);
                }
                state.typingActive = false;
                state.typingPayload = null;
            }

            function startTypingStatus() {
                const payload = typingThreadPayload();
                if (!payload) return;
                state.typingPayload = payload;
                if (!state.typingActive) {
                    state.typingActive = true;
                    postTypingStatus(true, payload);
                }
                if (!state.typingHeartbeat) {
                    state.typingHeartbeat = window.setInterval(() => {
                        if (state.typingActive && state.typingPayload) {
                            postTypingStatus(true, state.typingPayload);
                        }
                    }, 4000);
                }
                if (state.typingTimer) window.clearTimeout(state.typingTimer);
                state.typingTimer = window.setTimeout(stopTypingStatus, 2600);
            }

            function filteredThreads() {
                return state.threads.filter((item) => {
                    const filterMatch = state.filter === 'all' ? true : item.kind === state.filter;
                    if (!filterMatch) return false;
                    if (!state.query) return true;
                    const preview = item.last_text ? `${item.last_sender_name || ''} ${item.last_text}` : '';
                    const hay = `${item.title || ''} ${preview}`.toLowerCase();
                    return hay.includes(state.query.toLowerCase());
                });
            }

            function renderOverview(stats) {
                totalThreadsEl.textContent = String((stats && stats.total_threads) || state.threads.length || 0);
                activeRespondersEl.textContent = String((stats && stats.active_responders) || 0);
                unreadCountEl.textContent = String((stats && stats.unread_messages) || 0);
            }

            function userOnlineState(user) {
                const presenceStatus = String((user && user.presence_status) || '').trim().toLowerCase();
                if (presenceStatus === 'offline') {
                    return {
                        key: 'offline',
                        label: 'Offline'
                    };
                }
                const rawStatus = String((user && (user.availability_status || user.user_status || user.presence_status)) || '').trim().toLowerCase();
                const normalized = rawStatus === 'online' ? 'available' : rawStatus;
                const labels = {
                    responding: 'Responding',
                    available: 'Available',
                    busy: 'Busy',
                    offline: 'Offline'
                };
                const key = Object.prototype.hasOwnProperty.call(labels, normalized) ? normalized : 'offline';
                return {
                    key,
                    label: labels[key]
                };
            }

            function userIconByRole(role) {
                const normalized = String(role || '').trim().toLowerCase();
                if (normalized === 'admin') return 'fa-user-tie';
                if (normalized === 'dispatcher' || normalized === 'operator') return 'fa-headset';
                return 'fa-user';
            }

            function renderUserStatuses() {
                if (!userStatusListEl) return;
                const users = Array.isArray(state.userStatuses) ? state.userStatuses : [];
                if (!users.length) {
                    userStatusListEl.innerHTML = '<div class="ia-empty-list">No users found.</div>';
                    return;
                }

                userStatusListEl.innerHTML = users.map((user) => {
                    const stateInfo = userOnlineState(user);
                    return `
                        <div class="ia-user-status-item">
                            <div class="ia-user-status-avatar">
                                <i class="fas ${escapeHtml(userIconByRole(user.role))}"></i>
                            </div>
                            <div class="ia-user-status-main">
                                <p class="ia-user-status-name">${escapeHtml(user.name || user.email || ('User #' + user.id))}</p>
                                <p class="ia-user-status-role">${escapeHtml(user.role || 'user')}</p>
                            </div>
                            <span class="ia-user-status-state ${escapeHtml(stateInfo.key)}">
                                <span class="ia-dot ${escapeHtml(stateInfo.key)}"></span>
                                ${escapeHtml(stateInfo.label)}
                            </span>
                        </div>
                    `;
                }).join('');
            }

            async function loadUserStatuses() {
                if (!userStatusListEl) return;
                try {
                    const res = await fetch('api/interagency_users.php?include_inactive=1&include_self=1', { cache: 'no-store' });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        userStatusListEl.innerHTML = '<div class="ia-empty-list">Unable to load users.</div>';
                        return;
                    }
                    state.userStatuses = Array.isArray(data.items) ? data.items : [];
                    renderUserStatuses();
                } catch (_) {
                    userStatusListEl.innerHTML = '<div class="ia-empty-list">Unable to load users.</div>';
                }
            }

            function previewText(item) {
                const text = String(item.last_text || '').trim();
                const sender = String(item.last_sender_name || '').trim();
                if (!text) return 'No messages yet';
                if (!sender) return text;
                const senderLabel = sender === state.currentUser.name ? 'You' : sender;
                return `${senderLabel}: ${text}`;
            }

            function renderThreadList() {
                const items = filteredThreads();
                if (!items.length) {
                    threadListEl.innerHTML = '<div class="ia-empty-list">No conversations match your filter.</div>';
                    return;
                }

                threadListEl.innerHTML = items.map((item) => {
                    const activeClass = item.id === state.activeId ? 'active' : '';
                    const unread = item.unread > 0 ? `<span class="ia-unread">${item.unread}</span>` : '';
                    const avatarType = item.kind === 'department' ? 'department' : 'responder';
                    const stat = item.status === 'busy' ? 'busy' : (item.status === 'offline' ? 'offline' : 'online');
                    const channelLabel = threadChannelLabel(item);
                    return `
                        <button type="button" class="ia-thread ${activeClass}" data-id="${escapeHtml(item.id)}">
                            <div class="ia-avatar ${avatarType}">
                                <i class="fas ${escapeHtml(item.icon || 'fa-comments')}"></i>
                            </div>
                            <div class="ia-thread-main">
                                <div class="ia-thread-row">
                                    <div class="ia-thread-title-wrap">
                                        <p class="ia-thread-name">${escapeHtml(item.title || item.id)}</p>
                                        <span class="ia-thread-edit" data-edit-id="${escapeAttr(item.id)}" title="Edit thread name" aria-label="Edit thread name" role="button" tabindex="0">
                                            <i class="fas fa-pen"></i>
                                        </span>
                                    </div>
                                    <span class="ia-thread-time">${escapeHtml(rel(item.last_at))}</span>
                                </div>
                                <p class="ia-thread-sub">
                                    <span class="ia-dot ${escapeHtml(stat)}"></span>
                                    ${escapeHtml(channelLabel)}
                                </p>
                                <div class="ia-thread-row">
                                    <p class="ia-thread-preview">${escapeHtml(previewText(item))}</p>
                                    ${unread}
                                </div>
                            </div>
                        </button>
                    `;
                }).join('');
            }

            function renderChatHeader() {
                const active = activeThread();
                if (!active) {
                    state.chatSettingsOpen = false;
                    chatHeaderEl.innerHTML = '<p class="ia-chat-title">No thread selected</p>';
                    return;
                }
                const statusLabel = active.status === 'online' ? 'Online' : (active.status === 'busy' ? 'Busy' : 'Offline');
                const channelLabel = threadChannelLabel(active);
                const settingsPanel = state.chatSettingsOpen ? `
                    <div class="ia-chat-settings" role="menu" aria-label="Conversation settings">
                        <div class="ia-chat-settings-head">
                            <p class="ia-chat-settings-title">Message settings</p>
                            <p class="ia-chat-settings-sub">${escapeHtml(active.title || active.id)}</p>
                        </div>
                        <div class="ia-chat-settings-list">
                            <button type="button" class="ia-chat-settings-item" data-chat-setting-action="rename" role="menuitem">
                                <i class="fas fa-pen"></i>
                                <span>Edit conversation name</span>
                            </button>
                            <button type="button" class="ia-chat-settings-item" data-chat-setting-action="mark-read" role="menuitem">
                                <i class="fas fa-check-double"></i>
                                <span>Mark as read</span>
                            </button>
                            ${String(active.thread_kind || '') === 'group' ? `
                                <button type="button" class="ia-chat-settings-item" data-chat-setting-action="members" role="menuitem">
                                    <i class="fas fa-users"></i>
                                    <span>Members</span>
                                </button>
                                <button type="button" class="ia-chat-settings-item" data-chat-setting-action="add-member" role="menuitem">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Add Member</span>
                                </button>
                                <button type="button" class="ia-chat-settings-item" data-chat-setting-action="member-requests" role="menuitem">
                                    <i class="fas fa-user-clock"></i>
                                    <span>Member Requests</span>
                                </button>
                            ` : ''}
                            <button type="button" class="ia-chat-settings-item" data-chat-setting-action="show-images" role="menuitem">
                                <i class="fas fa-image"></i>
                                <span>Show Images</span>
                            </button>
                            <button type="button" class="ia-chat-settings-item" data-chat-setting-action="show-files" role="menuitem">
                                <i class="fas fa-folder-open"></i>
                                <span>Show Files</span>
                            </button>
                            <button type="button" class="ia-chat-settings-item" data-chat-setting-action="refresh" role="menuitem">
                                <i class="fas fa-rotate-right"></i>
                                <span>Refresh messages</span>
                            </button>
                        </div>
                    </div>
                ` : '';
                chatHeaderEl.innerHTML = `
                    <div class="ia-chat-head-main">
                        <p class="ia-chat-title">${escapeHtml(active.title || active.id)}</p>
                        <p class="ia-chat-meta">${escapeHtml(channelLabel)} · Status: ${escapeHtml(statusLabel)}</p>
                    </div>
                    <div class="ia-chat-actions">
                        <button type="button" class="ia-chat-info-btn" data-chat-settings-toggle aria-label="Open message settings" aria-expanded="${state.chatSettingsOpen ? 'true' : 'false'}">!</button>
                        ${settingsPanel}
                    </div>
                `;
            }

            function appendMessage(item) {
                const outgoing = !!item.is_self;
                const who = outgoing ? 'You' : (item.sender_name || 'System');
                const body = renderMessageBody(item) || '<div class="ia-message-text">Attachment</div>';
                const messageId = String(item.id || `msg-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`);
                const actions = renderMessageActions(item, messageId);
                const pinned = !!state.pinnedMessages[messageId];
                state.messageItems[messageId] = item;
                const html = `
                    <article class="ia-message ${outgoing ? 'outgoing' : 'incoming'} ${pinned ? 'is-pinned' : ''}" data-message-id="${escapeAttr(messageId)}">
                        <div class="meta">${escapeHtml(who)} · ${escapeHtml(time(item.created_at))}</div>
                        <div class="ia-message-row">
                            ${outgoing ? actions : ''}
                            <div class="bubble">${body}</div>
                            ${outgoing ? '' : actions}
                        </div>
                        <div class="ia-message-state" data-message-state ${pinned ? '' : 'hidden'}>Pinned</div>
                    </article>
                `;
                chatTimelineEl.insertAdjacentHTML('beforeend', html);
            }

            async function loadThreads() {
                const res = await fetch('api/interagency_chat_threads.php', { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;

                state.threads = Array.isArray(data.threads) ? data.threads : [];
                if (data.current_user) {
                    state.currentUser.id = Number(data.current_user.id) || 0;
                    state.currentUser.name = String(data.current_user.name || 'Admin');
                }
                if (!state.activeId || !state.threads.some((item) => item.id === state.activeId)) {
                    state.activeId = state.threads.length ? state.threads[0].id : '';
                }
                renderOverview(data.stats || null);
                renderThreadList();
                renderChatHeader();
                loadUserStatuses();
            }

            async function loadMessages(initial, markRead) {
                const active = activeThread();
                if (!active) {
                    chatTimelineEl.innerHTML = '';
                    return;
                }

                const params = new URLSearchParams();
                const kind = String(active.thread_kind || 'department');
                if (kind === 'group') {
                    params.set('thread_kind', 'group');
                    params.set('group_id', String(active.group_id || active.entity_id || 0));
                } else if (kind === 'external') {
                    params.set('thread_kind', 'external');
                    params.set('message_id', String(active.external_message_id || active.entity_id || 0));
                } else if (kind === 'user') {
                    params.set('thread_kind', 'user');
                    params.set('user_id', String(active.user_id || active.entity_id || 0));
                } else {
                    params.set('thread_kind', 'department');
                    params.set('department', String(active.department || ''));
                }
                const key = threadKey(active);
                const previousLastId = Number(state.lastIdByDept[key] || 0);
                const sinceId = initial ? 0 : previousLastId;
                if (sinceId > 0) params.set('since_id', String(sinceId));
                if (markRead) params.set('mark_read', '1');
                if (initial) params.set('limit', '100');

                const res = await fetch('api/interagency_chat_feed.php?' + params.toString(), { cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.ok) return;

                if (initial) {
                    chatTimelineEl.innerHTML = '';
                    state.messageItems = {};
                    closeMessageMenus();
                }
                const items = Array.isArray(data.items) ? data.items : [];
                if (!items.length) {
                    if (initial) chatTimelineEl.innerHTML = '<div class="ia-empty-list">No messages yet for this thread.</div>';
                    return;
                }

                const list = sinceId === 0 ? items.slice().reverse() : items;
                list.forEach((item) => {
                    if (item.id > (state.lastIdByDept[key] || 0)) state.lastIdByDept[key] = item.id;
                    appendMessage(item);
                });

                const last = list[list.length - 1];
                if (last) {
                    const lastAttachments = Array.isArray(last.attachments) ? last.attachments : [];
                    active.last_text = String(last.text || '').trim();
                    if (!active.last_text && lastAttachments.length) {
                        active.last_text = lastAttachments.length === 1
                            ? `[Attachment] ${String(lastAttachments[0].name || 'File')}`
                            : `[${lastAttachments.length} attachments]`;
                    }
                    active.last_sender_name = last.sender_name;
                    active.last_sender_role = last.sender_role;
                    active.last_at = last.created_at;
                    active.unread = 0;
                }

                chatTimelineEl.scrollTop = chatTimelineEl.scrollHeight;
                renderThreadList();
                renderChatHeader();
            }

            async function selectThread(threadId) {
                const target = state.threads.find((item) => item.id === threadId);
                if (!target) return;
                stopTypingStatus();
                state.activeId = target.id;
                state.chatSettingsOpen = false;
                state.lastIdByDept[threadKey(target)] = 0;
                clearPendingFiles();
                clearReplyTarget();
                state.typingUsers = [];
                renderTypingIndicator();
                renderThreadList();
                renderChatHeader();
                chatTimelineEl.innerHTML = '';
                state.messageItems = {};
                closeMessageMenus();
                try {
                    await loadMessages(true, true);
                    await loadTypingIndicator();
                    await loadThreads();
                } catch (err) {
                    const msg = (err && err.message) ? String(err.message) : 'Network error while sending message.';
                    alert(msg);
                }
            }

            async function handleChatSettingAction(action) {
                const active = activeThread();
                if (!active) return;

                state.chatSettingsOpen = false;
                renderChatHeader();

                if (action === 'rename') {
                    await renameThread(active.id);
                    return;
                }

                if (action === 'mark-read') {
                    await loadMessages(false, true);
                    await loadThreads();
                    return;
                }

                if (action === 'members') {
                    await openGroupMembersModal();
                    return;
                }

                if (action === 'add-member') {
                    await openAddGroupMemberModal();
                    return;
                }

                if (action === 'member-requests') {
                    await openGroupMemberRequestsModal();
                    return;
                }

                if (action === 'show-images') {
                    await openConversationMediaModal('images');
                    return;
                }

                if (action === 'show-files') {
                    await openConversationMediaModal('files');
                    return;
                }

                if (action === 'refresh') {
                    await loadMessages(true, true);
                    await loadThreads();
                }
            }

            async function sendChatPayload(payloadObj) {
                const active = activeThread();
                if (!active) {
                    alert('No active thread selected.');
                    return 0;
                }
                const threadKind = String(active.thread_kind || '');
                if (threadKind === 'external') {
                    alert('External incident conversations are read-only.');
                    return 0;
                }
                const isUserThread = threadKind === 'user';
                const isGroupThread = threadKind === 'group';
                const entityType = isGroupThread ? 'agency_group_chat' : (isUserThread ? 'agency_user_chat' : 'agency_chat');
                const entityId = isGroupThread
                    ? Number(active.group_id || active.entity_id || 0)
                    : (isUserThread
                        ? Number(active.user_id || active.entity_id || 0)
                        : Number(active.entity_id || 0));
                if (!Number.isInteger(entityId) || entityId <= 0) {
                    alert('Invalid thread target. Please re-open the thread.');
                    return 0;
                }
                try {
                    const res = await fetch('api/activity_event.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'chat',
                            entity_type: entityType,
                            entity_id: entityId,
                            details: JSON.stringify(payloadObj)
                        })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        const reason = (data && (data.detail || data.error)) ? String(data.detail || data.error) : 'Send failed';
                        alert('Failed to send message: ' + reason);
                        return 0;
                    }
                    return Number(data.message_id) || 0;
                } catch (error) {
                    const reason = (error && error.message) ? String(error.message) : 'Network error while sending message.';
                    alert(reason);
                    return 0;
                }
            }

            function isIncidentPickerOpen() {
                return !!(incidentPickerModal && incidentPickerModal.classList.contains('show'));
            }

            function openIncidentPicker() {
                if (!incidentPickerModal) return;
                if (!activeThread()) {
                    alert('Select a conversation first.');
                    return;
                }
                incidentPickerModal.hidden = false;
                incidentPickerModal.setAttribute('aria-hidden', 'false');
                incidentPickerModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                state.incidentPickerLoading = true;
                state.incidentPickerQuery = '';
                state.incidentPickerItems = [];
                if (incidentPickerSearchInput) incidentPickerSearchInput.value = '';
                renderIncidentPicker();
                loadIncidentPicker();
                window.setTimeout(() => {
                    if (incidentPickerSearchInput) incidentPickerSearchInput.focus();
                }, 0);
            }

            function closeIncidentPicker() {
                if (!incidentPickerModal) return;
                incidentPickerModal.classList.remove('show');
                incidentPickerModal.setAttribute('aria-hidden', 'true');
                incidentPickerModal.hidden = true;
                document.body.style.overflow = '';
                state.incidentPickerLoading = false;
                state.incidentPickerQuery = '';
                state.incidentPickerItems = [];
                if (incidentPickerSearchInput) incidentPickerSearchInput.value = '';
                if (sendIncidentBtn) sendIncidentBtn.focus();
            }

            async function loadIncidentPicker() {
                state.incidentPickerLoading = true;
                renderIncidentPicker();
                try {
                    const res = await fetch('api/incidents_list.php?status=active', { cache: 'no-store' });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        state.incidentPickerItems = [];
                    } else {
                        state.incidentPickerItems = Array.isArray(data.items) ? data.items : [];
                    }
                } catch (_) {
                    state.incidentPickerItems = [];
                }
                state.incidentPickerLoading = false;
                renderIncidentPicker();
            }

            function renderIncidentPicker() {
                if (!incidentPickerList) return;
                const query = String(state.incidentPickerQuery || '').trim().toLowerCase();
                const items = state.incidentPickerItems.filter((item) => {
                    if (!query) return true;
                    const hay = `#${item.id} ${item.incident_code || ''} ${item.type || ''} ${item.location || ''} ${item.title || ''}`.toLowerCase();
                    return hay.includes(query);
                });

                if (state.incidentPickerLoading && !state.incidentPickerItems.length) {
                    incidentPickerList.innerHTML = '<div class="ia-user-picker-empty">Loading incidents...</div>';
                    return;
                }
                if (!items.length) {
                    const emptyLabel = state.incidentPickerItems.length ? 'No incidents match your search.' : 'No active incidents available.';
                    incidentPickerList.innerHTML = `<div class="ia-user-picker-empty">${escapeHtml(emptyLabel)}</div>`;
                    return;
                }

                incidentPickerList.innerHTML = items.map((item) => {
                    const code = String(item.incident_code || '').trim();
                    const idLabel = code !== '' ? escapeHtml(code) : `#${item.id}`;
                    const type = String(item.type || '').trim();
                    const location = String(item.location || '').trim();
                    const priority = String(item.priority || '').trim();
                    const status = String(item.status || '').trim();
                    const metaParts = [];
                    if (type) metaParts.push(escapeHtml(type));
                    if (priority) metaParts.push(escapeHtml(priority));
                    if (status) metaParts.push(escapeHtml(status));
                    const metaHtml = metaParts.length ? `<p class="ia-user-option-meta">${metaParts.join(' · ')}</p>` : '';
                    const locHtml = location ? `<span class="ia-user-option-status">${escapeHtml(location)}</span>` : '';
                    return `
                        <button type="button" class="ia-user-option" data-incident-option="${escapeAttr(item.id)}" aria-pressed="false">
                            <div class="ia-user-option-top">
                                <div>
                                    <p class="ia-user-option-name">${idLabel}</p>
                                    ${metaHtml}
                                </div>
                                ${locHtml}
                            </div>
                        </button>
                    `;
                }).join('');
            }

            async function sendIncidentCard(incident) {
                if (!incident) return;
                const incidentId = Number(incident.id) || 0;
                if (incidentId <= 0) return;
                const card = {
                    incident_id: incidentId,
                    reference_no: String(incident.incident_code || '').trim(),
                    title: String(incident.title || '').trim(),
                    type: String(incident.type || '').trim(),
                    location: String(incident.location || '').trim(),
                    priority: String(incident.priority || '').trim()
                };
                const messageId = await sendChatPayload({ incident_card: card });
                if (messageId > 0) {
                    closeIncidentPicker();
                    await loadMessages(false, true);
                    await loadThreads();
                }
            }

            function updateIncidentCardDom(article, item) {
                if (!article || !item) return;
                const status = String(item.incident_status || 'pending').toLowerCase();
                const badge = article.querySelector('[data-incident-status]');
                if (badge) {
                    badge.textContent = status === 'accepted' ? 'Accepted' : (status === 'declined' ? 'Declined' : 'Pending');
                    badge.className = 'ia-incident-status status-' + escapeAttr(status);
                }
                const acceptBtn = article.querySelector('[data-incident-accept]');
                const declineBtn = article.querySelector('[data-incident-decline]');
                if (acceptBtn) acceptBtn.disabled = status !== 'pending';
                if (declineBtn) declineBtn.disabled = status !== 'pending';
            }

            async function handleIncidentDecision(article, item, decision) {
                if (!article || !item || !item.incident_card) return;
                const messageId = Number(item.id) || 0;
                const incidentId = Number(item.incident_card.incident_id) || 0;
                if (messageId <= 0) return;
                if (String(item.incident_status || 'pending') !== 'pending') return;

                const confirmMsg = decision === 'accepted'
                    ? 'Accept this incident assignment?'
                    : 'Decline this incident assignment?';
                if (!window.confirm(confirmMsg)) return;

                let statusRes;
                try {
                    const res = await fetch('api/interagency_incident_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ message_id: messageId, incident_id: incidentId, status: decision })
                    });
                    statusRes = await res.json();
                } catch (_) {
                    statusRes = null;
                }
                if (!statusRes || !statusRes.ok) {
                    alert('Unable to record the decision. Please try again.');
                    return;
                }

                item.incident_status = decision;
                updateIncidentCardDom(article, item);

                const label = String(item.incident_card.reference_no || ('#' + incidentId));
                const verb = decision === 'accepted' ? 'accepted' : 'declined';
                const followUp = `[INCIDENT] Incident ${label} ${verb} by ${state.currentUser.name}`;
                await sendChatPayload({ text: followUp });
                await loadMessages(false, true);
                await loadThreads();
            }

            function closeIncidentDetail() {
                if (!incidentDetailModal) return;
                incidentDetailModal.classList.remove('show');
                incidentDetailModal.setAttribute('aria-hidden', 'true');
                incidentDetailModal.hidden = true;
                document.body.style.overflow = '';
            }

            async function openIncidentDetail(incidentId) {
                if (!incidentDetailModal || !incidentDetailModalBody) return;
                incidentDetailModal.hidden = false;
                incidentDetailModal.setAttribute('aria-hidden', 'false');
                incidentDetailModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                incidentDetailModalBody.innerHTML = '<div class="ia-media-empty">Loading incident details...</div>';
                if (incidentDetailModalSubtitle) {
                    incidentDetailModalSubtitle.textContent = `Incident #${incidentId}`;
                }
                try {
                    const res = await fetch('api/incident_details.php?id=' + encodeURIComponent(String(incidentId)), { cache: 'no-store' });
                    const data = await res.json();
                    if (!data || !data.ok || !data.incident) {
                        incidentDetailModalBody.innerHTML = '<div class="ia-media-empty">Incident not found.</div>';
                        return;
                    }
                    renderIncidentDetail(data.incident);
                } catch (_) {
                    incidentDetailModalBody.innerHTML = '<div class="ia-media-empty">Unable to load incident details.</div>';
                }
            }

            function renderIncidentDetail(incident) {
                if (!incidentDetailModalBody) return;
                const id = Number(incident.id) || 0;
                const rows = [];
                const addRow = (key, value) => {
                    if (value === null || value === undefined || value === '') return;
                    rows.push({ key, value: String(value) });
                };
                if (incident.reference_no) addRow('Reference', incident.reference_no);
                addRow('Type', incident.type);
                addRow('Priority', incident.priority);
                addRow('Status', incident.status);
                addRow('Title', incident.title);
                addRow('Location', incident.location_address);
                addRow('Description', incident.description);
                addRow('Reported by', incident.caller_name);
                addRow('Assigned unit', incident.assigned_unit_identifier);
                if (incident.created_at) addRow('Reported at', incident.created_at);

                const statusValue = String(incident.status || '').trim().toLowerCase();
                const statusColor = statusValue === 'resolved' || statusValue === 'closed'
                    ? 'background:#dcfce7;color:#166534'
                    : (statusValue === 'dispatched' || statusValue === 'active'
                        ? 'background:#dbeafe;color:#1e40af'
                        : 'background:#fef3c7;color:#92400e');

                incidentDetailModalBody.innerHTML = `
                    <div class="ia-incident-detail-grid">
                        <div class="ia-incident-detail-row">
                            <span class="ia-incident-detail-key">Incident ID</span>
                            <p class="ia-incident-detail-value">#${id}</p>
                        </div>
                        ${rows.map((row) => {
                            if (row.key === 'Status') {
                                return `
                                    <div class="ia-incident-detail-row">
                                        <span class="ia-incident-detail-key">Status</span>
                                        <p class="ia-incident-detail-value"><span class="ia-incident-detail-badge" style="${statusColor}">${escapeHtml(row.value)}</span></p>
                                    </div>`;
                            }
                            return `
                                <div class="ia-incident-detail-row">
                                    <span class="ia-incident-detail-key">${escapeHtml(row.key)}</span>
                                    <p class="ia-incident-detail-value">${escapeHtml(row.value)}</p>
                                </div>`;
                        }).join('')}
                    </div>
                `;
            }

            async function handleSendMessage(event) {
                event.preventDefault();
                const text = messageInput.value.trim();
                if (!text && !state.pendingFiles.length) return;
                const active = activeThread();
                if (!active) return;

                const priority = String(messagePriority.value || 'Routine');
                const payloadText = text ? `[${priority.toUpperCase()}] ${text}` : '';
                const replyTo = state.replyTo ? {
                    message_id: Number(state.replyTo.message_id) || 0,
                    sender_name: String(state.replyTo.sender_name || ''),
                    text: String(state.replyTo.text || ''),
                    attachment_count: Number(state.replyTo.attachment_count || 0)
                } : null;

                try {
                    const attachments = await uploadPendingFiles();
                    const payloadObj = {
                        text: payloadText,
                        attachments: attachments
                    };
                    if (replyTo) {
                        payloadObj.reply_to = replyTo;
                    }
                    const messageId = await sendChatPayload(payloadObj);
                    if (messageId <= 0) return;
                    stopTypingStatus();
                    messageInput.value = '';
                    clearPendingFiles();
                    clearReplyTarget();
                    await loadMessages(false, true);
                    await loadThreads();
                } catch (error) {
                    const reason = (error && error.message) ? String(error.message) : 'Network error while sending message.';
                    alert(reason);
                }
            }

            async function addUserThread() {
                openAddThreadModal();
                state.addThreadLoading = true;
                state.addThreadUsers = [];
                state.addThreadQuery = '';
                state.addThreadSelectedId = 0;
                if (addThreadSearchInput) addThreadSearchInput.value = '';
                renderAddThreadUsers();

                try {
                    const usersRes = await fetch('api/interagency_users.php', { cache: 'no-store' });
                    const usersData = await usersRes.json();
                    if (!usersData || !usersData.ok) {
                        state.addThreadUsers = [];
                        state.addThreadLoading = false;
                        renderAddThreadUsers();
                        alert('Unable to load users.');
                        return;
                    }

                    state.addThreadUsers = (Array.isArray(usersData.items) ? usersData.items : []).filter((u) => !u.has_thread);
                    state.addThreadSelectedId = state.addThreadUsers.length ? Number(state.addThreadUsers[0].id) : 0;
                    state.addThreadLoading = false;
                    renderAddThreadUsers();
                } catch (_) {
                    state.addThreadUsers = [];
                    state.addThreadLoading = false;
                    renderAddThreadUsers();
                    alert('Network error while loading users.');
                }
            }

            async function confirmAddUserThread() {
                const selected = selectedAddThreadUser();
                if (!selected || state.addThreadLoading) return;

                state.addThreadLoading = true;
                syncAddThreadConfirmState();

                try {
                    const addRes = await fetch('api/interagency_add_user_thread.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ user_id: Number(selected.id) })
                    });
                    const addData = await addRes.json();
                    if (!addData || !addData.ok) {
                        state.addThreadLoading = false;
                        syncAddThreadConfirmState();
                        alert((addData && addData.error) ? String(addData.error) : 'Unable to add user thread.');
                        return;
                    }

                    const threadId = addData.thread && addData.thread.id ? String(addData.thread.id) : ('user-' + selected.id);
                    closeAddThreadModal();
                    await loadThreads();
                    if (state.threads.some((item) => item.id === threadId)) {
                        await selectThread(threadId);
                    }
                } catch (_) {
                    state.addThreadLoading = false;
                    syncAddThreadConfirmState();
                    alert('Network error while adding thread.');
                }
            }

            async function createGroupChat() {
                openGroupChatModal();
                state.groupLoading = true;
                state.groupUsers = [];
                state.groupQuery = '';
                state.groupSelectedIds = [];
                if (groupChatNameInput) groupChatNameInput.value = '';
                if (groupChatSearchInput) groupChatSearchInput.value = '';
                renderGroupUsers();

                try {
                    const usersRes = await fetch('api/interagency_users.php', { cache: 'no-store' });
                    const usersData = await usersRes.json();
                    if (!usersData || !usersData.ok) {
                        state.groupUsers = [];
                        state.groupLoading = false;
                        renderGroupUsers();
                        alert('Unable to load users.');
                        return;
                    }

                    state.groupUsers = Array.isArray(usersData.items) ? usersData.items : [];
                    state.groupLoading = false;
                    renderGroupUsers();
                } catch (_) {
                    state.groupUsers = [];
                    state.groupLoading = false;
                    renderGroupUsers();
                    alert('Network error while loading users.');
                }
            }

            async function confirmCreateGroupChat() {
                if (state.groupLoading) return;

                const name = groupChatNameInput ? groupChatNameInput.value.trim() : '';
                const userIds = selectedGroupUserIds();
                if (!name) {
                    alert('Enter a group name.');
                    return;
                }
                if (userIds.length < 2) {
                    alert('Select at least two users.');
                    return;
                }

                state.groupLoading = true;
                syncGroupCreateState();

                try {
                    const res = await fetch('api/interagency_group_create.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name, user_ids: userIds })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        state.groupLoading = false;
                        syncGroupCreateState();
                        alert((data && data.error) ? String(data.error) : 'Unable to create group chat.');
                        return;
                    }

                    const threadId = data.thread && data.thread.id ? String(data.thread.id) : '';
                    closeGroupChatModal();
                    await loadThreads();
                    if (threadId && state.threads.some((item) => item.id === threadId)) {
                        await selectThread(threadId);
                    }
                } catch (_) {
                    state.groupLoading = false;
                    syncGroupCreateState();
                    alert('Network error while creating group chat.');
                }
            }

            async function renameThread(threadId) {
                const target = state.threads.find((item) => item.id === threadId);
                if (!target) return;

                const currentTitle = String(target.title || target.id || '').trim();
                const raw = window.prompt('Edit thread name:', currentTitle);
                if (raw === null) return;

                const nextTitle = String(raw).trim().replace(/\s+/g, ' ');
                if (!nextTitle) {
                    alert('Thread name cannot be empty.');
                    return;
                }
                if (nextTitle.length > 120) {
                    alert('Thread name is too long (max 120 characters).');
                    return;
                }
                if (nextTitle === currentTitle) {
                    return;
                }

                const payload = {
                    title: nextTitle,
                    thread_kind: String(target.thread_kind || 'department')
                };

                if (payload.thread_kind === 'group') {
                    const groupId = Number(target.group_id || target.entity_id || 0);
                    if (!Number.isInteger(groupId) || groupId <= 0) {
                        alert('Invalid group thread target.');
                        return;
                    }
                    payload.group_id = groupId;
                } else if (payload.thread_kind === 'user') {
                    const userId = Number(target.user_id || target.entity_id || 0);
                    if (!Number.isInteger(userId) || userId <= 0) {
                        alert('Invalid user thread target.');
                        return;
                    }
                    payload.user_id = userId;
                } else {
                    payload.department = String(target.department || '').trim().toLowerCase();
                    if (!payload.department) {
                        alert('Invalid department thread target.');
                        return;
                    }
                }

                try {
                    const res = await fetch('api/interagency_thread_title_update.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        const reason = (data && data.error) ? String(data.error) : 'Unable to rename thread.';
                        alert(reason);
                        return;
                    }

                    target.title = String(data.title || nextTitle);
                    renderThreadList();
                    renderChatHeader();
                } catch (_) {
                    alert('Network error while renaming thread.');
                }
            }

            function bindEvents() {
                document.querySelectorAll('.ia-tab').forEach((tab) => {
                    tab.addEventListener('click', () => {
                        document.querySelectorAll('.ia-tab').forEach((btn) => btn.classList.remove('active'));
                        tab.classList.add('active');
                        state.filter = tab.getAttribute('data-filter') || 'all';
                        renderThreadList();
                    });
                });

                threadSearchInput.addEventListener('input', () => {
                    state.query = threadSearchInput.value.trim().toLowerCase();
                    renderThreadList();
                });

                threadListEl.addEventListener('click', (event) => {
                    const editBtn = event.target.closest('[data-edit-id]');
                    if (editBtn) {
                        const editId = editBtn.getAttribute('data-edit-id');
                        if (editId) {
                            renameThread(editId);
                        }
                        return;
                    }
                    const thread = event.target.closest('.ia-thread');
                    if (!thread) return;
                    const id = thread.getAttribute('data-id');
                    if (!id) return;
                    selectThread(id);
                });

                threadListEl.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    const editBtn = event.target.closest('[data-edit-id]');
                    if (!editBtn) return;
                    event.preventDefault();
                    const editId = editBtn.getAttribute('data-edit-id');
                    if (editId) {
                        renameThread(editId);
                    }
                });

                chatHeaderEl.addEventListener('click', async (event) => {
                    const toggle = event.target.closest('[data-chat-settings-toggle]');
                    if (toggle) {
                        event.preventDefault();
                        event.stopPropagation();
                        state.chatSettingsOpen = !state.chatSettingsOpen;
                        renderChatHeader();
                        return;
                    }

                    const actionBtn = event.target.closest('[data-chat-setting-action]');
                    if (!actionBtn) return;
                    event.preventDefault();
                    event.stopPropagation();
                    await handleChatSettingAction(String(actionBtn.getAttribute('data-chat-setting-action') || ''));
                });

                if (activeIncidentBannerEl) {
                    activeIncidentBannerEl.addEventListener('click', async (event) => {
                        const openBtn = event.target.closest('[data-active-incident-open]');
                        if (!openBtn) return;
                        event.preventDefault();
                        event.stopPropagation();
                        const incidentId = Number(openBtn.getAttribute('data-active-incident-open') || 0);
                        if (incidentId > 0) {
                            await openIncidentDetail(incidentId);
                        }
                    });
                }

                chatTimelineEl.addEventListener('click', async (event) => {
                    const viewBtn = event.target.closest('[data-incident-view]');
                    if (viewBtn) {
                        event.preventDefault();
                        const incidentId = Number(viewBtn.getAttribute('data-incident-view') || 0);
                        if (incidentId > 0) {
                            await openIncidentDetail(incidentId);
                        }
                        return;
                    }
                    const acceptBtn = event.target.closest('[data-incident-accept]');
                    if (acceptBtn) {
                        event.preventDefault();
                        const article = acceptBtn.closest('.ia-message');
                        const messageId = article ? String(article.getAttribute('data-message-id') || '') : '';
                        const item = messageId ? state.messageItems[messageId] : null;
                        await handleIncidentDecision(article, item, 'accepted');
                        return;
                    }
                    const declineBtn = event.target.closest('[data-incident-decline]');
                    if (declineBtn) {
                        event.preventDefault();
                        const article = declineBtn.closest('.ia-message');
                        const messageId = article ? String(article.getAttribute('data-message-id') || '') : '';
                        const item = messageId ? state.messageItems[messageId] : null;
                        await handleIncidentDecision(article, item, 'declined');
                        return;
                    }

                    const toggle = event.target.closest('[data-message-menu-toggle]');
                    if (toggle) {
                        event.preventDefault();
                        event.stopPropagation();
                        const actionsEl = toggle.closest('[data-message-actions]');
                        if (!actionsEl) return;
                        const shouldOpen = !actionsEl.classList.contains('open');
                        closeMessageMenus(actionsEl);
                        setMessageMenuOpen(actionsEl, shouldOpen);
                        return;
                    }

                    const actionBtn = event.target.closest('[data-message-action]');
                    if (!actionBtn) return;
                    event.preventDefault();
                    const article = actionBtn.closest('.ia-message');
                    const messageId = article ? String(article.getAttribute('data-message-id') || '') : '';
                    const item = messageId ? state.messageItems[messageId] : null;
                    closeMessageMenus();
                    await handleMessageAction(String(actionBtn.getAttribute('data-message-action') || ''), article, item);
                });

                chatForm.addEventListener('submit', handleSendMessage);
                if (messageInput) {
                    messageInput.addEventListener('input', () => {
                        if (messageInput.value.trim()) {
                            startTypingStatus();
                        } else {
                            stopTypingStatus();
                        }
                    });
                    messageInput.addEventListener('blur', () => {
                        if (state.typingTimer) window.clearTimeout(state.typingTimer);
                        state.typingTimer = window.setTimeout(stopTypingStatus, 900);
                    });
                }
                if (attachFileBtn && messageFilesInput) {
                    attachFileBtn.addEventListener('click', () => messageFilesInput.click());
                    messageFilesInput.addEventListener('change', () => {
                        const picked = Array.from(messageFilesInput.files || []);
                        if (!picked.length) return;
                        const merged = [...state.pendingFiles, ...picked].slice(0, 5);
                        state.pendingFiles = merged;
                        syncFileInput();
                        renderPendingFiles();
                    });
                }
                if (addThreadBtn) {
                    addThreadBtn.addEventListener('click', addUserThread);
                }
                if (createGroupBtn) {
                    createGroupBtn.addEventListener('click', createGroupChat);
                }
                if (addThreadSearchInput) {
                    addThreadSearchInput.addEventListener('input', () => {
                        state.addThreadQuery = addThreadSearchInput.value || '';
                        renderAddThreadUsers();
                    });
                }
                if (addThreadUserList) {
                    addThreadUserList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-add-thread-user]');
                        if (!option) return;
                        const userId = Number(option.getAttribute('data-add-thread-user') || 0);
                        if (userId <= 0) return;
                        state.addThreadSelectedId = userId;
                        renderAddThreadUsers();
                    });
                }
                if (addThreadConfirmBtn) {
                    addThreadConfirmBtn.addEventListener('click', confirmAddUserThread);
                }
                if (addThreadCancelBtn) {
                    addThreadCancelBtn.addEventListener('click', closeAddThreadModal);
                }
                if (addThreadModalCloseBtn) {
                    addThreadModalCloseBtn.addEventListener('click', closeAddThreadModal);
                }
                if (addThreadModal) {
                    addThreadModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-add-thread]')) {
                            closeAddThreadModal();
                        }
                    });
                }
                if (groupChatNameInput) {
                    groupChatNameInput.addEventListener('input', syncGroupCreateState);
                }
                if (groupChatSearchInput) {
                    groupChatSearchInput.addEventListener('input', () => {
                        state.groupQuery = groupChatSearchInput.value || '';
                        renderGroupUsers();
                    });
                }
                if (groupChatUserList) {
                    groupChatUserList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-group-user]');
                        if (!option) return;
                        const userId = Number(option.getAttribute('data-group-user') || 0);
                        if (userId <= 0) return;
                        if (state.groupSelectedIds.some((id) => Number(id) === userId)) {
                            state.groupSelectedIds = state.groupSelectedIds.filter((id) => Number(id) !== userId);
                        } else {
                            state.groupSelectedIds = [...state.groupSelectedIds, userId];
                        }
                        renderGroupUsers();
                    });
                }
                if (groupChatCreateBtn) {
                    groupChatCreateBtn.addEventListener('click', confirmCreateGroupChat);
                }
                if (groupChatCancelBtn) {
                    groupChatCancelBtn.addEventListener('click', closeGroupChatModal);
                }
                if (groupChatModalCloseBtn) {
                    groupChatModalCloseBtn.addEventListener('click', closeGroupChatModal);
                }
                if (groupChatModal) {
                    groupChatModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-group-chat]')) {
                            closeGroupChatModal();
                        }
                    });
                }
                if (sendIncidentBtn) {
                    sendIncidentBtn.addEventListener('click', openIncidentPicker);
                }
                if (incidentPickerSearchInput) {
                    incidentPickerSearchInput.addEventListener('input', () => {
                        state.incidentPickerQuery = incidentPickerSearchInput.value || '';
                        renderIncidentPicker();
                    });
                }
                if (incidentPickerList) {
                    incidentPickerList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-incident-option]');
                        if (!option) return;
                        const incidentId = Number(option.getAttribute('data-incident-option') || 0);
                        const incident = state.incidentPickerItems.find((item) => Number(item.id) === incidentId) || null;
                        if (incident) {
                            sendIncidentCard(incident);
                        }
                    });
                }
                if (incidentPickerCancelBtn) {
                    incidentPickerCancelBtn.addEventListener('click', closeIncidentPicker);
                }
                if (incidentPickerModalCloseBtn) {
                    incidentPickerModalCloseBtn.addEventListener('click', closeIncidentPicker);
                }
                if (incidentPickerModal) {
                    incidentPickerModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-incident-picker]')) {
                            closeIncidentPicker();
                        }
                    });
                }
                if (incidentDetailModalCloseBtn) {
                    incidentDetailModalCloseBtn.addEventListener('click', closeIncidentDetail);
                }
                if (incidentDetailModal) {
                    incidentDetailModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-incident-detail]')) {
                            closeIncidentDetail();
                        }
                    });
                }
                if (addGroupMemberSearchInput) {
                    addGroupMemberSearchInput.addEventListener('input', () => {
                        state.addGroupMemberQuery = addGroupMemberSearchInput.value || '';
                        renderAddGroupMemberUsers();
                    });
                }
                if (addGroupMemberUserList) {
                    addGroupMemberUserList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-add-group-member-user]');
                        if (!option) return;
                        const userId = Number(option.getAttribute('data-add-group-member-user') || 0);
                        if (userId <= 0) return;
                        state.addGroupMemberSelectedId = userId;
                        renderAddGroupMemberUsers();
                    });
                }
                if (addGroupMemberConfirmBtn) {
                    addGroupMemberConfirmBtn.addEventListener('click', confirmAddGroupMember);
                }
                if (addGroupMemberCancelBtn) {
                    addGroupMemberCancelBtn.addEventListener('click', closeAddGroupMemberModal);
                }
                if (addGroupMemberModalCloseBtn) {
                    addGroupMemberModalCloseBtn.addEventListener('click', closeAddGroupMemberModal);
                }
                if (addGroupMemberModal) {
                    addGroupMemberModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-add-group-member]')) {
                            closeAddGroupMemberModal();
                        }
                    });
                }
                if (groupMemberRequestsModalCloseBtn) {
                    groupMemberRequestsModalCloseBtn.addEventListener('click', closeGroupMemberRequestsModal);
                }
                if (groupMemberRequestsModal) {
                    groupMemberRequestsModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-group-member-requests]')) {
                            closeGroupMemberRequestsModal();
                            return;
                        }
                        const reviewBtn = event.target.closest('[data-review-member-request]');
                        if (reviewBtn) {
                            reviewGroupMemberRequest(
                                reviewBtn.getAttribute('data-request-id'),
                                String(reviewBtn.getAttribute('data-review-member-request') || '')
                            );
                        }
                    });
                }
                if (conversationMediaModalCloseBtn) {
                    conversationMediaModalCloseBtn.addEventListener('click', closeConversationMediaModal);
                }
                if (conversationMediaModal) {
                    conversationMediaModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-conversation-media]')) {
                            closeConversationMediaModal();
                        }
                    });
                }
                if (groupMembersModalCloseBtn) {
                    groupMembersModalCloseBtn.addEventListener('click', closeGroupMembersModal);
                }
                if (groupMembersModal) {
                    groupMembersModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-group-members]')) {
                            closeGroupMembersModal();
                            return;
                        }
                        const removeBtn = event.target.closest('[data-remove-group-member]');
                        if (removeBtn) {
                            const userId = Number(removeBtn.getAttribute('data-remove-group-member') || 0);
                            const memberName = removeBtn.getAttribute('data-member-name') || '';
                            removeGroupMember(userId, memberName);
                        }
                    });
                }
                document.addEventListener('click', (event) => {
                    if (event.target.closest('[data-clear-reply]')) {
                        clearReplyTarget();
                        return;
                    }
                    if (!event.target.closest('[data-message-actions]')) {
                        closeMessageMenus();
                    }
                    if (state.chatSettingsOpen && !event.target.closest('.ia-chat-actions')) {
                        state.chatSettingsOpen = false;
                        renderChatHeader();
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeMessageMenus();
                        if (state.chatSettingsOpen) {
                            state.chatSettingsOpen = false;
                            renderChatHeader();
                        }
                        if (isAddThreadModalOpen()) {
                            closeAddThreadModal();
                        }
                        if (isGroupChatModalOpen()) {
                            closeGroupChatModal();
                        }
                        if (isConversationMediaModalOpen()) {
                            closeConversationMediaModal();
                        }
                        if (isGroupMembersModalOpen()) {
                            closeGroupMembersModal();
                        }
                        if (addGroupMemberModal && addGroupMemberModal.classList.contains('show')) {
                            closeAddGroupMemberModal();
                        }
                        if (groupMemberRequestsModal && groupMemberRequestsModal.classList.contains('show')) {
                            closeGroupMemberRequestsModal();
                        }
                        if (isIncidentPickerOpen()) {
                            closeIncidentPicker();
                        }
                        if (incidentDetailModal && incidentDetailModal.classList.contains('show')) {
                            closeIncidentDetail();
                        }
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', async () => {
                bindEvents();
                try {
                    await loadActiveIncidentBanner();
                    await loadThreads();
                    if (state.activeId) await selectThread(state.activeId);
                } catch (_) {}

                let interagencyPollInFlight = false;
                state.poller = setInterval(async () => {
                    if (document.hidden || interagencyPollInFlight) return;
                    interagencyPollInFlight = true;
                    try {
                        await loadActiveIncidentBanner();
                        await loadThreads();
                        await loadMessages(false, false);
                        await loadTypingIndicator();
                    } catch (_) {
                    } finally {
                        interagencyPollInFlight = false;
                    }
                }, 5000);

                let presencePollInFlight = false;
                state.presencePoller = setInterval(async () => {
                    if (document.hidden || presencePollInFlight) return;
                    presencePollInFlight = true;
                    try {
                        await loadUserStatuses();
                    } catch (_) {
                    } finally {
                        presencePollInFlight = false;
                    }
                }, 2000);
            });
        })();
    </script>
    <script src="js/interagency-operations.js"></script>
    <script src="js/interagency-command.js"></script>
    <script src="js/interagency-events.js?v=<?php echo filemtime($rootDir . '/js/interagency-events.js'); ?>"></script>
    <script src="js/interagency-tips.js?v=20260809-ph-time-v1"></script>
    <script src="js/admin-anonymous-tip-details.js?v=20260808-admin-tip-details-v2"></script>
    <script src="js/interagency-external-inbox.js"></script>
    <script src="js/interagency-module-launcher.js"></script>
</body>
</html>
