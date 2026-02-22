<?php
// connect.php

$envPath = __DIR__ . '/.env';

// ✅ Ensure .env exists and is readable
if (!file_exists($envPath)) {
    throw new Exception(".env not found at: " . $envPath);
}
if (!is_readable($envPath)) {
    throw new Exception(".env not readable (check permissions) at: " . $envPath);
}

// ✅ Robust .env loader (works with #, quotes, CRLF, BOM)
$lines = file($envPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    throw new Exception("Failed to read .env file: " . $envPath);
}

foreach ($lines as $i => $line) {
    // remove BOM if present on first line
    if ($i === 0) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    $line = trim($line);

    // skip empty lines and comments
    if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;

    // split KEY=VALUE
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;

    $key = trim($parts[0]);
    $val = trim($parts[1]);

    // remove surrounding quotes
    $len = strlen($val);
    if ($len >= 2) {
        $first = $val[0];
        $last  = $val[$len - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
        }
    }

    // ✅ set env
    putenv("$key=$val");
    $_ENV[$key] = $val;
    $_SERVER[$key] = $val;
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