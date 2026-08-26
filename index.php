<?php
declare(strict_types=1);

$pageTitle = 'ALERTARA Emergency Response System';
$landingCssVersion = is_file(__DIR__ . '/css/landing.css')
    ? (string)filemtime(__DIR__ . '/css/landing.css')
    : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ALERTARA is an emergency response coordination system for incident intake, dispatch, responder tracking, resource readiness, and inter-agency collaboration.">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#0b3b52">
    <meta property="og:locale" content="en_PH">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ALERTARA">
    <meta property="og:title" content="ALERTARA Emergency Response System">
    <meta property="og:description" content="Emergency response coordination for incident intake, dispatch operations, responder monitoring, resource readiness, and inter-agency collaboration.">
    <meta property="og:url" content="https://emergency-response.alertaraqc.com/">
    <meta property="og:image" content="https://emergency-response.alertaraqc.com/images/alertara-social-preview-v1.png">
    <meta property="og:image:secure_url" content="https://emergency-response.alertaraqc.com/images/alertara-social-preview-v1.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="ALERTARA Emergency Response System — clearer emergency coordination when every second matters.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ALERTARA Emergency Response System">
    <meta name="twitter:description" content="Emergency response coordination for incident intake, dispatch operations, responder monitoring, resource readiness, and inter-agency collaboration.">
    <meta name="twitter:image" content="https://emergency-response.alertaraqc.com/images/alertara-social-preview-v1.png">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="canonical" href="https://emergency-response.alertaraqc.com/">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/landing.css?v=<?php echo rawurlencode($landingCssVersion); ?>">
</head>
<body class="landing-page">
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="landing-header">
        <a class="landing-brand" href="index.php" aria-label="ALERTARA home">
            <img src="images/logo.svg" alt="ALERTARA">
            <span><strong>ALERTARA</strong><small>Emergency Response System</small></span>
        </a>
        <nav class="landing-nav" aria-label="Main Navigation">
            <a href="#capabilities">Capabilities</a>
            <a href="#workflow">How it works</a>
            <a href="#about">About</a>
            <a class="nav-login" href="login.php"><i class="fas fa-arrow-right-to-bracket"></i> Login</a>
        </nav>
    </header>

    <main id="main-content">
        <section class="landing-hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <span class="hero-eyebrow"><i class="fas fa-tower-broadcast"></i> Connected emergency operations</span>
                <h1 id="hero-title">One response system.<br><span>Clearer decisions when every second matters.</span></h1>
                <p>ALERTARA brings incident intake, dispatcher coordination, responder navigation, resource readiness, and inter-agency updates into one operational workspace.</p>
                <div class="hero-actions">
                    <a class="landing-btn primary" href="login.php"><i class="fas fa-lock"></i> Access the System</a>
                    <a class="landing-btn secondary" href="#capabilities"><i class="fas fa-circle-play"></i> Explore Features</a>
                </div>
                <div class="hero-trust" aria-label="System qualities">
                    <span><i class="fas fa-circle-check"></i> Role-based access</span>
                    <span><i class="fas fa-circle-check"></i> Real-time coordination</span>
                    <span><i class="fas fa-circle-check"></i> Operational audit trail</span>
                </div>
            </div>

            <div class="hero-console" aria-label="Illustration of the ALERTARA operations dashboard">
                <div class="console-topbar">
                    <span><i class="fas fa-wave-square"></i> Operations Overview</span>
                    <span class="console-live"><i class="fas fa-circle"></i> System preview</span>
                </div>
                <div class="console-stats">
                    <article><small>Incident intake</small><strong>Unified</strong><span>Call, manual, TIP</span></article>
                    <article><small>Response units</small><strong>Visible</strong><span>Readiness and assignment</span></article>
                    <article><small>Operations</small><strong>Traceable</strong><span>Updates and review</span></article>
                </div>
                <div class="console-workspace">
                    <div class="console-map">
                        <span class="map-road road-one"></span>
                        <span class="map-road road-two"></span>
                        <span class="map-road road-three"></span>
                        <span class="map-pin pin-one"><i class="fas fa-location-dot"></i></span>
                        <span class="map-pin pin-two"><i class="fas fa-truck-medical"></i></span>
                        <span class="map-route"></span>
                        <div class="map-caption"><i class="fas fa-satellite-dish"></i> Live route coordination</div>
                    </div>
                    <div class="console-queue">
                        <div class="queue-head"><strong>Priority queue</strong><span>Sample</span></div>
                        <article class="queue-item critical"><span class="queue-icon"><i class="fas fa-fire-flame-curved"></i></span><span><strong>Fire response</strong><small>Unit assigned · En route</small></span></article>
                        <article class="queue-item medical"><span class="queue-icon"><i class="fas fa-kit-medical"></i></span><span><strong>Medical assistance</strong><small>Awaiting dispatch</small></span></article>
                        <article class="queue-item tip"><span class="queue-icon"><i class="fas fa-lightbulb"></i></span><span><strong>Verified TIP</strong><small>Converted to incident</small></span></article>
                    </div>
                </div>
            </div>
        </section>

        <section class="landing-proof" aria-label="System coverage">
            <div><strong>Incident Intake</strong><span>Calls, manual reports, and verified tips</span></div>
            <div><strong>Response Coordination</strong><span>Dispatch, route, and unit visibility</span></div>
            <div><strong>Operational Review</strong><span>Proof, feedback, and after-action reports</span></div>
        </section>

        <section id="capabilities" class="landing-section capabilities-section" aria-labelledby="capabilities-title">
            <div class="section-heading-copy">
                <span class="section-eyebrow">Platform capabilities</span>
                <h2 id="capabilities-title">Built around the complete emergency response cycle</h2>
                <p>Each module supports a specific operational need while sharing one incident record and response context.</p>
            </div>
            <div class="capability-grid">
                <article class="capability-card"><span class="capability-icon call"><i class="fas fa-phone-volume"></i></span><h3>Call Receiving & Incident Intake</h3><p>Capture emergency details, assess urgency, and log incidents without breaking the call-handling workflow.</p></article>
                <article class="capability-card"><span class="capability-icon dispatch"><i class="fas fa-bell"></i></span><h3>Dispatch Command Center</h3><p>Keep pending incidents organized, review essential context, and assign available response units.</p></article>
                <article class="capability-card"><span class="capability-icon gps"><i class="fas fa-map-location-dot"></i></span><h3>GPS & Route Coordination</h3><p>Monitor active response locations and preserve the approved route until a new route is authorized.</p></article>
                <article class="capability-card"><span class="capability-icon resource"><i class="fas fa-truck-medical"></i></span><h3>Resource Readiness</h3><p>See vehicles, personnel, equipment, availability, assignments, and responder support requests.</p></article>
                <article class="capability-card"><span class="capability-icon agency"><i class="fas fa-users"></i></span><h3>Inter-Agency Collaboration</h3><p>Coordinate transferred incidents, operational tasks, acknowledgements, and connected-agency updates.</p></article>
                <article class="capability-card"><span class="capability-icon review"><i class="fas fa-clipboard-check"></i></span><h3>Review, Proof & Analytics</h3><p>Review closed incidents, responder proof, feedback, after-action reports, and operational trends.</p></article>
            </div>
        </section>

        <section id="workflow" class="landing-section workflow-section" aria-labelledby="workflow-title">
            <div class="section-heading-copy light">
                <span class="section-eyebrow">Response workflow</span>
                <h2 id="workflow-title">A clear path from report to review</h2>
                <p>The system keeps the handoff understandable across dispatchers, responders, administrators, and partner agencies.</p>
            </div>
            <ol class="workflow-grid">
                <li><span>01</span><i class="fas fa-headset"></i><h3>Receive</h3><p>Capture the incident source and essential emergency details.</p></li>
                <li><span>02</span><i class="fas fa-list-check"></i><h3>Assess</h3><p>Confirm location, incident type, priority, and response needs.</p></li>
                <li><span>03</span><i class="fas fa-truck"></i><h3>Coordinate</h3><p>Dispatch units, monitor the route, and share operational updates.</p></li>
                <li><span>04</span><i class="fas fa-clipboard-check"></i><h3>Review</h3><p>Record proof, feedback, outcomes, and after-action findings.</p></li>
            </ol>
        </section>

        <section id="about" class="landing-section about-section" aria-labelledby="about-title">
            <div class="about-copy">
                <span class="section-eyebrow">About ALERTARA</span>
                <h2 id="about-title">Designed for coordinated, accountable emergency operations</h2>
                <p>ALERTARA provides a shared operational picture without replacing the responsibilities of dispatchers, responders, administrators, or connected agencies. It organizes information so the right people can act on the same incident context.</p>
                <ul class="about-list">
                    <li><i class="fas fa-user-shield"></i><span><strong>Role-aware workspaces</strong> for administrators and dispatchers</span></li>
                    <li><i class="fas fa-clock-rotate-left"></i><span><strong>Traceable activity</strong> for incident decisions and updates</span></li>
                    <li><i class="fas fa-mobile-screen-button"></i><span><strong>Responder connection</strong> for assignments, navigation, and coordination</span></li>
                </ul>
            </div>
            <aside class="about-callout">
                <span class="callout-icon"><i class="fas fa-user-shield"></i></span>
                <p>Authorized personnel access</p>
                <h3>Ready to enter the response console?</h3>
                <span>Secure sign-in and OTP verification protect access to operational modules.</span>
                <a class="landing-btn primary" href="login.php"><i class="fas fa-arrow-right-to-bracket"></i> Continue to Login</a>
            </aside>
        </section>
    </main>

    <footer class="landing-footer">
        <a class="landing-brand footer-brand" href="index.php"><img src="images/logo.svg" alt=""><span><strong>ALERTARA</strong><small>Emergency Response System</small></span></a>
        <p>Supporting clearer coordination across the emergency response lifecycle.</p>
        <a href="login.php">Authorized Access <i class="fas fa-arrow-right"></i></a>
    </footer>
</body>
</html>
