<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/gemini_helper.php';

$geminiKey = function_exists('ers_resolve_gemini_key') ? trim((string)ers_resolve_gemini_key()) : '';
$geminiUrl = function_exists('ers_resolve_gemini_url') ? trim((string)ers_resolve_gemini_url()) : '';

echo json_encode([
    'status' => 'ok',
    'message' => 'API working',
    'gemini_configured' => ($geminiKey !== '' && $geminiUrl !== ''),
    'gemini_key_length' => strlen($geminiKey),
    'gemini_url' => $geminiUrl,
]);
