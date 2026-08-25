<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/review.php');

$reviewUiBuild = '20260811-admin-review-compact-queues-v4';
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Review-UI-Build: ' . $reviewUiBuild);
}

$pageTitle = 'Review & Feedback';
$adminName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Admin'));
if ($adminName === '') {
    $adminName = 'Admin';
}
$adminUserId = (int)($_SESSION['user_id'] ?? 0);
$reviewScriptModified = is_file($rootDir . '/js/admin-review-after-action.js')
    ? (string)filemtime($rootDir . '/js/admin-review-after-action.js')
    : '0';
$reviewScriptVersion = rawurlencode($reviewUiBuild . '-' . $reviewScriptModified);
$reviewLandscapeCssModified = is_file($rootDir . '/css/admin-after-action-landscape.css')
    ? (string)filemtime($rootDir . '/css/admin-after-action-landscape.css')
    : '0';
$reviewLandscapeCssVersion = rawurlencode($reviewUiBuild . '-' . $reviewLandscapeCssModified);
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
        .main-content {
            padding:
                calc(var(--app-header-height-1) + 1.25rem)
                clamp(1rem, 4vw, 4rem)
                4rem;
            background: radial-gradient(circle at top right, rgba(56,189,248,.08), transparent 28%), #f3f7fb;
        }
        .ar-shell {
            width: min(100%, 1360px);
            margin: 0 auto;
            display: grid;
            gap: 1rem;
        }
        .ar-hero {
            background: linear-gradient(135deg, #0f172a, #1e3a8a);
            color: #f8fafc; border-radius: 22px;
            padding: 1.3rem 1.4rem;
            box-shadow: 0 20px 40px rgba(15,23,42,.18);
        }
        .ar-hero h1 { margin: 0; font-size: 1.6rem; }
        .ar-hero p { margin: .55rem 0 0; color: rgba(248,250,252,.88); line-height: 1.6; }
        .ar-stats { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 1rem; }
        .ar-stat, .ar-toolbar, .ar-card, .ar-modal-dialog { background: rgba(255,255,255,.98); border: 1px solid #dbe5f0; box-shadow: 0 14px 34px rgba(15,23,42,.08); }
        .ar-stat { border-radius: 20px; padding: 1rem 1.1rem; }
        .ar-stat span { display: inline-flex; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #64748b; }
        .ar-stat strong { display: block; margin-top: .65rem; font-size: 1.9rem; line-height: 1; color: #0f172a; }
        .ar-stat p { margin: .55rem 0 0; color: #64748b; line-height: 1.55; font-size: .9rem; }
        .ar-toolbar { border-radius: 20px; padding: 1rem; display: grid; grid-template-columns: 1.45fr 1fr 1fr auto; gap: .8rem; align-items: end; }
        .ar-field { display: flex; flex-direction: column; gap: .4rem; min-width: 0; }
        .ar-field label { color: #475569; font-size: .84rem; font-weight: 700; }
        .ar-field input, .ar-field select { width: 100%; border: 1px solid #cbd5e1; border-radius: 14px; background: #fff; color: #0f172a; padding: .78rem .9rem; font-size: .94rem; box-sizing: border-box; }
        .ar-field input:focus, .ar-field select:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,.14); }
        .ar-actions { display: flex; gap: .55rem; }
        .ar-btn { min-height: 46px; padding: .72rem 1rem; border-radius: 14px; border: 1px solid #cbd5e1; font-weight: 800; cursor: pointer; }
        .ar-btn.primary { background: linear-gradient(135deg, #0f766e, #2563eb); border-color: transparent; color: #fff; }
        .ar-btn.secondary { background: #fff; color: #0f172a; }
        .ar-card { border-radius: 22px; overflow: hidden; }
        .ar-card-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.15rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; }
        .ar-card-head h2 { margin: 0; color: #0f172a; font-size: 1.05rem; }
        .ar-card-head p { margin: .3rem 0 0; color: #64748b; font-size: .9rem; }
        .ar-count { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; background: #e2e8f0; color: #334155; font-size: .82rem; font-weight: 800; padding: .38rem .72rem; }
        .ar-chip, .ar-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .7rem; border-radius: 999px; font-size: .76rem; font-weight: 800; }
        .ar-chip.resolved { background: #dcfce7; color: #166534; }
        .ar-chip.cancelled { background: #fee2e2; color: #991b1b; }
        .ar-pill.critical { background: #7f1d1d; color: #fff; }
        .ar-pill.high { background: #fee2e2; color: #b91c1c; }
        .ar-pill.medium { background: #fef3c7; color: #b45309; }
        .ar-pill.low { background: #dcfce7; color: #166534; }
        .ar-pill.feedback { background: #eff6ff; color: #1d4ed8; }
        .ar-pill.empty { background: #e2e8f0; color: #475569; }
        .ar-row-actions { display: flex; gap: .45rem; flex-wrap: wrap; }
        .ar-action { border: 1px solid #cbd5e1; background: #fff; color: #0f172a; border-radius: 12px; padding: .55rem .85rem; font-weight: 700; cursor: pointer; }
        .ar-action.primary { background: #0f172a; color: #fff; border-color: #0f172a; }
        .ar-action.sync { background: #0f766e; color: #fff; border-color: #0f766e; }
        .ar-action.danger { background: #b91c1c; color: #fff; border-color: #b91c1c; }
        .ar-action.sent, .ar-action:disabled { background: #e2e8f0; color: #64748b; border-color: #cbd5e1; cursor: not-allowed; }
        .ar-empty { padding: 2rem 1rem; text-align: center; color: #64748b; }
        .ar-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.45); backdrop-filter: blur(3px); z-index: 2000; }
        .ar-modal { position: fixed; inset: 0; z-index: 2001; display: flex; align-items: center; justify-content: center; padding: 1rem; box-sizing: border-box; }
        .ar-modal[hidden], .ar-overlay[hidden] { display: none !important; }
        .ar-modal-dialog { width: min(1040px, 100%); max-height: calc(100vh - 2rem); overflow: auto; border-radius: 24px; }
        .ar-modal-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; padding: 1.15rem 1.25rem 1rem; border-bottom: 1px solid #e2e8f0; }
        .ar-modal-head p { margin: 0; color: #64748b; font-size: .82rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }
        .ar-modal-head h3 { margin: .35rem 0 0; color: #0f172a; font-size: 1.4rem; }
        .ar-close { width: 42px; height: 42px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; cursor: pointer; }
        .ar-modal-body { display: grid; gap: 1rem; padding: 1.2rem 1.25rem 1.3rem; }
        .ar-spotlight, .ar-grid article, .ar-feedback-panel { border-radius: 20px; border: 1px solid #dbe5f0; background: #f8fbff; }
        .ar-spotlight { display: grid; grid-template-columns: minmax(0,1.2fr) minmax(260px,.75fr); gap: 1rem; padding: 1rem; }
        .ar-badges { display: flex; gap: .45rem; flex-wrap: wrap; margin-bottom: .65rem; }
        .ar-spotlight h4 { margin: 0; font-size: 1.35rem; color: #0f172a; }
        .ar-spotlight .type { margin: .35rem 0 0; color: #334155; font-weight: 700; }
        .ar-spotlight .desc { margin: .7rem 0 0; color: #475569; line-height: 1.65; white-space: pre-wrap; }
        .ar-side { display: flex; flex-direction: column; gap: .55rem; justify-content: space-between; background: #fff; border: 1px solid #dbe5f0; border-radius: 18px; padding: .95rem; }
        .ar-side span { color: #64748b; font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }
        .ar-side strong { color: #0f172a; line-height: 1.55; }
        .ar-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 1rem; }
        .ar-grid article { padding: 1rem; }
        .ar-grid h4, .ar-feedback-panel h4 { margin: 0; color: #0f172a; font-size: 1rem; }
        .ar-list { display: grid; gap: .65rem; margin-top: .9rem; }
        .ar-detail { display: flex; justify-content: space-between; gap: .7rem; padding: .7rem .78rem; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; }
        .ar-detail span { color: #64748b; font-weight: 600; }
        .ar-detail strong { color: #0f172a; text-align: right; }
        .ar-feedback-panel { padding: 1rem; }
        .ar-feedback-panel p { margin: .3rem 0 0; color: #64748b; }
        .ar-feedback-list { display: grid; gap: .75rem; margin-top: 1rem; }
        .ar-feedback { border: 1px solid #e2e8f0; border-radius: 18px; background: #fff; padding: .9rem .95rem; }
        .ar-feedback-head { display: flex; justify-content: space-between; gap: .8rem; align-items: center; }
        .ar-feedback-head strong { color: #0f172a; }
        .ar-feedback-head span { color: #64748b; font-size: .84rem; }
        .ar-note { margin: .65rem 0 0; color: #334155; line-height: 1.65; white-space: pre-wrap; }
        .ar-feedback-empty { border: 1px dashed #cbd5e1; border-radius: 18px; background: #f8fafc; color: #64748b; padding: 1.05rem 1rem; }
        .ar-proof-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .8rem; margin-top: 1rem; }
        .ar-proof-card { margin: 0; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden; background: #fff; }
        .ar-proof-card a { display: block; background: #e2e8f0; }
        .ar-proof-card img { display: block; width: 100%; height: 220px; object-fit: contain; background: transparent; }
        .ar-proof-card figcaption { display: grid; gap: .18rem; padding: .7rem .78rem; color: #64748b; font-size: .82rem; line-height: 1.45; }
        .ar-proof-card figcaption strong { color: #0f172a; font-size: .86rem; }
        .ar-report-status { display: inline-flex; align-items: center; gap: .38rem; width: fit-content; padding: .36rem .68rem; border-radius: 999px; font-size: .76rem; font-weight: 800; white-space: nowrap; }
        .ar-report-status.submitted { background: #fef3c7; color: #92400e; }
        .ar-report-status.approved { background: #dcfce7; color: #166534; }
        .ar-report-status.revision { background: #fee2e2; color: #991b1b; }
        .ar-report-status.pending, .ar-report-status.none { background: #e2e8f0; color: #475569; }
        .ar-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
        .ar-after-action-list { display: grid; gap: 1rem; margin-top: 1rem; }
        .ar-report-card { overflow: hidden; border: 1px solid #cbd5e1; border-radius: 18px; background: #fff; }
        .ar-report-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: .95rem 1rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .ar-report-kicker { display: block; color: #64748b; font-size: .76rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
        .ar-report-head h5 { margin: .35rem 0 0; color: #0f172a; font-size: 1.08rem; }
        .ar-report-head p { margin: .28rem 0 0; color: #64748b; font-size: .82rem; }
        .ar-report-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .65rem; padding: 1rem; }
        .ar-report-field { display: grid; gap: .35rem; min-width: 0; padding: .72rem .78rem; border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fbff; }
        .ar-report-field-wide { grid-column: 1 / -1; }
        .ar-report-field span { color: #64748b; font-size: .72rem; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
        .ar-report-field strong { color: #0f172a; font-size: .88rem; font-weight: 650; line-height: 1.58; white-space: pre-wrap; overflow-wrap: anywhere; }
        .ar-review-box { margin: 0 1rem 1rem; padding: .9rem; border: 1px solid #bfdbfe; border-radius: 15px; background: #eff6ff; }
        .ar-review-box label { display: block; color: #1e3a8a; font-size: .82rem; font-weight: 800; }
        .ar-review-box textarea { width: 100%; margin-top: .45rem; padding: .72rem .78rem; border: 1px solid #93c5fd; border-radius: 12px; background: #fff; color: #0f172a; font: inherit; line-height: 1.5; resize: vertical; box-sizing: border-box; }
        .ar-review-box textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.14); }
        .ar-review-actions { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .72rem; }
        .ar-review-btn { min-height: 42px; padding: .65rem .85rem; border: 0; border-radius: 11px; color: #fff; font-weight: 800; cursor: pointer; }
        .ar-review-btn.approve { background: #15803d; }
        .ar-review-btn.return { background: #b91c1c; }
        .ar-review-btn:disabled { opacity: .58; cursor: wait; }
        .ar-review-help { margin: .62rem 0 0 !important; color: #475569 !important; font-size: .78rem; line-height: 1.5; }
        .ar-review-message { min-height: 1.2em; margin-top: .4rem; color: #1d4ed8; font-size: .8rem; font-weight: 700; }
        .ar-review-message.error { color: #b91c1c; }
        .ar-review-outcome { margin: 0 1rem 1rem; padding: .82rem .9rem; border-radius: 14px; border: 1px solid #cbd5e1; background: #f8fafc; }
        .ar-review-outcome.approved { border-color: #86efac; background: #f0fdf4; }
        .ar-review-outcome.revision { border-color: #fecaca; background: #fef2f2; }
        .ar-review-outcome > div { display: flex; align-items: center; justify-content: space-between; gap: .7rem; flex-wrap: wrap; }
        .ar-review-outcome > div > span:last-child { color: #64748b; font-size: .8rem; }
        .ar-review-outcome p { margin: .6rem 0 0; color: #334155; line-height: 1.55; }
        .ar-review-outcome blockquote { display: grid; gap: .28rem; margin: .65rem 0 0; padding: .65rem .75rem; border-left: 4px solid #64748b; border-radius: 0 10px 10px 0; background: rgba(255,255,255,.72); }
        .ar-review-outcome blockquote strong { color: #334155; font-size: .78rem; }
        .ar-review-outcome blockquote span { color: #475569; line-height: 1.55; white-space: pre-wrap; }
        .ar-note-source { display: inline-flex; padding: .3rem .55rem; border-radius: 999px; background: #e2e8f0; color: #475569 !important; font-size: .72rem !important; font-weight: 800; white-space: nowrap; }
        .ar-toast { position: fixed; top: calc(var(--app-header-height-1) + .75rem); right: 1rem; z-index: 3000; max-width: min(420px, calc(100vw - 2rem)); padding: .82rem 1rem; border-radius: 13px; background: #1e293b; color: #fff; box-shadow: 0 14px 34px rgba(15,23,42,.26); font-size: .88rem; font-weight: 700; transition: opacity .2s ease, transform .2s ease; }
        .ar-toast.success { background: #166534; }
        .ar-toast.error { background: #991b1b; }
        .ar-toast.leaving { opacity: 0; transform: translateY(-8px); }
        html.ar-modal-open, html.ar-modal-open body { overflow: hidden; }
        [data-theme="dark"] .main-content { background: radial-gradient(circle at top right, rgba(59,130,246,.14), transparent 28%), #08111f; }
        /* Responsive review cards: prevent action buttons and report fields from overflowing. */
        .ar-shell,
        .ar-card,
        .ar-card-head,
        .ar-after-action-list,
        .ar-report-card,
        .ar-report-head,
        .ar-report-grid,
        .ar-review-box,
        .ar-row-actions,
        .ar-review-actions {
            min-width: 0;
        }

        .ar-card-head-actions,
        .ar-row-actions,
        .ar-review-actions {
            max-width: 100%;
        }

        .ar-row-actions .ar-action,
        .ar-review-actions .ar-review-btn {
            min-width: 0;
            max-width: 100%;
        }

        .ar-report-head > *,
        .ar-report-field,
        .ar-detail,
        .ar-feedback-head {
            min-width: 0;
        }

        .ar-report-head h5,
        .ar-report-head p,
        .ar-report-field strong,
        .ar-detail strong,
        .ar-feedback-head strong {
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        @media (max-width: 1180px) {
            .main-content {
                padding-left: clamp(0.9rem, 2.5vw, 2rem);
                padding-right: clamp(0.9rem, 2.5vw, 2rem);
            }

            .ar-shell {
                width: 100%;
            }

            .ar-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ar-report-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ar-row-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 900px) {
            .ar-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ar-grid,
            .ar-report-grid {
                grid-template-columns: 1fr;
            }

            .ar-card-head,
            .ar-report-head,
            .ar-feedback-head {
                align-items: flex-start;
            }

            .ar-row-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
            }

            .ar-row-actions .ar-action {
                width: 100%;
                white-space: normal;
            }
        }

        @media (max-width: 640px) {
            .main-content {
                padding: calc(var(--app-header-height-mobile-1) + 0.8rem) 0.7rem 1.25rem;
            }

            .ar-hero {
                padding: 1rem;
                border-radius: 18px;
            }

            .ar-hero h1 {
                font-size: 1.3rem;
                overflow-wrap: anywhere;
            }

            .ar-hero p {
                font-size: 0.82rem;
            }

            .ar-stats,
            .ar-toolbar,
            .ar-grid,
            .ar-report-grid {
                grid-template-columns: 1fr;
            }

            .ar-stat,
            .ar-toolbar,
            .ar-card,
            .ar-modal-dialog {
                border-radius: 16px;
            }

            .ar-card-head,
            .ar-report-head {
                flex-direction: column;
            }

            .ar-card-head-actions,
            .ar-row-actions,
            .ar-review-actions {
                width: 100%;
            }

            .ar-row-actions,
            .ar-review-actions {
                grid-template-columns: 1fr;
            }

            .ar-action,
            .ar-review-btn {
                width: 100%;
            }

            .ar-report-field-wide {
                grid-column: auto;
            }

            .ar-detail {
                flex-direction: column;
                align-items: flex-start;
            }

            .ar-detail strong {
                text-align: left;
            }
        }

        [data-theme="dark"] .ar-stat, [data-theme="dark"] .ar-toolbar, [data-theme="dark"] .ar-card, [data-theme="dark"] .ar-modal-dialog { background: linear-gradient(180deg, rgba(15,23,42,.98), rgba(2,6,23,.98)); border-color: #334155; box-shadow: 0 18px 42px rgba(2,6,23,.38); }
        [data-theme="dark"] .ar-stat strong, [data-theme="dark"] .ar-card-head h2, [data-theme="dark"] .ar-modal-head h3, [data-theme="dark"] .ar-spotlight h4, [data-theme="dark"] .ar-side strong, [data-theme="dark"] .ar-grid h4, [data-theme="dark"] .ar-feedback-panel h4, [data-theme="dark"] .ar-detail strong, [data-theme="dark"] .ar-feedback-head strong, [data-theme="dark"] .ar-proof-card figcaption strong { color: #f8fafc !important; }
        [data-theme="dark"] .ar-stat span, [data-theme="dark"] .ar-stat p, [data-theme="dark"] .ar-field label, [data-theme="dark"] .ar-card-head p, [data-theme="dark"] .ar-modal-head p, [data-theme="dark"] .ar-spotlight .type, [data-theme="dark"] .ar-spotlight .desc, [data-theme="dark"] .ar-side span, [data-theme="dark"] .ar-detail span, [data-theme="dark"] .ar-feedback-panel p, [data-theme="dark"] .ar-feedback-head span, [data-theme="dark"] .ar-note, [data-theme="dark"] .ar-feedback-empty, [data-theme="dark"] .ar-proof-card figcaption { color: #94a3b8 !important; }
        [data-theme="dark"] .ar-field input, [data-theme="dark"] .ar-field select, [data-theme="dark"] .ar-btn.secondary, [data-theme="dark"] .ar-action, [data-theme="dark"] .ar-close { background: #0f172a !important; color: #f8fafc !important; border-color: #475569 !important; }
        [data-theme="dark"] .ar-action.sync { background: #115e59 !important; color: #ccfbf1 !important; border-color: #0f766e !important; }
        [data-theme="dark"] .ar-action.danger { background: #7f1d1d !important; color: #fecaca !important; border-color: #991b1b !important; }
        [data-theme="dark"] .ar-action.sent, [data-theme="dark"] .ar-action:disabled { background: #1e293b !important; color: #94a3b8 !important; border-color: #334155 !important; }
        [data-theme="dark"] .ar-card-head, [data-theme="dark"] .ar-spotlight, [data-theme="dark"] .ar-grid article, [data-theme="dark"] .ar-feedback-panel, [data-theme="dark"] .ar-side, [data-theme="dark"] .ar-detail, [data-theme="dark"] .ar-feedback, [data-theme="dark"] .ar-feedback-empty, [data-theme="dark"] .ar-proof-card { background: #020617 !important; border-color: #334155 !important; }
        [data-theme="dark"] .ar-count, [data-theme="dark"] .ar-pill.empty { background: #1e293b !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .ar-chip.resolved { background: #052e16 !important; color: #bbf7d0 !important; }
        [data-theme="dark"] .ar-chip.cancelled { background: #450a0a !important; color: #fecaca !important; }
        [data-theme="dark"] .ar-pill.high { background: #450a0a !important; color: #fecaca !important; }
        [data-theme="dark"] .ar-pill.medium { background: #451a03 !important; color: #fde68a !important; }
        [data-theme="dark"] .ar-pill.low { background: #052e16 !important; color: #bbf7d0 !important; }
        [data-theme="dark"] .ar-report-card, [data-theme="dark"] .ar-report-head, [data-theme="dark"] .ar-report-field, [data-theme="dark"] .ar-review-outcome, [data-theme="dark"] .ar-review-box { background: #020617 !important; border-color: #334155 !important; }
        [data-theme="dark"] .ar-report-head h5, [data-theme="dark"] .ar-report-field strong { color: #f8fafc !important; }
        [data-theme="dark"] .ar-review-outcome p, [data-theme="dark"] .ar-review-outcome blockquote strong { color: #cbd5e1 !important; }
        [data-theme="dark"] .ar-review-box label { color: #93c5fd !important; }
        [data-theme="dark"] .ar-review-box textarea { background: #0f172a !important; color: #f8fafc !important; border-color: #475569 !important; }
        [data-theme="dark"] .ar-review-outcome blockquote { background: #0f172a !important; }
        [data-theme="dark"] .ar-report-status.submitted { background: #451a03 !important; color: #fde68a !important; }
        [data-theme="dark"] .ar-report-status.approved { background: #052e16 !important; color: #bbf7d0 !important; }
        [data-theme="dark"] .ar-report-status.revision { background: #450a0a !important; color: #fecaca !important; }
        [data-theme="dark"] .ar-report-status.pending, [data-theme="dark"] .ar-report-status.none, [data-theme="dark"] .ar-note-source { background: #1e293b !important; color: #cbd5e1 !important; }
        @media (max-width: 1180px) { .ar-stats, .ar-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .ar-toolbar, .ar-spotlight { grid-template-columns: 1fr; } .ar-report-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .ar-actions { justify-content: flex-end; } }
        @media (max-width: 767px) { .main-content { padding: calc(var(--app-header-height-mobile-1) + 1rem) .75rem 1.5rem; } .ar-stats, .ar-toolbar, .ar-grid, .ar-report-grid { grid-template-columns: 1fr; } .ar-report-field-wide { grid-column: auto; } .ar-actions, .ar-row-actions, .ar-review-actions { display: grid; grid-template-columns: 1fr; } .ar-review-btn { width: 100%; } .ar-feedback-head, .ar-card-head, .ar-panel-head, .ar-report-head { flex-direction: column; align-items: flex-start; } .ar-toast { top: calc(var(--app-header-height-mobile-1) + .5rem); } }
    </style>
    <link rel="stylesheet" href="css/admin-after-action-landscape.css?v=<?php echo htmlspecialchars($reviewLandscapeCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>
    <main class="main-content">
        <div class="main-container ar-shell">
            <section class="ar-hero">
                <div class="ar-hero-icon" aria-hidden="true"><i class="fas fa-clipboard-check"></i></div>
                <div class="ar-hero-copy">
                    <span class="ar-eyebrow">Operations quality desk</span>
                    <h1>Reviews &amp; Feedback</h1>
                    <p>Hi <?php echo htmlspecialchars($adminName); ?>. Start with incidents missing a report, then review new submissions, revisions, and completed decisions.</p>
                </div>
                <div class="ar-hero-guide" aria-label="Review workflow">
                    <span><i class="fas fa-1"></i> Open a case</span>
                    <span><i class="fas fa-2"></i> Check the report and proof</span>
                    <span><i class="fas fa-3"></i> Approve or return</span>
                </div>
            </section>

            <section class="ar-stats" aria-label="Review workload" aria-live="polite">
                <button type="button" class="ar-stat no-report is-active" data-queue-shortcut="no_report" aria-pressed="true">
                    <span class="ar-stat-icon"><i class="fas fa-file-lines"></i></span>
                    <span class="ar-stat-copy"><small>No after-action report</small><strong id="statNoReport">0</strong><em>Needs responder follow-up</em></span>
                </button>
                <button type="button" class="ar-stat pending" data-queue-shortcut="submitted" aria-pressed="false">
                    <span class="ar-stat-icon"><i class="fas fa-inbox"></i></span>
                    <span class="ar-stat-copy"><small>Needs approval</small><strong id="statPending">0</strong><em>Open review queue</em></span>
                </button>
                <button type="button" class="ar-stat revision" data-queue-shortcut="revision_required" aria-pressed="false">
                    <span class="ar-stat-icon"><i class="fas fa-rotate-left"></i></span>
                    <span class="ar-stat-copy"><small>Waiting on revision</small><strong id="statRevision">0</strong><em>Returned to responders</em></span>
                </button>
                <button type="button" class="ar-stat approved" data-queue-shortcut="approved" aria-pressed="false">
                    <span class="ar-stat-icon"><i class="fas fa-circle-check"></i></span>
                    <span class="ar-stat-copy"><small>Approved</small><strong id="statApproved">0</strong><em>Completed decisions</em></span>
                </button>
            </section>

            <section class="ar-toolbar" aria-label="Review queue filters">
                <div class="ar-field ar-search-field">
                    <label for="searchFilterInput">Find a review case</label>
                    <div class="ar-input-with-icon">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="searchFilterInput" autocomplete="off" placeholder="Incident, responder, location, unit, vehicle, or report text">
                    </div>
                </div>
                <div class="ar-field">
                    <label for="categoryFilterSelect">Category</label>
                    <select id="categoryFilterSelect">
                        <option value="">All Categories</option>
                        <option value="fire">Fire</option>
                        <option value="medical">Medical</option>
                        <option value="traffic">Traffic</option>
                        <option value="police">Police</option>
                        <option value="rescue">Rescue</option>
                        <option value="disaster">Disaster</option>
                        <option value="general">General</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="ar-field">
                    <label for="statusFilterSelect">Work queue</label>
                    <select id="statusFilterSelect">
                        <option value="no_report" selected>No after-action report</option>
                        <option value="submitted">Needs approval</option>
                        <option value="revision_required">Waiting on revision</option>
                        <option value="approved">Approved reports</option>
                        <option value="">All review cases</option>
                    </select>
                </div>
                <div class="ar-actions">
                    <button type="button" class="ar-btn primary" id="refreshReviewBtn"><i class="fas fa-rotate"></i> Refresh queue</button>
                    <button type="button" class="ar-btn secondary" id="resetFilterBtn"><i class="fas fa-filter-circle-xmark"></i> Clear</button>
                </div>
            </section>

            <section class="ar-card">
                <div class="ar-card-head">
                    <div>
                        <span class="ar-section-kicker"><i class="fas fa-list-check"></i> Review workload</span>
                        <h2 id="queueTitle">No after-action report</h2>
                        <p id="tableSubtitle">Loading responder after-action reports...</p>
                    </div>
                    <div class="ar-card-head-actions">
                        <button type="button" class="ar-view-all" data-queue-shortcut="" aria-pressed="false"><i class="fas fa-layer-group" aria-hidden="true"></i> All cases</button>
                        <span class="ar-count" id="incidentCountBadge">0 cases</span>
                    </div>
                </div>
                <div class="ar-queue" id="incidentTableBody" aria-live="polite" aria-busy="true"></div>
            </section>
        </div>
    </main>
    <div id="adminFeedbackOverlay" class="ar-overlay" hidden></div>
    <div id="adminFeedbackModal" class="ar-modal" hidden role="dialog" aria-modal="true" aria-labelledby="adminFeedbackTitle">
        <div class="ar-modal-dialog">
            <div class="ar-modal-head">
                <div>
                    <p>After-Action Review</p>
                    <h3 id="adminFeedbackTitle">After-Action Review Details</h3>
                </div>
                <div class="ar-modal-head-actions">
                    <button type="button" class="ar-close ar-expand" id="adminFeedbackExpand" aria-label="Maximize review workspace" aria-pressed="false" title="Maximize review workspace"><i class="fas fa-expand"></i></button>
                    <button type="button" class="ar-close" id="adminFeedbackClose" aria-label="Close"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="ar-modal-body">
                <div class="ar-review-workspace">
                    <aside class="ar-review-context ar-review-scroll-region" aria-label="Incident context">
                        <section class="ar-spotlight">
                            <div>
                                <div id="adminModalBadges" class="ar-badges"></div>
                                <h4 id="adminModalCode">--</h4>
                                <p id="adminModalType" class="type">--</p>
                                <p id="adminModalDescription" class="desc">--</p>
                            </div>
                            <div class="ar-side">
                                <span>Location</span>
                                <strong id="adminModalLocation">--</strong>
                                <strong id="adminModalClosed">Closed: --</strong>
                            </div>
                        </section>

                        <section class="ar-grid ar-context-grid">
                            <article>
                                <h4><i class="fas fa-stopwatch"></i> Timeline</h4>
                                <div class="ar-list">
                                    <div class="ar-detail"><span>Dispatched</span><strong id="adminModalDispatch">--</strong></div>
                                    <div class="ar-detail"><span>On Scene</span><strong id="adminModalOnScene">--</strong></div>
                                    <div class="ar-detail"><span>Response Time</span><strong id="adminModalResponse">--</strong></div>
                                    <div class="ar-detail"><span>Resolution Time</span><strong id="adminModalResolution">--</strong></div>
                                </div>
                            </article>
                            <article>
                                <h4><i class="fas fa-truck-medical"></i> Unit &amp; Vehicle</h4>
                                <div class="ar-list">
                                    <div class="ar-detail"><span>Assigned Unit</span><strong id="adminModalUnit">--</strong></div>
                                    <div class="ar-detail"><span>Driver</span><strong id="adminModalDriver">--</strong></div>
                                    <div class="ar-detail"><span>Vehicle</span><strong id="adminModalVehicle">--</strong></div>
                                    <div class="ar-detail"><span>Plate Number</span><strong id="adminModalPlate">--</strong></div>
                                </div>
                            </article>
                            <article>
                                <h4><i class="fas fa-clipboard-check"></i> Review Status</h4>
                                <div class="ar-list">
                                    <div class="ar-detail"><span>Total Reports</span><strong id="adminModalReportCount">0</strong></div>
                                    <div class="ar-detail"><span>Awaiting Review</span><strong id="adminModalPendingCount">0</strong></div>
                                    <div class="ar-detail"><span>Approved</span><strong id="adminModalApprovedCount">0</strong></div>
                                    <div class="ar-detail"><span>Last Activity</span><strong id="adminModalLastUpdated">--</strong></div>
                                </div>
                            </article>
                        </section>
                    </aside>

                    <section class="ar-feedback-panel ar-after-action-panel" aria-labelledby="afterActionPanelTitle">
                        <div class="ar-panel-head">
                            <div>
                                <h4 id="afterActionPanelTitle"><i class="fas fa-file-signature"></i> Responder After-Action Report</h4>
                                <p>Review the full submission and record the admin decision.</p>
                            </div>
                            <span class="ar-workspace-hint"><i class="fas fa-table-columns"></i> Review document</span>
                        </div>
                        <div id="adminAfterActionList" class="ar-after-action-list"></div>
                    </section>

                    <aside class="ar-review-support ar-review-scroll-region" aria-label="Supporting evidence and notes">
                        <section class="ar-feedback-panel ar-operational-notes-panel">
                            <h4><i class="fas fa-clipboard-list"></i> Operational Notes</h4>
                            <p>Dispatcher and responder notes, without rating scores.</p>
                            <div id="adminFeedbackList" class="ar-feedback-list"></div>
                        </section>
                        <section class="ar-feedback-panel ar-proof-panel">
                            <h4><i class="fas fa-camera"></i> Resolution Proof</h4>
                            <p>Responder-uploaded completion photos for verification.</p>
                            <div id="adminProofGallery" class="ar-proof-gallery"></div>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
    </div>
    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <div id="adminReviewConfig" data-admin-user-id="<?php echo $adminUserId; ?>" hidden></div>
    <script src="js/admin-review-after-action.js?v=<?php echo htmlspecialchars($reviewScriptVersion, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
 </body>
 </html>
