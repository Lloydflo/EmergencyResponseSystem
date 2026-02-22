<?php
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input["email"] ?? "");

if ($email === "") {
  echo json_encode(["success"=>false, "message"=>"Email is required", "user"=>null]);
  exit;
}

echo json_encode([
  "success" => true,
  "message" => "Login endpoint working",
  "user" => ["id"=>1, "name"=>"Test User", "email"=>$email]
]);