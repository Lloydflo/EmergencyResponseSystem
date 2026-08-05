<?php

const INTERAGENCY_TIMEZONE = 'Asia/Manila';

function interagency_timezone(): DateTimeZone {
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone(INTERAGENCY_TIMEZONE);
    }
    return $timezone;
}

function interagency_now(): string {
    return (new DateTimeImmutable('now', interagency_timezone()))->format('Y-m-d H:i:s');
}

function interagency_apply_database_timezone(PDO $pdo): void {
    static $applied = [];
    $key = spl_object_id($pdo);
    if (isset($applied[$key])) {
        return;
    }
    $applied[$key] = true;

    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (Throwable $e) {
        // Keep the request working even if the DB user cannot change session settings.
    }
}

function interagency_manila_iso(?string $datetime): ?string {
    $value = trim((string)$datetime);
    if ($value === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value, interagency_timezone());
        return $date->format(DateTimeInterface::ATOM);
    } catch (Throwable $e) {
        return $value;
    }
}
