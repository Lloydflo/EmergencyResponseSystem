<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/incident_admin_review.php';

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

function ensure_feedback_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `incident_notes` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `incident_id` BIGINT UNSIGNED NOT NULL,
                `author_name` VARCHAR(150) DEFAULT NULL,
                `rating` TINYINT UNSIGNED NULL,
                `note` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_incident_notes_incident_id` (`incident_id`),
                KEY `idx_incident_notes_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('Incident notes table self-heal skipped: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `incident_notes` LIKE 'id'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
        $type = (string)($row['Type'] ?? $row['type'] ?? 'BIGINT UNSIGNED');
        if ($row && strpos($extra, 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE `incident_notes` MODIFY `id` {$type} NOT NULL AUTO_INCREMENT");
        }
    } catch (Throwable $e) {
        error_log('Incident notes id auto-increment self-heal skipped: ' . $e->getMessage());
    }
}

function ensure_feedback_rating_column(PDO $pdo): bool
{
    ensure_feedback_table($pdo);

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

function feedback_needs_manual_id_fallback(string $message): bool
{
    return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
        || strpos($message, "Field 'id' doesn't have a default value") !== false
        || strpos($message, "Field 'id' doesn't have a default") !== false;
}

function insert_feedback_note(PDO $pdo, int $incidentId, string $authorName, ?int $rating, string $note, bool $hasRatingColumn): void
{
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
    } catch (Throwable $e) {
        if (!feedback_needs_manual_id_fallback((string)$e->getMessage())) {
            throw $e;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM incident_notes")->fetchColumn();
        if ($hasRatingColumn) {
            $stmt = $pdo->prepare('INSERT INTO incident_notes (id, incident_id, author_name, rating, note) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$nextId, $incidentId, $authorName, $rating, $note]);
        } else {
            $fallbackNote = $note;
            if ($fallbackNote === '' && $rating !== null) {
                $fallbackNote = 'Rating submitted: ' . $rating . '/5';
            }
            $stmt = $pdo->prepare('INSERT INTO incident_notes (id, incident_id, author_name, note) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nextId, $incidentId, $authorName, $fallbackNote]);
        }
    }
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
        insert_feedback_note($pdo, $incidentId, $authorName, $rating, $note, $hasRatingColumn);

        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        error_log('Incident feedback save failed: ' . $e->getMessage());
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
        $adminReview = ers_fetch_incident_admin_review($pdo, $incidentId);

        echo json_encode([
            'ok' => true,
            'data' => $notes,
            'admin_review' => $adminReview ? [
                'submitted' => true,
                'sent_at' => $adminReview['sent_at'] ?? null,
                'sent_by_name' => $adminReview['sent_by_name'] ?? null,
                'sent_by_user_id' => isset($adminReview['sent_by_user_id']) && $adminReview['sent_by_user_id'] !== null ? (int)$adminReview['sent_by_user_id'] : null,
            ] : [
                'submitted' => false,
                'sent_at' => null,
                'sent_by_name' => null,
                'sent_by_user_id' => null,
            ],
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
