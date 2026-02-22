<?php
// connect.php

// ✅ Robust .env loader (supports #, quotes, spaces)
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // skip comments
        if ($line === '' || str_starts_with($line, '#')) continue;

        // split KEY=VALUE
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        $key = trim($parts[0]);
        $val = trim($parts[1]);

        // remove surrounding quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }

        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

error_log("ENV_PATH=" . __DIR__ . "/.env");
error_log("DB_HOST=" . var_export(getenv("DB_HOST"), true));
error_log("DB_NAME=" . var_export(getenv("DB_NAME"), true));
error_log("DB_USER=" . var_export(getenv("DB_USER"), true));
error_log("DB_PASS_SET=" . (getenv("DB_PASS") ? "yes" : "no"));

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