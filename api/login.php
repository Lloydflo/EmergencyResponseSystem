<?php
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

echo json_encode([
    "success" => true,
    "message" => "Login received",
    "email_received" => $email
]);