<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function feedback_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_feedback_rating_column(PDO $pdo): bool
{
    if (feedback_column_exists($pdo, 'incident_notes', 'rating')) {
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE `incident_notes` ADD COLUMN IF NOT EXISTS `rating` TINYINT UNSIGNED NULL AFTER `author_name`");
    } catch (Throwable $e) {
        return feedback_column_exists($pdo, 'incident_notes', 'rating');
    }

    return feedback_column_exists($pdo, 'incident_notes', 'rating');
}

$hasRatingColumn = ensure_feedback_rating_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $incidentId = (int)($input['incident_id'] ?? 0);
    $authorName = trim((string)($input['author_name'] ?? 'Anonymous'));
    $note = trim((string)($input['note'] ?? ''));
    $ratingRaw = $input['rating'] ?? null;
    $rating = null;

    if ($ratingRaw !== null && $ratingRaw !== '') {
        if (!is_numeric($ratingRaw)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid rating']);
            exit;
        }
        $rating = (int)$ratingRaw;
        if ($rating < 1 || $rating > 5) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Rating must be between 1 and 5']);
            exit;
        }
    }

    if ($incidentId < 1 || ($note === '' && $rating === null)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }

    if ($authorName === '') {
        $authorName = 'Anonymous';
    }

    try {
        if ($hasRatingColumn) {
            $stmt = $pdo->prepare('INSERT INTO incident_notes (incident_id, author_name, rating, note) VALUES (?, ?, ?, ?)');
            $stmt->execute([$incidentId, $authorName, $rating, $note]);
        } else {
            $fallbackNote = $note;
            if ($fallbackNote === '' && $rating !== null) {
                $fallbackNote = 'Rating submitted: ' . $rating . '/5';
            }
            $stmt = $pdo->prepare('INSERT INTO incident_notes (incident_id, author_name, note) VALUES (?, ?, ?)');
            $stmt->execute([$incidentId, $authorName, $fallbackNote]);
        }

        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to save feedback']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $incidentId = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;
    if ($incidentId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
        exit;
    }

    try {
        if ($hasRatingColumn) {
            $stmt = $pdo->prepare('SELECT author_name, rating, note, created_at FROM incident_notes WHERE incident_id = ? ORDER BY created_at DESC');
            $stmt->execute([$incidentId]);

            $summaryStmt = $pdo->prepare(
                'SELECT COUNT(*) AS feedback_count, COUNT(rating) AS rating_count, ROUND(AVG(rating), 1) AS avg_rating
                 FROM incident_notes
                 WHERE incident_id = ?'
            );
            $summaryStmt->execute([$incidentId]);
        } else {
            $stmt = $pdo->prepare('SELECT author_name, NULL AS rating, note, created_at FROM incident_notes WHERE incident_id = ? ORDER BY created_at DESC');
            $stmt->execute([$incidentId]);

            $summaryStmt = $pdo->prepare(
                'SELECT COUNT(*) AS feedback_count, 0 AS rating_count, NULL AS avg_rating
                 FROM incident_notes
                 WHERE incident_id = ?'
            );
            $summaryStmt->execute([$incidentId]);
        }

        $notes = $stmt->fetchAll();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['feedback_count' => 0, 'rating_count' => 0, 'avg_rating' => null];

        echo json_encode([
            'ok' => true,
            'data' => $notes,
            'summary' => [
                'feedback_count' => isset($summary['feedback_count']) ? (int)$summary['feedback_count'] : 0,
                'rating_count' => isset($summary['rating_count']) ? (int)$summary['rating_count'] : 0,
                'avg_rating' => isset($summary['avg_rating']) && $summary['avg_rating'] !== null ? (float)$summary['avg_rating'] : null,
            ]
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to load feedback']);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'Invalid method']);
