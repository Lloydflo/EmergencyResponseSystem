<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$incident_id            = intval($_POST["incident_id"] ?? 0);
$responder_id            = intval($_POST["responder_id"] ?? 0);
$response_rating         = intval($_POST["response_rating"] ?? 0);
$communication_rating    = intval($_POST["communication_rating"] ?? 0);
$professionalism_rating  = intval($_POST["professionalism_rating"] ?? 0);
$outcome                 = trim($_POST["outcome"] ?? "");
$review_text             = trim($_POST["review_text"] ?? "");

if ($incident_id <= 0 || $responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid incident_id or responder_id"]);
    exit;
}
if ($response_rating < 1 || $response_rating > 5 ||
    $communication_rating < 1 || $communication_rating > 5 ||
    $professionalism_rating < 1 || $professionalism_rating > 5) {
    echo json_encode(["success" => false, "message" => "Ratings must be between 1 and 5"]);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Lock the row and confirm this responder actually owns this incident
    // and it's genuinely awaiting review — prevents double submits and
    // spoofed incident_id values from other responders' incidents.
    $check = $pdo->prepare("
        SELECT id FROM incidents
        WHERE id = ?
          AND completed_by_responder_id = ?
          AND review_status = 'pending_review'
        FOR UPDATE
    ");
    $check->execute([$incident_id, $responder_id]);

    if (!$check->fetch()) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Incident is not awaiting your review"]);
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO incident_reviews
            (incident_id, responder_id, response_rating, communication_rating, professionalism_rating, outcome, review_text)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $incident_id, $responder_id, $response_rating,
        $communication_rating, $professionalism_rating, $outcome, $review_text
    ]);

    $update = $pdo->prepare("
        UPDATE incidents
        SET review_status = 'submitted_review', updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $update->execute([$incident_id]);

    $pdo->commit();
    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}