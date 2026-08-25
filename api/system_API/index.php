<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

function ers_system_api_action(): string
{
    $action = $_GET['action'] ?? $_GET['endpoint'] ?? $_GET['module'] ?? '';

    if ($action === '') {
        $pathInfo = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
        if ($pathInfo !== '') {
            $action = explode('/', $pathInfo, 2)[0];
        }
    }

    $action = strtolower(trim((string)$action));
    $action = preg_replace('/[^a-z0-9_.-]+/', '_', $action) ?? '';
    return str_replace('-', '_', $action);
}

function ers_system_api_routes(): array
{
    return [
        'anonymous_tip' => 'anonymous_tip.php',
        'anonymous_tips' => 'anonymous_tip.php',
        'tips' => 'anonymous_tip.php',
        'event_coordination' => 'event_coordination.php',
        'events' => 'event_coordination.php',
        'interagency_external_incident_send' => 'interagency_external_incident_send.php',
        'external_incident_send' => 'interagency_external_incident_send.php',
        'receive_survey' => 'receive_survey.php',
        'survey' => 'receive_survey.php',
        'send_analytics' => 'send_analytics.php',
        'analytics' => 'send_analytics.php',
        'send_incident' => 'send_incident.php',
        'send_incident.api' => 'send_incident.php',
        'incident' => 'send_incident.php',
        'send_route' => 'send_route.php',
        'route' => 'send_route.php',
        'routing' => 'send_route.php',
        'tracking_data' => 'tracking_data.php',
        'tracking' => 'tracking_data.php',
        'live_tracking' => 'live_tracking.php',
        'live_track' => 'live_tracking.php',
    ];
}

function ers_system_api_overview(): array
{
    return [
        'success' => true,
        'message' => 'Unified ERS system API is available.',
        'endpoint' => '/ERS/api/system_API/',
        'usage' => [
            'GET /ERS/api/system_API/?action=anonymous_tip',
            'POST /ERS/api/system_API/?action=anonymous_tip',
            'GET /ERS/api/system_API/?action=event_coordination',
            'POST /ERS/api/system_API/?action=event_coordination',
            'POST /ERS/api/system_API/?action=interagency_external_incident_send',
            'GET /ERS/api/system_API/?action=receive_survey',
            'POST /ERS/api/system_API/?action=receive_survey',
            'POST /ERS/api/system_API/?action=send_analytics',
            'POST /ERS/api/system_API/?action=send_incident',
            'POST /ERS/api/system_API/?action=send_route',
            'POST /ERS/api/system_API/?action=tracking_data',
            'GET /ERS/api/system_API/?action=live_tracking',
        ],
        'actions' => array_keys(ers_system_api_routes()),
    ];
}

$action = ers_system_api_action();
$routes = ers_system_api_routes();

if ($action === '' || in_array($action, ['overview', 'index'], true)) {
    ers_external_json(200, ers_system_api_overview());
}

if (!isset($routes[$action])) {
    ers_external_json(404, [
        'success' => false,
        'error' => 'Unknown system API action',
        'allowed_actions' => array_keys($routes),
    ]);
}

require __DIR__ . '/' . $routes[$action];
?>