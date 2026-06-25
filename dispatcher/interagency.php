<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/interagency.php');

$pageTitle = 'Inter-Agency Coordination';
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
            padding: 3rem 1.5rem;
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
            grid-template-columns: repeat(3, minmax(160px, 1fr));
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

        .ia-user-status-state.online {
            border-color: #bbf7d0;
            background: #ecfdf5;
            color: #166534;
        }

        .ia-user-status-state.offline {
            border-color: #e2e8f0;
            background: #f1f5f9;
            color: #64748b;
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
            grid-template-columns: repeat(4, 1fr);
            gap: 0.45rem;
        }
        
        .ia-list-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ia-list-actions .ia-tabs {
            flex: 1;
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

        .ia-dot.online,
        .ia-dot.active {
            background: #22c55e;
        }

        .ia-dot.busy {
            background: #f59e0b;
        }

        .ia-dot.offline,
        .ia-dot.inactive {
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
            grid-template-columns: 160px 1fr auto auto;
            gap: 0.6rem;
            align-items: center;
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
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid #dbe6f1;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
        }

        .ia-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid #e7edf4;
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

        .ia-modal-search {
            position: relative;
            margin-bottom: 0.9rem;
        }

        .ia-modal-search i {
            position: absolute;
            top: 50%;
            left: 0.8rem;
            transform: translateY(-50%);
            color: #64748b;
        }

        .ia-modal-search input {
            width: 100%;
            border: 1px solid #d7e1ea;
            border-radius: 10px;
            padding: 0.72rem 0.85rem 0.72rem 2.3rem;
            color: #102a43;
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
        }

        .ia-user-option.selected {
            border-color: #0f766e;
            background: #ecfdf5;
        }

        .ia-user-option-top,
        .ia-user-option-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .ia-user-option-name,
        .ia-user-option-meta {
            margin: 0;
        }

        .ia-user-option-name { font-weight: 800; color: #102a43; }
        .ia-user-option-meta { margin-top: 0.3rem; color: #64748b; font-size: 0.78rem; }
        .ia-user-option-badge { border-radius: 999px; padding: 0.3rem 0.55rem; background: #eef2ff; color: #3730a3; font-size: 0.72rem; font-weight: 700; }
        .ia-user-option-status { color: #0f766e; font-size: 0.74rem; font-weight: 700; }
        .ia-user-picker-empty { padding: 1rem; border: 1px dashed #d7e1ea; border-radius: 14px; text-align: center; color: #64748b; background: #f8fbff; }

        .ia-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.65rem;
            padding: 0.9rem 1.1rem;
            border-top: 1px solid #e7edf4;
        }

        .ia-modal-btn { border: 1px solid #d7e1ea; border-radius: 10px; padding: 0.62rem 0.85rem; cursor: pointer; font-weight: 800; }
        .ia-modal-btn.secondary { background: #fff; color: #35516d; }
        .ia-modal-btn.primary { background: #0f766e; border-color: #0f766e; color: #fff; }
        .ia-modal-btn:disabled { opacity: 0.55; cursor: not-allowed; }

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

        @media (max-width: 1280px) {
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
                padding: 1rem 0.8rem;
            }

            .ia-head {
                flex-direction: column;
                align-items: flex-start;
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
                    <div class="ia-stat-label">Total Chats</div>
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
            </section>

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
                        </div>
                    </div>
                    <div class="ia-thread-list" id="threadList" aria-live="polite"></div>
                </aside>

                <section class="ia-chat-panel">
                    <div class="ia-chat-head" id="chatHeader"></div>
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
                        <p class="ia-user-status-sub">Online and offline status</p>
                    </div>
                    <div class="ia-user-status-list" id="userStatusList" aria-live="polite">
                        <div class="ia-empty-list">Loading users...</div>
                    </div>
                </aside>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

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

    <div class="ia-modal-shell" id="requestGroupMemberModal" hidden aria-hidden="true">
        <div class="ia-modal-backdrop" data-close-request-group-member></div>
        <div class="ia-modal" role="dialog" aria-modal="true" aria-labelledby="requestGroupMemberModalTitle">
            <div class="ia-modal-head">
                <div>
                    <p class="ia-modal-title" id="requestGroupMemberModalTitle">Request Add Member</p>
                    <p class="ia-modal-subtitle" id="requestGroupMemberModalSubtitle">Choose an active user. An admin must approve the request first.</p>
                </div>
                <button type="button" class="ia-modal-close" id="requestGroupMemberModalCloseBtn" aria-label="Close member request modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ia-modal-body">
                <div class="ia-modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="requestGroupMemberSearchInput" placeholder="Search users to request...">
                </div>
                <div class="ia-user-picker-list" id="requestGroupMemberUserList"></div>
            </div>
            <div class="ia-modal-actions">
                <button type="button" class="ia-modal-btn secondary" id="requestGroupMemberCancelBtn">Cancel</button>
                <button type="button" class="ia-modal-btn primary" id="requestGroupMemberConfirmBtn" disabled>Send Request</button>
            </div>
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
                groupMemberRequestUsers: [],
                groupMemberRequestQuery: '',
                groupMemberRequestSelectedId: 0,
                groupMemberRequestGroupId: 0,
                groupMemberRequestLoading: false,
                userStatuses: [],
                chatSettingsOpen: false,
                poller: null
            };

            const threadListEl = document.getElementById('threadList');
            const chatHeaderEl = document.getElementById('chatHeader');
            const chatTimelineEl = document.getElementById('chatTimeline');
            const threadSearchInput = document.getElementById('threadSearchInput');
            const messageInput = document.getElementById('messageInput');
            const messagePriority = document.getElementById('messagePriority');
            const chatForm = document.getElementById('chatForm');
            const attachFileBtn = document.getElementById('attachFileBtn');
            const messageFilesInput = document.getElementById('messageFiles');
            const replyPreviewEl = document.getElementById('replyPreview');
            const filePreviewEl = document.getElementById('filePreview');
            const addThreadBtn = document.getElementById('addThreadBtn');
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
            const requestGroupMemberModal = document.getElementById('requestGroupMemberModal');
            const requestGroupMemberModalSubtitle = document.getElementById('requestGroupMemberModalSubtitle');
            const requestGroupMemberSearchInput = document.getElementById('requestGroupMemberSearchInput');
            const requestGroupMemberUserList = document.getElementById('requestGroupMemberUserList');
            const requestGroupMemberCancelBtn = document.getElementById('requestGroupMemberCancelBtn');
            const requestGroupMemberConfirmBtn = document.getElementById('requestGroupMemberConfirmBtn');
            const requestGroupMemberModalCloseBtn = document.getElementById('requestGroupMemberModalCloseBtn');
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
                if (String(item.kind || '') === 'department') {
                    return 'Department Channel';
                }
                return `${formatRole(item.role || 'responder')} Channel`;
            }

            function statusDotClass(value) {
                const status = String(value || '').toLowerCase();
                if (status === 'active') return 'active';
                if (status === 'inactive') return 'inactive';
                if (status === 'busy') return 'busy';
                if (status === 'online') return 'online';
                return 'offline';
            }

            function statusText(value) {
                const status = String(value || '').toLowerCase();
                if (status === 'active') return 'Active';
                if (status === 'inactive') return 'Inactive';
                if (status === 'online') return 'Online';
                if (status === 'busy') return 'Busy';
                return 'Offline';
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
                            const status = String(member.status || '').toLowerCase() === 'active' ? 'Active' : 'Inactive';
                            const creatorBadge = member.is_creator ? '<span class="ia-member-badge">Creator</span>' : '';
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
                                        <span class="ia-member-status ${status === 'Active' ? '' : 'inactive'}">${escapeHtml(status)}</span>
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

            function selectedGroupMemberRequestUser() {
                return state.groupMemberRequestUsers.find((item) => Number(item.id) === Number(state.groupMemberRequestSelectedId)) || null;
            }

            function syncGroupMemberRequestState() {
                if (!requestGroupMemberConfirmBtn) return;
                requestGroupMemberConfirmBtn.disabled = state.groupMemberRequestLoading || !selectedGroupMemberRequestUser();
                requestGroupMemberConfirmBtn.textContent = state.groupMemberRequestLoading ? 'Sending...' : 'Send Request';
            }

            function renderGroupMemberRequestUsers() {
                if (!requestGroupMemberUserList) return;
                const query = String(state.groupMemberRequestQuery || '').trim().toLowerCase();
                const items = state.groupMemberRequestUsers.filter((item) => {
                    if (!query) return true;
                    return `${item.name || ''} ${item.email || ''} ${item.role || ''}`.toLowerCase().includes(query);
                });
                if (items.length && !items.some((item) => Number(item.id) === Number(state.groupMemberRequestSelectedId))) {
                    state.groupMemberRequestSelectedId = Number(items[0].id);
                }
                if (!items.length) state.groupMemberRequestSelectedId = 0;

                if (state.groupMemberRequestLoading && !state.groupMemberRequestUsers.length) {
                    requestGroupMemberUserList.innerHTML = '<div class="ia-user-picker-empty">Loading active users...</div>';
                    syncGroupMemberRequestState();
                    return;
                }
                if (!items.length) {
                    requestGroupMemberUserList.innerHTML = '<div class="ia-user-picker-empty">All active users are already members of this group.</div>';
                    syncGroupMemberRequestState();
                    return;
                }

                requestGroupMemberUserList.innerHTML = items.map((item) => {
                    const selected = Number(item.id) === Number(state.groupMemberRequestSelectedId);
                    return `
                        <button type="button" class="ia-user-option ${selected ? 'selected' : ''}" data-request-group-member-user="${escapeAttr(item.id)}" aria-pressed="${selected ? 'true' : 'false'}">
                            <div class="ia-user-option-top">
                                <div>
                                    <p class="ia-user-option-name">${escapeHtml(item.name || ('User #' + item.id))}</p>
                                    <p class="ia-user-option-meta">${escapeHtml(item.email || 'No email provided')}</p>
                                </div>
                                <span class="ia-user-option-badge">${escapeHtml(formatRole(item.role || 'user'))}</span>
                            </div>
                            <div class="ia-user-option-bottom">
                                <span class="ia-user-option-meta">ID ${escapeHtml(item.id)}</span>
                                <span class="ia-user-option-status">${selected ? 'Selected' : 'Active account'}</span>
                            </div>
                        </button>
                    `;
                }).join('');
                syncGroupMemberRequestState();
            }

            function closeGroupMemberRequestModal() {
                if (!requestGroupMemberModal) return;
                requestGroupMemberModal.classList.remove('show');
                requestGroupMemberModal.setAttribute('aria-hidden', 'true');
                requestGroupMemberModal.hidden = true;
                document.body.style.overflow = '';
                state.groupMemberRequestUsers = [];
                state.groupMemberRequestQuery = '';
                state.groupMemberRequestSelectedId = 0;
                state.groupMemberRequestGroupId = 0;
                state.groupMemberRequestLoading = false;
                if (requestGroupMemberSearchInput) requestGroupMemberSearchInput.value = '';
                if (requestGroupMemberUserList) requestGroupMemberUserList.innerHTML = '';
                syncGroupMemberRequestState();
            }

            async function openGroupMemberRequestModal() {
                const active = activeThread();
                const groupId = active ? Number(active.group_id || active.entity_id || 0) : 0;
                if (!requestGroupMemberModal || String(active && active.thread_kind || '') !== 'group' || groupId <= 0) {
                    alert('Member requests are available for group chats only.');
                    return;
                }

                requestGroupMemberModal.hidden = false;
                requestGroupMemberModal.setAttribute('aria-hidden', 'false');
                requestGroupMemberModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                state.groupMemberRequestGroupId = groupId;
                state.groupMemberRequestLoading = true;
                state.groupMemberRequestUsers = [];
                state.groupMemberRequestQuery = '';
                state.groupMemberRequestSelectedId = 0;
                if (requestGroupMemberSearchInput) requestGroupMemberSearchInput.value = '';
                if (requestGroupMemberModalSubtitle) requestGroupMemberModalSubtitle.textContent = `Choose a user to request for ${active.title || 'this group chat'}. An admin must approve first.`;
                renderGroupMemberRequestUsers();

                try {
                    const [membersRes, usersRes] = await Promise.all([
                        fetch('api/interagency_group_members.php?group_id=' + encodeURIComponent(String(groupId)), { cache: 'no-store' }),
                        fetch('api/interagency_users.php', { cache: 'no-store' })
                    ]);
                    const [membersData, usersData] = await Promise.all([membersRes.json(), usersRes.json()]);
                    if (!membersData || !membersData.ok || !usersData || !usersData.ok) {
                        throw new Error((membersData && membersData.error) || (usersData && usersData.error) || 'Unable to load users.');
                    }
                    const memberIds = new Set((Array.isArray(membersData.members) ? membersData.members : []).map((member) => Number(member.id)));
                    state.groupMemberRequestUsers = (Array.isArray(usersData.items) ? usersData.items : [])
                        .filter((item) => !memberIds.has(Number(item.id)));
                    state.groupMemberRequestLoading = false;
                    renderGroupMemberRequestUsers();
                    window.setTimeout(() => requestGroupMemberSearchInput && requestGroupMemberSearchInput.focus(), 0);
                } catch (err) {
                    closeGroupMemberRequestModal();
                    alert((err && err.message) ? String(err.message) : 'Unable to load users.');
                }
            }

            async function submitGroupMemberRequest() {
                const selected = selectedGroupMemberRequestUser();
                const groupId = Number(state.groupMemberRequestGroupId || 0);
                if (!selected || groupId <= 0 || state.groupMemberRequestLoading) return;

                state.groupMemberRequestLoading = true;
                syncGroupMemberRequestState();
                try {
                    const res = await fetch('api/interagency_group_member_request.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ group_id: groupId, user_id: Number(selected.id) })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? String(data.error) : 'Unable to submit member request.');
                    }
                    closeGroupMemberRequestModal();
                    alert('Member request sent. The user will be added after admin approval.');
                } catch (err) {
                    state.groupMemberRequestLoading = false;
                    syncGroupMemberRequestState();
                    alert((err && err.message) ? String(err.message) : 'Unable to submit member request.');
                }
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

            function renderMessageBody(item) {
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
                return replyHtml + textHtml + filesHtml;
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
                    alert('Unsend is ready in the menu, but it still needs a backend endpoint before it can remove the message for everyone.');
                    return;
                }
                if (action === 'report') {
                    alert('Report is ready in the menu, but it still needs backend handling before it can submit a report.');
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
                return d.toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit' });
            }

            function activeThread() {
                return state.threads.find((item) => item.id === state.activeId) || null;
            }

            function threadKey(thread) {
                if (!thread) return '';
                if (String(thread.thread_kind || '') === 'group') {
                    return `group:${thread.group_id || thread.entity_id || 0}`;
                }
                if (String(thread.thread_kind || '') === 'user') {
                    return `user:${thread.user_id || thread.entity_id || 0}`;
                }
                return `dept:${thread.department || ''}`;
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
                const status = String((user && user.status) || '').trim().toLowerCase();
                const online = status === 'active' || status === 'online';
                return {
                    key: online ? 'online' : 'offline',
                    label: online ? 'Online' : 'Offline'
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
                    const stat = statusDotClass(item.status);
                    const channelLabel = threadChannelLabel(item);
                    const statusLabel = statusText(item.status);
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
                                    ${escapeHtml(channelLabel)} &middot; ${escapeHtml(statusLabel)}
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
                const statusLabel = statusText(active.status);
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
                state.activeId = target.id;
                state.chatSettingsOpen = false;
                state.lastIdByDept[threadKey(target)] = 0;
                clearPendingFiles();
                clearReplyTarget();
                renderThreadList();
                renderChatHeader();
                chatTimelineEl.innerHTML = '';
                state.messageItems = {};
                closeMessageMenus();
                try {
                    await loadMessages(true, true);
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
                    await openGroupMemberRequestModal();
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
                const threadKind = String(active.thread_kind || '');
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
                    return;
                }
                try {
                    const attachments = await uploadPendingFiles();
                    const payloadObj = {
                        text: payloadText,
                        attachments: attachments
                    };
                    if (replyTo) {
                        payloadObj.reply_to = replyTo;
                    }
                    const details = JSON.stringify(payloadObj);

                    const res = await fetch('api/activity_event.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'chat',
                            entity_type: entityType,
                            entity_id: entityId,
                            details: details
                        })
                    });
                    const data = await res.json();
                    if (!data || !data.ok) {
                        const reason = (data && (data.detail || data.error)) ? String(data.detail || data.error) : 'Send failed';
                        alert('Failed to send message: ' + reason);
                        return;
                    }
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
                try {
                    const usersRes = await fetch('api/interagency_users.php', { cache: 'no-store' });
                    const usersData = await usersRes.json();
                    if (!usersData || !usersData.ok) {
                        alert('Unable to load users.');
                        return;
                    }

                    const candidates = (Array.isArray(usersData.items) ? usersData.items : []).filter((u) => !u.has_thread);
                    if (!candidates.length) {
                        alert('All active users already have conversation threads.');
                        return;
                    }

                    const menu = candidates.slice(0, 30).map((u) => `${u.id}: ${u.name} (${u.role})`).join('\n');
                    const raw = window.prompt(`Enter user ID to add as conversation thread:\n\n${menu}`);
                    if (raw === null) return;
                    const userId = Number.parseInt(String(raw).trim(), 10);
                    if (!Number.isInteger(userId) || userId <= 0) {
                        alert('Invalid user ID.');
                        return;
                    }

                    const addRes = await fetch('api/interagency_add_user_thread.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ user_id: userId })
                    });
                    const addData = await addRes.json();
                    if (!addData || !addData.ok) {
                        alert('Unable to add user thread.');
                        return;
                    }

                    await loadThreads();
                    if (addData.thread && addData.thread.id) {
                        await selectThread(String(addData.thread.id));
                    }
                } catch (_) {
                    alert('Network error while adding thread.');
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

                chatTimelineEl.addEventListener('click', async (event) => {
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
                        }
                    });
                }
                if (requestGroupMemberSearchInput) {
                    requestGroupMemberSearchInput.addEventListener('input', () => {
                        state.groupMemberRequestQuery = requestGroupMemberSearchInput.value || '';
                        renderGroupMemberRequestUsers();
                    });
                }
                if (requestGroupMemberUserList) {
                    requestGroupMemberUserList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-request-group-member-user]');
                        if (!option) return;
                        const userId = Number(option.getAttribute('data-request-group-member-user') || 0);
                        if (userId <= 0) return;
                        state.groupMemberRequestSelectedId = userId;
                        renderGroupMemberRequestUsers();
                    });
                }
                if (requestGroupMemberConfirmBtn) {
                    requestGroupMemberConfirmBtn.addEventListener('click', submitGroupMemberRequest);
                }
                if (requestGroupMemberCancelBtn) {
                    requestGroupMemberCancelBtn.addEventListener('click', closeGroupMemberRequestModal);
                }
                if (requestGroupMemberModalCloseBtn) {
                    requestGroupMemberModalCloseBtn.addEventListener('click', closeGroupMemberRequestModal);
                }
                if (requestGroupMemberModal) {
                    requestGroupMemberModal.addEventListener('click', (event) => {
                        if (event.target.matches('[data-close-request-group-member]')) {
                            closeGroupMemberRequestModal();
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
                        if (isConversationMediaModalOpen()) {
                            closeConversationMediaModal();
                        }
                        if (isGroupMembersModalOpen()) {
                            closeGroupMembersModal();
                        }
                        if (requestGroupMemberModal && requestGroupMemberModal.classList.contains('show')) {
                            closeGroupMemberRequestModal();
                        }
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', async () => {
                bindEvents();
                try {
                    await loadThreads();
                    if (state.activeId) await selectThread(state.activeId);
                } catch (_) {}

                state.poller = setInterval(async () => {
                    try {
                        await loadThreads();
                        await loadMessages(false, false);
                    } catch (_) {}
                }, 5000);
            });
        })();
    </script>
</body>
</html>
