<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/gemini_helper.php';

$geminiKey = function_exists('ers_resolve_gemini_key') ? trim((string)ers_resolve_gemini_key()) : '';

echo json_encode([
    'status' => 'ok',
    'message' => 'API working',
    'gemini_configured' => ($geminiKey !== ''),
]);
