<?php
require_once __DIR__ . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('review.php');
$pageTitle = 'Emergency Call Center';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
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
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/admin-header.php'; ?>
    <main class="main-content">
        <div class="main-container">
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
                        <option value="priority_desc">Priority (High → Low)</option>
                        <option value="code_asc">Incident Code (A → Z)</option>
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
                        <div id="summaryCode" class="summary-value">—</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-list"></i> Type</div>
                        <div id="summaryType" class="summary-value">—</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-signal"></i> Priority</div>
                        <div id="summaryPriority" class="summary-value">—</div>
                    </div>
                    <div>
                        <div class="summary-label"><i class="fa fa-check-circle"></i> Status</div>
                        <div id="summaryStatus" class="summary-value">—</div>
                    </div>
                </div>
                <div class="summary-row">
                    <div class="summary-col">
                        <div class="summary-label"><i class="fa fa-location-dot"></i> Location</div>
                        <div id="summaryLocation" class="summary-value">—</div>
                    </div>
                </div>
                <div class="summary-row">
                    <div class="summary-col">
                        <div class="summary-label"><i class="fa fa-align-left"></i> Description</div>
                        <div id="summaryDescription" class="summary-value">—</div>
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
                    <h4>Feedback Notes</h4>
                    <div id="feedbackList" class="feedback-list" aria-live="polite"></div>
                </div>

                <div class="feedback-form">
                    <h4>Add Feedback</h4>
                    <form id="feedbackForm" aria-label="Add feedback form">
                        <input type="hidden" id="feedbackIncidentId" />
                        <div class="form-field">
                            <label for="authorInput">Your name</label>
                            <input type="text" id="authorInput" placeholder="Anonymous" />
                        </div>
                        <div class="form-field">
                            <label for="noteInput">Feedback note <span class="required" aria-hidden="true">*</span></label>
                            <textarea id="noteInput" rows="4" placeholder="Any specific observations, suggested improvements, or commendations..." aria-required="true"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-success"><i class="fa fa-paper-plane"></i> Submit Feedback</button>
                            <button type="button" id="cancelFeedbackBtn" class="btn btn-secondary">Close</button>
                        </div>
                    </form>
                </div>
            </section>

            <section id="panelProof" class="tab-panel" role="tabpanel" aria-labelledby="tabProof" hidden>
                <div class="proof-section">
                    <div class="section-header">
                        <h4>Resolution Proof</h4>
                        <p class="text-muted">Attach a photo or capture using your camera to document the resolution.</p>
                    </div>
                    <div class="proof-controls">
                        <div class="control-group">
                            <label class="control-label" for="proofFile">Upload Image</label>
                            <input type="file" id="proofFile" accept="image/*" aria-label="Upload proof image" />
                            <button type="button" id="uploadProofBtn" class="btn btn-primary" aria-label="Upload image"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Camera Capture</label>
                            <div class="camera-row">
                                <video id="proofVideo" playsinline muted aria-label="Camera preview"></video>
                                <canvas id="proofCanvas" hidden aria-label="Captured image preview"></canvas>
                            </div>
                            <div class="camera-actions">
                                <button type="button" id="startCameraBtn" class="btn" aria-label="Start camera"><i class="fa fa-camera"></i> Start Camera</button>
                                <button type="button" id="capturePhotoBtn" class="btn btn-primary" disabled aria-label="Capture photo"><i class="fa fa-dot-circle"></i> Capture</button>
                                <button type="button" id="stopCameraBtn" class="btn btn-secondary" disabled aria-label="Stop camera"><i class="fa fa-stop"></i> Stop</button>
                                <button type="button" id="saveCaptureBtn" class="btn btn-primary" hidden aria-label="Save captured image"><i class="fa fa-save"></i> Save Capture</button>
                                <button type="button" id="discardCaptureBtn" class="btn btn-secondary" hidden aria-label="Discard capture">Discard</button>
                            </div>
                        </div>
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