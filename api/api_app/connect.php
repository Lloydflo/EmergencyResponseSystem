<?php
// api/connect.php

$envPath = __DIR__ . '/.env';
if (file_exists($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Laktawan ang empty lines o comments
        if ($line === '' || strpos($line, '#') === 0) continue;

        // Siguraduhin na may '=' bago i-parse
        if (strpos($line, '=') !== false) {
            // Gamitin ang limit 2 para hindi ma-cut ang password kung may '=' sa loob nito
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Alisin ang quotes kung meron
            $value = preg_replace('/^["\'](.*)["\']$/', '$1', $value);

            // I-set sa parehong env at server globals
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value"); 
        }
    }
}

function db(): PDO {
    // Gumamit ng $_ENV imbes na getenv para mas sigurado
    $host = $_ENV["DB_HOST"] ?? "127.0.0.1";
    $db   = $_ENV["DB_NAME"] ?? "emergency_response_test";
    $user = $_ENV["DB_USER"] ?? "root";
    $pass = $_ENV["DB_PASS"] ?? "";
    $port = $_ENV["DB_PORT"] ?? "3306";

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}