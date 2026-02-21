<?php
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

$email = $input['email'] ?? '';

echo json_encode([
    "success" => true,
    "message" => "OTP sent",
    "otp" => "123456"
]);