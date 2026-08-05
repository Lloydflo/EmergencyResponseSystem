<?php
/**
 * Structured operational audit helper.
 *
 * Existing callers may continue using log_activity_event(). New workflow code
 * should call record_operational_audit_event() when it already has a PDO
 * connection so the audit entry can participate in the same transaction.
 */

if (!function_exists('ensure_activity_log_auto_increment')) {
    function ensure_activity_log_auto_increment(PDO $pdo): void
    {
        static $checked = [];
        $key = spl_object_id($pdo);
        if (isset($checked[$key])) {
            return;
        }
        $checked[$key] = true;

        // DDL can implicitly commit an active workflow transaction in MySQL.
        // During transactional writes, rely on the manual-id fallback instead.
        if ($pdo->inTransaction()) {
            return;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM activity_log LIKE 'id'");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
            if ($row && strpos($extra, 'auto_increment') === false) {
                $pdo->exec("ALTER TABLE activity_log MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
            }
        } catch (Throwable $e) {
            // Legacy installations are handled by the manual-id fallback.
        }
    }
}

if (!function_exists('activity_log_needs_manual_id_fallback')) {
    function activity_log_needs_manual_id_fallback(string $message): bool
    {
        return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
            || strpos($message, "Field 'id' doesn't have a default value") !== false
            || strpos($message, "Field 'id' doesn't have a default") !== false;
    }
}

if (!function_exists('ers_audit_columns')) {
    /** @return array<string,bool> */
    function ers_audit_columns(PDO $pdo): array
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $columns = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM activity_log');
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                $name = (string)($row['Field'] ?? $row['field'] ?? '');
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
        } catch (Throwable $e) {
            // The eventual insert will surface the underlying database issue.
        }
        return $cache[$key] = $columns;
    }
}

if (!function_exists('ers_audit_table_exists')) {
    function ers_audit_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            return $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('ers_audit_request_id')) {
    function ers_audit_request_id(): string
    {
        static $requestId = null;
        if ($requestId !== null) {
            return $requestId;
        }
        $header = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($header !== '' && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $header)) {
            return $requestId = substr($header, 0, 64);
        }
        try {
            return $requestId = 'req_' . bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            return $requestId = 'req_' . str_replace('.', '', uniqid('', true));
        }
    }
}

if (!function_exists('ers_audit_clean_scalar')) {
    function ers_audit_clean_scalar($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        $text = trim((string)$value);
        if (strlen($text) > 2000) {
            $text = substr($text, 0, 2000) . '…';
        }
        return $text;
    }
}

if (!function_exists('ers_audit_sanitize_metadata')) {
    /** @return array<string,mixed> */
    function ers_audit_sanitize_metadata(array $metadata, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['notice' => 'Nested metadata omitted'];
        }
        $sensitive = [
            'password', 'pass', 'password_hash', 'otp', 'token', 'access_token',
            'refresh_token', 'api_key', 'apikey', 'authorization', 'cookie',
            'session', 'session_id', 'secret', 'private_key', 'firebase_token',
        ];
        $clean = [];
        $count = 0;
        foreach ($metadata as $key => $value) {
            if ($count >= 80) {
                $clean['_truncated'] = true;
                break;
            }
            $name = substr((string)$key, 0, 100);
            $normalized = strtolower(str_replace(['-', ' '], '_', $name));
            if (in_array($normalized, $sensitive, true)) {
                $clean[$name] = '[REDACTED]';
                $count++;
                continue;
            }
            if (is_array($value)) {
                $clean[$name] = ers_audit_sanitize_metadata($value, $depth + 1);
            } elseif (is_object($value)) {
                $clean[$name] = ers_audit_sanitize_metadata((array)$value, $depth + 1);
            } else {
                $clean[$name] = ers_audit_clean_scalar($value);
            }
            $count++;
        }
        return $clean;
    }
}

if (!function_exists('ers_audit_actor_snapshot')) {
    /** @return array{name:string,email:string,role:string} */
    function ers_audit_actor_snapshot(PDO $pdo, ?int $userId, array $context): array
    {
        $name = trim((string)($context['actor_name'] ?? ''));
        $email = trim((string)($context['actor_email'] ?? ''));
        $role = strtolower(trim((string)($context['actor_role'] ?? '')));

        if ($userId !== null && $userId > 0 && ($name === '' || $email === '' || $role === '')) {
            try {
                $stmt = $pdo->prepare('SELECT name, email, role FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $name = $name !== '' ? $name : trim((string)($row['name'] ?? ''));
                $email = $email !== '' ? $email : trim((string)($row['email'] ?? ''));
                $role = $role !== '' ? $role : strtolower(trim((string)($row['role'] ?? '')));
            } catch (Throwable $e) {
                // Snapshot remains best effort.
            }
        }

        if ($userId !== null && $userId > 0) {
            $name = $name !== '' ? $name : trim((string)($_SESSION['user_name'] ?? 'User'));
            $email = $email !== '' ? $email : trim((string)($_SESSION['user_email'] ?? ''));
            $role = $role !== '' ? $role : strtolower(trim((string)(
                $_SESSION['login_role'] ?? $_SESSION['user_role'] ?? 'user'
            )));
        }

        if ($name === '') {
            $name = 'System';
        }
        if ($role === '') {
            $role = $userId ? 'user' : 'system';
        }
        if ($role === 'operator') {
            $role = 'dispatcher';
        }

        return [
            'name' => substr($name, 0, 150),
            'email' => substr($email, 0, 150),
            'role' => substr($role, 0, 32),
        ];
    }
}

if (!function_exists('ers_audit_infer_source')) {
    function ers_audit_infer_source(string $role, string $action, array $context): string
    {
        $explicit = strtolower(trim((string)($context['source_channel'] ?? $context['source'] ?? '')));
        if ($explicit !== '') {
            return substr($explicit, 0, 32);
        }

        $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
        if ($role === 'responder' || strpos($script, '/api/api_app/') !== false) {
            return 'responder_app';
        }
        if ($role === 'dispatcher' || strpos($script, '/dispatcher/') !== false) {
            return 'dispatcher_web';
        }
        if ($role === 'admin' || strpos($script, '/admin/') !== false) {
            return 'admin_web';
        }
        if (strpos($script, '/api/system_api/') !== false || strpos($action, 'external') !== false) {
            return 'external_api';
        }
        if ($role === 'system') {
            return 'system';
        }
        return 'server_api';
    }
}

if (!function_exists('ers_audit_infer_category')) {
    function ers_audit_infer_category(string $action, string $entityType, array $context): string
    {
        $explicit = strtolower(trim((string)($context['event_category'] ?? $context['category'] ?? '')));
        if ($explicit !== '') {
            return substr($explicit, 0, 32);
        }
        $haystack = strtolower($action . ' ' . $entityType);
        $map = [
            'authentication' => ['login', 'logout', 'auth', 'otp', 'session'],
            'call_intake' => ['call', 'intake', 'hotline'],
            'dispatch' => ['dispatch', 'allocation'],
            // Workflow actions take priority over the generic assignment entity.
            'navigation' => ['navigation', 'navigate', 'en_route', 'enroute', 'route'],
            'arrival' => ['arrived', 'arrival', 'on_scene'],
            'completion' => ['complete', 'resolved', 'cleared', 'closure'],
            'report_review' => ['after_action', 'report', 'review', 'approval', 'verified'],
            'resource' => ['resource', 'backup', 'equipment', 'supply'],
            'coordination' => ['chat', 'message', 'broadcast', 'coordination', 'interagency'],
            'presence' => ['presence', 'online', 'offline', 'location'],
            'assignment' => ['assignment', 'acknowledg', 'received'],
            'incident' => ['incident', 'priority', 'triage'],
            'administration' => ['user', 'account', 'settings', 'admin'],
        ];
        foreach ($map as $category => $needles) {
            foreach ($needles as $needle) {
                if (strpos($haystack, $needle) !== false) {
                    return $category;
                }
            }
        }
        return 'system';
    }
}

if (!function_exists('ers_audit_infer_outcome')) {
    function ers_audit_infer_outcome(string $action, array $context): string
    {
        $explicit = strtolower(trim((string)($context['event_outcome'] ?? $context['outcome'] ?? '')));
        if (in_array($explicit, ['success', 'failed', 'warning', 'info'], true)) {
            return $explicit;
        }
        $action = strtolower($action);
        foreach (['failed', 'failure', 'error', 'rejected', 'declined', 'denied'] as $needle) {
            if (strpos($action, $needle) !== false) {
                return 'failed';
            }
        }
        foreach (['cancelled', 'canceled', 'returned', 'warning'] as $needle) {
            if (strpos($action, $needle) !== false) {
                return 'warning';
            }
        }
        return 'success';
    }
}

if (!function_exists('ers_audit_reference_no')) {
    function ers_audit_reference_no(
        PDO $pdo,
        string $entityType,
        ?int $entityId,
        array $context
    ): string {
        $reference = trim((string)($context['reference_no'] ?? $context['incident_reference'] ?? ''));
        if ($reference !== '') {
            return substr($reference, 0, 64);
        }

        $incidentId = (int)($context['incident_id'] ?? 0);
        $callId = (int)($context['call_id'] ?? 0);
        $dispatchId = (int)($context['dispatch_id'] ?? 0);
        $assignmentId = (int)($context['assignment_id'] ?? 0);
        $reportId = (int)($context['report_id'] ?? 0);

        $entity = strtolower($entityType);
        if ($incidentId <= 0 && $entityId && in_array($entity, ['incident', 'navigation', 'route', 'arrival'], true)) {
            $incidentId = $entityId;
        }
        if ($callId <= 0 && $entityId && $entity === 'call') {
            $callId = $entityId;
        }
        if ($dispatchId <= 0 && $entityId && $entity === 'dispatch') {
            $dispatchId = $entityId;
        }
        if ($assignmentId <= 0 && $entityId && in_array($entity, ['assignment', 'dispatch_assignment'], true)) {
            $assignmentId = $entityId;
        }
        if ($reportId <= 0 && $entityId && in_array($entity, ['after_action_report', 'report'], true)) {
            $reportId = $entityId;
        }

        try {
            if ($incidentId > 0 && ers_audit_table_exists($pdo, 'incidents')) {
                $stmt = $pdo->prepare('SELECT reference_no FROM incidents WHERE id = ? LIMIT 1');
                $stmt->execute([$incidentId]);
                $reference = trim((string)$stmt->fetchColumn());
            } elseif ($callId > 0 && ers_audit_table_exists($pdo, 'calls')) {
                $stmt = $pdo->prepare('SELECT reference_no FROM calls WHERE id = ? LIMIT 1');
                $stmt->execute([$callId]);
                $reference = trim((string)$stmt->fetchColumn());
            } elseif ($dispatchId > 0 && ers_audit_table_exists($pdo, 'dispatches')) {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(NULLIF(d.reference_no, \'\'), i.reference_no) '
                    . 'FROM dispatches d LEFT JOIN incidents i ON i.id = d.incident_id '
                    . 'WHERE d.id = ? LIMIT 1'
                );
                $stmt->execute([$dispatchId]);
                $reference = trim((string)$stmt->fetchColumn());
            } elseif ($assignmentId > 0 && ers_audit_table_exists($pdo, 'dispatch_operator_records')) {
                $stmt = $pdo->prepare(
                    'SELECT i.reference_no FROM dispatch_operator_records dor '
                    . 'LEFT JOIN incidents i ON i.id = dor.incident_id '
                    . 'WHERE dor.id = ? LIMIT 1'
                );
                $stmt->execute([$assignmentId]);
                $reference = trim((string)$stmt->fetchColumn());
            } elseif ($reportId > 0 && ers_audit_table_exists($pdo, 'responder_after_action_reports')) {
                $stmt = $pdo->prepare(
                    'SELECT i.reference_no FROM responder_after_action_reports aar '
                    . 'LEFT JOIN incidents i ON i.id = aar.incident_id '
                    . 'WHERE aar.id = ? LIMIT 1'
                );
                $stmt->execute([$reportId]);
                $reference = trim((string)$stmt->fetchColumn());
            }
        } catch (Throwable $e) {
            $reference = '';
        }

        return substr($reference, 0, 64);
    }
}

if (!function_exists('ers_audit_valid_datetime')) {
    function ers_audit_valid_datetime($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ers_audit_normalize_operational_datetime')) {
    /**
     * Normalize a browser/app timestamp into an Asia/Manila database datetime.
     * Values outside a bounded operational window are rejected so a modified
     * client clock cannot arbitrarily backdate or future-date audit records.
     */
    function ers_audit_normalize_operational_datetime(
        $value,
        bool $defaultToNow = false,
        int $maxPastDays = 30,
        int $maxFutureSeconds = 300
    ): ?string {
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $timezone);
        $fallback = $defaultToNow ? $now->format('Y-m-d H:i:s') : null;

        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            if (
                is_int($value)
                || is_float($value)
                || (is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', trim($value)))
            ) {
                $numeric = (float)$value;
                if ($numeric > 100000000000.0) {
                    $numeric /= 1000.0;
                }
                if (!is_finite($numeric) || $numeric <= 0) {
                    return $fallback;
                }
                $candidate = (new DateTimeImmutable('@' . (string)(int)floor($numeric)))
                    ->setTimezone($timezone);
            } else {
                $candidate = new DateTimeImmutable(trim((string)$value), $timezone);
                $candidate = $candidate->setTimezone($timezone);
            }

            $candidateEpoch = $candidate->getTimestamp();
            $nowEpoch = $now->getTimestamp();
            $maxPastSeconds = max(1, $maxPastDays) * 86400;
            if (
                $candidateEpoch < ($nowEpoch - $maxPastSeconds)
                || $candidateEpoch > ($nowEpoch + max(0, $maxFutureSeconds))
            ) {
                return $fallback;
            }
            return $candidate->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return $fallback;
        }
    }
}

if (!function_exists('record_operational_audit_event')) {
    /**
     * @param array<string,mixed> $context
     * @return int|null Inserted (or deduplicated) activity-log id.
     */
    function record_operational_audit_event(
        PDO $pdo,
        ?int $userId,
        string $action,
        string $entityType = 'system',
        ?int $entityId = null,
        string $details = '',
        array $context = []
    ): ?int {
        $action = substr(trim($action), 0, 64);
        $entityType = substr(trim($entityType) !== '' ? trim($entityType) : 'system', 0, 64);
        $details = trim($details);
        $userId = ($userId !== null && $userId > 0) ? $userId : null;
        $entityId = ($entityId !== null && $entityId > 0) ? $entityId : null;
        if ($action === '') {
            return null;
        }

        try {
            ensure_activity_log_auto_increment($pdo);
            $columns = ers_audit_columns($pdo);
            if (!$columns || !isset($columns['action'])) {
                return null;
            }

            $actor = ers_audit_actor_snapshot($pdo, $userId, $context);
            $source = ers_audit_infer_source($actor['role'], $action, $context);
            $category = ers_audit_infer_category($action, $entityType, $context);
            $outcome = ers_audit_infer_outcome($action, $context);
            $reference = ers_audit_reference_no($pdo, $entityType, $entityId, $context);
            $metadata = isset($context['metadata']) && is_array($context['metadata'])
                ? ers_audit_sanitize_metadata($context['metadata'])
                : [];
            $eventKey = substr(trim((string)($context['event_key'] ?? '')), 0, 160);

            if ($details === '') {
                $details = ucwords(str_replace(['_', '-'], ' ', $action));
            }

            if ($eventKey !== '' && isset($columns['event_key'])) {
                $existing = $pdo->prepare('SELECT id FROM activity_log WHERE event_key = ? LIMIT 1');
                $existing->execute([$eventKey]);
                $existingId = (int)$existing->fetchColumn();
                if ($existingId > 0) {
                    return $existingId;
                }
            }

            $valuesByColumn = [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'actor_name' => $actor['name'],
                'actor_email' => $actor['email'],
                'actor_role' => $actor['role'],
                'source_channel' => $source,
                'event_category' => $category,
                'event_outcome' => $outcome,
                'reference_no' => $reference !== '' ? $reference : null,
                'metadata_json' => $metadata !== []
                    ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'ip_address' => substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
                'user_agent' => substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
                'request_id' => ers_audit_request_id(),
                'event_key' => $eventKey !== '' ? $eventKey : null,
            ];

            $insertColumns = [];
            $params = [];
            foreach ($valuesByColumn as $column => $value) {
                if (isset($columns[$column])) {
                    $insertColumns[] = '`' . $column . '`';
                    $params[] = $value;
                }
            }

            $occurredAt = ers_audit_valid_datetime($context['occurred_at'] ?? null);
            if ($occurredAt !== null && isset($columns['created_at'])) {
                $insertColumns[] = '`created_at`';
                $params[] = $occurredAt;
            }

            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $sql = 'INSERT INTO activity_log (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                $message = (string)$e->getMessage();
                if ($eventKey !== '' && isset($columns['event_key']) && strpos($message, 'Duplicate entry') !== false) {
                    $existing = $pdo->prepare('SELECT id FROM activity_log WHERE event_key = ? LIMIT 1');
                    $existing->execute([$eventKey]);
                    $existingId = (int)$existing->fetchColumn();
                    return $existingId > 0 ? $existingId : null;
                }
                if (!activity_log_needs_manual_id_fallback($message)) {
                    throw $e;
                }

                $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log')->fetchColumn();
                array_unshift($insertColumns, '`id`');
                array_unshift($params, $nextId);
                $fallbackSql = 'INSERT INTO activity_log (' . implode(', ', $insertColumns) . ') VALUES ('
                    . implode(', ', array_fill(0, count($insertColumns), '?')) . ')';
                $fallback = $pdo->prepare($fallbackSql);
                $fallback->execute($params);
                return $nextId;
            }
        } catch (Throwable $e) {
            error_log('Operational audit write failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('log_operational_event')) {
    /** @param array<string,mixed> $context */
    function log_operational_event(
        ?int $userId,
        string $action,
        string $entityType = 'system',
        ?int $entityId = null,
        string $details = '',
        array $context = []
    ): bool {
        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if (!$pdo instanceof PDO) {
            return false;
        }
        return record_operational_audit_event(
            $pdo,
            $userId,
            $action,
            $entityType,
            $entityId,
            $details,
            $context
        ) !== null;
    }
}

if (!function_exists('log_activity_event')) {
    function log_activity_event(
        ?int $userId,
        string $action,
        string $entityType = 'system',
        ?int $entityId = null,
        string $details = ''
    ): bool {
        return log_operational_event($userId, $action, $entityType, $entityId, $details);
    }
}
