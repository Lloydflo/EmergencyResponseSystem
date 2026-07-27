<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('GET');
$userId = op_query_int('user_id');
op_require_positive($userId, 'user_id');

try {
    $pdo = db();
    op_require_active_responder($pdo, $userId);
    touch_user_presence($pdo, $userId);

    $statement = $pdo->prepare(
        'SELECT gt.id, gt.name, '
        . 'CASE WHEN gm.id IS NULL THEN 0 ELSE 1 END AS is_member, '
        . 'CASE WHEN req.id IS NULL THEN 0 ELSE 1 END AS request_pending, '
        . 'COALESCE(r.last_read_id, 0) AS last_read_id, '
        . '(SELECT latest.id FROM interagency_groups_threads_read latest '
        . ' WHERE latest.group_id = gt.id ORDER BY latest.id DESC LIMIT 1) AS latest_message_id, '
        . '(SELECT latest.message_details FROM interagency_groups_threads_read latest '
        . ' WHERE latest.group_id = gt.id ORDER BY latest.id DESC LIMIT 1) AS latest_message_details, '
        . '(SELECT latest.created_at FROM interagency_groups_threads_read latest '
        . ' WHERE latest.group_id = gt.id ORDER BY latest.id DESC LIMIT 1) AS latest_message_at, '
        . '(SELECT COUNT(*) FROM interagency_groups_threads_read unread '
        . ' WHERE unread.group_id = gt.id '
        . ' AND unread.id > COALESCE(r.last_read_id, 0) '
        . ' AND unread.sender_user_id <> CAST(? AS CHAR) '
        . ' AND unread.sender_user_id <> COALESCE((SELECT me.name FROM users me WHERE me.id = ? LIMIT 1), \'\')) AS unread_count '
        . 'FROM interagency_group_threads gt '
        . 'LEFT JOIN interagency_group_members gm '
        . ' ON gm.group_id = gt.id AND gm.user_id = ? AND gm.is_active = 1 '
        . 'LEFT JOIN interagency_group_member_requests req '
        . " ON req.group_id = gt.id AND req.requested_user_id = ? AND req.status = 'pending' "
        . 'LEFT JOIN interagency_group_thread_reads r '
        . ' ON r.group_id = gt.id AND r.user_id = ? '
        . 'WHERE gt.is_active = 1 ORDER BY gt.name ASC'
    );
    $statement->execute([$userId, $userId, $userId, $userId, $userId]);

    $groups = [];
    foreach (op_fetch_all($statement) as $row) {
        $isMember = (int)$row['is_member'] === 1;
        $requestPending = (int)$row['request_pending'] === 1;
        $latestText = '';
        if ($isMember && !empty($row['latest_message_details'])) {
            $details = json_decode((string)$row['latest_message_details'], true);
            if (is_array($details)) {
                $latestText = trim((string)($details['text'] ?? ''));
                if ($latestText === '' && !empty($details['attachments'][0])) {
                    $attachment = $details['attachments'][0];
                    $isImage = (int)($attachment['is_image'] ?? 0) === 1;
                    $latestText = $isImage
                        ? '📷 Image'
                        : '📎 ' . trim((string)($attachment['name'] ?? $attachment['file_name'] ?? 'File'));
                }
            }
        }
        $latestText = preg_replace('/^\[ROUTINE\]\s*/', '', $latestText) ?? $latestText;
        $latestAt = (string)($row['latest_message_at'] ?? '');

        $groups[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'displayName' => (string)$row['name'],
            'isMember' => $isMember,
            'requestPending' => $requestPending,
            'lastMessage' => $isMember
                ? ($latestText !== '' ? $latestText : 'Tap to open group chat')
                : ($requestPending ? 'Request pending approval' : 'Request access to join'),
            'lastMessageTime' => $isMember && $latestAt !== '' ? strtotime($latestAt) * 1000 : 0,
            'unreadCount' => $isMember ? max(0, (int)$row['unread_count']) : 0,
            'lastReadId' => (int)$row['last_read_id'],
            'latestMessageId' => (int)($row['latest_message_id'] ?? 0),
        ];
    }

    op_success(['groups' => $groups]);
} catch (Throwable $error) {
    error_log('get-interagency-groups: ' . $error->getMessage());
    op_error('Unable to load department channels.', 500);
}
