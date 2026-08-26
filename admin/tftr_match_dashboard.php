<?php
/**
 * tftr_match_dashboard.php
 *
 * Suggests matches between AlertaRAQC accident reports and TFTR `incidents`,
 * and lets a dispatcher manually confirm a link (POST -> link_tftr_accident.php).
 *
 * DEPLOY: place this file anywhere inside
 *   /var/www/emergency_response_alertaraqc/
 * (it reuses your existing includes/config.php for DB access).
 *
 * Visit it in a browser, e.g.:
 *   https://emergency-response.alertaraqc.com/tftr_match_dashboard.php
 * Protect this path (auth, IP allowlist, or .htpasswd) since it can write links.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---- Config -----------------------------------------------------------
$dbConfig = require __DIR__ . '/includes/config.php'; // reuses your existing config loader

$ALERTA_API_URL   = 'https://tftr.alertaraqc.com/api/accidents/accident_report_api.php';
$LINK_API_URL     = 'https://emergency-response.alertaraqc.com/api/link_tftr_accident.php';

// Only consider incidents created within this many hours as candidates
$LOOKBACK_HOURS   = 24;

// Matching thresholds
$MAX_TIME_DIFF_MIN = 20;   // minutes apart to be considered a candidate at all
$MIN_SCORE_TO_SHOW  = 20;  // 0-100 combined score threshold

// ---- DB connection ------------------------------------------------------
function get_pdo(array $cfg): PDO {
    $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_NAME']};charset=utf8mb4";
    return new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$pdo = get_pdo($dbConfig);

// ---- Ensure tracking table exists ---------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tftr_accident_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id BIGINT UNSIGNED NOT NULL,
        tftr_accident_id VARCHAR(50) NOT NULL,
        matched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        matched_by VARCHAR(100) DEFAULT NULL,
        UNIQUE KEY uniq_incident (incident_id),
        UNIQUE KEY uniq_accident (tftr_accident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---- Handle confirm action (POST) ---------------------------------------
$flashMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_link') {
    $incidentId = (int)($_POST['incident_id'] ?? 0);
    $tftrAccidentId = trim((string)($_POST['tftr_accident_id'] ?? ''));

    if ($incidentId > 0 && $tftrAccidentId !== '') {
        $payload = json_encode([
            'incident_id' => $incidentId,
            'tftr_accident_id' => $tftrAccidentId,
        ]);

        $ch = curl_init($LINK_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $flashMessage = "❌ Request failed: " . htmlspecialchars($curlErr);
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            // Record locally so it stops showing as a suggestion
            $stmt = $pdo->prepare("
                INSERT INTO tftr_accident_links (incident_id, tftr_accident_id, matched_by)
                VALUES (:incident_id, :tftr_accident_id, :matched_by)
                ON DUPLICATE KEY UPDATE tftr_accident_id = VALUES(tftr_accident_id)
            ");
            $stmt->execute([
                ':incident_id' => $incidentId,
                ':tftr_accident_id' => $tftrAccidentId,
                ':matched_by' => $_SERVER['REMOTE_USER'] ?? 'dashboard',
            ]);
            $flashMessage = "✅ Linked incident #{$incidentId} to {$tftrAccidentId} (HTTP {$httpCode})";
        } else {
            $flashMessage = "⚠️ Link API returned HTTP {$httpCode}: " . htmlspecialchars((string)$response);
        }
    } else {
        $flashMessage = "❌ Missing incident_id or tftr_accident_id.";
    }
}

// ---- Ignore action: mark a pair as "not a match" so it stops suggesting ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ignore') {
    $_SESSION['ignored'] = $_SESSION['ignored'] ?? [];
    $_SESSION['ignored'][] = $_POST['incident_id'] . '|' . $_POST['tftr_accident_id'];
}
$ignoredPairs = $_SESSION['ignored'] ?? [];

// ---- Fetch AlertaRAQC accidents -----------------------------------------
function fetch_alertaraqc_accidents(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

$accidents = fetch_alertaraqc_accidents($ALERTA_API_URL);

// Already-linked tftr_accident_id's -> skip these
$linkedAccidentIds = $pdo->query("SELECT tftr_accident_id FROM tftr_accident_links")
    ->fetchAll(PDO::FETCH_COLUMN);
$linkedIncidentIds = $pdo->query("SELECT incident_id FROM tftr_accident_links")
    ->fetchAll(PDO::FETCH_COLUMN);

$accidents = array_filter($accidents, function ($a) use ($linkedAccidentIds) {
    return !in_array($a['public_accident_id'], $linkedAccidentIds, true)
        && ($a['status'] ?? '') !== 'Cleared';
});

// ---- Fetch candidate incidents from DB -----------------------------------
$stmt = $pdo->prepare("
    SELECT id, reference_no, type, priority, status, description,
           location_address, latitude, longitude, created_at
    FROM incidents
    WHERE created_at >= (NOW() - INTERVAL :hrs HOUR)
      AND id NOT IN (" . (count($linkedIncidentIds) ? implode(',', array_map('intval', $linkedIncidentIds)) : '0') . ")
    ORDER BY created_at DESC
");
$stmt->bindValue(':hrs', $LOOKBACK_HOURS, PDO::PARAM_INT);
$stmt->execute();
$incidents = $stmt->fetchAll();

// ---- Matching helpers -----------------------------------------------------
function normalize_text(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function location_score(string $a, string $b): float {
    $a = normalize_text($a);
    $b = normalize_text($b);
    if ($a === '' || $b === '') return 0.0;
    $wordsA = array_unique(explode(' ', $a));
    $wordsB = array_unique(explode(' ', $b));
    // ignore very short/common filler words
    $stop = ['the','near','street','st','road','rd','ave','avenue','city','district','philippines','metro','manila'];
    $wordsA = array_diff($wordsA, $stop);
    $wordsB = array_diff($wordsB, $stop);
    if (!count($wordsA) || !count($wordsB)) return 0.0;
    $overlap = count(array_intersect($wordsA, $wordsB));
    $union = count(array_unique(array_merge($wordsA, $wordsB)));
    return $union > 0 ? ($overlap / $union) * 100 : 0.0;
}

function time_diff_minutes(string $accidentDate, string $accidentTime, string $incidentCreatedAt): ?float {
    try {
        $accidentDt = new DateTime("$accidentDate $accidentTime");
        $incidentDt = new DateTime($incidentCreatedAt);
        return abs(($accidentDt->getTimestamp() - $incidentDt->getTimestamp()) / 60);
    } catch (Exception $e) {
        return null;
    }
}

// ---- Build candidate match list -------------------------------------------
$candidates = [];
foreach ($accidents as $acc) {
    foreach ($incidents as $inc) {
        $pairKey = $inc['id'] . '|' . $acc['public_accident_id'];
        if (in_array($pairKey, $ignoredPairs, true)) continue;

        $timeDiff = time_diff_minutes($acc['accident_date'], $acc['accident_time'], $inc['created_at']);
        if ($timeDiff === null || $timeDiff > $MAX_TIME_DIFF_MIN) continue;

        $locA = trim(($acc['road_name'] ?? '') . ' ' . ($acc['specific_location'] ?? ''));
        $locB = $inc['location_address'] ?? '';
        $locScore = location_score($locA, $locB);

        // Combined score: time closeness (0-50) + location overlap (0-50)
        $timeScore = max(0, 50 - ($timeDiff / $MAX_TIME_DIFF_MIN) * 50);
        $totalScore = $timeScore + ($locScore * 0.5);

        if ($totalScore >= $MIN_SCORE_TO_SHOW) {
            $candidates[] = [
                'incident' => $inc,
                'accident' => $acc,
                'time_diff_min' => round($timeDiff, 1),
                'loc_score' => round($locScore, 1),
                'score' => round($totalScore, 1),
            ];
        }
    }
}

usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>TFTR ↔ AlertaRAQC Match Dashboard</title>
<style>
  body { font-family: system-ui, sans-serif; background: #0f1117; color: #e6e6e6; margin: 0; padding: 24px; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  .sub { color: #9aa0aa; margin-bottom: 24px; font-size: 14px; }
  .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; background: #1c2733; border: 1px solid #2e3f52; }
  .card { background: #171a21; border: 1px solid #2a2e37; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
  .score { float: right; font-weight: 700; padding: 3px 10px; border-radius: 999px; font-size: 13px; }
  .score-high { background: #16341f; color: #4ade80; }
  .score-mid { background: #3a2f12; color: #facc15; }
  .score-low { background: #3a1414; color: #f87171; }
  .row { display: flex; gap: 16px; margin-top: 8px; }
  .col { flex: 1; background: #10131a; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
  .col b { display: block; color: #9aa0aa; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
  .meta { color: #9aa0aa; font-size: 12px; margin-top: 8px; }
  form { display: inline; }
  button { border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; margin-top: 10px; margin-right: 8px; }
  .btn-confirm { background: #22c55e; color: #062910; font-weight: 600; }
  .btn-ignore { background: #2a2e37; color: #c8c8c8; }
  .empty { color: #9aa0aa; padding: 40px; text-align: center; }
</style>
</head>
<body>

<h1>🔗 TFTR ↔ AlertaRAQC Match Suggestions</h1>
<div class="sub">Manual-approve linking — nothing is linked until you click Confirm.</div>

<?php if ($flashMessage): ?>
  <div class="flash"><?= htmlspecialchars($flashMessage) ?></div>
<?php endif; ?>

<?php if (empty($candidates)): ?>
  <div class="empty">Walang candidate matches sa ngayon. (Lookback: <?= $LOOKBACK_HOURS ?>h)</div>
<?php else: ?>
  <?php foreach ($candidates as $c): ?>
    <?php
      $inc = $c['incident'];
      $acc = $c['accident'];
      $scoreClass = $c['score'] >= 70 ? 'score-high' : ($c['score'] >= 40 ? 'score-mid' : 'score-low');
    ?>
    <div class="card">
      <span class="score <?= $scoreClass ?>">Match score: <?= $c['score'] ?>/100</span>
      <strong>Incident #<?= (int)$inc['id'] ?> (<?= htmlspecialchars($inc['reference_no']) ?>)</strong>
      ↔ <strong><?= htmlspecialchars($acc['public_accident_id']) ?></strong>

      <div class="row">
        <div class="col">
          <b>TFTR Incident</b>
          Type: <?= htmlspecialchars($inc['type']) ?> (<?= htmlspecialchars($inc['priority']) ?>)<br>
          Location: <?= htmlspecialchars($inc['location_address'] ?? '—') ?><br>
          Created: <?= htmlspecialchars($inc['created_at']) ?>
        </div>
        <div class="col">
          <b>AlertaRAQC Accident</b>
          Type: <?= htmlspecialchars($acc['accident_type']) ?><br>
          Location: <?= htmlspecialchars($acc['road_name'] . ' — ' . $acc['specific_location']) ?><br>
          When: <?= htmlspecialchars($acc['accident_date'] . ' ' . $acc['accident_time']) ?>
        </div>
      </div>

      <div class="meta">
        ⏱ Time diff: <?= $c['time_diff_min'] ?> min &nbsp;|&nbsp; 📍 Location overlap: <?= $c['loc_score'] ?>%
      </div>

      <form method="post">
        <input type="hidden" name="action" value="confirm_link">
        <input type="hidden" name="incident_id" value="<?= (int)$inc['id'] ?>">
        <input type="hidden" name="tftr_accident_id" value="<?= htmlspecialchars($acc['public_accident_id']) ?>">
        <button type="submit" class="btn-confirm">✓ Confirm Link</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="ignore">
        <input type="hidden" name="incident_id" value="<?= (int)$inc['id'] ?>">
        <input type="hidden" name="tftr_accident_id" value="<?= htmlspecialchars($acc['public_accident_id']) ?>">
        <button type="submit" class="btn-ignore">✕ Not a match</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
