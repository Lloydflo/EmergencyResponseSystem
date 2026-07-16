<?php

// api/api_app/connect.php

date_default_timezone_set("Asia/Manila");

/*
 * connect.php is inside api/api_app/.
 * The .env file is inside api/.
 */
$envPath = dirname(__DIR__) . "/.env";

if (file_exists($envPath) && is_readable($envPath)) {
    $lines = file(
        $envPath,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === "" || str_starts_with($line, "#")) {
            continue;
        }

        if (strpos($line, "=") === false) {
            continue;
        }

        [$key, $value] = explode("=", $line, 2);

        $key = trim($key);
        $value = trim($value);

        $value = preg_replace(
            '/^["\'](.*)["\']$/',
            '$1',
            $value
        );

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        putenv("$key=$value");
    }
}

function db(): PDO
{
    $host = $_ENV["DB_HOST"] ?? "127.0.0.1";
    $db   = $_ENV["DB_NAME"] ?? "emergency_response_test";
    $user = $_ENV["DB_USER"] ?? "root";
    $pass = $_ENV["DB_PASS"] ?? "";
    $port = $_ENV["DB_PORT"] ?? "3306";

    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $host,
        $port,
        $db
    );

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false
        ]
    );

    $pdo->exec("SET time_zone = '+08:00'");

    return $pdo;
}