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
            padding:
                calc(var(--app-header-height-1) + 1.25rem)
                1.5rem
                3rem;
            flex: 1 0 auto;
            min-height: 100vh;
        }

        .um-shell {
            margin-top: 0;
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

        .um-btn.um-unit-retry {
            min-height: 44px;
            justify-self: start;
            margin-top: 0.2rem;
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
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 100;
            pointer-events: auto !important;
        }

        .um-close i {
            pointer-events: none !important;
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
            position: relative;
            z-index: 100;
            pointer-events: auto !important;
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

        /* User Management directory — page-scoped people-first layout. */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        .main-content {
            padding:
                calc(var(--app-header-height-1) + 1.25rem)
                clamp(1rem, 3vw, 3rem)
                4rem;
            background:
                radial-gradient(circle at 92% 2%, rgba(37, 99, 235, 0.12), transparent 30%),
                radial-gradient(circle at 10% 32%, rgba(13, 148, 136, 0.08), transparent 28%),
                #f3f7fb;
        }

        .um-shell {
            width: min(100%, 1440px);
            margin: 0 auto;
            display: grid;
            gap: 1.1rem;
        }

        .um-sr-only {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 0, 0) !important;
            white-space: nowrap !important;
            border: 0 !important;
        }

        .um-hero {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            min-height: 178px;
            border-radius: 24px;
            padding: clamp(1.35rem, 3vw, 2rem);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            color: #f8fafc;
            background: linear-gradient(125deg, #0f172a 0%, #164e63 52%, #0f766e 100%);
            box-shadow: 0 20px 44px rgba(15, 23, 42, 0.18);
        }

        .um-hero::after {
            content: '';
            position: absolute;
            z-index: -1;
            width: 320px;
            height: 320px;
            right: -90px;
            top: -150px;
            border-radius: 50%;
            border: 52px solid rgba(255, 255, 255, 0.07);
        }

        .um-hero-copy {
            max-width: 790px;
        }

        .um-eyebrow,
        .um-section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.74rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .um-eyebrow {
            color: #99f6e4;
        }

        .um-section-kicker {
            color: #0f766e;
        }

        .um-hero h1 {
            margin: 0.55rem 0 0;
            font-size: clamp(1.65rem, 3vw, 2.35rem);
            line-height: 1.12;
            letter-spacing: -0.035em;
            color: #ffffff;
        }

        .um-hero p {
            margin: 0.72rem 0 0;
            color: rgba(248, 250, 252, 0.84);
            font-size: 0.98rem;
            line-height: 1.62;
        }

        .um-add-btn {
            position: relative;
            z-index: 1;
            min-height: 48px;
            border: 1px solid rgba(255, 255, 255, 0.34);
            border-radius: 14px;
            padding: 0.75rem 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 10px 25px rgba(2, 6, 23, 0.18);
            font-size: 0.9rem;
            font-weight: 800;
        }

        .um-add-btn:hover {
            background: #ecfeff;
            color: #115e59;
            transform: translateY(-1px);
        }

        .um-add-btn:focus-visible,
        .um-clear-btn:focus-visible,
        .um-action:focus-visible,
        .um-btn:focus-visible,
        .um-close:focus-visible {
            outline: 3px solid rgba(45, 212, 191, 0.45);
            outline-offset: 2px;
        }

        .um-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.9rem;
        }

        .um-stat {
            min-width: 0;
            border: 1px solid #dbe5f0;
            border-radius: 18px;
            padding: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.07);
        }

        .um-stat-icon {
            flex: 0 0 auto;
            width: 42px;
            height: 42px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #1d4ed8;
            background: #dbeafe;
        }

        .um-stat-active .um-stat-icon {
            color: #047857;
            background: #d1fae5;
        }

        .um-stat-responder .um-stat-icon {
            color: #b45309;
            background: #fef3c7;
        }

        .um-stat-dispatcher .um-stat-icon {
            color: #6d28d9;
            background: #ede9fe;
        }

        .um-stat > div {
            min-width: 0;
        }

        .um-stat > div > span {
            display: block;
            color: #64748b;
            font-size: 0.74rem;
            line-height: 1.3;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .um-stat strong {
            display: block;
            margin-top: 0.3rem;
            color: #0f172a;
            font-size: 1.75rem;
            line-height: 1;
        }

        .um-stat small {
            display: block;
            margin-top: 0.42rem;
            color: #64748b;
            font-size: 0.77rem;
            line-height: 1.35;
        }

        .um-directory.um-card {
            border-radius: 22px;
            border-color: #dbe5f0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 16px 38px rgba(15, 23, 42, 0.08);
        }

        .um-directory-head {
            padding: 1.2rem 1.25rem 1rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .um-directory-head h2 {
            margin: 0.34rem 0 0;
            color: #0f172a;
            font-size: 1.18rem;
            line-height: 1.35;
        }

        .um-directory-head p {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.55;
        }

        .um-summary {
            flex: 0 0 auto;
            padding: 0.42rem 0.75rem;
            color: #334155;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
        }

        .um-toolbar {
            padding: 0.95rem 1.1rem;
            grid-template-columns: minmax(220px, 1.5fr) repeat(4, minmax(125px, 0.55fr)) auto;
            align-items: end;
            gap: 0.7rem;
            background: #f8fafc;
        }

        .um-search-wrap {
            position: relative;
            min-width: 0;
        }

        .um-search-wrap > i {
            position: absolute;
            left: 0.88rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }

        .um-search {
            min-height: 44px;
            padding: 0.7rem 0.82rem 0.7rem 2.55rem;
            border-radius: 12px;
            border-color: #cbd5e1;
        }

        .um-filter-field {
            min-width: 0;
            display: grid;
            gap: 0.32rem;
        }

        .um-filter-field label {
            color: #475569;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .um-filter-field .um-select {
            min-height: 44px;
            padding: 0.62rem 0.7rem;
            border-radius: 12px;
            border-color: #cbd5e1;
            font-size: 0.84rem;
        }

        .um-clear-btn {
            min-height: 44px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 0.62rem 0.78rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.42rem;
            color: #334155;
            background: #ffffff;
            font-weight: 800;
            cursor: pointer;
        }

        .um-clear-btn:hover {
            border-color: #94a3b8;
            background: #f1f5f9;
        }

        .um-people-grid {
            min-height: 230px;
            padding: 1.1rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: start;
            gap: 0.95rem;
            background: #f8fbff;
        }

        .um-person-card {
            min-width: 0;
            height: 100%;
            border: 1px solid #dbe5f0;
            border-radius: 18px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.055);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .um-person-card:hover {
            border-color: #a5b4fc;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.1);
            transform: translateY(-2px);
        }

        .um-person-card.is-inactive {
            background: #fbfcfe;
        }

        .um-person-main {
            padding: 1rem;
            display: grid;
            gap: 0.9rem;
            flex: 1 1 auto;
        }

        .um-person-head {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: start;
            gap: 0.72rem;
        }

        .um-avatar {
            width: 46px;
            height: 46px;
            border-radius: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #0f766e;
            background: linear-gradient(145deg, #ccfbf1, #cffafe);
            border: 1px solid #99f6e4;
            font-size: 0.88rem;
            font-weight: 900;
            letter-spacing: 0.03em;
        }

        .um-person-card[data-role="dispatcher"] .um-avatar {
            color: #3730a3;
            background: linear-gradient(145deg, #e0e7ff, #ede9fe);
            border-color: #c7d2fe;
        }

        .um-person-identity {
            min-width: 0;
        }

        .um-person-identity h3 {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .um-person-role {
            display: inline-flex;
            align-items: center;
            gap: 0.36rem;
            margin-top: 0.26rem;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .um-chip {
            gap: 0.35rem;
            padding: 0.3rem 0.58rem;
            text-transform: none;
            white-space: nowrap;
        }

        .um-chip i {
            font-size: 0.52rem;
        }

        .um-contact-list {
            display: grid;
            gap: 0.48rem;
        }

        .um-contact-link,
        .um-contact-empty {
            min-width: 0;
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            align-items: center;
            gap: 0.45rem;
            color: #475569;
            font-size: 0.82rem;
            text-decoration: none;
        }

        .um-contact-link i,
        .um-contact-empty i {
            color: #64748b;
            text-align: center;
        }

        .um-contact-link span,
        .um-contact-empty span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .um-contact-link:hover {
            color: #0f766e;
            text-decoration: underline;
        }

        .um-assignment {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.75rem;
            display: grid;
            gap: 0.58rem;
            background: #f8fafc;
        }

        .um-assignment-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
        }

        .um-assignment-head span:first-child {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .um-unit-state {
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 800;
        }

        .um-assignment-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.55rem;
        }

        .um-assignment-item {
            min-width: 0;
            display: grid;
            gap: 0.16rem;
        }

        .um-assignment-item span {
            color: #64748b;
            font-size: 0.67rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .um-assignment-item strong {
            color: #1e293b;
            font-size: 0.8rem;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .um-person-note {
            margin: 0;
            display: flex;
            align-items: flex-start;
            gap: 0.45rem;
            color: #64748b;
            font-size: 0.77rem;
            line-height: 1.45;
        }

        .um-person-note i {
            margin-top: 0.15rem;
        }

        .um-person-actions {
            padding: 0.72rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            background: #fbfdff;
        }

        .um-action {
            width: auto;
            min-height: 44px;
            height: auto;
            padding: 0.48rem 0.65rem;
            gap: 0.4rem;
            border-radius: 10px;
            color: #334155;
            font-size: 0.76rem;
            font-weight: 800;
        }

        .um-action.view {
            color: #1d4ed8;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .um-action.invite {
            color: #0f766e;
            border-color: #99f6e4;
            background: #f0fdfa;
        }

        .um-action.delete {
            margin-left: auto;
            color: #b91c1c;
        }

        .um-action:disabled,
        .um-btn:disabled {
            opacity: 0.55;
            cursor: wait;
            transform: none;
        }

        .um-directory-state {
            grid-column: 1 / -1;
            min-height: 210px;
            padding: 2rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
        }

        .um-directory-state > i {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #1d4ed8;
            background: #dbeafe;
            font-size: 1.25rem;
        }

        .um-directory-state h3 {
            margin: 0.85rem 0 0;
            color: #1e293b;
            font-size: 1rem;
        }

        .um-directory-state p {
            max-width: 440px;
            margin: 0.38rem 0 0;
            font-size: 0.86rem;
            line-height: 1.55;
        }

        .um-directory-state .um-btn {
            margin-top: 0.85rem;
        }

        .um-modal {
            background: rgba(2, 6, 23, 0.62);
            backdrop-filter: blur(4px);
        }

        .um-modal-card {
            width: min(680px, 100%);
            max-height: calc(100vh - 2rem);
            max-height: calc(100dvh - 2rem);
            overflow: auto;
            border-radius: 22px;
        }

        .um-modal-head {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 1rem 1.15rem;
        }

        .um-modal-head h2 {
            margin: 0.25rem 0 0;
            font-size: 1.15rem;
        }

        .um-modal-body {
            padding: 1.15rem;
        }

        .um-form-grid {
            gap: 0.9rem;
        }

        .um-field label {
            text-transform: none;
            letter-spacing: 0;
            font-size: 0.8rem;
        }

        .um-input,
        .um-select {
            min-height: 44px;
            border-radius: 11px;
            padding: 0.62rem 0.7rem;
        }

        .um-modal-foot {
            position: sticky;
            bottom: 0;
            z-index: 2;
            padding: 0.85rem 1.15rem;
        }

        .um-btn {
            min-height: 44px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .um-details-card {
            width: min(720px, 100%);
        }

        .um-detail-profile {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 0.9rem;
            padding: 0.95rem;
            border: 1px solid #dbe5f0;
            border-radius: 17px;
            background: #f8fbff;
        }

        .um-detail-profile .um-avatar {
            width: 58px;
            height: 58px;
            font-size: 1rem;
        }

        .um-detail-profile[data-role="dispatcher"] .um-avatar {
            color: #3730a3;
            background: linear-gradient(145deg, #e0e7ff, #ede9fe);
            border-color: #c7d2fe;
        }

        .um-input[readonly] {
            color: #475569;
            background: #f1f5f9;
            cursor: default;
        }

        .um-detail-profile h3 {
            margin: 0;
            color: #0f172a;
            font-size: 1.2rem;
        }

        .um-detail-profile p {
            margin: 0.3rem 0 0;
            color: #64748b;
            font-size: 0.84rem;
        }

        .um-detail-badges {
            margin-top: 0.55rem;
            display: flex;
            gap: 0.42rem;
            flex-wrap: wrap;
        }

        .um-detail-grid {
            margin-top: 0.9rem;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.68rem;
        }

        .um-detail-item {
            min-width: 0;
            padding: 0.78rem;
            border: 1px solid #e2e8f0;
            border-radius: 13px;
            display: grid;
            gap: 0.3rem;
            background: #ffffff;
        }

        .um-detail-item span {
            color: #64748b;
            font-size: 0.69rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .um-detail-item strong,
        .um-detail-item a {
            color: #1e293b;
            font-size: 0.85rem;
            font-weight: 700;
            overflow-wrap: anywhere;
            text-decoration: none;
        }

        .um-detail-item a:hover {
            color: #0f766e;
            text-decoration: underline;
        }

        .um-details-actions {
            flex-wrap: wrap;
        }

        .um-toast {
            bottom: 1.25rem;
            right: 1.25rem;
            max-width: min(420px, calc(100vw - 2rem));
            padding: 0.82rem 1rem;
            border-radius: 13px;
        }

        html[data-theme="dark"] .main-content {
            background:
                radial-gradient(circle at 92% 2%, rgba(37, 99, 235, 0.16), transparent 30%),
                radial-gradient(circle at 10% 32%, rgba(13, 148, 136, 0.1), transparent 28%),
                #08111f;
        }

        html[data-theme="dark"] .um-stat,
        html[data-theme="dark"] .um-directory.um-card,
        html[data-theme="dark"] .um-person-card,
        html[data-theme="dark"] .um-modal-card {
            color: #e2e8f0;
            border-color: #334155;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(2, 6, 23, 0.98));
            box-shadow: 0 16px 36px rgba(2, 6, 23, 0.34);
        }

        html[data-theme="dark"] .um-stat strong,
        html[data-theme="dark"] .um-directory-head h2,
        html[data-theme="dark"] .um-person-identity h3,
        html[data-theme="dark"] .um-assignment-item strong,
        html[data-theme="dark"] .um-directory-state h3,
        html[data-theme="dark"] .um-detail-profile h3,
        html[data-theme="dark"] .um-detail-item strong,
        html[data-theme="dark"] .um-detail-item a {
            color: #f8fafc;
        }

        html[data-theme="dark"] .um-section-kicker {
            color: #5eead4;
        }

        html[data-theme="dark"] .um-directory-head,
        html[data-theme="dark"] .um-person-actions {
            border-color: #334155;
        }

        html[data-theme="dark"] .um-toolbar,
        html[data-theme="dark"] .um-people-grid,
        html[data-theme="dark"] .um-person-actions,
        html[data-theme="dark"] .um-assignment,
        html[data-theme="dark"] .um-detail-profile {
            color: #e2e8f0;
            border-color: #334155;
            background: #0b1323;
        }

        html[data-theme="dark"] .um-search,
        html[data-theme="dark"] .um-filter-field .um-select,
        html[data-theme="dark"] .um-clear-btn,
        html[data-theme="dark"] .um-detail-item {
            color: #f8fafc;
            border-color: #475569;
            background: #0f172a;
        }

        html[data-theme="dark"] .um-input[readonly] {
            color: #cbd5e1;
            background: #1e293b;
        }

        html[data-theme="dark"] .um-filter-field label,
        html[data-theme="dark"] .um-stat > div > span,
        html[data-theme="dark"] .um-stat small,
        html[data-theme="dark"] .um-directory-head p,
        html[data-theme="dark"] .um-person-role,
        html[data-theme="dark"] .um-contact-link,
        html[data-theme="dark"] .um-contact-empty,
        html[data-theme="dark"] .um-assignment-item span,
        html[data-theme="dark"] .um-person-note,
        html[data-theme="dark"] .um-directory-state,
        html[data-theme="dark"] .um-detail-profile p,
        html[data-theme="dark"] .um-detail-item span {
            color: #94a3b8;
        }

        html[data-theme="dark"] .um-assignment-head span:first-child,
        html[data-theme="dark"] .um-action {
            color: #cbd5e1;
        }

        html[data-theme="dark"] .um-summary {
            color: #e2e8f0;
            border-color: #475569;
            background: #1e293b;
        }

        html[data-theme="dark"] .um-action.view {
            color: #bfdbfe;
            border-color: #1e40af;
            background: #172554;
        }

        html[data-theme="dark"] .um-action.invite {
            color: #99f6e4;
            border-color: #115e59;
            background: #042f2e;
        }

        html[data-theme="dark"] .um-action.delete {
            color: #fecaca;
        }

        @media (max-width: 1220px) {
            .um-toolbar {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .um-search-wrap {
                grid-column: span 3;
            }

            .um-people-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 920px) {
            .um-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .um-toolbar {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .um-search-wrap {
                grid-column: 1 / -1;
            }

            .um-clear-btn {
                width: 100%;
            }
        }

        @media (max-width: 680px) {
            .main-content {
                padding:
                    calc(var(--app-header-height-mobile-1) + 0.85rem)
                    0.7rem
                    1.5rem;
            }

            .um-shell {
                gap: 0.8rem;
            }

            .um-hero {
                min-height: 0;
                padding: 1.15rem;
                border-radius: 19px;
                flex-direction: column;
                align-items: stretch;
            }

            .um-add-btn {
                width: 100%;
            }

            .um-stats {
                grid-template-columns: 1fr 1fr;
                gap: 0.65rem;
            }

            .um-stat {
                padding: 0.8rem;
                gap: 0.62rem;
            }

            .um-stat-icon {
                width: 36px;
                height: 36px;
                border-radius: 11px;
            }

            .um-stat strong {
                font-size: 1.45rem;
            }

            .um-stat small {
                display: none;
            }

            .um-directory-head {
                padding: 1rem;
                flex-direction: column;
            }

            .um-toolbar {
                padding: 0.8rem;
                grid-template-columns: 1fr;
            }

            .um-search-wrap {
                grid-column: auto;
            }

            .um-people-grid {
                padding: 0.75rem;
                grid-template-columns: 1fr;
            }

            .um-person-card:hover {
                transform: none;
            }

            .um-action {
                flex: 1 1 auto;
            }

            .um-action.delete {
                margin-left: 0;
                flex: 0 0 auto;
            }

            .um-modal {
                padding: 0.5rem;
                align-items: flex-end;
            }

            .um-modal-card {
                width: 100%;
                max-height: calc(100vh - 1rem);
                max-height: calc(100dvh - 1rem);
                border-radius: 20px 20px 12px 12px;
            }

            .um-form-grid,
            .um-detail-grid {
                grid-template-columns: 1fr;
            }

            .um-field.full {
                grid-column: auto;
            }

            .um-modal-foot,
            .um-details-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .um-details-actions .um-btn {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .um-add-btn,
            .um-action,
            .um-person-card,
            .um-btn,
            .um-toast {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content">
        <div class="main-container um-shell">
            <section class="um-hero" aria-labelledby="userManagementTitle">
                <div class="um-hero-copy">
                    <span class="um-eyebrow"><i class="fas fa-shield-halved" aria-hidden="true"></i> Account operations</span>
                    <h1 id="userManagementTitle">People &amp; access</h1>
                    <p>Hi <?php echo htmlspecialchars($adminName); ?>. Keep dispatchers ready, responders assigned, and account access up to date.</p>
                </div>
                <button type="button" class="um-add-btn" id="openAddUserBtn">
                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                    <span>Add team member</span>
                </button>
            </section>

            <section class="um-stats" aria-label="Account overview" aria-live="polite">
                <article class="um-stat um-stat-total">
                    <span class="um-stat-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                    <div><span>Team accounts</span><strong id="statTotal">0</strong><small>Dispatchers and responders</small></div>
                </article>
                <article class="um-stat um-stat-active">
                    <span class="um-stat-icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                    <div><span>Active access</span><strong id="statActive">0</strong><small id="statActiveMeta">No accounts loaded</small></div>
                </article>
                <article class="um-stat um-stat-responder">
                    <span class="um-stat-icon"><i class="fas fa-truck-medical" aria-hidden="true"></i></span>
                    <div><span>Responders</span><strong id="statResponders">0</strong><small id="statResponderMeta">0 assigned to units</small></div>
                </article>
                <article class="um-stat um-stat-dispatcher">
                    <span class="um-stat-icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
                    <div><span>Dispatchers</span><strong id="statDispatchers">0</strong><small>Command and coordination</small></div>
                </article>
            </section>

            <section class="um-directory um-card" aria-labelledby="teamDirectoryTitle">
                <header class="um-directory-head">
                    <div>
                        <span class="um-section-kicker">Team directory</span>
                        <h2 id="teamDirectoryTitle">Find and manage a team member</h2>
                        <p>Contact details, access state, and responder assignments are grouped per person.</p>
                    </div>
                    <span class="um-summary" id="userCountBadge" aria-live="polite">0 people</span>
                </header>

                <div class="um-toolbar" role="search" aria-label="Filter team members">
                    <div class="um-search-wrap">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <label class="um-sr-only" for="userSearchInput">Search team members</label>
                        <input
                            type="search"
                            id="userSearchInput"
                            class="um-search"
                            autocomplete="off"
                            placeholder="Search by name, email, phone, unit, or plate...">
                    </div>
                    <div class="um-filter-field">
                        <label for="userRoleFilter">Role</label>
                        <select id="userRoleFilter" class="um-select">
                            <option value="all">All roles</option>
                            <option value="dispatcher">Dispatchers</option>
                            <option value="responder">Responders</option>
                        </select>
                    </div>
                    <div class="um-filter-field">
                        <label for="userStatusFilter">Access</label>
                        <select id="userStatusFilter" class="um-select">
                            <option value="all">Any status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="um-filter-field">
                        <label for="userDepartmentFilter">Department</label>
                        <select id="userDepartmentFilter" class="um-select">
                            <option value="all">All departments</option>
                            <option value="medical">Medical</option>
                            <option value="police">Police</option>
                            <option value="fire">Fire</option>
                            <option value="unassigned">Not assigned</option>
                        </select>
                    </div>
                    <div class="um-filter-field">
                        <label for="userSortSelect">Order</label>
                        <select id="userSortSelect" class="um-select">
                            <option value="newest">Newest first</option>
                            <option value="name">Name A–Z</option>
                            <option value="readiness">Active first</option>
                        </select>
                    </div>
                    <button type="button" class="um-clear-btn" id="clearUserFiltersBtn">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i>
                        <span>Clear</span>
                    </button>
                </div>

                <div class="um-people-grid" id="usersTableBody" aria-live="polite" aria-busy="true"></div>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <div class="um-modal" id="addUserModal" aria-hidden="true">
        <div class="um-modal-card" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle" tabindex="-1">
            <div class="um-modal-head">
                <h2 id="accountModalTitle">Add New Account</h2>
                <button type="button" class="um-close" id="closeAddUserModal" aria-label="Close" onclick="closeModal(true)">
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
                            <button type="button" class="um-btn um-unit-retry" id="retryAvailableUnitsBtn" hidden>
                                <i class="fas fa-rotate" aria-hidden="true"></i> Retry unit check
                            </button>
                        </div>
                        <div class="um-field" id="newUserUnitCodeField" hidden>
                            <label for="newUserUnitCode">Unit Code</label>
                            <input id="newUserUnitCode" class="um-input" maxlength="50" placeholder="Select a unit" readonly aria-readonly="true">
                        </div>
                        <div class="um-field" id="newUserPlateField" hidden>
                            <label for="newUserPlateNumber">Plate Number</label>
                            <input id="newUserPlateNumber" class="um-input" maxlength="50" placeholder="Select a unit" readonly aria-readonly="true">
                        </div>
                        <div class="um-field" id="newUserUnitTypeField" hidden>
                            <label for="newUserUnitType">Unit Type</label>
                            <input id="newUserUnitType" class="um-input" maxlength="50" placeholder="Select a unit" readonly aria-readonly="true">
                        </div>
                        <div class="um-field" id="newUserUnitStatusField" hidden>
                            <label for="newUserUnitStatus">Unit Status</label>
                            <input id="newUserUnitStatus" class="um-input" maxlength="50" placeholder="Select a unit" readonly aria-readonly="true">
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
                            <select id="newUserDepartment" class="um-select">
                                <option value="">Select department</option>
                                <option value="medical">Medical</option>
                                <option value="police">Police</option>
                                <option value="fire">Fire</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="um-modal-foot">
                    <button type="button" class="um-btn" id="cancelAddUserBtn" onclick="closeModal(true)">Cancel</button>
                    <button type="submit" class="um-btn primary" id="accountModalSubmitBtn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="um-modal" id="userDetailsModal" aria-hidden="true">
        <div class="um-modal-card um-details-card" role="dialog" aria-modal="true" aria-labelledby="userDetailsTitle" tabindex="-1">
            <div class="um-modal-head">
                <div>
                    <span class="um-section-kicker">Team member</span>
                    <h2 id="userDetailsTitle">Account details</h2>
                </div>
                <button type="button" class="um-close" id="closeUserDetailsModal" aria-label="Close account details" onclick="closeDetailsModal()">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="um-modal-body" id="userDetailsBody"></div>
            <div class="um-modal-foot um-details-actions">
                <button type="button" class="um-btn" id="detailsCloseBtn" onclick="closeDetailsModal()">Close</button>
                <button type="button" class="um-btn" id="detailsInviteBtn" hidden>
                    <i class="fas fa-envelope" aria-hidden="true"></i> Resend invitation
                </button>
                <button type="button" class="um-btn primary" id="detailsEditBtn">
                    <i class="fas fa-pen" aria-hidden="true"></i> Edit account
                </button>
            </div>
        </div>
    </div>

    <div class="um-toast" id="userToast" role="status" aria-live="polite" aria-atomic="true"></div>

    <script>
        const adminUsersApiUrl = 'api/admin_users.php';
        const responderAppInviteApiUrl = 'api/resend_responder_app_invitation.php';
        const availableUnitsApiUrl = 'api/units_list.php?status=available&include_unassigned=1&only_unassigned_responders=1';
        const userRows = [];
        let availableUnits = [];
        let availableUnitsLoaded = false;
        let availableUnitsLoading = false;
        let availableUnitsError = '';
        let editingId = null;

        const usersTableBody = document.getElementById('usersTableBody');
        const userCountBadge = document.getElementById('userCountBadge');
        const userSearchInput = document.getElementById('userSearchInput');
        const userRoleFilter = document.getElementById('userRoleFilter');
        const userStatusFilter = document.getElementById('userStatusFilter');
        const userDepartmentFilter = document.getElementById('userDepartmentFilter');
        const userSortSelect = document.getElementById('userSortSelect');
        const clearUserFiltersBtn = document.getElementById('clearUserFiltersBtn');
        const statTotal = document.getElementById('statTotal');
        const statActive = document.getElementById('statActive');
        const statActiveMeta = document.getElementById('statActiveMeta');
        const statResponders = document.getElementById('statResponders');
        const statResponderMeta = document.getElementById('statResponderMeta');
        const statDispatchers = document.getElementById('statDispatchers');
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
        const retryAvailableUnitsBtn = document.getElementById('retryAvailableUnitsBtn');
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
        const userDetailsModal = document.getElementById('userDetailsModal');
        const userDetailsTitle = document.getElementById('userDetailsTitle');
        const userDetailsBody = document.getElementById('userDetailsBody');
        const closeUserDetailsModal = document.getElementById('closeUserDetailsModal');
        const detailsCloseBtn = document.getElementById('detailsCloseBtn');
        const detailsInviteBtn = document.getElementById('detailsInviteBtn');
        const detailsEditBtn = document.getElementById('detailsEditBtn');
        let detailsUserId = null;
        let accountFormBaseline = '';
        const passwordRuleElements = passwordRequirements
            ? Array.from(passwordRequirements.querySelectorAll('[data-rule]'))
            : [];
        const modalReturnFocus = new WeakMap();
        const modalBackgroundState = new Map();

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

        function serializeAccountForm() {
            return JSON.stringify({
                name: newUserName.value,
                email: newUserEmail.value,
                contact: newUserContact.value,
                password: newUserPassword.value,
                role: newUserRole.value,
                department: newUserDepartment.value,
                status: newUserStatus.value,
                assignedUnit: newUserAssignedUnit
                    ? (newUserAssignedUnit.dataset.pendingValue || newUserAssignedUnit.value)
                    : ''
            });
        }

        function formHasUnsavedChanges() {
            return addUserModal.classList.contains('show') && serializeAccountForm() !== accountFormBaseline;
        }

        function focusableElements(modal) {
            return Array.from(modal.querySelectorAll(
                'button:not([disabled]):not([hidden]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
            )).filter((element) => !element.closest('[hidden]'));
        }

        function setModalBackgroundInert(active) {
            if (active) {
                Array.from(document.body.children).forEach((element) => {
                    if (
                        element === addUserModal ||
                        element === userDetailsModal ||
                        element === userToast ||
                        (element.id && (element.id === 'addUserModal' || element.id === 'userDetailsModal' || element.id === 'userToast')) ||
                        element.classList.contains('um-modal') ||
                        element.classList.contains('um-toast') ||
                        element.tagName === 'SCRIPT'
                    ) {
                        element.inert = false;
                        element.removeAttribute('inert');
                        return;
                    }
                    if (!modalBackgroundState.has(element)) {
                        modalBackgroundState.set(element, Boolean(element.inert));
                    }
                    element.inert = true;
                });
                return;
            }
            modalBackgroundState.forEach((wasInert, element) => {
                if (element && element.isConnected) {
                    element.inert = wasInert;
                    if (!wasInert) element.removeAttribute('inert');
                }
            });
            modalBackgroundState.clear();
            if (addUserModal) {
                addUserModal.inert = false;
                addUserModal.removeAttribute('inert');
            }
            if (userDetailsModal) {
                userDetailsModal.inert = false;
                userDetailsModal.removeAttribute('inert');
            }
        }

        function showModal(modal, preferredFocus) {
            if (!modal) return;
            modalReturnFocus.set(modal, document.activeElement instanceof HTMLElement ? document.activeElement : null);
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            modal.inert = false;
            modal.removeAttribute('inert');
            document.body.style.overflow = 'hidden';
            setModalBackgroundInert(true);
            window.setTimeout(() => {
                const target = preferredFocus || focusableElements(modal)[0] || modal.querySelector('[role="dialog"]');
                if (target && typeof target.focus === 'function') target.focus();
            }, 0);
        }

        function hideModal(modal) {
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.um-modal.show')) {
                document.body.style.overflow = '';
                setModalBackgroundInert(false);
            }
            const returnTarget = modalReturnFocus.get(modal);
            modalReturnFocus.delete(modal);
            if (returnTarget && (document.body.contains(returnTarget) || (document.contains && document.contains(returnTarget))) && typeof returnTarget.focus === 'function') {
                window.setTimeout(() => returnTarget.focus(), 0);
            }
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

        function departmentToUnitType(department) {
            const key = String(department || '').trim().toLowerCase();
            if (key === 'medical') return 'ambulance';
            if (key === 'police') return 'police';
            if (key === 'fire') return 'fire';
            return '';
        }

        function normalizeDepartmentValue(department) {
            const value = String(department || '').trim().toLowerCase();
            if (value.includes('fire') || value.includes('bfp')) return 'fire';
            if (value.includes('police') || value.includes('pnp')) return 'police';
            if (
                value.includes('medical') ||
                value.includes('medic') ||
                value.includes('ems') ||
                value.includes('ambulance') ||
                value.includes('health') ||
                value.includes('emt')
            ) {
                return 'medical';
            }
            return ['medical', 'police', 'fire'].includes(value) ? value : '';
        }

        function displayDepartmentValue(department) {
            const value = normalizeDepartmentValue(department);
            const labels = {
                medical: 'Medical',
                police: 'Police',
                fire: 'Fire'
            };
            return labels[value] || displayUnitValue(department);
        }

        function unitMatchesSelectedDepartment(unit) {
            const expectedType = departmentToUnitType(newUserDepartment ? newUserDepartment.value : '');
            if (!expectedType) return true;
            const unitType = String(unit.unit_type || '').trim().toLowerCase();
            if (expectedType === 'ambulance') {
                return /\b(ambulance|medical|medic|ems|emt)\b/.test(unitType);
            }
            if (expectedType === 'police') {
                return /\b(police|patrol|pnp|law enforcement)\b/.test(unitType);
            }
            if (expectedType === 'fire') {
                return /\b(fire|engine|ladder|bfp)\b/.test(unitType);
            }
            return unitType === expectedType;
        }

        function unitHasOtherResponderAssignment(unit) {
            const unitId = Number(unit && unit.id) || 0;
            if (unitId <= 0) return false;

            const responderUserId = Number(unit.responder_user_id) || 0;
            if (responderUserId > 0 && responderUserId !== editingId) {
                return true;
            }

            return userRows.some((row) => {
                if (!row || Number(row.id) === editingId) return false;
                if (String(row.role || '').trim().toLowerCase() !== 'responder') return false;
                return (Number(row.assigned_unit_id) || 0) === unitId;
            });
        }

        function getEditingRow() {
            return editingId !== null ? (userRows.find((row) => Number(row.id) === editingId) || null) : null;
        }

        function unitFromUserRow(row) {
            if (!row || !row.assigned_unit_id) return null;
            return {
                id: Number(row.assigned_unit_id) || 0,
                identifier: String(row.unit_code || '').trim(),
                unit_type: String(row.unit_type || '').trim(),
                plate_number: String(row.vehicle_plate || '').trim(),
                resource_name: '',
                status: String(row.unit_status || '').trim(),
                responder_user_id: Number(row.id) || 0
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

        function clearResponderUnitDetails() {
            if (newUserUnitCode) newUserUnitCode.value = '';
            if (newUserPlateNumber) newUserPlateNumber.value = '';
            if (newUserUnitType) newUserUnitType.value = '';
            if (newUserUnitStatus) newUserUnitStatus.value = '';
        }

        function renderAvailableUnitOptions() {
            if (!newUserAssignedUnit || !newUserUnitHint) return;

            const editingUnitId = Number(getEditingRow()?.assigned_unit_id) || 0;
            const pendingValue = newUserAssignedUnit.dataset.pendingValue ||
                newUserAssignedUnit.value ||
                (editingUnitId > 0 ? String(editingUnitId) : '');
            ensureCurrentAssignedUnitOption(pendingValue);

            if (availableUnitsLoading) {
                newUserAssignedUnit.innerHTML = '<option value="">Loading available units...</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'Loading currently available units.';
                if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = true;
                updateResponderUnitDetails();
                return;
            }

            if (!departmentToUnitType(newUserDepartment ? newUserDepartment.value : '')) {
                newUserAssignedUnit.innerHTML = '<option value="">Select department first</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'Select Medical, Police, or Fire to show matching units.';
                if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = true;
                clearResponderUnitDetails();
                updateResponderUnitDetails();
                return;
            }

            if (availableUnitsError) {
                newUserAssignedUnit.innerHTML = '<option value="">Unit service unavailable</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = availableUnitsError;
                if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = false;
                updateResponderUnitDetails();
                return;
            }

            if (!availableUnits.length) {
                newUserAssignedUnit.innerHTML = '<option value="">No available units</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'No available units are ready right now.';
                if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = true;
                updateResponderUnitDetails();
                return;
            }

            const filteredUnits = availableUnits
                .filter(unitMatchesSelectedDepartment)
                .filter((unit) => !unitHasOtherResponderAssignment(unit));
            if (!filteredUnits.length) {
                newUserAssignedUnit.innerHTML = '<option value="">No units for selected department</option>';
                newUserAssignedUnit.disabled = true;
                newUserAssignedUnit.required = false;
                newUserUnitHint.textContent = 'No unassigned units match the selected department.';
                if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = true;
                updateResponderUnitDetails();
                return;
            }

            const options = filteredUnits.map((unit) => (
                `<option value="${unit.id}">${escapeHtml(formatUnitLabel(unit))}</option>`
            )).join('');

            newUserAssignedUnit.disabled = false;
            newUserAssignedUnit.required = newUserRole && newUserRole.value === 'responder';
            newUserAssignedUnit.innerHTML = '<option value="">Select available unit</option>' + options;
            if (retryAvailableUnitsBtn) retryAvailableUnitsBtn.hidden = true;

            if (pendingValue && filteredUnits.some((unit) => String(unit.id) === String(pendingValue))) {
                newUserAssignedUnit.value = String(pendingValue);
            }
            delete newUserAssignedUnit.dataset.pendingValue;
            newUserUnitHint.textContent = editingId === null
                ? 'Only unassigned units matching the selected department are listed.'
                : 'Only unassigned units are listed; the current assigned unit remains available for this account.';
            updateResponderUnitDetails();
        }

        async function loadAvailableUnits() {
            if (availableUnitsLoading) return;
            availableUnitsLoading = true;
            availableUnitsError = '';
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
                    status: String(item.status || '').trim(),
                    responder_user_id: Number(item.responder_user_id) || 0
                })).filter((item) => item.id > 0);
                availableUnitsLoaded = true;
            } catch (error) {
                availableUnits = [];
                availableUnitsLoaded = false;
                availableUnitsError = error.message || 'Unable to load available units.';
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

        function syncDepartmentFieldForRole() {
            if (!newUserRole || !newUserDepartment) return;

            newUserDepartment.required = newUserRole.value === 'responder';
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
            syncDepartmentFieldForRole();
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
            const normalized = String(role || '').toLowerCase();
            const safe = ['dispatcher', 'responder'].includes(normalized) ? normalized : 'dispatcher';
            const label = safe.charAt(0).toUpperCase() + safe.slice(1);
            return '<span class="um-chip ' + safe + '">' + escapeHtml(label) + '</span>';
        }

        function statusChip(status) {
            const safe = String(status || '').toLowerCase() === 'active' ? 'active' : 'inactive';
            const label = safe.charAt(0).toUpperCase() + safe.slice(1);
            return '<span class="um-chip ' + safe + '"><i class="fas fa-circle" aria-hidden="true"></i>' + escapeHtml(label) + '</span>';
        }

        function displayUnitValue(value) {
            const raw = String(value || '').replace(/_/g, ' ').trim();
            if (!raw) return 'N/A';
            if (['offline', 'unavailable', 'out of service', 'off duty', 'leave'].includes(raw.toLowerCase())) {
                return 'Offline';
            }
            return raw.charAt(0).toUpperCase() + raw.slice(1);
        }

        function normalizedRole(role) {
            return String(role || '').trim().toLowerCase() === 'responder' ? 'responder' : 'dispatcher';
        }

        function normalizedStatus(status) {
            return String(status || '').trim().toLowerCase() === 'active' ? 'active' : 'inactive';
        }

        function personInitials(name) {
            const words = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!words.length) return 'TM';
            return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('');
        }

        function formattedCreatedDate(value) {
            const raw = String(value || '').trim();
            if (!raw) return 'Date unavailable';
            const date = new Date(raw + (raw.length === 10 ? 'T00:00:00' : ''));
            if (Number.isNaN(date.getTime())) return raw;
            return new Intl.DateTimeFormat('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).format(date);
        }

        function renderStats() {
            const total = userRows.length;
            const active = userRows.filter((row) => normalizedStatus(row.status) === 'active').length;
            const responders = userRows.filter((row) => normalizedRole(row.role) === 'responder');
            const dispatchers = total - responders.length;
            const assignedResponders = responders.filter((row) => Number(row.assigned_unit_id) > 0 || String(row.unit_code || '').trim() !== '').length;
            const activeRate = total > 0 ? Math.round((active / total) * 100) : 0;

            statTotal.textContent = String(total);
            statActive.textContent = String(active);
            statResponders.textContent = String(responders.length);
            statDispatchers.textContent = String(dispatchers);
            statActiveMeta.textContent = total > 0 ? activeRate + '% of team accounts' : 'No accounts loaded';
            statResponderMeta.textContent = assignedResponders + ' assigned to unit' + (assignedResponders === 1 ? '' : 's');
        }

        function filtersAreActive() {
            return userSearchInput.value.trim() !== '' ||
                userRoleFilter.value !== 'all' ||
                userStatusFilter.value !== 'all' ||
                userDepartmentFilter.value !== 'all';
        }

        function filteredRows() {
            const needle = userSearchInput.value.trim().toLowerCase();
            const role = userRoleFilter.value;
            const status = userStatusFilter.value;
            const department = userDepartmentFilter.value;
            const rows = userRows.filter((row) => {
                const rowRole = normalizedRole(row.role);
                const rowStatus = normalizedStatus(row.status);
                const rowDepartment = normalizeDepartmentValue(row.department);

                if (role !== 'all' && rowRole !== role) return false;
                if (status !== 'all' && rowStatus !== status) return false;
                if (department === 'unassigned' && rowDepartment !== '') return false;
                if (department !== 'all' && department !== 'unassigned' && rowDepartment !== department) return false;
                if (!needle) return true;

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

            if (userSortSelect.value === 'name') {
                rows.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }));
            } else if (userSortSelect.value === 'readiness') {
                rows.sort((a, b) => {
                    const statusDifference = normalizedStatus(a.status) === normalizedStatus(b.status)
                        ? 0
                        : (normalizedStatus(a.status) === 'active' ? -1 : 1);
                    return statusDifference || String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
                });
            }

            return rows;
        }

        function directoryState(icon, title, message, actionHtml = '') {
            return `
                <div class="um-directory-state">
                    <i class="fas ${icon}" aria-hidden="true"></i>
                    <h3>${escapeHtml(title)}</h3>
                    <p>${escapeHtml(message)}</p>
                    ${actionHtml}
                </div>
            `;
        }

        function renderRows() {
            const rows = filteredRows();
            renderStats();
            userCountBadge.textContent = filtersAreActive()
                ? rows.length + ' of ' + userRows.length + ' people'
                : rows.length + ' ' + (rows.length === 1 ? 'person' : 'people');
            usersTableBody.setAttribute('aria-busy', 'false');

            if (!rows.length) {
                const message = filtersAreActive()
                    ? 'Try another name or clear one of the role, access, or department filters.'
                    : 'Add a dispatcher or responder to begin building the response team.';
                const action = filtersAreActive()
                    ? '<button type="button" class="um-btn" data-action="clear-filters"><i class="fas fa-rotate-left" aria-hidden="true"></i> Clear filters</button>'
                    : '<button type="button" class="um-btn primary" data-action="add"><i class="fas fa-user-plus" aria-hidden="true"></i> Add team member</button>';
                usersTableBody.innerHTML = directoryState('fa-users-slash', 'No matching team members', message, action);
                return;
            }

            usersTableBody.innerHTML = rows.map((row) => {
                const role = normalizedRole(row.role);
                const status = normalizedStatus(row.status);
                const department = displayDepartmentValue(row.department);
                const contactNumber = String(row.contact_number || '').trim();
                const phoneHref = contactNumber.replace(/[^\d+]/g, '');
                const unitCode = String(row.unit_code || '').trim();
                const unitType = displayUnitValue(row.unit_type);
                const vehiclePlate = String(row.vehicle_plate || '').trim();
                const unitStatus = displayUnitValue(row.unit_status);
                const hasUnit = Number(row.assigned_unit_id) > 0 || unitCode !== '';
                const assignment = role === 'responder'
                    ? `
                        <div class="um-assignment">
                            <div class="um-assignment-head">
                                <span><i class="fas fa-truck-medical" aria-hidden="true"></i> Response assignment</span>
                                <span class="um-unit-state">${escapeHtml(hasUnit ? unitStatus : 'Not assigned')}</span>
                            </div>
                            <div class="um-assignment-grid">
                                <div class="um-assignment-item"><span>Department</span><strong>${escapeHtml(department)}</strong></div>
                                <div class="um-assignment-item"><span>Unit</span><strong>${escapeHtml(unitCode || 'Not assigned')}</strong></div>
                                <div class="um-assignment-item"><span>Unit type</span><strong>${escapeHtml(hasUnit ? unitType : '—')}</strong></div>
                                <div class="um-assignment-item"><span>Plate</span><strong>${escapeHtml(vehiclePlate || '—')}</strong></div>
                            </div>
                        </div>
                    `
                    : `
                        <div class="um-assignment">
                            <div class="um-assignment-head">
                                <span><i class="fas fa-headset" aria-hidden="true"></i> Coordination access</span>
                                <span class="um-unit-state">${escapeHtml(status === 'active' ? 'Ready' : 'Disabled')}</span>
                            </div>
                            <div class="um-assignment-grid">
                                <div class="um-assignment-item"><span>Assignment</span><strong>${escapeHtml(department === 'N/A' ? 'Command center' : department)}</strong></div>
                                <div class="um-assignment-item"><span>Member since</span><strong>${escapeHtml(formattedCreatedDate(row.created))}</strong></div>
                            </div>
                        </div>
                    `;

                return `
                    <article class="um-person-card ${status === 'inactive' ? 'is-inactive' : ''}" data-row-id="${Number(row.id)}" data-role="${role}">
                        <div class="um-person-main">
                            <div class="um-person-head">
                                <span class="um-avatar" aria-hidden="true">${escapeHtml(personInitials(row.name))}</span>
                                <div class="um-person-identity">
                                    <h3>${escapeHtml(row.name || 'Unnamed team member')}</h3>
                                    <span class="um-person-role"><i class="fas ${role === 'responder' ? 'fa-truck-medical' : 'fa-headset'}" aria-hidden="true"></i>${escapeHtml(role === 'responder' ? 'Responder' : 'Dispatcher')}</span>
                                </div>
                                ${statusChip(status)}
                            </div>
                            <div class="um-contact-list">
                                <a class="um-contact-link" href="mailto:${escapeHtml(row.email || '')}" title="Email ${escapeHtml(row.name || 'team member')}"><i class="fas fa-envelope" aria-hidden="true"></i><span>${escapeHtml(row.email || 'No email')}</span></a>
                                ${contactNumber
                                    ? `<a class="um-contact-link" href="tel:${escapeHtml(phoneHref)}" title="Call ${escapeHtml(row.name || 'team member')}"><i class="fas fa-phone" aria-hidden="true"></i><span>${escapeHtml(contactNumber)}</span></a>`
                                    : '<span class="um-contact-empty"><i class="fas fa-phone" aria-hidden="true"></i><span>No contact number</span></span>'}
                            </div>
                            ${assignment}
                            <p class="um-person-note"><i class="fas fa-calendar" aria-hidden="true"></i><span>Account added ${escapeHtml(formattedCreatedDate(row.created))}</span></p>
                        </div>
                        <div class="um-person-actions" aria-label="Actions for ${escapeHtml(row.name || 'team member')}">
                            <button type="button" class="um-action view" data-action="view" data-id="${Number(row.id)}"><i class="fas fa-address-card" aria-hidden="true"></i><span>Details</span></button>
                            ${role === 'responder' && status === 'active' ? `<button type="button" class="um-action invite" data-action="invite" data-id="${Number(row.id)}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span>Resend invite</span></button>` : ''}
                            <button type="button" class="um-action edit" data-action="edit" data-id="${Number(row.id)}"><i class="fas fa-pen" aria-hidden="true"></i><span>Edit</span></button>
                            <button type="button" class="um-action delete" data-action="delete" data-id="${Number(row.id)}" aria-label="Delete ${escapeHtml(row.name || 'team member')}"><i class="fas fa-trash" aria-hidden="true"></i><span class="um-sr-only">Delete</span></button>
                        </div>
                    </article>
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
            availableUnits = [];
            availableUnitsLoaded = false;
            availableUnitsError = '';
            syncPasswordFieldForRole();
            updatePasswordRequirements(false);
            accountFormBaseline = serializeAccountForm();
            showModal(addUserModal, newUserName);
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

        function closeModal(force = false) {
            if (!force && formHasUnsavedChanges()) {
                const discard = window.confirm('Discard the unsaved account changes?');
                if (!discard) return false;
            }

            hideModal(addUserModal);
            addUserForm.reset();
            editingId = null;
            accountFormBaseline = '';
            resetPasswordToggle();
            syncPasswordFieldForRole();
            syncResponderUnitField();
            updatePasswordRequirements(false);
            setAccountModalMode('create');
            return true;
        }

        function restoreAddUserForm(payload) {
            if (!payload) return;

            newUserName.value = payload.name || '';
            newUserEmail.value = payload.email || '';
            newUserContact.value = payload.contact_number || '';
            newUserRole.value = payload.role || 'dispatcher';
            newUserDepartment.value = normalizeDepartmentValue(payload.department);
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

        function detailItem(label, value, href = '') {
            const displayed = String(value || '').trim() || 'Not provided';
            const content = href
                ? `<a href="${escapeHtml(href)}">${escapeHtml(displayed)}</a>`
                : `<strong>${escapeHtml(displayed)}</strong>`;
            return `<div class="um-detail-item"><span>${escapeHtml(label)}</span>${content}</div>`;
        }

        function openUserDetails(row) {
            if (!row) return;

            const role = normalizedRole(row.role);
            const status = normalizedStatus(row.status);
            const contactNumber = String(row.contact_number || '').trim();
            const phoneHref = contactNumber.replace(/[^\d+]/g, '');
            const department = displayDepartmentValue(row.department);
            const hasUnit = Number(row.assigned_unit_id) > 0 || String(row.unit_code || '').trim() !== '';

            detailsUserId = Number(row.id);
            userDetailsTitle.textContent = row.name || 'Account details';
            userDetailsBody.innerHTML = `
                <section class="um-detail-profile" data-role="${role}">
                    <span class="um-avatar" aria-hidden="true">${escapeHtml(personInitials(row.name))}</span>
                    <div>
                        <h3>${escapeHtml(row.name || 'Unnamed team member')}</h3>
                        <p>${escapeHtml(role === 'responder' ? 'Field response account' : 'Dispatch and coordination account')}</p>
                        <div class="um-detail-badges">${roleChip(role)}${statusChip(status)}</div>
                    </div>
                </section>
                <section class="um-detail-grid" aria-label="Account information">
                    ${detailItem('Email address', row.email, 'mailto:' + String(row.email || ''))}
                    ${detailItem('Contact number', contactNumber, phoneHref ? 'tel:' + phoneHref : '')}
                    ${detailItem('Department', department === 'N/A' ? (role === 'dispatcher' ? 'Command center' : 'Not assigned') : department)}
                    ${detailItem('Account created', formattedCreatedDate(row.created))}
                    ${role === 'responder' ? detailItem('Assigned unit', hasUnit ? row.unit_code : 'Not assigned') : ''}
                    ${role === 'responder' ? detailItem('Unit type', hasUnit ? displayUnitValue(row.unit_type) : 'Not assigned') : ''}
                    ${role === 'responder' ? detailItem('Vehicle plate', hasUnit ? row.vehicle_plate : 'Not assigned') : ''}
                    ${role === 'responder' ? detailItem('Unit readiness', hasUnit ? displayUnitValue(row.unit_status) : 'Not assigned') : ''}
                </section>
            `;
            detailsInviteBtn.hidden = role !== 'responder' || status !== 'active';
            showModal(userDetailsModal, closeUserDetailsModal);
        }

        function closeDetailsModal() {
            detailsUserId = null;
            hideModal(userDetailsModal);
        }

        window.closeModal = closeModal;
        window.closeDetailsModal = closeDetailsModal;

        async function sendResponderInvitation(id, button) {
            if (button) button.disabled = true;
            try {
                const response = await fetch(responderAppInviteApiUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ user_id: id })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to send the app invitation.');
                }
                showToast(result.message || 'App invitation email sent.');
                return true;
            } catch (error) {
                showToast(error.message || 'Unable to send the app invitation.');
                return false;
            } finally {
                if (button) button.disabled = false;
            }
        }

        async function loadUsers() {
            usersTableBody.setAttribute('aria-busy', 'true');
            usersTableBody.innerHTML = directoryState('fa-spinner fa-spin', 'Loading team directory', 'Retrieving the latest account and responder assignment information.');

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
                usersTableBody.setAttribute('aria-busy', 'false');
                usersTableBody.innerHTML = directoryState(
                    'fa-triangle-exclamation',
                    'Team directory is unavailable',
                    'Check the connection and try loading the accounts again.',
                    '<button type="button" class="um-btn primary" data-action="reload"><i class="fas fa-rotate" aria-hidden="true"></i> Try again</button>'
                );
                showToast(error.message || 'Unable to load users.');
            }
        }

        usersTableBody.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-action]');
            if (!button) return;

            const action = button.getAttribute('data-action');
            if (action === 'clear-filters') {
                clearUserFilters();
                return;
            }
            if (action === 'add') {
                editingId = null;
                addUserForm.reset();
                openModal();
                return;
            }
            if (action === 'reload') {
                loadUsers();
                return;
            }

            const id = Number(button.getAttribute('data-id'));
            const target = userRows.find((row) => Number(row.id) === id);
            if (!target) return;

            if (action === 'view') {
                openUserDetails(target);
                return;
            }

            if (action === 'invite') {
                await sendResponderInvitation(id, button);
                return;
            }

            if (action === 'edit') {
                editingId = id;
                addUserForm.reset();
                restoreAddUserForm(target);
                openModal();
                return;
            }

            if (action === 'delete') {
                const roleMessage = normalizedRole(target.role) === 'responder'
                    ? ' Their responder record and current unit assignment will also be released.'
                    : '';
                const ok = window.confirm(
                    'Permanently delete the account for ' + target.name + '?' + roleMessage + '\n\nThis action cannot be undone.'
                );
                if (!ok) return;

                button.disabled = true;
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

                    const index = userRows.findIndex((row) => Number(row.id) === id);
                    if (index >= 0) userRows.splice(index, 1);
                    if (editingId === id) editingId = null;
                    renderRows();
                    showToast(result.message || 'User account permanently deleted.');
                } catch (error) {
                    showToast(error.message || 'Unable to delete user.');
                    button.disabled = false;
                }
                return;
            }
        });

        if (openAddUserBtn) {
            openAddUserBtn.addEventListener('click', () => {
                editingId = null;
                addUserForm.reset();
                openModal();
            });
        }

        if (closeAddUserModal) {
            closeAddUserModal.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeModal(true);
            });
        }

        if (cancelAddUserBtn) {
            cancelAddUserBtn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeModal(true);
            });
        }

        if (addUserModal) {
            addUserModal.addEventListener('click', (event) => {
                if (event.target === addUserModal) {
                    closeModal(false);
                    return;
                }
                const closeTarget = event.target.closest('#closeAddUserModal, #cancelAddUserBtn');
                if (closeTarget) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeModal(true);
                }
            });
        }

        if (closeUserDetailsModal) {
            closeUserDetailsModal.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeDetailsModal();
            });
        }

        if (detailsCloseBtn) {
            detailsCloseBtn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeDetailsModal();
            });
        }

        if (userDetailsModal) {
            userDetailsModal.addEventListener('click', (event) => {
                if (event.target === userDetailsModal) {
                    closeDetailsModal();
                    return;
                }
                const closeTarget = event.target.closest('#closeUserDetailsModal, #detailsCloseBtn');
                if (closeTarget) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeDetailsModal();
                }
            });
        }

        detailsEditBtn.addEventListener('click', () => {
            const target = userRows.find((row) => Number(row.id) === detailsUserId);
            if (!target) return;
            const returnTarget = usersTableBody.querySelector(`.um-action.edit[data-id="${Number(target.id)}"]`);
            hideModal(userDetailsModal);
            detailsUserId = null;
            editingId = Number(target.id);
            addUserForm.reset();
            restoreAddUserForm(target);
            openModal();
            if (returnTarget) modalReturnFocus.set(addUserModal, returnTarget);
        });

        detailsInviteBtn.addEventListener('click', async () => {
            const target = userRows.find((row) => Number(row.id) === detailsUserId);
            if (!target || normalizedRole(target.role) !== 'responder') return;
            await sendResponderInvitation(Number(target.id), detailsInviteBtn);
        });

        document.addEventListener('keydown', (event) => {
            const activeModal = userDetailsModal.classList.contains('show')
                ? userDetailsModal
                : (addUserModal.classList.contains('show') ? addUserModal : null);
            if (!activeModal) return;

            if (event.key === 'Escape') {
                event.preventDefault();
                if (activeModal === userDetailsModal) closeDetailsModal();
                else closeModal();
                return;
            }

            if (event.key === 'Tab') {
                const focusable = focusableElements(activeModal);
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        window.addEventListener('beforeunload', (event) => {
            if (!formHasUnsavedChanges()) return;
            event.preventDefault();
            event.returnValue = '';
        });

        addUserForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const editId = editingId;
            const isEdit = editId !== null;

            if (newUserRole.value === 'responder' && newUserAssignedUnit && availableUnitsLoading) {
                showToast('Please wait while available units are being checked.');
                return;
            }
            if (newUserRole.value === 'responder' && newUserAssignedUnit && !availableUnitsLoaded) {
                showToast('Unable to verify unit availability. Use Retry unit check before saving.');
                return;
            }

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

            const departmentRequired = payload.role === 'responder';

            if (!payload.name || !payload.email || !payload.contact_number || (departmentRequired && !payload.department) || (passwordRequired && !payload.password)) {
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

            const emailExists = userRows.some((row) => (
                Number(row.id) !== editId &&
                String(row.email || '').toLowerCase() === payload.email.toLowerCase()
            ));
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
                    const targetIndex = userRows.findIndex((row) => Number(row.id) === editId);
                    if (targetIndex >= 0) {
                        userRows[targetIndex] = result.user;
                    }
                } else {
                    userRows.unshift(result.user);
                }
                closeModal(true);
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

        if (newUserDepartment) {
            newUserDepartment.addEventListener('change', () => {
                if (newUserAssignedUnit) {
                    newUserAssignedUnit.value = '';
                    delete newUserAssignedUnit.dataset.pendingValue;
                }
                clearResponderUnitDetails();
                renderAvailableUnitOptions();
            });
        }

        if (newUserAssignedUnit) {
            newUserAssignedUnit.addEventListener('change', updateResponderUnitDetails);
        }

        if (retryAvailableUnitsBtn) {
            retryAvailableUnitsBtn.addEventListener('click', () => {
                availableUnitsLoaded = false;
                availableUnitsError = '';
                loadAvailableUnits();
            });
        }

        function clearUserFilters() {
            userSearchInput.value = '';
            userRoleFilter.value = 'all';
            userStatusFilter.value = 'all';
            userDepartmentFilter.value = 'all';
            userSortSelect.value = 'newest';
            renderRows();
            userSearchInput.focus();
        }

        userSearchInput.addEventListener('input', renderRows);
        [userRoleFilter, userStatusFilter, userDepartmentFilter, userSortSelect].forEach((control) => {
            control.addEventListener('change', renderRows);
        });
        clearUserFiltersBtn.addEventListener('click', clearUserFilters);

        loadUsers();
    </script>
</body>
</html>
