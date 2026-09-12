<?php
declare(strict_types=1);

if (!function_exists('ers_normalize_priority_value')) {
    function ers_normalize_priority_value(string $priority): string
    {
        $priority = strtolower(trim($priority));
        if ($priority === 'moderate') {
            return 'medium';
        }
        if ($priority === 'urgent') {
            return 'high';
        }
        return in_array($priority, ['critical', 'high', 'medium', 'low'], true) ? $priority : 'medium';
    }
}

if (!function_exists('ers_build_incident_priority_assessment')) {
    function ers_build_incident_priority_assessment(array $input, string $fallbackPriority = 'medium'): array
    {
        $fallbackPriority = ers_normalize_priority_value($fallbackPriority);
        $text = ers_priority_assessment_text($input);
        $incidentTypes = ers_priority_assessment_types($input['incident_types'] ?? $input['type'] ?? []);
        $score = 0;
        $factors = [];

        $addFactor = static function (string $key, string $label, int $weight) use (&$score, &$factors): void {
            if (isset($factors[$key])) {
                return;
            }
            $score += $weight;
            $factors[$key] = [
                'key' => $key,
                'label' => $label,
                'weight' => $weight,
            ];
        };

        if (preg_match('/\b(unconscious|non[\s-]?responsive|not breathing|difficulty breathing|chest pain|no pulse|cardiac arrest|cpr|resuscitation|walang malay|hindi humihinga|di humihinga|nahihirapang huminga|tumigil ang puso|hinto ang puso)\b/u', $text)) {
            $addFactor('life_threat', 'Immediate life threat indicator', 85);
        }
        if (preg_match('/\b(gunshot|shot|shooting|stab|stabbing|weapon|armed|hostage|barilan|binaril|saksak|sinaksak|may armas|holdap|mass casualty|maraming nasugatan)\b/u', $text)) {
            $addFactor('weapon_or_violence', 'Weapon or violent threat indicator', 80);
        }
        if (preg_match('/\b(fire|explosion|blast|earthquake|flood|collapse|collapsed|trapped|drowning|sunog|pagsabog|lindol|baha|gumuho|guho|naipit|nalulunod)\b/u', $text)) {
            $addFactor('active_hazard', 'Active major hazard (explosion/fire/collapse/flood)', 80);
        }
        if (preg_match('/\b(critical|life-threatening|delikado|malubha|grabe|seryoso)\b/u', $text)) {
            $addFactor('critical_intensity', 'Critical or life-threatening indicator', 80);
        }
        if (preg_match('/\b(severe bleeding|heavy bleeding|stroke|seizure|burns|serious injury|critical injury|matinding pagdurugo|kombulsyon)\b/u', $text)) {
            $addFactor('severe_symptoms', 'Severe injury or symptom indicator', 55);
        }
        if (preg_match('/\b(injury|fracture|sprain|minor bleeding|assault|robbery|burglary|smoke|collision|accident|traffic|missing|distress|dizziness|fever|vomiting|pregnant|labor|child|elderly|sugat|pilay|bukol|bahagyang pagdurugo|bugbog|aksidente|banggaan|trapiko|nawawala|nahilo|lagnat|pagsusuka|buntis|manganganak|bata|matanda)\b/u', $text)) {
            $addFactor('medium_indicator', 'Medium urgency incident indicator', 35);
        }
        if (preg_match('/(\b\d+\b|multiple|many|several|marami|ilan|dalawa(?:ng)?|tatlo(?:ng)?|apat|lima(?:ng)?)\s+(victims?|patients?|people|persons?|injured|casualties|nasugatan|tao|biktima|pasiente|sasakyan|vehicles?)/u', $text)) {
            $addFactor('multiple_victims', 'Multiple victims or vehicles indicator', 15);
        }
        if (preg_match('/\b(minor|mild|stable|resolved|okay na|ok na|walang sugat|hindi seryoso|bahagya|stable na)\b/u', $text)) {
            $addFactor('minor_or_stable', 'Minor, stable, or resolved indicator', -20);
        }

        if (in_array('fire', $incidentTypes, true) && isset($factors['active_hazard'])) {
            $addFactor('fire_context', 'Fire incident context increases urgency', 8);
        }
        if ((in_array('medical', $incidentTypes, true) || in_array('ambulance', $incidentTypes, true)) && (isset($factors['life_threat']) || isset($factors['severe_symptoms']))) {
            $addFactor('medical_context', 'Medical context with severe symptoms', 8);
        }
        if (in_array('police', $incidentTypes, true) && isset($factors['weapon_or_violence'])) {
            $addFactor('police_context', 'Police context with active threat', 8);
        }

        $hasIndicator = count($factors) > 0;
        if (!$hasIndicator) {
            $score = [
                'critical' => 86,
                'high' => 62,
                'medium' => 38,
                'low' => 12,
            ][$fallbackPriority] ?? 38;
        }

        $score = max(0, min(100, $score));
        $metricPriority = ers_priority_from_score($score);
        if (!$hasIndicator) {
            $metricPriority = $fallbackPriority;
        }

        $confidence = 0.25;
        if ($hasIndicator) {
            $confidence = 0.42 + min(0.38, count($factors) * 0.07);
            if (strlen($text) >= 80) {
                $confidence += 0.08;
            }
            if (trim((string)($input['location'] ?? '')) !== '') {
                $confidence += 0.04;
            }
        }
        $confidence = max(0.2, min(0.95, $confidence));

        return [
            'has_indicator' => $hasIndicator,
            'priority' => $metricPriority,
            'score' => $score,
            'confidence' => round($confidence, 3),
            'factors' => array_values($factors),
            'model' => 'ers-rule-priority-v1',
            'assessed_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('ers_priority_assessment_text')) {
    function ers_priority_assessment_text(array $input): string
    {
        $parts = [];
        foreach (['description', 'call_notes', 'callNotes', 'location', 'title'] as $key) {
            if (isset($input[$key])) {
                $parts[] = (string)$input[$key];
            }
        }
        if (isset($input['type'])) {
            $parts[] = is_array($input['type']) ? implode(' ', $input['type']) : (string)$input['type'];
        }
        if (isset($input['incident_types'])) {
            $parts[] = is_array($input['incident_types']) ? implode(' ', $input['incident_types']) : (string)$input['incident_types'];
        }

        $text = strtolower(trim(implode(' ', $parts)));
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?? $text;
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}

if (!function_exists('ers_priority_assessment_types')) {
    function ers_priority_assessment_types($value): array
    {
        $rawItems = is_array($value) ? $value : preg_split('/[,|]+/', (string)$value);
        $items = [];
        foreach ($rawItems ?: [] as $item) {
            $normalized = strtolower(trim((string)$item));
            if ($normalized === 'ambulance') {
                $normalized = 'medical';
            } elseif ($normalized === 'crime') {
                $normalized = 'police';
            } elseif ($normalized === 'accident') {
                $normalized = 'traffic';
            }
            if ($normalized !== '' && !in_array($normalized, $items, true)) {
                $items[] = $normalized;
            }
        }
        return $items;
    }
}

if (!function_exists('ers_priority_from_score')) {
    function ers_priority_from_score(int $score): string
    {
        if ($score >= 80) {
            return 'critical';
        }
        if ($score >= 50) {
            return 'high';
        }
        if ($score >= 25) {
            return 'medium';
        }
        return 'low';
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

if (!function_exists('ers_incident_priority_index_exists')) {
    function ers_incident_priority_index_exists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('ers_ensure_incident_priority_schema')) {
    function ers_ensure_incident_priority_schema(PDO $pdo): void
    {
        foreach (['calls', 'incidents'] as $table) {
            if (ers_incident_priority_column_exists($pdo, $table, 'priority')) {
                $pdo->exec("ALTER TABLE `{$table}` MODIFY `priority` VARCHAR(20) NOT NULL DEFAULT 'medium'");
                $pdo->exec("UPDATE `{$table}` SET `priority` = 'medium' WHERE LOWER(`priority`) = 'moderate'");
                $pdo->exec("UPDATE `{$table}` SET `priority` = 'high' WHERE LOWER(`priority`) = 'urgent'");

                $columns = [
                    'priority_score' => "`priority_score` TINYINT UNSIGNED DEFAULT NULL AFTER `priority`",
                    'priority_confidence' => "`priority_confidence` DECIMAL(4,3) DEFAULT NULL AFTER `priority_score`",
                    'priority_factors_json' => "`priority_factors_json` LONGTEXT DEFAULT NULL AFTER `priority_confidence`",
                    'priority_model' => "`priority_model` VARCHAR(60) DEFAULT NULL AFTER `priority_factors_json`",
                    'priority_assessed_at' => "`priority_assessed_at` DATETIME DEFAULT NULL AFTER `priority_model`",
                ];
                foreach ($columns as $column => $definition) {
                    if (!ers_incident_priority_column_exists($pdo, $table, $column)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
                    }
                }
                $indexName = "idx_{$table}_priority_score";
                if (!ers_incident_priority_index_exists($pdo, $table, $indexName)) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD KEY `{$indexName}` (`priority_score`)");
                }
            }
        }
    }
}

if (!function_exists('ers_priority_assessment_db_params')) {
    function ers_priority_assessment_db_params(array $assessment): array
    {
        return [
            ':priority_score' => isset($assessment['score']) ? (int)$assessment['score'] : null,
            ':priority_confidence' => isset($assessment['confidence']) ? (float)$assessment['confidence'] : null,
            ':priority_factors_json' => json_encode($assessment['factors'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':priority_model' => substr((string)($assessment['model'] ?? 'ers-rule-priority-v1'), 0, 60),
            ':priority_assessed_at' => $assessment['assessed_at'] ?? null,
        ];
    }
}
