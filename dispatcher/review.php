<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/review.php');
$pageTitle = 'Emergency Call Center';
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
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/call.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/review.css">
    <link rel="stylesheet" href="css/dispatcher-module-dark.css?v=20260226h">
    <script>document.documentElement.setAttribute('data-theme','dark'); localStorage.setItem('ers-theme','dark');</script>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>
    <main class="main-content">
        <div class="main-container dispatcher-shell">
            <div class="page-header">
                <h2>Review & Feedback</h2>
                <p class="text-muted">Review resolved incidents and submit feedback to improve response quality.</p>
            </div>

            <div class="filters">
                <div class="filter-group">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter">
                        <option value="all">All</option>
                        <option value="resolved">Resolved / Cancelled</option>
                        <option value="dispatched" selected>Dispatched</option>
                        <option value="active">Active</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="dayFilter">Day</label>
                    <input type="date" id="dayFilter" />
                </div>
                <div class="filter-group search">
                    <label for="searchInput">Search</label>
                    <input type="text" id="searchInput" placeholder="Search by code, type, location..." />
                </div>
                <div class="filter-group">
                    <label for="sortSelect">Sort</label>
                    <select id="sortSelect" aria-label="Sort incidents">
                        <option value="recent" selected>Most Recent</option>
                        <option value="priority_desc">Priority (High -> Low)</option>
                        <option value="code_asc">Incident Code (A -> Z)</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button id="applyFiltersBtn" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                    <button id="clearFiltersBtn" class="btn btn-secondary"><i class="fa fa-undo"></i> Reset</button>
                </div>
            </div>

            <div id="incidentsContainer" class="card-grid"></div>
        </div>
    </main>

    <!-- Review Modal -->
    <div id="reviewModalOverlay" class="modal-overlay" hidden></div>
    <div id="reviewModal" class="modal" hidden>
        <div class="modal-header">
            <h3 id="modalTitle">Review Incident</h3>
            <button id="modalClose" class="modal-close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="modal-body">
            <section class="incident-summary">
                <div class="summary-row">
                    <div>
                        <div class="summary-label"><i class="fa fa-hashtag"></i> Incident Code</div>
                        <div id="summaryCode" class="summary-value">--</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-list"></i> Type</div>
                        <div id="summaryType" class="summary-value">--</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-signal"></i> Priority</div>
                        <div id="summaryPriority" class="summary-value">--</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-check-circle"></i> Status</div>
                        <div id="summaryStatus" class="summary-value">--</div>
                    </div>
                </div>
                <div class="summary-row">
                    <div>
                        <div class="summary-label"><i class="fa fa-clock"></i> Dispatch Time</div>
                        <div id="summaryDispatchTime" class="summary-value">--</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-hourglass-end"></i> Resolve Time</div>
                        <div id="summaryResolveTime" class="summary-value">--</div>
                    </div>
                </div>
                <div class="summary-row">
                    <div class="summary-col">
                        <div class="summary-label"><i class="fa fa-location-dot"></i> Location</div>
                        <div id="summaryLocation" class="summary-value">--</div>
                    </div>
                </div>
                <div class="summary-row">
                    <div class="summary-col">
                        <div class="summary-label"><i class="fa fa-align-left"></i> Description</div>
                        <div id="summaryDescription" class="summary-value">--</div>
                    </div>
                </div>
            </section>

            <nav class="modal-tabs" role="tablist" aria-label="Incident review sections">
                <button class="tab-btn active" id="tabFeedback" role="tab" aria-selected="true" aria-controls="panelFeedback">
                    <i class="fa fa-comments"></i> Feedback
                </button>
                <button class="tab-btn" id="tabProof" role="tab" aria-selected="false" aria-controls="panelProof">
                    <i class="fa fa-camera"></i> Proof
                </button>
            </nav>

            <section id="panelFeedback" class="tab-panel" role="tabpanel" aria-labelledby="tabFeedback">
                <div class="feedback-section">
                    <h4>Responder Feedback</h4>
                    <div id="feedbackList" class="feedback-list" aria-live="polite"></div>
                </div>
                <div class="form-actions">
                    <button type="button" id="cancelFeedbackBtn" class="btn btn-secondary">Close</button>
                    <button type="button" id="confirmReviewBtn" class="btn btn-success"><i class="fa fa-check"></i> Confirm</button>
                </div>
            </section>

            <section id="panelProof" class="tab-panel" role="tabpanel" aria-labelledby="tabProof" hidden>
                <div class="proof-section">
                    <div class="section-header">
                        <h4>Resolution Proof</h4>
                        <p class="text-muted">Below are the images sent by responders as proof of resolution.</p>
                    </div>
                    <div class="proof-controls" style="border: 2px dashed #28a745; border-radius: 12px; padding: 1rem; margin-bottom: 1rem;">
                        <!-- No upload/camera controls, only gallery below -->
                    </div>
                    <div class="proof-gallery">
                        <div class="gallery-header">
                            <h5>Uploaded Proofs</h5>
                        </div>
                        <div id="proofGallery" class="gallery-grid" aria-live="polite"></div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script src="js/review-feedback.js"></script>
</body>
</html>

