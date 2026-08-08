<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';
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

    echo json_encode([
        'ok' => true,
        'meta' => $report['meta'],
        'metrics' => $report['metrics'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('report_metrics.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to calculate report metrics.']);
}
