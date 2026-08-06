<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
require_once __DIR__ . '/../includes/report_analytics.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $scope = ers_report_scope($_GET);
    $report = ers_report_fetch_metrics($pdo, $scope);
    $metrics = $report['metrics'];

    $reportData = [
        'period' => $scope['period_label'] . ' (' . $scope['start_date'] . ' to ' . $scope['end_date'] . ', ' . ERS_REPORT_TIMEZONE . ')',
        'comparison_period' => $scope['previous_start_date'] . ' to ' . $scope['previous_end_date'],
        'filters' => 'Type: ' . ($scope['type'] ?: 'all') . '; Priority: ' . ($scope['priority'] ?: 'all'),
        'incident_definition' => 'Incidents created in the selected period only',
        'total_incidents' => (int)$metrics['total_incidents'],
        'previous_total_incidents' => (int)$metrics['previous_total_incidents'],
        'resolved_incidents_by_period_end' => (int)$metrics['resolved_incidents'],
        'resolution_rate' => $metrics['resolution_rate'] === null ? 'Unavailable' : number_format((float)$metrics['resolution_rate'], 1) . '%',
        'previous_resolution_rate' => $metrics['previous_resolution_rate'] === null ? 'Unavailable' : number_format((float)$metrics['previous_resolution_rate'], 1) . '%',
        'response_time_definition' => 'Dispatch assigned_at to recorded on_scene_at only; cleared/completion time is not used',
        'avg_dispatch_to_scene' => $metrics['avg_response_time_min'] === null ? 'Unavailable' : number_format((float)$metrics['avg_response_time_min'], 1) . ' minutes',
        'response_sample_count' => (int)$metrics['avg_response_sample_count'],
        'arrival_sla_target' => ERS_REPORT_RESPONSE_SLA_MINUTES . ' minutes; compliance benchmark ' . number_format(ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT, 0) . '%',
        'arrival_sla_compliance' => $metrics['response_sla_compliance_rate'] === null ? 'Unavailable' : number_format((float)$metrics['response_sla_compliance_rate'], 1) . '%',
        'dispatches' => (int)$metrics['total_dispatches'],
        'resolution_rate_benchmark' => number_format(ERS_REPORT_RESOLUTION_TARGET_PERCENT, 0) . '%',
        'dispatch_acknowledgement_rate' => $metrics['dispatch_acknowledgement_rate'] === null ? 'Unavailable' : number_format((float)$metrics['dispatch_acknowledgement_rate'], 1) . '%',
        'dispatch_acknowledgement_benchmark' => number_format(ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT, 0) . '%',
        'current_unit_utilization' => $metrics['resource_utilization'] === null ? 'Unavailable' : number_format((float)$metrics['resource_utilization'], 1) . '%',
        'unit_utilization_operational_band' => number_format(ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT, 0) . '% to ' . number_format(ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT, 0) . '%, dashboard benchmark',
        'unit_utilization_caveat' => 'Live snapshot, not a historical value for the selected period',
        'active_responder_accounts' => $metrics['active_responder_accounts'] ?? 'Unavailable',
    ];

    $text = generateReportInsights($reportData);
    if (is_string($text) && trim($text) !== '') {
        echo json_encode([
            'ok' => true,
            'text' => $text,
            'meta' => array_merge(ers_report_public_scope($scope), [
                'response_sample_count' => (int)$metrics['avg_response_sample_count'],
                'definitions' => $report['meta']['definitions'],
            ]),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $error = function_exists('getGeminiLastError') ? trim((string)getGeminiLastError()) : '';
    echo json_encode([
        'ok' => false,
        'error' => $error !== '' ? $error : 'AI insights are unavailable.',
        'meta' => ers_report_public_scope($scope),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('ai_report_insights.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to prepare AI report data.']);
}
