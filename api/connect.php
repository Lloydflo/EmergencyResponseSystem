<?php
// emergency_app/connect.php
function db(): PDO {
  $host = getenv("DB_HOST") ?: "127.0.0.1";
  $db   = getenv("DB_NAME") ?: "emergency_response_test";
  $user = getenv("DB_USER") ?: "root";
  $pass = getenv("DB_PASS") ?: "";
  $port = getenv("DB_PORT") ?: "3306";

  $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
}