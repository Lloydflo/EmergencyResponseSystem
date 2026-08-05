<?php
// update_profile.php
header('Content-Type: application/json');
require __DIR__ . "/connect.php";

$pdo = db();   // ← this line was missing

$user_id   = $_POST['user_id'] ?? null;
$full_name = trim($_POST['full_name'] ?? '');
$username  = trim($_POST['username'] ?? '');
$email     = trim($_POST['email'] ?? '');

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit;
}

$stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, email = ?, updated_at = NOW() WHERE id = ?");
$ok = $stmt->execute([$full_name, $username, $email, $user_id]);

echo json_encode(['success' => $ok, 'message' => $ok ? 'Profile updated' : 'Update failed']);