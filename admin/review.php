<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/review.php');

$pageTitle = 'Review & Feedback';
$adminName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Admin'));
if ($adminName === '') {
    $adminName = 'Admin';
}
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
        .ar-scroll { max-height: 620px; overflow: auto; }
        .ar-table { width: 100%; border-collapse: collapse; min-width: 1080px; }
        .ar-table th, .ar-table td { padding: .82rem .8rem; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; font-size: .88rem; }
        .ar-table th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #334155; text-transform: uppercase; letter-spacing: .04em; font-size: .74rem; }
        .ar-table tr:hover td { background: #f8fbff; }
        .ar-ref { font-weight: 800; color: #0f172a; margin-bottom: .2rem; }
        .ar-meta { color: #64748b; line-height: 1.55; }
        .ar-chip, .ar-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .7rem; border-radius: 999px; font-size: .76rem; font-weight: 800; }
        .ar-chip.resolved { background: #dcfce7; color: #166534; }
        .ar-chip.cancelled { background: #fee2e2; color: #991b1b; }
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
        .ar-rating-wrap { display: grid; justify-items: end; gap: .25rem; }
        .ar-rating-label { color: #92400e; font-size: .78rem; font-weight: 800; }
        .ar-stars { display: inline-flex; gap: .18rem; color: #f59e0b; }
        .ar-note { margin: .65rem 0 0; color: #334155; line-height: 1.65; white-space: pre-wrap; }
        .ar-feedback-empty { border: 1px dashed #cbd5e1; border-radius: 18px; background: #f8fafc; color: #64748b; padding: 1.05rem 1rem; }
        .ar-proof-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .8rem; margin-top: 1rem; }
        .ar-proof-card { margin: 0; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden; background: #fff; }
        .ar-proof-card a { display: block; background: #e2e8f0; }
        .ar-proof-card img { display: block; width: 100%; height: 220px; object-fit: contain; background: transparent; }
        .ar-proof-card figcaption { display: grid; gap: .18rem; padding: .7rem .78rem; color: #64748b; font-size: .82rem; line-height: 1.45; }
        .ar-proof-card figcaption strong { color: #0f172a; font-size: .86rem; }
        [data-theme="dark"] .main-content { background: radial-gradient(circle at top right, rgba(59,130,246,.14), transparent 28%), #08111f; }
        [data-theme="dark"] .ar-stat, [data-theme="dark"] .ar-toolbar, [data-theme="dark"] .ar-card, [data-theme="dark"] .ar-modal-dialog { background: linear-gradient(180deg, rgba(15,23,42,.98), rgba(2,6,23,.98)); border-color: #334155; box-shadow: 0 18px 42px rgba(2,6,23,.38); }
        [data-theme="dark"] .ar-stat strong, [data-theme="dark"] .ar-ref, [data-theme="dark"] .ar-card-head h2, [data-theme="dark"] .ar-modal-head h3, [data-theme="dark"] .ar-spotlight h4, [data-theme="dark"] .ar-side strong, [data-theme="dark"] .ar-grid h4, [data-theme="dark"] .ar-feedback-panel h4, [data-theme="dark"] .ar-detail strong, [data-theme="dark"] .ar-feedback-head strong, [data-theme="dark"] .ar-proof-card figcaption strong { color: #f8fafc !important; }
        [data-theme="dark"] .ar-stat span, [data-theme="dark"] .ar-stat p, [data-theme="dark"] .ar-field label, [data-theme="dark"] .ar-card-head p, [data-theme="dark"] .ar-meta, [data-theme="dark"] .ar-modal-head p, [data-theme="dark"] .ar-spotlight .type, [data-theme="dark"] .ar-spotlight .desc, [data-theme="dark"] .ar-side span, [data-theme="dark"] .ar-detail span, [data-theme="dark"] .ar-feedback-panel p, [data-theme="dark"] .ar-feedback-head span, [data-theme="dark"] .ar-note, [data-theme="dark"] .ar-feedback-empty, [data-theme="dark"] .ar-proof-card figcaption { color: #94a3b8 !important; }
        [data-theme="dark"] .ar-rating-label { color: #fde68a !important; }
        [data-theme="dark"] .ar-field input, [data-theme="dark"] .ar-field select, [data-theme="dark"] .ar-btn.secondary, [data-theme="dark"] .ar-action, [data-theme="dark"] .ar-close { background: #0f172a !important; color: #f8fafc !important; border-color: #475569 !important; }
        [data-theme="dark"] .ar-action.sync { background: #115e59 !important; color: #ccfbf1 !important; border-color: #0f766e !important; }
        [data-theme="dark"] .ar-action.danger { background: #7f1d1d !important; color: #fecaca !important; border-color: #991b1b !important; }
        [data-theme="dark"] .ar-action.sent, [data-theme="dark"] .ar-action:disabled { background: #1e293b !important; color: #94a3b8 !important; border-color: #334155 !important; }
        [data-theme="dark"] .ar-card-head, [data-theme="dark"] .ar-table th, [data-theme="dark"] .ar-table tr:hover td, [data-theme="dark"] .ar-spotlight, [data-theme="dark"] .ar-grid article, [data-theme="dark"] .ar-feedback-panel, [data-theme="dark"] .ar-side, [data-theme="dark"] .ar-detail, [data-theme="dark"] .ar-feedback, [data-theme="dark"] .ar-feedback-empty, [data-theme="dark"] .ar-proof-card { background: #020617 !important; border-color: #334155 !important; }
        [data-theme="dark"] .ar-count, [data-theme="dark"] .ar-pill.empty { background: #1e293b !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .ar-chip.resolved { background: #052e16 !important; color: #bbf7d0 !important; }
        [data-theme="dark"] .ar-chip.cancelled { background: #450a0a !important; color: #fecaca !important; }
        [data-theme="dark"] .ar-pill.high { background: #450a0a !important; color: #fecaca !important; }
        [data-theme="dark"] .ar-pill.medium { background: #451a03 !important; color: #fde68a !important; }
        [data-theme="dark"] .ar-pill.low { background: #052e16 !important; color: #bbf7d0 !important; }
        @media (max-width: 1180px) { .ar-stats, .ar-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .ar-toolbar, .ar-spotlight { grid-template-columns: 1fr; } .ar-actions { justify-content: flex-end; } }
        @media (max-width: 767px) { .main-content { padding: calc(var(--app-header-height-mobile-1) + 1rem) .75rem 1.5rem; } .ar-stats, .ar-toolbar, .ar-grid { grid-template-columns: 1fr; } .ar-actions, .ar-row-actions { display: grid; grid-template-columns: 1fr; } .ar-feedback-head, .ar-card-head { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>
    <main class="main-content">
        <div class="main-container ar-shell">
            <section class="ar-hero">
                <h1>Review &amp; Feedback Console</h1>
                <p>Hi <?php echo htmlspecialchars($adminName); ?>. Feedback and received survey entries are listed here automatically so the admin team can review them per closed incident.</p>
            </section>

            <section class="ar-stats" aria-live="polite">
                <article class="ar-stat"><span>Submitted Reviews</span><strong id="statClosed">0</strong><p>Resolved and cancelled incidents sent by dispatcher.</p></article>
                <article class="ar-stat"><span>With Feedback</span><strong id="statFeedback">0</strong><p>Incidents that already have dispatcher notes or ratings.</p></article>
                <article class="ar-stat"><span>Average Rating</span><strong id="statRating">--</strong><p>Average score from dispatcher feedback entries.</p></article>
                <article class="ar-stat"><span>Average Response</span><strong id="statResponse">--</strong><p>Average dispatch-to-scene timing for visible incidents.</p></article>
            </section>

            <section class="ar-toolbar">
                <div class="ar-field">
                    <label for="searchFilterInput">Search</label>
                    <input type="text" id="searchFilterInput" placeholder="Search incident, location, driver, plate, or vehicle...">
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
                    </select>
                </div>
                <div class="ar-field">
                    <label for="statusFilterSelect">Queue Filter</label>
                    <select id="statusFilterSelect">
                        <option value="">All Closed Cases</option>
                        <option value="resolved">Resolved Only</option>
                        <option value="cancelled">Cancelled Only</option>
                        <option value="with_feedback">With Feedback / Survey</option>
                        <option value="without_feedback">No Feedback Yet</option>
                    </select>
                </div>
                <div class="ar-actions">
                    <button type="button" class="ar-btn primary" id="refreshReviewBtn"><i class="fas fa-rotate"></i> Refresh</button>
                    <button type="button" class="ar-btn secondary" id="resetFilterBtn"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </section>

            <section class="ar-card">
                <div class="ar-card-head">
                    <div>
                        <h2>Feedback &amp; Survey Queue</h2>
                        <p id="tableSubtitle">Loading closed incidents and dispatcher feedback...</p>
                    </div>
                    <span class="ar-count" id="incidentCountBadge">0 incident(s)</span>
                </div>
                <div class="ar-scroll">
                    <table class="ar-table">
                        <thead>
                            <tr>
                                <th>Incident</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Feedback</th>
                                <th>Closed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="incidentTableBody"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
    <div id="adminFeedbackOverlay" class="ar-overlay" hidden></div>
    <div id="adminFeedbackModal" class="ar-modal" hidden role="dialog" aria-modal="true" aria-labelledby="adminFeedbackTitle">
        <div class="ar-modal-dialog">
            <div class="ar-modal-head">
                <div>
                    <p>Dispatcher Feedback</p>
                    <h3 id="adminFeedbackTitle">Incident Feedback Details</h3>
                </div>
                <button type="button" class="ar-close" id="adminFeedbackClose" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="ar-modal-body">
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
                <section class="ar-grid">
                    <article><h4><i class="fas fa-stopwatch"></i> Timeline</h4><div class="ar-list"><div class="ar-detail"><span>Dispatched</span><strong id="adminModalDispatch">--</strong></div><div class="ar-detail"><span>On Scene</span><strong id="adminModalOnScene">--</strong></div><div class="ar-detail"><span>Response Time</span><strong id="adminModalResponse">--</strong></div><div class="ar-detail"><span>Resolution Time</span><strong id="adminModalResolution">--</strong></div></div></article>
                    <article><h4><i class="fas fa-truck-medical"></i> Unit & Vehicle</h4><div class="ar-list"><div class="ar-detail"><span>Assigned Unit</span><strong id="adminModalUnit">--</strong></div><div class="ar-detail"><span>Driver</span><strong id="adminModalDriver">--</strong></div><div class="ar-detail"><span>Vehicle</span><strong id="adminModalVehicle">--</strong></div><div class="ar-detail"><span>Plate Number</span><strong id="adminModalPlate">--</strong></div></div></article>
                    <article><h4><i class="fas fa-chart-line"></i> Feedback Summary</h4><div class="ar-list"><div class="ar-detail"><span>Average Rating</span><strong id="adminModalAvgRating">--</strong></div><div class="ar-detail"><span>Rated Entries</span><strong id="adminModalRatingCount">0</strong></div><div class="ar-detail"><span>Total Feedback</span><strong id="adminModalFeedbackCount">0</strong></div><div class="ar-detail"><span>Last Updated</span><strong id="adminModalLastUpdated">--</strong></div></div></article>
                </section>
                <section class="ar-feedback-panel">
                    <h4><i class="fas fa-paper-plane"></i> Feedback &amp; Surveys</h4>
                    <p>Dispatcher notes, responder messages, after-action entries, and received surveys appear here automatically.</p>
                    <div id="adminFeedbackList" class="ar-feedback-list"></div>
                </section>
                <section class="ar-feedback-panel">
                    <h4><i class="fas fa-camera"></i> Responder Resolution Proof</h4>
                    <p>Photos uploaded by responders when completing the incident are shown here for admin review.</p>
                    <div id="adminProofGallery" class="ar-proof-gallery"></div>
                </section>
            </div>
        </div>
    </div>
    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <script>
        (function () {
            const qs = (selector, ctx = document) => ctx.querySelector(selector);
            const tableBody = qs('#incidentTableBody');
            const countBadge = qs('#incidentCountBadge');
            const tableSubtitle = qs('#tableSubtitle');
            const searchFilterInput = qs('#searchFilterInput');
            const categoryFilterSelect = qs('#categoryFilterSelect');
            const statusFilterSelect = qs('#statusFilterSelect');
            const resetFilterBtn = qs('#resetFilterBtn');
            const refreshReviewBtn = qs('#refreshReviewBtn');
            const modal = qs('#adminFeedbackModal');
            const modalOverlay = qs('#adminFeedbackOverlay');
            const modalClose = qs('#adminFeedbackClose');
            let incidentRows = [];
            const PH_TIME_ZONE = 'Asia/Manila';
            const PH_DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
                timeZone: PH_TIME_ZONE,
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
                timeZoneName: 'short'
            });

            function escapeHtml(value) {
                return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }
            function toNumber(value) {
                if (value === null || value === undefined || value === '') return null;
                const number = Number(value);
                return Number.isFinite(number) ? number : null;
            }
            function normalizeStatus(status) {
                return String(status || '').toLowerCase() === 'cancelled' ? 'cancelled' : 'resolved';
            }
            function normalizePriority(priority) {
                const safe = String(priority || '').toLowerCase();
                if (safe === 'high') return 'high';
                if (safe === 'medium') return 'medium';
                return 'low';
            }
            function formatDate(value) {
                if (!value) return '--';
                const date = parseDateValue(value);
                return Number.isNaN(date.getTime()) ? String(value) : PH_DATE_FORMATTER.format(date);
            }
            function parseDateValue(value) {
                const raw = String(value || '').trim();
                if (!raw) return new Date(NaN);
                if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
                    return new Date(raw.replace(' ', 'T') + 'Z');
                }
                if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(raw)) {
                    return new Date(raw + 'Z');
                }
                return new Date(raw);
            }
            function formatMinutes(value) {
                const minutes = toNumber(value);
                if (minutes === null) return '--';
                if (minutes < 60) return Math.round(minutes) + ' min';
                const hours = Math.floor(minutes / 60);
                const remaining = Math.round(minutes % 60);
                return remaining ? (hours + 'h ' + remaining + 'm') : (hours + 'h');
            }
            function formatRating(value) {
                const rating = toNumber(value);
                return rating === null ? '--' : (rating.toFixed(1) + ' / 5');
            }
            function normalizeProofUrl(value) {
                const raw = String(value || '').trim();
                if (!raw) return '';
                if (/^(https?:|data:|blob:)/i.test(raw)) return raw;
                return raw.replace(/^\/+/, '');
            }
            function average(values) {
                return values.length ? (values.reduce((sum, value) => sum + value, 0) / values.length) : null;
            }
            function statusChip(status) {
                const safe = normalizeStatus(status);
                const label = safe === 'cancelled' ? 'Cancelled' : 'Resolved';
                return '<span class="ar-chip ' + safe + '">' + escapeHtml(label) + '</span>';
            }
            function priorityChip(priority) {
                const safe = normalizePriority(priority);
                const label = safe.charAt(0).toUpperCase() + safe.slice(1) + ' Priority';
                return '<span class="ar-pill ' + safe + '">' + escapeHtml(label) + '</span>';
            }
            function feedbackPill(row) {
                const count = Number(row.feedback_count || 0);
                if (!count) return '<span class="ar-pill empty"><i class="fas fa-inbox"></i> No feedback</span>';
                const rating = toNumber(row.avg_rating);
                const label = rating === null ? (count + ' note(s)') : ('Response Rating ' + rating.toFixed(1) + ' / 5');
                return '<span class="ar-pill feedback"><i class="fas fa-paper-plane"></i> ' + escapeHtml(label) + '</span>';
            }
            function isCrimeAnalyticsCandidate(row) {
                if (normalizeStatus(row.status) !== 'resolved') return false;
                const haystack = [row.type, row.title, row.description].join(' ').toLowerCase();
                return /\b(police|crime|robbery|theft|fraud|assault|homicide|violence|weapon|gun|knife|patalim|riot)\b/.test(haystack);
            }
            function crimeAnalyticsAction(row) {
                if (!isCrimeAnalyticsCandidate(row)) return '';
                const status = String(row.crime_analytics_status || '').toLowerCase();
                if (status === 'sent') {
                    const sentAt = row.crime_analytics_synced_at ? ' title="Sent ' + escapeHtml(formatDate(row.crime_analytics_synced_at)) + '"' : '';
                    return '<button type="button" class="ar-action sent" disabled' + sentAt + '><i class="fas fa-circle-check"></i> Crime Sent</button>';
                }
                if (status === 'failed') {
                    return '<button type="button" class="ar-action danger" data-action="send-crime-analytics" data-id="' + escapeHtml(row.id) + '"><i class="fas fa-rotate"></i> Retry Crime</button>';
                }
                return '<button type="button" class="ar-action sync" data-action="send-crime-analytics" data-id="' + escapeHtml(row.id) + '"><i class="fas fa-share-from-square"></i> Send Crime</button>';
            }
            function renderStars(rating) {
                const value = toNumber(rating);
                if (value === null || value < 1) return '<span>No rating</span>';
                const rounded = Math.max(1, Math.min(5, Math.round(value)));
                let html = '';
                for (let i = 1; i <= 5; i += 1) html += '<i class="' + (i <= rounded ? 'fas' : 'far') + ' fa-star"></i>';
                return html;
            }
            function responseRatingLabel(rating) {
                const value = toNumber(rating);
                return value === null || value < 1 ? 'No response rating' : 'Response Rating: ' + Math.round(value) + ' / 5';
            }

            async function loadRows() {
                tableBody.innerHTML = '<tr><td colspan="9" class="ar-empty">Loading feedback and survey queue...</td></tr>';
                try {
                    const response = await fetch('api/incidents_list.php?status=closed', { cache: 'no-store' });
                    const data = await response.json();
                    if (!data.ok) throw new Error(data.error || 'Failed to load incidents');
                    incidentRows = (Array.isArray(data.items) ? data.items : []).filter((row) => {
                        return Boolean(row.submitted_to_admin) || Number(row.feedback_count || 0) > 0;
                    });
                    renderStats(incidentRows);
                    renderTable();
                } catch (error) {
                    tableBody.innerHTML = '<tr><td colspan="9" class="ar-empty">Failed to load feedback queue.</td></tr>';
                    tableSubtitle.textContent = 'Unable to load feedback and survey entries at the moment.';
                }
            }

            function renderStats(rows) {
                const withFeedback = rows.filter((row) => Number(row.feedback_count || 0) > 0);
                const ratings = rows.map((row) => toNumber(row.avg_rating)).filter((value) => value !== null);
                const responses = rows.map((row) => toNumber(row.response_time_min)).filter((value) => value !== null);
                qs('#statClosed').textContent = String(rows.length);
                qs('#statFeedback').textContent = String(withFeedback.length);
                qs('#statRating').textContent = ratings.length ? formatRating(average(ratings)) : '--';
                qs('#statResponse').textContent = responses.length ? formatMinutes(average(responses)) : '--';
            }

            function getFilteredRows() {
                const searchNeedle = String(searchFilterInput.value || '').trim().toLowerCase();
                const categoryNeedle = String(categoryFilterSelect.value || '').trim().toLowerCase();
                const statusNeedle = String(statusFilterSelect.value || '').trim().toLowerCase();
                return incidentRows.filter((row) => {
                    const rowStatus = normalizeStatus(row.status);
                    const feedbackCount = Number(row.feedback_count || 0);
                    if (categoryNeedle && String(row.type || '').toLowerCase() !== categoryNeedle) return false;
                    if (statusNeedle === 'resolved' && rowStatus !== 'resolved') return false;
                    if (statusNeedle === 'cancelled' && rowStatus !== 'cancelled') return false;
                    if (statusNeedle === 'with_feedback' && feedbackCount < 1) return false;
                    if (statusNeedle === 'without_feedback' && feedbackCount > 0) return false;
                    if (searchNeedle) {
                        const haystack = [row.incident_code, row.type, row.location, row.driver_name, row.plate_number, row.vehicle_name, row.assigned_unit].join(' ').toLowerCase();
                        if (!haystack.includes(searchNeedle)) return false;
                    }
                    return true;
                });
            }

            function renderTable() {
                const rows = getFilteredRows();
                countBadge.textContent = rows.length + ' incident(s)';
                tableSubtitle.textContent = rows.length ? 'Closed incidents with dispatcher feedback, received surveys, or admin review submissions.' : 'No feedback or survey entries matched the current filter.';
                if (!rows.length) {
                    tableBody.innerHTML = '<tr><td colspan="9" class="ar-empty">No feedback or survey entries match the current filter.</td></tr>';
                    return;
                }
                tableBody.innerHTML = rows.map((row) => `
                    <tr>
                        <td><div class="ar-ref">${escapeHtml(row.incident_code || 'No reference')}</div><div class="ar-meta">${escapeHtml(row.assigned_unit || 'No assigned unit')}</div></td>
                        <td>${escapeHtml(row.type || '--')}</td>
                        <td>${escapeHtml(row.location || '--')}</td>
                        <td>${priorityChip(row.priority)}</td>
                        <td>${statusChip(row.status)}</td>
                        <td>${escapeHtml(formatMinutes(row.response_time_min))}</td>
                        <td>${feedbackPill(row)}</td>
                        <td>${escapeHtml(formatDate(row.resolved_at || row.cleared_at))}</td>
                        <td><div class="ar-row-actions"><button type="button" class="ar-action primary" data-action="view-feedback" data-id="${row.id}"><i class="fas fa-paper-plane"></i> View Feedback</button><button type="button" class="ar-action" data-action="view-feedback" data-id="${row.id}"><i class="fas fa-eye"></i> Details</button>${crimeAnalyticsAction(row)}</div></td>
                    </tr>
                `).join('');
            }

            function setModalLoading() {
                qs('#adminFeedbackTitle').textContent = 'Incident Feedback Details';
                qs('#adminModalCode').textContent = '--';
                qs('#adminModalType').textContent = '--';
                qs('#adminModalDescription').textContent = '--';
                qs('#adminModalLocation').textContent = '--';
                qs('#adminModalClosed').textContent = 'Closed: --';
                qs('#adminModalDispatch').textContent = '--';
                qs('#adminModalOnScene').textContent = '--';
                qs('#adminModalResponse').textContent = '--';
                qs('#adminModalResolution').textContent = '--';
                qs('#adminModalUnit').textContent = '--';
                qs('#adminModalDriver').textContent = '--';
                qs('#adminModalVehicle').textContent = '--';
                qs('#adminModalPlate').textContent = '--';
                qs('#adminModalAvgRating').textContent = '--';
                qs('#adminModalRatingCount').textContent = '0';
                qs('#adminModalFeedbackCount').textContent = '0';
                qs('#adminModalLastUpdated').textContent = '--';
                qs('#adminModalBadges').innerHTML = '';
                qs('#adminFeedbackList').innerHTML = '<div class="ar-feedback-empty">Loading dispatcher feedback...</div>';
                qs('#adminProofGallery').innerHTML = '<div class="ar-feedback-empty">Loading responder proof images...</div>';
            }

            function renderProofs(proofPayload) {
                const gallery = qs('#adminProofGallery');
                const items = proofPayload && proofPayload.ok && Array.isArray(proofPayload.items) ? proofPayload.items : [];
                if (!proofPayload || !proofPayload.ok) {
                    gallery.innerHTML = '<div class="ar-feedback-empty">Unable to load responder proof images.</div>';
                    return;
                }
                if (!items.length) {
                    gallery.innerHTML = '<div class="ar-feedback-empty">No responder proof image was uploaded for this incident.</div>';
                    return;
                }
                gallery.innerHTML = items.map((item) => {
                    const source = item.source === 'responder_completion'
                        ? 'Responder completion upload'
                        : 'Resolution proof upload';
                    const proofUrl = escapeHtml(normalizeProofUrl(item.url || ''));
                    return `
                        <figure class="ar-proof-card">
                            <a href="${proofUrl}" target="_blank" rel="noopener" title="Open full proof image">
                                <img src="${proofUrl}" alt="Responder resolution proof">
                            </a>
                            <figcaption>
                                <strong>${escapeHtml(source)}</strong>
                                <span>${escapeHtml(formatDate(item.created_at))}</span>
                            </figcaption>
                        </figure>
                    `;
                }).join('');
            }

            function populateModal(incident, feedbackPayload, proofPayload) {
                qs('#adminFeedbackTitle').textContent = 'Incident ' + (incident.reference_no || incident.id || '');
                qs('#adminModalCode').textContent = incident.reference_no || ('Incident #' + (incident.id || '--'));
                qs('#adminModalType').textContent = incident.type || '--';
                qs('#adminModalDescription').textContent = incident.description || 'No incident description provided.';
                qs('#adminModalLocation').textContent = incident.location_address || '--';
                qs('#adminModalClosed').textContent = 'Closed: ' + formatDate(incident.resolved_at || incident.cleared_at || incident.updated_at);
                qs('#adminModalDispatch').textContent = formatDate(incident.dispatch_assigned_at || incident.assigned_at || incident.created_at);
                qs('#adminModalOnScene').textContent = formatDate(incident.on_scene_at);
                qs('#adminModalResponse').textContent = formatMinutes(incident.response_time_min);
                qs('#adminModalResolution').textContent = formatMinutes(incident.resolution_time_min);
                qs('#adminModalUnit').textContent = incident.assigned_unit_identifier || 'Unassigned';
                qs('#adminModalDriver').textContent = incident.driver_name || 'Not recorded';
                qs('#adminModalVehicle').textContent = incident.vehicle_name || incident.assigned_unit_identifier || 'Not recorded';
                qs('#adminModalPlate').textContent = incident.plate_number || 'Not recorded';
                const feedbackSummary = feedbackPayload && feedbackPayload.ok && feedbackPayload.summary ? feedbackPayload.summary : {};
                const avgRating = toNumber(feedbackSummary.avg_rating) !== null ? feedbackSummary.avg_rating : incident.avg_rating;
                const ratingCount = Number(feedbackSummary.rating_count ?? incident.rating_count ?? 0);
                const feedbackCount = Number(feedbackSummary.feedback_count ?? incident.feedback_count ?? 0);
                qs('#adminModalAvgRating').textContent = formatRating(avgRating);
                qs('#adminModalRatingCount').textContent = String(ratingCount);
                qs('#adminModalFeedbackCount').textContent = String(feedbackCount);
                qs('#adminModalLastUpdated').textContent = formatDate(incident.updated_at || incident.resolved_at || incident.cleared_at);
                qs('#adminModalBadges').innerHTML = statusChip(incident.status) + ' ' + priorityChip(incident.priority);
                const notes = feedbackPayload && feedbackPayload.ok && Array.isArray(feedbackPayload.data) ? feedbackPayload.data : [];
                qs('#adminFeedbackList').innerHTML = notes.length ? notes.map((note) => `
                    <div class="ar-feedback">
                        <div class="ar-feedback-head">
                            <div><strong>${escapeHtml(note.author_name || 'Dispatcher')}</strong><span>${escapeHtml(formatDate(note.created_at))}</span></div>
                            <div class="ar-rating-wrap">
                                <span class="ar-rating-label">${escapeHtml(responseRatingLabel(note.rating))}</span>
                                <div class="ar-stars">${renderStars(note.rating)}</div>
                            </div>
                        </div>
                        <p class="ar-note">${escapeHtml(note.note || 'No additional note provided.')}</p>
                    </div>
                `).join('') : '<div class="ar-feedback-empty">No feedback or survey entry has been received for this incident yet.</div>';
                renderProofs(proofPayload);
            }

            async function sendCrimeAnalytics(button, incidentId) {
                const row = incidentRows.find((item) => Number(item.id) === Number(incidentId));
                const incidentCode = row ? (row.incident_code || ('#' + incidentId)) : ('#' + incidentId);
                if (!window.confirm('Send resolved police/crime incident ' + incidentCode + ' to Crime Analytics?')) {
                    return;
                }

                const originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending';
                try {
                    const response = await fetch('api/send_crime_analytics.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            incident_id: incidentId,
                            confirm_send: true
                        })
                    });
                    const raw = await response.text();
                    let data = {};
                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch (parseError) {
                        throw new Error('Invalid response from send endpoint.');
                    }
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || data.message || 'Unable to send incident.');
                    }
                    if (data.dry_run) {
                        window.alert('Payload prepared, but not sent. Enable CRIME_ANALYTICS_SEND_ENABLED=true in .env to allow live sending.');
                    } else if (data.already_sent) {
                        window.alert('This incident was already sent to Crime Analytics.');
                    } else {
                        window.alert('Incident sent to Crime Analytics.');
                    }
                    await loadRows();
                } catch (error) {
                    window.alert('Crime Analytics send failed: ' + (error.message || 'Unknown error'));
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            }

            async function openModal(incidentId) {
                setModalLoading();
                modalOverlay.hidden = false;
                modal.hidden = false;
                try {
                    const [detailsRes, feedbackRes, proofsRes] = await Promise.all([
                        fetch('api/incident_details.php?id=' + encodeURIComponent(incidentId), { cache: 'no-store' }),
                        fetch('api/incident_feedback.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store' }),
                        fetch('api/incident_proofs.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store' })
                    ]);
                    const detailsData = await detailsRes.json();
                    const feedbackData = await feedbackRes.json();
                    const proofsData = await proofsRes.json();
                    if (!detailsData.ok || !detailsData.incident) throw new Error(detailsData.error || 'Incident details not available');
                    populateModal(detailsData.incident, feedbackData, proofsData);
                } catch (error) {
                    qs('#adminFeedbackList').innerHTML = '<div class="ar-feedback-empty">' + escapeHtml(error.message || 'Unable to load feedback.') + '</div>';
                    qs('#adminProofGallery').innerHTML = '<div class="ar-feedback-empty">Unable to load responder proof images.</div>';
                }
            }

            function closeModal() {
                modalOverlay.hidden = true;
                modal.hidden = true;
            }

            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('[data-action]');
                if (!button) return;
                const incidentId = parseInt(button.getAttribute('data-id') || '', 10);
                if (!Number.isInteger(incidentId)) return;
                const action = button.getAttribute('data-action');
                if (action === 'send-crime-analytics') {
                    sendCrimeAnalytics(button, incidentId);
                    return;
                }
                openModal(incidentId);
            });
            resetFilterBtn.addEventListener('click', () => {
                searchFilterInput.value = '';
                categoryFilterSelect.value = '';
                statusFilterSelect.value = '';
                renderTable();
            });
            refreshReviewBtn.addEventListener('click', loadRows);
            searchFilterInput.addEventListener('input', renderTable);
            categoryFilterSelect.addEventListener('change', renderTable);
            statusFilterSelect.addEventListener('change', renderTable);
            modalOverlay.addEventListener('click', closeModal);
            modalClose.addEventListener('click', closeModal);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) closeModal();
            });

            closeModal();
            loadRows();
        })();
    </script>
 </body>
 </html>
