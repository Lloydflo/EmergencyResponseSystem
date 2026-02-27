<?php
// Returns details for a single unit
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$out = ["unit"=>null];
if ($pdo && $id) {
    $stmt = $pdo->prepare("SELECT * FROM units WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $out['unit'] = $stmt->fetch(PDO::FETCH_ASSOC);
}
echo json_encode($out);