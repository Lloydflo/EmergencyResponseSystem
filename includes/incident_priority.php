<?php
declare(strict_types=1);

if (!function_exists('ers_normalize_priority_value')) {
    function ers_normalize_priority_value(string $priority): string
    {
        $priority = strtolower(trim($priority));
        if ($priority === 'medium') {
            return 'moderate';
        }
        return in_array($priority, ['critical', 'high', 'urgent', 'moderate', 'low'], true) ? $priority : 'moderate';
    }
}

if (!function_exists('ers_build_incident_priority_assessment')) {
    function ers_build_incident_priority_assessment(array $input, string $fallbackPriority = 'moderate'): array
    {
        return [
            'has_indicator' => false,
            'priority' => ers_normalize_priority_value($fallbackPriority),
        ];
    }
}

if (!function_exists('ers_incident_priority_column_exists')) {
    function ers_incident_priority_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('ers_ensure_incident_priority_schema')) {
    function ers_ensure_incident_priority_schema(PDO $pdo): void
    {
        foreach (['calls', 'incidents'] as $table) {
            if (ers_incident_priority_column_exists($pdo, $table, 'priority')) {
                $pdo->exec("ALTER TABLE `{$table}` MODIFY `priority` VARCHAR(20) NOT NULL DEFAULT 'moderate'");
            }
        }
    }
}

if (!function_exists('ers_priority_assessment_db_params')) {
    function ers_priority_assessment_db_params(array $assessment): array
    {
        return [];
    }
}
