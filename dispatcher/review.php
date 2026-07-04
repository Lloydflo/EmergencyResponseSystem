<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/review.php');

$pageTitle = 'Review & Feedback';
$reviewerName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Dispatcher'));
if ($reviewerName === '') {
    $reviewerName = 'Dispatcher';
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
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/review.css">
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content review-page">
        <div class="main-container review-dashboard" data-reviewer-name="<?php echo htmlspecialchars($reviewerName, ENT_QUOTES, 'UTF-8'); ?>">
            <section class="review-stats-grid" id="reviewStatsGrid" aria-live="polite">
                <article class="review-stat-card">
                    <span class="stat-label">Closed Incidents</span>
                    <strong id="statClosedIncidents">0</strong>
                    <p>Resolved and cancelled incidents in the current result set.</p>
                </article>
                <article class="review-stat-card">
                    <span class="stat-label">Average Response</span>
                    <strong id="statAverageResponse">--</strong>
                    <p>Average dispatch-to-scene time based on latest responder data.</p>
                </article>
                <article class="review-stat-card">
                    <span class="stat-label">Average Rating</span>
                    <strong id="statAverageRating">--</strong>
                    <p>Average dispatcher feedback rating across rated incidents.</p>
                </article>
                <article class="review-stat-card">
                    <span class="stat-label">Latest Unit Coverage</span>
                    <strong id="statUnitsTracked">0</strong>
                    <p>Closed incidents with tracked responder or vehicle assignment.</p>
                </article>
            </section>

            <section class="review-toolbar">
                <div class="toolbar-field">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter">
                        <option value="closed" selected>Resolved / Closed</option>
                        <option value="resolved_only">Resolved Only</option>
                        <option value="cancelled">Cancelled Only</option>
                    </select>
                </div>
                <div class="toolbar-field">
                    <label for="dayFilter">Day</label>
                    <input type="date" id="dayFilter">
                </div>
                <div class="toolbar-field toolbar-search">
                    <label for="searchInput">Search</label>
                    <input type="text" id="searchInput" placeholder="Search reference, type, location, driver, plate...">
                </div>
                <div class="toolbar-field">
                    <label for="sortSelect">Sort</label>
                    <select id="sortSelect" aria-label="Sort reviews">
                        <option value="recent" selected>Most Recent</option>
                        <option value="rating_desc">Highest Rated</option>
                        <option value="response_asc">Fastest Response</option>
                        <option value="priority_desc">Priority (High to Low)</option>
                        <option value="code_asc">Reference (A to Z)</option>
                    </select>
                </div>
                <div class="toolbar-actions">
                    <button id="applyFiltersBtn" class="btn btn-primary" type="button"><i class="fas fa-filter"></i> Apply</button>
                    <button id="clearFiltersBtn" class="btn btn-secondary" type="button"><i class="fas fa-rotate-left"></i> Reset</button>
                </div>
            </section>

            <section class="review-results-bar">
                <div>
                    <h3>Completed Incident Reviews</h3>
                    <p id="resultsMeta">Loading closed incidents...</p>
                </div>
            </section>

            <section id="incidentsContainer" class="review-incident-list" aria-live="polite"></section>
        </div>
    </main>

    <div id="reviewModalOverlay" class="review-modal-overlay" hidden></div>
    <div id="reviewModal" class="review-modal" hidden role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="review-modal-dialog">
            <div class="review-modal-header">
                <div>
                    <p class="modal-eyebrow">Incident Review</p>
                    <h3 id="modalTitle">Closed Incident Details</h3>
                </div>
                <button id="modalClose" class="modal-close" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="review-modal-body">
                <section id="incidentDetailPanel" class="incident-spotlight">
                    <div class="spotlight-copy">
                        <div class="spotlight-badges">
                            <span id="modalStatusBadge" class="status-chip">Status</span>
                            <span id="modalPriorityBadge" class="priority-chip">Priority</span>
                        </div>
                        <h4 id="summaryCode">--</h4>
                        <p id="summaryType" class="spotlight-type">--</p>
                        <p id="summaryDescription" class="spotlight-description">--</p>
                    </div>
                    <div class="spotlight-location">
                        <span class="spotlight-label">Location</span>
                        <strong id="summaryLocation">--</strong>
                        <span id="summaryClosedTime" class="spotlight-time">Closed: --</span>
                    </div>
                </section>

                <section class="incident-meta-grid">
                    <article class="review-panel">
                        <h4><i class="fas fa-stopwatch"></i> Response Timeline</h4>
                        <div class="meta-stack">
                            <div class="meta-row"><span>Dispatched</span><strong id="summaryDispatchTime">--</strong></div>
                            <div class="meta-row"><span>On Scene</span><strong id="summaryOnSceneTime">--</strong></div>
                            <div class="meta-row"><span>Response Time</span><strong id="summaryResponseTime">--</strong></div>
                            <div class="meta-row"><span>Resolution Time</span><strong id="summaryResolutionTime">--</strong></div>
                        </div>
                    </article>

                    <article class="review-panel">
                        <h4><i class="fas fa-truck-medical"></i> Responder & Vehicle</h4>
                        <div class="meta-stack">
                            <div class="meta-row"><span>Assigned Unit</span><strong id="summaryUnit">--</strong></div>
                            <div class="meta-row"><span>Driver</span><strong id="summaryDriver">--</strong></div>
                            <div class="meta-row"><span>Vehicle</span><strong id="summaryVehicle">--</strong></div>
                            <div class="meta-row"><span>Plate Number</span><strong id="summaryPlate">--</strong></div>
                        </div>
                    </article>

                    <article class="review-panel">
                        <h4><i class="fas fa-star-half-stroke"></i> Feedback Snapshot</h4>
                        <div class="meta-stack">
                            <div class="meta-row"><span>Average Rating</span><strong id="summaryAverageRating">--</strong></div>
                            <div class="meta-row"><span>Rated Entries</span><strong id="summaryRatingCount">0</strong></div>
                            <div class="meta-row"><span>Total Feedback</span><strong id="summaryFeedbackCount">0</strong></div>
                            <div class="meta-row"><span>Last Update</span><strong id="summaryLastUpdated">--</strong></div>
                        </div>
                    </article>
                </section>

                <section class="review-modal-columns">
                    <article id="feedbackReviewPanel" class="review-panel">
                        <div class="panel-head">
                            <div>
                                <h4><i class="fas fa-pen-to-square"></i> Add Dispatcher Feedback</h4>
                                <p>Rate the completed response and add notes for future improvement.</p>
                            </div>
                        </div>

                        <input type="hidden" id="feedbackIncidentId" value="">

                        <div class="rating-field">
                            <span class="field-label">Response Rating</span>
                            <div id="ratingInput" class="rating-input" role="radiogroup" aria-label="Incident rating">
                                <button type="button" class="rating-star" data-rating="1" aria-label="1 star"><i class="fas fa-star"></i></button>
                                <button type="button" class="rating-star" data-rating="2" aria-label="2 stars"><i class="fas fa-star"></i></button>
                                <button type="button" class="rating-star" data-rating="3" aria-label="3 stars"><i class="fas fa-star"></i></button>
                                <button type="button" class="rating-star" data-rating="4" aria-label="4 stars"><i class="fas fa-star"></i></button>
                                <button type="button" class="rating-star" data-rating="5" aria-label="5 stars"><i class="fas fa-star"></i></button>
                            </div>
                            <span id="ratingHelper" class="rating-helper">Select a rating from 1 to 5.</span>
                        </div>

                        <div class="feedback-form">
                            <label class="field-label" for="feedbackNoteInput">Feedback Note</label>
                            <textarea id="feedbackNoteInput" rows="5" placeholder="Share what went well, what was delayed, or what needs follow-up..."></textarea>
                        </div>

                        <div class="modal-actions">
                            <button id="closeFeedbackBtn" type="button" class="btn btn-secondary">Close</button>
                            <button id="saveFeedbackBtn" type="button" class="btn btn-success"><i class="fas fa-paper-plane"></i> Send to Admin</button>
                        </div>
                    </article>

                    <article class="review-panel">
                        <div class="panel-head">
                            <div>
                                <h4><i class="fas fa-comments"></i> Submitted Feedback</h4>
                                <p>All dispatcher notes and ratings recorded for this incident.</p>
                            </div>
                            <div id="feedbackSummary" class="feedback-summary-chips"></div>
                        </div>
                        <div id="feedbackList" class="feedback-feed" aria-live="polite"></div>
                    </article>
                </section>

                <section class="review-panel proof-panel">
                    <div class="panel-head">
                        <div>
                            <h4><i class="fas fa-camera"></i> Resolution Proof</h4>
                            <p>Uploaded responder images and proof records for this incident.</p>
                        </div>
                    </div>
                    <div id="proofGallery" class="proof-gallery" aria-live="polite"></div>
                </section>
            </div>
        </div>
    </div>

    <script src="js/review-feedback.js"></script>
</body>
</html>
