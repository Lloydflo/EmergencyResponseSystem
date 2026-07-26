<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity_log.php';
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

function feedback_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
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

function infer_feedback_rating_from_note(string $note): ?int
{
    if (preg_match('/(?:rating submitted:\s*)?([1-5])\s*\/\s*5/i', $note, $matches)) {
        return (int)$matches[1];
    }
    if (preg_match('/\b([1-5])\s*(?:star|stars)\b/i', $note, $matches)) {
        return (int)$matches[1];
    }
    return null;
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

        $actorUserId = null;
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            $actorUserId = (int)$_SESSION['user_id'];
        }

        $reference = '#' . $incidentId;
        try {
            $incidentStmt = $pdo->prepare('SELECT reference_no FROM incidents WHERE id = ? LIMIT 1');
            $incidentStmt->execute([$incidentId]);
            $incidentRef = trim((string)$incidentStmt->fetchColumn());
            if ($incidentRef !== '') {
                $reference = $incidentRef;
            }
        } catch (Throwable $referenceError) {
            $reference = '#' . $incidentId;
        }

        log_activity_event(
            $actorUserId,
            'incident_feedback_added',
            'incident',
            $incidentId,
            json_encode([
                'message' => 'Dispatcher feedback was added for incident ' . $reference . '.',
                'actor_name' => $authorName,
                'actor_role' => 'dispatcher',
                'incident_reference' => $reference,
                'rating' => $rating,
                'has_note' => $note !== '',
            ], JSON_UNESCAPED_UNICODE)
        );

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
        $notes = [];
        if ($hasRatingColumn) {
            $stmt = $pdo->prepare("SELECT author_name, rating, note, created_at FROM incident_notes WHERE incident_id = ? AND note NOT LIKE 'Resolution proof uploaded:%' ORDER BY created_at DESC");
            $stmt->execute([$incidentId]);
        } else {
            $stmt = $pdo->prepare("SELECT author_name, NULL AS rating, note, created_at FROM incident_notes WHERE incident_id = ? AND note NOT LIKE 'Resolution proof uploaded:%' ORDER BY created_at DESC");
            $stmt->execute([$incidentId]);
        }

        $notes = $stmt->fetchAll();

        $hasIncidentSurveys = feedback_table_exists($pdo, 'incident_surveys')
            && feedback_column_exists($pdo, 'incident_surveys', 'incident_id')
            && feedback_column_exists($pdo, 'incident_surveys', 'response_rating');
        if ($hasIncidentSurveys) {
            $surveyStmt = $pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(source_system), ''), 'Group 6 Feedback System') AS author_name,
                    response_rating AS rating,
                    CONCAT_WS(' | ',
                        CONCAT('Survey ID: ', survey_id),
                        IF(citizen_satisfaction IS NOT NULL AND citizen_satisfaction <> '', CONCAT('Satisfaction: ', citizen_satisfaction), NULL),
                        IF(score IS NOT NULL, CONCAT('Score: ', score), NULL),
                        IF(response_rating IS NOT NULL, CONCAT('Response rating: ', response_rating, '/5'), NULL)
                    ) AS note,
                    received_at AS created_at
                 FROM incident_surveys
                 WHERE incident_id = ?"
            );
            $surveyStmt->execute([$incidentId]);
            $surveyNotes = $surveyStmt->fetchAll();
            if ($surveyNotes) {
                $notes = array_merge($notes, $surveyNotes);
            }
        }

        usort($notes, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        $ratingValues = [];
        foreach ($notes as &$noteRow) {
            $ratingValue = null;
            if (isset($noteRow['rating']) && $noteRow['rating'] !== null && $noteRow['rating'] !== '' && is_numeric((string)$noteRow['rating'])) {
                $ratingValue = (int)$noteRow['rating'];
            }
            if ($ratingValue === null) {
                $ratingValue = infer_feedback_rating_from_note((string)($noteRow['note'] ?? ''));
            }
            if ($ratingValue !== null && $ratingValue >= 1 && $ratingValue <= 5) {
                $noteRow['rating'] = $ratingValue;
                $ratingValues[] = $ratingValue;
            } else {
                $noteRow['rating'] = null;
            }
        }
        unset($noteRow);
        $avgRating = $ratingValues ? round(array_sum($ratingValues) / count($ratingValues), 1) : null;
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
                'feedback_count' => count($notes),
                'rating_count' => count($ratingValues),
                'avg_rating' => $avgRating,
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
