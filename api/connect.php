<?php
// connect.php
function db(): PDO {
    $cfg = require __DIR__ . '/db.config.php';

    $host = $cfg['DB_HOST'];
    $port = $cfg['DB_PORT'];
    $db   = $cfg['DB_NAME'];
    $user = $cfg['DB_USER'];
    $pass = $cfg['DB_PASS'];

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}