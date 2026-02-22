<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$input = json_decode(file_get_contents("php://input"), true);
$dept = strtolower(trim($input["department"] ?? "")); // fire/police/medical

if ($dept === "") {
  echo json_encode(["success"=>false, "message"=>"department required", "incidents"=>[]]);
  exit;
}

try {
  $pdo = db();

  if ($dept === "police") {
    // police sees police + traffic
    $stmt = $pdo->prepare("
      SELECT id, reference_no, type, priority, status, title, description, location_address, latitude, longitude
      FROM incidents
      WHERE type IN ('police','traffic')
      ORDER BY id DESC
    ");
    $stmt->execute();
  } else {
    // fire sees fire, medical sees medical
    $stmt = $pdo->prepare("
      SELECT id, reference_no, type, priority, status, title, description, location_address, latitude, longitude
      FROM incidents
      WHERE type = ?
      ORDER BY id DESC
    ");
    $stmt->execute([$dept]);
  }

  $incidents = $stmt->fetchAll();
  echo json_encode(["success"=>true, "message"=>"OK", "incidents"=>$incidents]);

} catch (Throwable $e) {
  echo json_encode(["success"=>false, "message"=>"Server error: ".$e->getMessage(), "incidents"=>[]]);
}