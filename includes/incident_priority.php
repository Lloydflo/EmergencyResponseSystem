<?php
declare(strict_types=1);

if (!function_exists('ers_incident_priority_rules')) {
    function ers_incident_priority_rules(): array
    {
        return [
            'incident_type' => [
                'bomb_threat' => ['label' => 'Bomb Threat', 'score' => 40],
                'active_shooter' => ['label' => 'Active Shooter', 'score' => 40],
                'major_structural_fire' => ['label' => 'Major Structural Fire', 'score' => 38],
                'building_collapse' => ['label' => 'Building Collapse', 'score' => 38],
                'chemical_spill_hazardous_material' => ['label' => 'Chemical Spill / Hazardous Material', 'score' => 35],
                'earthquake' => ['label' => 'Earthquake', 'score' => 35],
                'landslide' => ['label' => 'Landslide', 'score' => 33],
                'flash_flood' => ['label' => 'Flash Flood', 'score' => 32],
                'typhoon_damage' => ['label' => 'Typhoon Damage', 'score' => 30],
                'gas_leak' => ['label' => 'Gas Leak', 'score' => 30],
                'medical_emergency' => ['label' => 'Medical Emergency', 'score' => 28],
                'vehicular_accident' => ['label' => 'Vehicular Accident', 'score' => 25],
                'missing_person' => ['label' => 'Missing Person', 'score' => 20],
                'animal_rescue' => ['label' => 'Animal Rescue', 'score' => 10],
                'power_outage' => ['label' => 'Power Outage', 'score' => 8],
                'noise_complaint_minor_disturbance' => ['label' => 'Noise Complaint / Minor Disturbance', 'score' => 3],
            ],
            'threat_to_life' => [
                'multiple_lives_immediate_danger' => ['label' => 'Multiple lives are in immediate danger', 'score' => 30],
                'trapped_or_seriously_injured' => ['label' => 'People are trapped or seriously injured', 'score' => 25],
                'possible_danger_nearby_people' => ['label' => 'Possible danger to nearby people', 'score' => 15],
                'no_immediate_danger' => ['label' => 'No immediate danger to life', 'score' => 5],
                'false_alarm_hoax' => ['label' => 'False alarm / Hoax', 'score' => 0],
            ],
            'severity_level' => [
                'catastrophic' => ['label' => 'Catastrophic', 'score' => 20],
                'major' => ['label' => 'Major', 'score' => 15],
                'moderate' => ['label' => 'Moderate', 'score' => 10],
                'minor' => ['label' => 'Minor', 'score' => 5],
                'very_minor' => ['label' => 'Very Minor', 'score' => 2],
            ],
            'population_affected' => [
                'more_than_500' => ['label' => 'More than 500 people', 'score' => 10],
                '100_500' => ['label' => '100-500 people', 'score' => 8],
                '20_99' => ['label' => '20-99 people', 'score' => 6],
                '5_19' => ['label' => '5-19 people', 'score' => 4],
                '1_4' => ['label' => '1-4 people', 'score' => 2],
            ],
            'verification_status' => [
                'verified_emergency_personnel_cctv_official' => ['label' => 'Verified by emergency personnel, CCTV, or official source', 'score' => 10],
                'confirmed_multiple_witnesses' => ['label' => 'Confirmed by multiple witnesses', 'score' => 8],
                'one_identified_witness' => ['label' => 'Reported by one identified witness', 'score' => 5],
                'anonymous_unverified' => ['label' => 'Anonymous or unverified report', 'score' => 2],
                'confirmed_false_report' => ['label' => 'Confirmed false report', 'score' => 0],
            ],
        ];
    }
}

if (!function_exists('ers_priority_from_score')) {
    function ers_priority_from_score(int $score): array
    {
        if ($score >= 90) {
            return ['priority' => 'critical', 'label' => 'CRITICAL', 'color' => 'red', 'action' => 'Immediate dispatch and instant notifications.'];
        }
        if ($score >= 70) {
            return ['priority' => 'high', 'label' => 'HIGH', 'color' => 'orange', 'action' => 'Dispatch as soon as possible with high priority notification.'];
        }
        if ($score >= 45) {
            return ['priority' => 'urgent', 'label' => 'URGENT', 'color' => 'yellow', 'action' => 'Standard emergency response after Critical and High incidents.'];
        }
        if ($score >= 20) {
            return ['priority' => 'moderate', 'label' => 'MODERATE', 'color' => 'blue', 'action' => 'Normal response assigned to available responders.'];
        }
        return ['priority' => 'low', 'label' => 'LOW', 'color' => 'green', 'action' => 'Routine handling and monitoring.'];
    }
}

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
        $source = $input['priority_indicator'] ?? $input;
        if (!is_array($source)) {
            $source = [];
        }

        $rules = ers_incident_priority_rules();
        $fieldMap = [
            'incident_type' => 'indicator_incident_type',
            'threat_to_life' => 'threat_to_life',
            'severity_level' => 'severity_level',
            'population_affected' => 'population_affected',
            'verification_status' => 'verification_status',
        ];

        $values = [];
        $breakdown = [];
        $total = 0;
        $hasIndicatorValue = false;

        foreach ($fieldMap as $ruleKey => $columnKey) {
            $raw = trim((string)($source[$ruleKey] ?? $source[$columnKey] ?? ''));
            $values[$columnKey] = $raw;
            $item = $rules[$ruleKey][$raw] ?? null;
            $score = $item ? (int)$item['score'] : 0;
            if ($raw !== '') {
                $hasIndicatorValue = true;
            }
            $total += $score;
            $breakdown[$ruleKey] = [
                'value' => $raw,
                'label' => $item['label'] ?? '',
                'score' => $score,
            ];
        }

        if (!$hasIndicatorValue) {
            $fallback = ers_normalize_priority_value($fallbackPriority);
            $fallbackScores = [
                'critical' => 90,
                'high' => 70,
                'urgent' => 45,
                'moderate' => 20,
                'low' => 0,
            ];
            $total = $fallbackScores[$fallback] ?? 20;
        }

        $meta = ers_priority_from_score($total);
        return [
            'has_indicator' => $hasIndicatorValue,
            'score' => $total,
            'priority' => $meta['priority'],
            'label' => $meta['label'],
            'color' => $meta['color'],
            'action' => $meta['action'],
            'values' => $values,
            'breakdown' => $breakdown,
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
            if (!ers_incident_priority_column_exists($pdo, $table, 'priority_score')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `priority_score` TINYINT UNSIGNED DEFAULT NULL AFTER `priority`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'priority_label')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `priority_label` VARCHAR(20) DEFAULT NULL AFTER `priority_score`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'priority_color')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `priority_color` VARCHAR(20) DEFAULT NULL AFTER `priority_label`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'indicator_incident_type')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `indicator_incident_type` VARCHAR(80) DEFAULT NULL AFTER `priority_color`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'threat_to_life')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `threat_to_life` VARCHAR(80) DEFAULT NULL AFTER `indicator_incident_type`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'severity_level')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `severity_level` VARCHAR(80) DEFAULT NULL AFTER `threat_to_life`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'population_affected')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `population_affected` VARCHAR(80) DEFAULT NULL AFTER `severity_level`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'verification_status')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `verification_status` VARCHAR(80) DEFAULT NULL AFTER `population_affected`");
            }
            if (!ers_incident_priority_column_exists($pdo, $table, 'priority_breakdown')) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `priority_breakdown` LONGTEXT DEFAULT NULL AFTER `verification_status`");
            }
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `priority` VARCHAR(20) NOT NULL DEFAULT 'moderate'");
        }
    }
}

if (!function_exists('ers_priority_assessment_db_params')) {
    function ers_priority_assessment_db_params(array $assessment): array
    {
        $values = $assessment['values'] ?? [];
        return [
            ':priority_score' => (int)($assessment['score'] ?? 0),
            ':priority_label' => (string)($assessment['label'] ?? ''),
            ':priority_color' => (string)($assessment['color'] ?? ''),
            ':indicator_incident_type' => (string)($values['indicator_incident_type'] ?? ''),
            ':threat_to_life' => (string)($values['threat_to_life'] ?? ''),
            ':severity_level' => (string)($values['severity_level'] ?? ''),
            ':population_affected' => (string)($values['population_affected'] ?? ''),
            ':verification_status' => (string)($values['verification_status'] ?? ''),
            ':priority_breakdown' => json_encode($assessment['breakdown'] ?? [], JSON_UNESCAPED_SLASHES),
        ];
    }
}
