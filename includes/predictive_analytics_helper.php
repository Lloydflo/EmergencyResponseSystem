<?php
declare(strict_types=1);

if (!function_exists('ers_predictive_table_exists')) {
    function ers_predictive_table_exists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1"
            );
            $stmt->execute([$tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ers_predictive_average')) {
    function ers_predictive_average(array $values): float
    {
        $filtered = array_values(array_filter($values, static function ($value): bool {
            return is_numeric($value);
        }));
        if ($filtered === []) {
            return 0.0;
        }
        return array_sum($filtered) / count($filtered);
    }
}

if (!function_exists('ers_predictive_safe_ratio')) {
    function ers_predictive_safe_ratio(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return $numerator / $denominator;
    }
}

if (!function_exists('ers_predictive_normalize_type')) {
    function ers_predictive_normalize_type(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === 'crime') {
            return 'police';
        }
        if ($type === 'accident') {
            return 'traffic';
        }
        return in_array($type, ['medical', 'fire', 'police', 'traffic'], true) ? $type : 'other';
    }
}

if (!function_exists('ers_predictive_type_label')) {
    function ers_predictive_type_label(string $type): string
    {
        $map = [
            'medical' => 'Medical',
            'fire' => 'Fire',
            'police' => 'Police',
            'traffic' => 'Traffic',
            'other' => 'Other',
        ];
        return $map[$type] ?? 'Other';
    }
}

if (!function_exists('ers_predictive_trend_label')) {
    function ers_predictive_trend_label(float $deltaPercent): string
    {
        if ($deltaPercent >= 8) {
            return 'Rising';
        }
        if ($deltaPercent <= -8) {
            return 'Cooling';
        }
        return 'Stable';
    }
}

if (!function_exists('ers_predictive_risk_label')) {
    function ers_predictive_risk_label(float $score): string
    {
        if ($score >= 75) {
            return 'High';
        }
        if ($score >= 45) {
            return 'Medium';
        }
        return 'Low';
    }
}

if (!function_exists('ers_predictive_peak_window_label')) {
    function ers_predictive_peak_window_label(int $hour): string
    {
        $start = max(0, min(23, $hour));
        $end = ($start + 1) % 24;
        $startLabel = DateTime::createFromFormat('H', str_pad((string)$start, 2, '0', STR_PAD_LEFT));
        $endLabel = DateTime::createFromFormat('H', str_pad((string)$end, 2, '0', STR_PAD_LEFT));
        if (!$startLabel || !$endLabel) {
            return 'Unavailable';
        }
        return $startLabel->format('g A') . ' - ' . $endLabel->format('g A');
    }
}

if (!function_exists('ers_predictive_default_snapshot')) {
    function ers_predictive_default_snapshot(): array
    {
        $historyLabels = [];
        $actualSeries = [];
        $forecastSeries = [];
        $today = new DateTimeImmutable('today');
        for ($i = 27; $i >= 0; $i--) {
            $day = $today->modify('-' . $i . ' days');
            $historyLabels[] = $day->format('M j');
            $actualSeries[] = 0;
        }
        $forecastLabels = [];
        for ($i = 1; $i <= 7; $i++) {
            $day = $today->modify('+' . $i . ' days');
            $forecastLabels[] = $day->format('M j');
        }
        $forecastSeries = array_fill(0, max(0, count($historyLabels) - 1), null);
        $forecastSeries[] = 0;
        $forecastSeries = array_merge($forecastSeries, array_fill(0, 7, 0));

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'forecast' => [
                'next_7_total' => 0,
                'avg_daily' => 0.0,
                'delta_percent' => 0.0,
                'delta_label' => 'Stable',
                'high_priority_load' => 0,
                'peak_window' => 'Unavailable',
            ],
            'resource' => [
                'strain_index' => 0.0,
                'available_units' => 0,
                'busy_units' => 0,
                'total_units' => 0,
                'active_responders' => 0,
                'standby_ratio' => 0.0,
            ],
            'resolution' => [
                'projected_carryover' => 0,
                'resolved_last_14_days' => 0,
            ],
            'current' => [
                'active_incidents' => 0,
                'recent_7_day_average' => 0.0,
                'previous_7_day_average' => 0.0,
            ],
            'peak_hour' => [
                'hour' => null,
                'label' => 'Unavailable',
                'count' => 0,
            ],
            'type_forecast' => [
                ['type' => 'medical', 'label' => 'Medical', 'historical' => 0, 'forecast' => 0, 'share' => 0.0, 'trend' => 'Stable', 'risk' => 'Low'],
                ['type' => 'fire', 'label' => 'Fire', 'historical' => 0, 'forecast' => 0, 'share' => 0.0, 'trend' => 'Stable', 'risk' => 'Low'],
                ['type' => 'police', 'label' => 'Police', 'historical' => 0, 'forecast' => 0, 'share' => 0.0, 'trend' => 'Stable', 'risk' => 'Low'],
                ['type' => 'traffic', 'label' => 'Traffic', 'historical' => 0, 'forecast' => 0, 'share' => 0.0, 'trend' => 'Stable', 'risk' => 'Low'],
                ['type' => 'other', 'label' => 'Other', 'historical' => 0, 'forecast' => 0, 'share' => 0.0, 'trend' => 'Stable', 'risk' => 'Low'],
            ],
            'priority_mix' => [
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ],
            'hotspots' => [],
            'recommendations' => [
                'Connect incident, dispatch, and unit history to build a stronger forecast baseline.',
                'Keep at least one reserve unit available until predictive volume rises above baseline.',
                'Refresh the AI summary after new incidents are logged to improve prediction quality.',
            ],
            'charts' => [
                'timeline_labels' => array_merge($historyLabels, $forecastLabels),
                'actual_series' => array_merge($actualSeries, array_fill(0, 7, null)),
                'forecast_series' => $forecastSeries,
                'type_labels' => ['Medical', 'Fire', 'Police', 'Traffic', 'Other'],
                'type_values' => [0, 0, 0, 0, 0],
                'priority_labels' => ['High', 'Medium', 'Low'],
                'priority_values' => [0, 0, 0],
            ],
        ];
    }
}

if (!function_exists('ers_predictive_build_snapshot')) {
    function ers_predictive_build_snapshot(PDO $pdo): array
    {
        $snapshot = ers_predictive_default_snapshot();

        if (!ers_predictive_table_exists($pdo, 'incidents')) {
            return $snapshot;
        }

        $today = new DateTimeImmutable('today');
        $historyStart = $today->modify('-27 days');
        $historyEndExclusive = $today->modify('+1 day');
        $last30Start = $today->modify('-29 days');
        $last14Start = $today->modify('-13 days');
        $prev14Start = $today->modify('-27 days');
        $prev14EndExclusive = $today->modify('-13 days');
        $hotspotStart = $today->modify('-44 days');
        $peakStart = $today->modify('-59 days');

        $historyMap = [];
        $typeBucketsByWeekday = [];
        $historyValues = [];
        $historyLabels = [];
        $historyDates = [];
        for ($i = 27; $i >= 0; $i--) {
            $day = $today->modify('-' . $i . ' days');
            $key = $day->format('Y-m-d');
            $historyMap[$key] = 0;
            $historyValues[] = 0;
            $historyDates[] = $key;
            $historyLabels[] = $day->format('M j');
        }

        $dailyStmt = $pdo->prepare(
            "SELECT DATE(created_at) AS incident_day, COUNT(*) AS total_count
             FROM incidents
             WHERE created_at >= :start AND created_at < :end
             GROUP BY DATE(created_at)
             ORDER BY incident_day ASC"
        );
        $dailyStmt->execute([
            ':start' => $historyStart->format('Y-m-d 00:00:00'),
            ':end' => $historyEndExclusive->format('Y-m-d 00:00:00'),
        ]);
        foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['incident_day'] ?? '');
            if (array_key_exists($key, $historyMap)) {
                $historyMap[$key] = (int)($row['total_count'] ?? 0);
            }
        }

        foreach ($historyDates as $index => $dateKey) {
            $count = (int)($historyMap[$dateKey] ?? 0);
            $historyValues[$index] = $count;
            $weekday = (int)(new DateTimeImmutable($dateKey))->format('w');
            if (!isset($typeBucketsByWeekday[$weekday])) {
                $typeBucketsByWeekday[$weekday] = [];
            }
            $typeBucketsByWeekday[$weekday][] = $count;
        }

        $recent7 = array_slice($historyValues, -7);
        $previous7 = array_slice($historyValues, -14, 7);
        $recent7Avg = round(ers_predictive_average($recent7), 1);
        $previous7Avg = round(ers_predictive_average($previous7), 1);
        $trendRatio = $previous7Avg > 0 ? max(0.82, min(1.28, $recent7Avg / $previous7Avg)) : 1.0;

        $forecastLabels = [];
        $forecastValues = [];
        for ($i = 1; $i <= 7; $i++) {
            $futureDate = $today->modify('+' . $i . ' days');
            $weekday = (int)$futureDate->format('w');
            $weekdayAverage = ers_predictive_average($typeBucketsByWeekday[$weekday] ?? $recent7);
            $predicted = (int)round(((0.6 * $recent7Avg) + (0.4 * $weekdayAverage)) * $trendRatio);
            $predicted = max(0, $predicted);
            $forecastLabels[] = $futureDate->format('M j');
            $forecastValues[] = $predicted;
        }

        $next7Total = array_sum($forecastValues);
        $forecastAvg = round(ers_predictive_average($forecastValues), 1);
        $deltaPercent = $recent7Avg > 0 ? round((($forecastAvg - $recent7Avg) / $recent7Avg) * 100, 1) : 0.0;
        $deltaLabel = ers_predictive_trend_label($deltaPercent);

        $types = ['medical', 'fire', 'police', 'traffic', 'other'];
        $typeCurrent = array_fill_keys($types, 0);
        $typePrevious = array_fill_keys($types, 0);

        $typeStmt = $pdo->prepare(
            "SELECT LOWER(type) AS type_name,
                    SUM(CASE WHEN created_at >= :currentStart AND created_at < :currentEnd THEN 1 ELSE 0 END) AS current_count,
                    SUM(CASE WHEN created_at >= :previousStart AND created_at < :previousEnd THEN 1 ELSE 0 END) AS previous_count
             FROM incidents
             WHERE created_at >= :previousStart AND created_at < :currentEnd
             GROUP BY LOWER(type)"
        );
        $typeStmt->execute([
            ':currentStart' => $last30Start->format('Y-m-d 00:00:00'),
            ':currentEnd' => $historyEndExclusive->format('Y-m-d 00:00:00'),
            ':previousStart' => $today->modify('-59 days')->format('Y-m-d 00:00:00'),
            ':previousEnd' => $last30Start->format('Y-m-d 00:00:00'),
        ]);
        foreach ($typeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ers_predictive_normalize_type((string)($row['type_name'] ?? 'other'));
            $typeCurrent[$key] += (int)($row['current_count'] ?? 0);
            $typePrevious[$key] += (int)($row['previous_count'] ?? 0);
        }

        $currentTypeTotal = max(1, array_sum($typeCurrent));
        $remainingForecast = $next7Total;
        $typeForecast = [];
        foreach ($types as $index => $typeKey) {
            if ($index === count($types) - 1) {
                $forecastCount = max(0, $remainingForecast);
            } else {
                $share = ers_predictive_safe_ratio((float)$typeCurrent[$typeKey], (float)$currentTypeTotal);
                $forecastCount = (int)round($next7Total * $share);
                $remainingForecast -= $forecastCount;
            }
            $historical = $typeCurrent[$typeKey];
            $previous = $typePrevious[$typeKey];
            $trendDelta = $previous > 0 ? (($historical - $previous) / $previous) * 100 : 0.0;
            $riskScore = ($forecastCount * 10) + (($typeKey === 'medical' || $typeKey === 'fire') ? 12 : 0);
            $typeForecast[] = [
                'type' => $typeKey,
                'label' => ers_predictive_type_label($typeKey),
                'historical' => $historical,
                'forecast' => $forecastCount,
                'share' => round(ers_predictive_safe_ratio((float)$historical, (float)$currentTypeTotal) * 100, 1),
                'trend' => ers_predictive_trend_label((float)$trendDelta),
                'risk' => ers_predictive_risk_label((float)$riskScore),
            ];
        }

        $priorityMix = ['high' => 0, 'medium' => 0, 'low' => 0];
        $priorityStmt = $pdo->prepare(
            "SELECT LOWER(priority) AS priority_name, COUNT(*) AS total_count
             FROM incidents
             WHERE created_at >= :start AND created_at < :end
             GROUP BY LOWER(priority)"
        );
        $priorityStmt->execute([
            ':start' => $last30Start->format('Y-m-d 00:00:00'),
            ':end' => $historyEndExclusive->format('Y-m-d 00:00:00'),
        ]);
        foreach ($priorityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower((string)($row['priority_name'] ?? ''));
            if ($key === 'critical') {
                $key = 'high';
            }
            if (isset($priorityMix[$key])) {
                $priorityMix[$key] += (int)($row['total_count'] ?? 0);
            }
        }
        $priorityTotal = max(1, array_sum($priorityMix));
        $highPriorityShare = ers_predictive_safe_ratio((float)$priorityMix['high'], (float)$priorityTotal);
        $highPriorityLoad = (int)round($next7Total * $highPriorityShare);

        $peakHour = null;
        $peakCount = 0;
        $peakStmt = $pdo->prepare(
            "SELECT HOUR(created_at) AS incident_hour, COUNT(*) AS total_count
             FROM incidents
             WHERE created_at >= :start AND created_at < :end
             GROUP BY HOUR(created_at)
             ORDER BY total_count DESC, incident_hour ASC
             LIMIT 1"
        );
        $peakStmt->execute([
            ':start' => $peakStart->format('Y-m-d 00:00:00'),
            ':end' => $historyEndExclusive->format('Y-m-d 00:00:00'),
        ]);
        $peakRow = $peakStmt->fetch(PDO::FETCH_ASSOC);
        if ($peakRow) {
            $peakHour = isset($peakRow['incident_hour']) ? (int)$peakRow['incident_hour'] : null;
            $peakCount = (int)($peakRow['total_count'] ?? 0);
        }

        $hotspots = [];
        $hotspotStmt = $pdo->prepare(
            "SELECT TRIM(location) AS location_name,
                    LOWER(type) AS type_name,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN LOWER(priority) IN ('high', 'critical') THEN 1 ELSE 0 END) AS high_count
             FROM incidents
             WHERE created_at >= :start
               AND created_at < :end
               AND TRIM(COALESCE(location, '')) <> ''
             GROUP BY TRIM(location), LOWER(type)
             ORDER BY total_count DESC"
        );
        $hotspotStmt->execute([
            ':start' => $hotspotStart->format('Y-m-d 00:00:00'),
            ':end' => $historyEndExclusive->format('Y-m-d 00:00:00'),
        ]);
        $hotspotBuckets = [];
        foreach ($hotspotStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $location = trim((string)($row['location_name'] ?? ''));
            if ($location === '') {
                continue;
            }
            if (!isset($hotspotBuckets[$location])) {
                $hotspotBuckets[$location] = [
                    'location' => $location,
                    'incidents' => 0,
                    'high_priority' => 0,
                    'types' => [],
                ];
            }
            $typeKey = ers_predictive_normalize_type((string)($row['type_name'] ?? 'other'));
            $count = (int)($row['total_count'] ?? 0);
            $highCount = (int)($row['high_count'] ?? 0);
            $hotspotBuckets[$location]['incidents'] += $count;
            $hotspotBuckets[$location]['high_priority'] += $highCount;
            if (!isset($hotspotBuckets[$location]['types'][$typeKey])) {
                $hotspotBuckets[$location]['types'][$typeKey] = 0;
            }
            $hotspotBuckets[$location]['types'][$typeKey] += $count;
        }
        foreach ($hotspotBuckets as $bucket) {
            arsort($bucket['types']);
            $dominantTypeKey = (string)array_key_first($bucket['types']);
            $riskScore = ($bucket['incidents'] * 9) + ($bucket['high_priority'] * 12);
            $hotspots[] = [
                'location' => $bucket['location'],
                'incidents' => $bucket['incidents'],
                'high_priority' => $bucket['high_priority'],
                'dominant_type' => ers_predictive_type_label($dominantTypeKey !== '' ? $dominantTypeKey : 'other'),
                'risk' => ers_predictive_risk_label((float)$riskScore),
            ];
        }
        usort($hotspots, static function (array $a, array $b): int {
            if ($a['incidents'] === $b['incidents']) {
                return $b['high_priority'] <=> $a['high_priority'];
            }
            return $b['incidents'] <=> $a['incidents'];
        });
        $hotspots = array_slice($hotspots, 0, 4);

        $activeIncidents = 0;
        $activeStmt = $pdo->query(
            "SELECT COUNT(*) AS total_count
             FROM incidents
             WHERE LOWER(status) IN ('pending', 'dispatched', 'active', 'in_progress', 'enroute', 'on_scene')"
        );
        if ($activeStmt) {
            $activeIncidents = (int)($activeStmt->fetch()['total_count'] ?? 0);
        }

        $resolvedLast14 = 0;
        $resolvedStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_count
             FROM incidents
             WHERE LOWER(status) = 'resolved'
               AND created_at >= :start
               AND created_at < :end"
        );
        $resolvedStmt->execute([
            ':start' => $last14Start->format('Y-m-d 00:00:00'),
            ':end' => $historyEndExclusive->format('Y-m-d 00:00:00'),
        ]);
        $resolvedLast14 = (int)($resolvedStmt->fetch()['total_count'] ?? 0);
        $resolvedPerDay = $resolvedLast14 > 0 ? ($resolvedLast14 / 14) : 0.0;

        $totalUnits = 0;
        $availableUnits = 0;
        $busyUnits = 0;
        if (ers_predictive_table_exists($pdo, 'units')) {
            $totalUnits = (int)($pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'] ?? 0);
            $availableUnits = (int)($pdo->query("SELECT COUNT(*) AS c FROM units WHERE LOWER(status) IN ('available', 'ready')")->fetch()['c'] ?? 0);
            $busyUnits = (int)($pdo->query("SELECT COUNT(*) AS c FROM units WHERE LOWER(status) IN ('assigned', 'acknowledged', 'enroute', 'on_scene', 'dispatched')")->fetch()['c'] ?? 0);
        }

        $activeResponders = 0;
        $totalResponders = 0;
        if (ers_predictive_table_exists($pdo, 'staff')) {
            $totalResponders = (int)($pdo->query("SELECT COUNT(*) AS c FROM staff")->fetch()['c'] ?? 0);
            $activeResponders = (int)($pdo->query("SELECT COUNT(*) AS c FROM staff WHERE LOWER(status) IN ('available', 'on_duty')")->fetch()['c'] ?? 0);
        }

        $busyRatio = ers_predictive_safe_ratio((float)$busyUnits, (float)max(1, $totalUnits));
        $responderPressure = $totalResponders > 0
            ? 1 - ers_predictive_safe_ratio((float)$activeResponders, (float)$totalResponders)
            : 0.0;
        $incidentPressure = ers_predictive_safe_ratio((float)($activeIncidents + $highPriorityLoad), (float)max(1, $availableUnits + 1));
        $incidentPressure = min(1.0, $incidentPressure / 3.0);
        $strainIndex = round(min(1.0, ($busyRatio * 0.45) + ($incidentPressure * 0.35) + ($responderPressure * 0.20)) * 100, 1);
        $standbyRatio = round(ers_predictive_safe_ratio((float)$availableUnits, (float)max(1, $totalUnits)) * 100, 1);

        $projectedCarryover = max(0, (int)round($activeIncidents + $highPriorityLoad - ($resolvedPerDay * 3)));

        $topHotspot = $hotspots[0] ?? null;
        usort($typeForecast, static function (array $a, array $b): int {
            if ($a['forecast'] === $b['forecast']) {
                return strcmp($a['label'], $b['label']);
            }
            return $b['forecast'] <=> $a['forecast'];
        });
        $topType = $typeForecast[0] ?? null;

        $recommendations = [];
        if ($topHotspot) {
            $recommendations[] = 'Pre-stage ' . ($topType['label'] ?? 'response') . ' coverage near ' . $topHotspot['location'] . ' before the ' . ers_predictive_peak_window_label((int)($peakHour ?? 8)) . ' pressure window.';
        }
        if ($strainIndex >= 70) {
            $recommendations[] = 'Resource strain is elevated. Hold non-critical maintenance and protect at least ' . max(2, (int)ceil($highPriorityLoad / 2)) . ' reserve units for surge coverage.';
        } else {
            $recommendations[] = 'Current strain is manageable. Keep a standby buffer of ' . max(1, (int)ceil($forecastAvg / 2)) . ' units to absorb forecast volatility.';
        }
        if ($highPriorityLoad > 0) {
            $recommendations[] = 'Prepare for about ' . $highPriorityLoad . ' high-priority incidents over the next 7 days and tighten dispatch escalation during the peak window.';
        }
        if (($topType['forecast'] ?? 0) > 0) {
            $recommendations[] = 'Shift briefing focus toward ' . strtolower((string)($topType['label'] ?? 'response')) . ' readiness because it leads the current 7-day prediction mix.';
        }
        $recommendations[] = 'Refresh the AI brief after major dispatch updates so the predictive summary reflects the latest incident and resource state.';

        $timelineLabels = array_merge($historyLabels, $forecastLabels);
        $actualSeries = array_merge($historyValues, array_fill(0, 7, null));
        $forecastSeries = array_fill(0, max(0, count($historyValues) - 1), null);
        $forecastSeries[] = end($historyValues) ?: 0;
        $forecastSeries = array_merge($forecastSeries, $forecastValues);

        $snapshot['generated_at'] = date('Y-m-d H:i:s');
        $snapshot['forecast'] = [
            'next_7_total' => $next7Total,
            'avg_daily' => $forecastAvg,
            'delta_percent' => $deltaPercent,
            'delta_label' => $deltaLabel,
            'high_priority_load' => $highPriorityLoad,
            'peak_window' => $peakHour !== null ? ers_predictive_peak_window_label($peakHour) : 'Unavailable',
        ];
        $snapshot['resource'] = [
            'strain_index' => $strainIndex,
            'available_units' => $availableUnits,
            'busy_units' => $busyUnits,
            'total_units' => $totalUnits,
            'active_responders' => $activeResponders,
            'standby_ratio' => $standbyRatio,
        ];
        $snapshot['resolution'] = [
            'projected_carryover' => $projectedCarryover,
            'resolved_last_14_days' => $resolvedLast14,
        ];
        $snapshot['current'] = [
            'active_incidents' => $activeIncidents,
            'recent_7_day_average' => $recent7Avg,
            'previous_7_day_average' => $previous7Avg,
        ];
        $snapshot['peak_hour'] = [
            'hour' => $peakHour,
            'label' => $peakHour !== null ? ers_predictive_peak_window_label($peakHour) : 'Unavailable',
            'count' => $peakCount,
        ];
        $snapshot['type_forecast'] = $typeForecast;
        $snapshot['priority_mix'] = $priorityMix;
        $snapshot['hotspots'] = $hotspots;
        $snapshot['recommendations'] = array_values(array_unique($recommendations));
        $snapshot['charts'] = [
            'timeline_labels' => $timelineLabels,
            'actual_series' => $actualSeries,
            'forecast_series' => $forecastSeries,
            'type_labels' => array_map(static function (array $row): string {
                return (string)$row['label'];
            }, $typeForecast),
            'type_values' => array_map(static function (array $row): int {
                return (int)$row['forecast'];
            }, $typeForecast),
            'priority_labels' => ['High', 'Medium', 'Low'],
            'priority_values' => [
                (int)$priorityMix['high'],
                (int)$priorityMix['medium'],
                (int)$priorityMix['low'],
            ],
        ];

        return $snapshot;
    }
}
