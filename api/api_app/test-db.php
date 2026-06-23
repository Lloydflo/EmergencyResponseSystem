<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../db.php";

echo json_encode([
    "has_conn" => isset($conn),
    "has_con" => isset($con),
    "has_mysqli" => isset($mysqli),
    "has_pdo" => isset($pdo)
]);