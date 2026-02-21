<?php
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

$email = $input['email'] ?? '';
$otp = $input['otp'] ?? '';

if ($otp === "123456") {
    echo json_encode([
        "success" => true,
        "message" => "OTP verified",
        "user" => [
            "id" => 1,
            "name" => "Verified User",
            "email" => $email
        ]
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Invalid OTP",
        "user" => null
    ]);
}