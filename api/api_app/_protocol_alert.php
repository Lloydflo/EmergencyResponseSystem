<?php
declare(strict_types=1);

/**
 * Pushes dispatcher "quick action" protocol alerts — Emergency Broadcast,
 * Lockdown Protocol, Mass Casualty Incident — to every active responder
 * device. These are command-level alerts, so unlike a routine broadcast the
 * Android client keeps them on screen (a blocking in-app overlay, not just a
 * dismissible tray notification) until the responder explicitly acknowledges
 * them. See FCM_NOTIFICATION_PAYLOADS.md ("protocol_alert") in the Android
 * project for the client-side contract.
 */
require_once __DIR__ . '/_fcm.php';

/**
 * @param array{
 *   protocol:string,
 *   protocol_label?:string,
 *   title?:string,
 *   message:string,
 *   priority?:string,
 *   alert_id?:string
 * } $payload
 * @return array{attempted:int,delivered:int,failed:int,errors:list<string>}
 */
function ers_send_protocol_alert(PDO $pdo, array $payload): array
{
    $protocol = strtolower(trim((string)($payload['protocol'] ?? '')));
    $allowed = ['broadcast', 'lockdown', 'mci'];
    if (!in_array($protocol, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported protocol alert type: ' . $protocol);
    }

    $label = trim((string)($payload['protocol_label'] ?? ''));
    $title = trim((string)($payload['title'] ?? '')) ?: $label ?: match ($protocol) {
        'lockdown' => 'Lockdown Protocol',
        'mci' => 'Mass Casualty Incident',
        default => 'Emergency Broadcast',
    };
    $message = ers_notification_preview((string)($payload['message'] ?? ''), 900);
    $priority = strtolower(trim((string)($payload['priority'] ?? ''))) ?: 'critical';
    $alertId = trim((string)($payload['alert_id'] ?? '')) ?: (string)random_int(100000, 999999);

    return ers_fcm_send_to_all_responders($pdo, [
        'type' => 'protocol_alert',
        'protocol' => $protocol,
        'alert_id' => $alertId,
        'title' => $title,
        'body' => $message,
        'priority' => $priority,
    ]);
}
