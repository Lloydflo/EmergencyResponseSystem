<?php
// connect.php

echo json_encode([
  "env_file" => __DIR__ . "/.env",
  "DB_HOST" => getenv("DB_HOST"),
  "DB_NAME" => getenv("DB_NAME"),
  "DB_USER" => getenv("DB_USER"),
  "DB_PASS_SET" => getenv("DB_PASS") ? true : false,
]);
exit;

// ✅ load .env BEFORE db()
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
    }
}

function db(): PDO {
  $host = getenv("DB_HOST") ?: "127.0.0.1";
  $db   = getenv("DB_NAME") ?: "";
  $user = getenv("DB_USER") ?: "";
  $pass = getenv("DB_PASS") ?: "";
  $port = getenv("DB_PORT") ?: "3306";

  if ($db === "" || $user === "" || $pass === "") {
    throw new Exception("DB env vars missing (DB_HOST/DB_NAME/DB_USER/DB_PASS)");
  }

  $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
}