<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/user_account_cleanup.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

function admin_users_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function admin_users_normalize_role(?string $role): string
{
    $value = strtolower(trim((string)$role));
    $value = str_replace(['-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value ?? '');
    $value = trim((string)$value);

    if ($value === 'dispatch' || $value === 'dispatch operator' || $value === 'operator') {
        return 'dispatcher';
    }

    if ($value === 'responder') {
        return 'responder';
    }

    if ($value === 'admin' || $value === 'administrator') {
        return 'admin';
    }

    return $value;
}

function admin_users_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function admin_users_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function admin_users_get_role_column_type(PDO $pdo): ?string
{
    return admin_users_get_column_type($pdo, 'users', 'role');
}

function admin_users_get_column_type(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : null;
}

function admin_users_get_column_extra(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : null;
}

function admin_users_has_primary_key(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_TYPE = ?
         LIMIT 1'
    );
    $stmt->execute([$table, 'PRIMARY KEY']);
    return (bool)$stmt->fetchColumn();
}

function admin_users_ensure_schema(PDO $pdo): void
{
    if (!admin_users_has_primary_key($pdo, 'users')) {
        $pdo->exec("ALTER TABLE `users` ADD PRIMARY KEY (`id`)");
    }

    $idExtra = admin_users_get_column_extra($pdo, 'users', 'id');
    if ($idExtra === null || stripos($idExtra, 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE `users` MODIFY `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
    }

    if (!admin_users_has_column($pdo, 'users', 'department')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `department` VARCHAR(150) DEFAULT NULL AFTER `name`");
    }

    if (!admin_users_has_column($pdo, 'users', 'unit_code')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `unit_code` VARCHAR(50) DEFAULT NULL AFTER `department`");
    }
    if (!admin_users_has_column($pdo, 'users', 'unit_type')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `unit_type` VARCHAR(50) DEFAULT NULL AFTER `unit_code`");
    }
    if (!admin_users_has_column($pdo, 'users', 'vehicle_plate')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `vehicle_plate` VARCHAR(50) DEFAULT NULL AFTER `unit_type`");
    }
    if (!admin_users_has_column($pdo, 'users', 'unit_status')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `unit_status` VARCHAR(50) DEFAULT NULL AFTER `vehicle_plate`");
    }

    ers_ensure_user_inactive_cleanup_schema($pdo);
    ers_backfill_inactive_user_dates($pdo);

    $roleColumnType = admin_users_get_role_column_type($pdo);
    if ($roleColumnType !== null && (stripos($roleColumnType, "'dispatcher'") === false || stripos($roleColumnType, "'responder'") === false)) {
        $pdo->exec(
            "ALTER TABLE `users`
             MODIFY `role` ENUM('admin','operator','viewer','dispatcher','responder') NOT NULL DEFAULT 'viewer'"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `responders` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `department` ENUM('fire','police','medical','barangay','other') NOT NULL DEFAULT 'other',
            `email` VARCHAR(255) NOT NULL,
            `contact_number` VARCHAR(50) NOT NULL DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_responders_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!admin_users_has_column($pdo, 'responders', 'contact_number')) {
        $pdo->exec("ALTER TABLE `responders` ADD COLUMN `contact_number` VARCHAR(50) NOT NULL DEFAULT '' AFTER `email`");
    } elseif (stripos(admin_users_get_column_type($pdo, 'responders', 'contact_number') ?? '', 'varchar(50)') === false) {
        $pdo->exec("ALTER TABLE `responders` MODIFY `contact_number` VARCHAR(50) DEFAULT NULL");
    }

    if (!admin_users_has_column($pdo, 'responders', 'assigned_unit_id')) {
        $pdo->exec("ALTER TABLE `responders` ADD COLUMN `assigned_unit_id` BIGINT UNSIGNED DEFAULT NULL AFTER `contact_number`");
    }
}

function admin_users_responder_department(string $department, string $role): string
{
    $value = strtolower($department . ' ' . $role);

    if (strpos($value, 'fire') !== false || strpos($value, 'bfp') !== false) {
        return 'fire';
    }
    if (strpos($value, 'police') !== false || strpos($value, 'pnp') !== false) {
        return 'police';
    }
    if (
        strpos($value, 'medical') !== false ||
        strpos($value, 'medic') !== false ||
        strpos($value, 'ems') !== false ||
        strpos($value, 'ambulance') !== false ||
        strpos($value, 'health') !== false ||
        strpos($value, 'nurse') !== false ||
        strpos($value, 'emt') !== false
    ) {
        return 'medical';
    }
    if (strpos($value, 'barangay') !== false || strpos($value, 'tanod') !== false) {
        return 'barangay';
    }

    return 'other';
}

function admin_users_responder_status_value(PDO $pdo, string $status): string
{
    $statusType = admin_users_get_column_type($pdo, 'responders', 'status') ?? '';

    if (stripos($statusType, "'Available'") !== false || stripos($statusType, "'Offline'") !== false) {
        return $status === 'active' ? 'Available' : 'Offline';
    }

    return $status;
}

function admin_users_normalize_assigned_unit_id($value): ?int
{
    $unitId = (int)$value;
    return $unitId > 0 ? $unitId : null;
}

function admin_users_available_unit_exists(PDO $pdo, int $unitId): bool
{
    if ($unitId <= 0 || !admin_users_table_exists($pdo, 'units')) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM `units`
         WHERE `id` = ?
           AND LOWER(COALESCE(`status`, '')) = 'available'
         LIMIT 1"
    );
    $stmt->execute([$unitId]);
    return (bool)$stmt->fetchColumn();
}

function admin_users_unit_identifier(PDO $pdo, int $unitId): string
{
    if ($unitId <= 0 || !admin_users_table_exists($pdo, 'units')) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT COALESCE(`identifier`, \'\') FROM `units` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$unitId]);
    return trim((string)$stmt->fetchColumn());
}

function admin_users_unit_id_for_identifier(PDO $pdo, string $identifier): ?int
{
    $identifier = trim($identifier);
    if ($identifier === '' || !admin_users_table_exists($pdo, 'units')) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT `id`
         FROM `units`
         WHERE UPPER(TRIM(`identifier`)) = UPPER(TRIM(?))
         LIMIT 1"
    );
    $stmt->execute([$identifier]);
    $unitId = (int)$stmt->fetchColumn();

    return $unitId > 0 ? $unitId : null;
}

function admin_users_unit_has_other_responder_assignment(
    PDO $pdo,
    int $unitId,
    ?int $currentUserId = null,
    ?string $currentEmail = null
): bool {
    if ($unitId <= 0) {
        return false;
    }

    $unitIdentifier = admin_users_unit_identifier($pdo, $unitId);
    if (
        $unitIdentifier !== '' &&
        admin_users_table_exists($pdo, 'users') &&
        admin_users_has_column($pdo, 'users', 'role') &&
        admin_users_has_column($pdo, 'users', 'unit_code')
    ) {
        $params = [$unitIdentifier];
        $currentUserFilter = '';
        if ($currentUserId !== null && $currentUserId > 0 && admin_users_has_column($pdo, 'users', 'id')) {
            $currentUserFilter = ' AND `id` <> ?';
            $params[] = $currentUserId;
        }

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM `users`
             WHERE LOWER(COALESCE(`role`, '')) = 'responder'
               AND `unit_code` IS NOT NULL
               AND TRIM(`unit_code`) <> ''
               AND UPPER(TRIM(`unit_code`)) = UPPER(TRIM(?))
               {$currentUserFilter}
             LIMIT 1"
        );
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    if (
        admin_users_table_exists($pdo, 'responders') &&
        admin_users_has_column($pdo, 'responders', 'assigned_unit_id')
    ) {
        if (!admin_users_has_column($pdo, 'responders', 'email')) {
            $stmt = $pdo->prepare('SELECT 1 FROM `responders` WHERE `assigned_unit_id` = ? LIMIT 1');
            $stmt->execute([$unitId]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare('SELECT COALESCE(`email`, \'\') AS `email` FROM `responders` WHERE `assigned_unit_id` = ?');
        $stmt->execute([$unitId]);
        $normalizedCurrentEmail = strtolower(trim((string)$currentEmail));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($normalizedCurrentEmail === '' || $email === '' || $email !== $normalizedCurrentEmail) {
                return true;
            }
        }
    }

    return false;
}

function admin_users_unit_assignable_to_responder(
    PDO $pdo,
    int $unitId,
    ?int $currentUserId = null,
    ?string $currentEmail = null,
    ?int $currentAssignedUnitId = null
): bool {
    if ($unitId <= 0 || admin_users_unit_has_other_responder_assignment($pdo, $unitId, $currentUserId, $currentEmail)) {
        return false;
    }

    if ($currentAssignedUnitId !== null && $currentAssignedUnitId === $unitId) {
        return true;
    }

    return admin_users_available_unit_exists($pdo, $unitId);
}

function admin_users_vehicle_resource_table(PDO $pdo): ?string
{
    if (admin_users_table_exists($pdo, 'resource_records')) {
        return 'resource_records';
    }
    if (admin_users_table_exists($pdo, 'admin_resources')) {
        return 'admin_resources';
    }
    return null;
}

function admin_users_empty_unit_assignment(): array
{
    return [
        'unit_code' => null,
        'unit_type' => null,
        'vehicle_plate' => null,
        'unit_status' => null,
    ];
}

function admin_users_unit_assignment_overrides(array $input): array
{
    $overrides = [];
    foreach (['unit_code', 'unit_type', 'vehicle_plate', 'unit_status'] as $key) {
        if (array_key_exists($key, $input)) {
            $value = trim((string)($input[$key] ?? ''));
            $overrides[$key] = $value !== '' ? $value : null;
        }
    }
    return $overrides;
}

function admin_users_apply_unit_assignment_overrides(array $assignment, array $input): array
{
    foreach (admin_users_unit_assignment_overrides($input) as $key => $value) {
        $assignment[$key] = $value;
    }
    return $assignment;
}

function admin_users_fetch_unit_assignment(PDO $pdo, ?int $unitId): array
{
    if ($unitId === null || $unitId <= 0 || !admin_users_table_exists($pdo, 'units')) {
        return admin_users_empty_unit_assignment();
    }

    $resourceTable = admin_users_vehicle_resource_table($pdo);
    $resourceJoin = '';
    $plateSelect = 'NULL AS vehicle_plate';
    if ($resourceTable !== null) {
        $resourceJoin = " LEFT JOIN `" . $resourceTable . "` rr ON rr.code = u.identifier AND LOWER(rr.category) = 'vehicles'";
        $plateSelect = 'rr.plate_number AS vehicle_plate';
    }

    $stmt = $pdo->prepare(
        "SELECT u.identifier AS unit_code,
                u.unit_type,
                u.status AS unit_status,
                {$plateSelect}
         FROM `units` u
         {$resourceJoin}
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return admin_users_empty_unit_assignment();
    }

    return [
        'unit_code' => trim((string)($row['unit_code'] ?? '')) ?: null,
        'unit_type' => trim((string)($row['unit_type'] ?? '')) ?: null,
        'vehicle_plate' => trim((string)($row['vehicle_plate'] ?? '')) ?: null,
        'unit_status' => trim((string)($row['unit_status'] ?? '')) ?: null,
    ];
}

function admin_users_assign_unit_to_responder(PDO $pdo, ?int $unitId, string $name): void
{
    if ($unitId === null || $unitId <= 0 || $name === '' || !admin_users_table_exists($pdo, 'units')) {
        return;
    }

    $resourceTable = admin_users_vehicle_resource_table($pdo);
    if ($resourceTable === null) {
        return;
    }

    $sets = [];
    $params = [];
    if (admin_users_has_column($pdo, $resourceTable, 'assignment')) {
        $sets[] = 'rr.assignment = ?';
        $params[] = 'Assigned to ' . $name;
    }
    if (admin_users_has_column($pdo, $resourceTable, 'driver_name')) {
        $sets[] = 'rr.driver_name = ?';
        $params[] = $name;
    }
    if ($sets === []) {
        return;
    }
    $params[] = $unitId;

    $stmt = $pdo->prepare(
        "UPDATE `" . $resourceTable . "` rr
         INNER JOIN `units` u ON u.identifier = rr.code
         SET " . implode(', ', $sets) . "
         WHERE u.id = ?
           AND LOWER(rr.category) = 'vehicles'"
    );
    $stmt->execute($params);
}

function admin_users_clear_unit_responder_assignment(PDO $pdo, ?int $unitId): void
{
    if ($unitId === null || $unitId <= 0 || !admin_users_table_exists($pdo, 'units')) {
        return;
    }

    $resourceTable = admin_users_vehicle_resource_table($pdo);
    if ($resourceTable === null) {
        return;
    }

    $sets = [];
    if (admin_users_has_column($pdo, $resourceTable, 'assignment')) {
        $sets[] = 'rr.assignment = NULL';
    }
    if (admin_users_has_column($pdo, $resourceTable, 'driver_name')) {
        $sets[] = 'rr.driver_name = NULL';
    }
    if ($sets === []) {
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE `" . $resourceTable . "` rr
         INNER JOIN `units` u ON u.identifier = rr.code
         SET " . implode(', ', $sets) . "
         WHERE u.id = ?
           AND LOWER(rr.category) = 'vehicles'"
    );
    $stmt->execute([$unitId]);
}

function admin_users_clear_unit_responder_assignment_by_identifier(PDO $pdo, string $unitCode): void
{
    $unitCode = trim($unitCode);
    if ($unitCode === '') {
        return;
    }

    $resourceTable = admin_users_vehicle_resource_table($pdo);
    if ($resourceTable === null) {
        return;
    }

    $sets = [];
    if (admin_users_has_column($pdo, $resourceTable, 'assignment')) {
        $sets[] = '`assignment` = NULL';
    }
    if (admin_users_has_column($pdo, $resourceTable, 'driver_name')) {
        $sets[] = '`driver_name` = NULL';
    }
    if (admin_users_has_column($pdo, $resourceTable, 'status')) {
        $sets[] = "`status` = 'available'";
    }
    if (admin_users_has_column($pdo, $resourceTable, 'updated_at')) {
        $sets[] = '`updated_at` = CURRENT_TIMESTAMP';
    }
    if ($sets === []) {
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE `" . $resourceTable . "`
         SET " . implode(', ', $sets) . "
         WHERE UPPER(TRIM(`code`)) = UPPER(TRIM(?))
           AND LOWER(`category`) = 'vehicles'"
    );
    $stmt->execute([$unitCode]);
}

function admin_users_clear_stale_responder_unit_links(PDO $pdo, ?int $unitId, string $email): void
{
    if (
        $unitId === null ||
        $unitId <= 0 ||
        !admin_users_table_exists($pdo, 'responders') ||
        !admin_users_has_column($pdo, 'responders', 'assigned_unit_id')
    ) {
        return;
    }

    $sets = ['`assigned_unit_id` = NULL'];
    $params = [];
    if (admin_users_has_column($pdo, 'responders', 'status')) {
        $sets[] = '`status` = ?';
        $params[] = admin_users_responder_status_value($pdo, 'inactive');
    }

    $where = '`assigned_unit_id` = ?';
    $params[] = $unitId;
    if ($email !== '' && admin_users_has_column($pdo, 'responders', 'email')) {
        $where .= ' OR LOWER(TRIM(`email`)) = LOWER(TRIM(?))';
        $params[] = $email;
    }

    $stmt = $pdo->prepare(
        'UPDATE `responders`
         SET ' . implode(', ', $sets) . "
         WHERE {$where}"
    );
    $stmt->execute($params);
}

function admin_users_release_responder_vehicle_assignment(PDO $pdo, array $user): void
{
    $assignedUnitId = admin_users_normalize_assigned_unit_id($user['assigned_unit_id'] ?? null);
    $unitCode = trim((string)($user['unit_code'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));

    if ($assignedUnitId === null && $unitCode !== '') {
        $assignedUnitId = admin_users_unit_id_for_identifier($pdo, $unitCode);
    }
    if ($unitCode === '' && $assignedUnitId !== null) {
        $unitCode = admin_users_unit_identifier($pdo, $assignedUnitId);
    }

    if ($assignedUnitId !== null) {
        admin_users_clear_unit_responder_assignment($pdo, $assignedUnitId);
    }
    admin_users_clear_stale_responder_unit_links($pdo, $assignedUnitId, $email);

    if ($unitCode === '') {
        return;
    }

    admin_users_clear_unit_responder_assignment_by_identifier($pdo, $unitCode);
    ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, 'available');
    ers_update_unit_status_by_identifier($pdo, $unitCode, 'available');
}

function admin_users_sync_responder_record(
    PDO $pdo,
    string $name,
    string $email,
    string $role,
    string $department,
    string $contactNumber,
    string $status,
    ?int $assignedUnitId = null,
    ?string $oldEmail = null,
    ?string $passwordHash = null
): void {
    if (!admin_users_has_column($pdo, 'responders', 'email')) {
        return;
    }

    $responderDepartment = admin_users_responder_department($department, $role);
    $isActive = $status === 'active' ? 1 : 0;

    if ($oldEmail !== null && strcasecmp($oldEmail, $email) !== 0) {
        admin_users_delete_responder_record($pdo, $oldEmail);
    }

    $values = ['email' => $email];

    if (admin_users_has_column($pdo, 'responders', 'name')) {
        $values['name'] = $name;
    }
    if (admin_users_has_column($pdo, 'responders', 'full_name')) {
        $values['full_name'] = $name;
    }
    if (admin_users_has_column($pdo, 'responders', 'department')) {
        $values['department'] = $responderDepartment;
    }
    if (admin_users_has_column($pdo, 'responders', 'contact_number')) {
        $values['contact_number'] = $contactNumber;
    }
    if (admin_users_has_column($pdo, 'responders', 'assigned_unit_id')) {
        $values['assigned_unit_id'] = $role === 'responder' ? $assignedUnitId : null;
    }
    if (admin_users_has_column($pdo, 'responders', 'is_active')) {
        $values['is_active'] = $isActive;
    }
    if (admin_users_has_column($pdo, 'responders', 'status')) {
        $values['status'] = admin_users_responder_status_value($pdo, $status);
    }
    if (admin_users_has_column($pdo, 'responders', 'password')) {
        $values['password'] = $passwordHash ?: password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    $existingStmt = $pdo->prepare('SELECT `id` FROM `responders` WHERE LOWER(`email`) = LOWER(?) LIMIT 1');
    $existingStmt->execute([$email]);
    $existingId = $existingStmt->fetchColumn();

    if ($existingId) {
        $updateValues = $values;
        if ($passwordHash === null) {
            unset($updateValues['password']);
        }

        $sets = [];
        $params = [];
        foreach ($updateValues as $column => $value) {
            $sets[] = "`{$column}` = ?";
            $params[] = $value;
        }
        $params[] = (int)$existingId;

        $updateStmt = $pdo->prepare('UPDATE `responders` SET ' . implode(', ', $sets) . ' WHERE `id` = ?');
        $updateStmt->execute($params);
        return;
    }

    $columns = array_keys($values);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = '`' . implode('`, `', $columns) . '`';

    $insertStmt = $pdo->prepare("INSERT INTO `responders` ({$columnSql}) VALUES ({$placeholders})");
    $insertStmt->execute(array_values($values));
}

function admin_users_delete_responder_record(PDO $pdo, string $email): void
{
    $deleteStmt = $pdo->prepare('DELETE FROM `responders` WHERE LOWER(`email`) = LOWER(?)');
    $deleteStmt->execute([$email]);
}

function admin_users_responder_contact_map(PDO $pdo): array
{
    if (
        !admin_users_has_column($pdo, 'responders', 'email') ||
        !admin_users_has_column($pdo, 'responders', 'contact_number')
    ) {
        return [];
    }

    try {
        $rows = $pdo->query('SELECT `email`, `contact_number` FROM `responders`')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('admin_users responder contact map skipped: ' . $e->getMessage());
        return [];
    }

    $contacts = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '') {
            $contacts[$email] = (string)($row['contact_number'] ?? '');
        }
    }

    return $contacts;
}

function admin_users_responder_contact_for_email(PDO $pdo, string $email): string
{
    $contacts = admin_users_responder_contact_map($pdo);
    return $contacts[strtolower(trim($email))] ?? '';
}

function admin_users_responder_unit_map(PDO $pdo): array
{
    if (
        !admin_users_has_column($pdo, 'responders', 'email') ||
        !admin_users_has_column($pdo, 'responders', 'assigned_unit_id')
    ) {
        return [];
    }

    try {
        $rows = $pdo->query('SELECT `email`, `assigned_unit_id` FROM `responders`')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('admin_users responder unit map skipped: ' . $e->getMessage());
        return [];
    }

    $units = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '') {
            $units[$email] = isset($row['assigned_unit_id']) ? (int)$row['assigned_unit_id'] : 0;
        }
    }

    return $units;
}

function admin_users_responder_unit_for_email(PDO $pdo, string $email): ?int
{
    $units = admin_users_responder_unit_map($pdo);
    $value = $units[strtolower(trim($email))] ?? 0;
    return $value > 0 ? $value : null;
}

function admin_users_password_errors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'one lowercase letter';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'one number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'one special character';
    }

    return $errors;
}

function admin_users_fetch_rows(PDO $pdo): array
{
    $hasDepartment = admin_users_has_column($pdo, 'users', 'department');
    $departmentSelect = $hasDepartment ? 'COALESCE(`department`, \'\') AS `department`' : '\'\' AS `department`';

    $sql = "
        SELECT
            `id`,
            `name`,
            `email`,
            `role`,
            `status`,
            COALESCE(`unit_code`, '') AS `unit_code`,
            COALESCE(`unit_type`, '') AS `unit_type`,
            COALESCE(`vehicle_plate`, '') AS `vehicle_plate`,
            COALESCE(`unit_status`, '') AS `unit_status`,
            {$departmentSelect},
            `created_at`
        FROM `users`
        WHERE LOWER(`role`) IN ('dispatcher', 'responder', 'operator')
        ORDER BY `created_at` DESC, `id` DESC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $contacts = admin_users_responder_contact_map($pdo);
    $assignedUnits = admin_users_responder_unit_map($pdo);

    return array_map(static function (array $row) use ($contacts, $assignedUnits): array {
        $created = (string)($row['created_at'] ?? '');
        if ($created !== '' && strlen($created) >= 10) {
            $created = substr($created, 0, 10);
        }

        $email = (string)($row['email'] ?? '');

        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'email' => $email,
            'role' => admin_users_normalize_role((string)($row['role'] ?? '')),
            'department' => (string)($row['department'] ?? ''),
            'contact_number' => $contacts[strtolower(trim($email))] ?? '',
            'assigned_unit_id' => ($assignedUnits[strtolower(trim($email))] ?? 0) > 0 ? (int)$assignedUnits[strtolower(trim($email))] : null,
            'unit_code' => (string)($row['unit_code'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'vehicle_plate' => (string)($row['vehicle_plate'] ?? ''),
            'unit_status' => (string)($row['unit_status'] ?? ''),
            'status' => (string)($row['status'] ?? 'inactive'),
            'created' => $created,
        ];
    }, $rows);
}

function admin_users_row_to_payload(array $row): array
{
    $created = (string)($row['created_at'] ?? '');
    if ($created !== '' && strlen($created) >= 10) {
        $created = substr($created, 0, 10);
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'role' => admin_users_normalize_role((string)($row['role'] ?? '')),
        'department' => (string)($row['department'] ?? ''),
        'contact_number' => (string)($row['contact_number'] ?? ''),
        'assigned_unit_id' => isset($row['assigned_unit_id']) && (int)$row['assigned_unit_id'] > 0 ? (int)$row['assigned_unit_id'] : null,
        'unit_code' => (string)($row['unit_code'] ?? ''),
        'unit_type' => (string)($row['unit_type'] ?? ''),
        'vehicle_plate' => (string)($row['vehicle_plate'] ?? ''),
        'unit_status' => (string)($row['unit_status'] ?? ''),
        'status' => (string)($row['status'] ?? 'inactive'),
        'created' => $created,
    ];
}

function admin_users_fetch_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            `id`,
            `name`,
            `email`,
            `role`,
            `status`,
            COALESCE(`unit_code`, '') AS `unit_code`,
            COALESCE(`unit_type`, '') AS `unit_type`,
            COALESCE(`vehicle_plate`, '') AS `vehicle_plate`,
            COALESCE(`unit_status`, '') AS `unit_status`,
            COALESCE(`department`, '') AS `department`,
            `created_at`
         FROM `users`
         WHERE `id` = ?
           AND LOWER(`role`) IN ('dispatcher', 'responder', 'operator')
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($row)) {
        $row['contact_number'] = admin_users_responder_contact_for_email($pdo, (string)($row['email'] ?? ''));
        $row['assigned_unit_id'] = admin_users_responder_unit_for_email($pdo, (string)($row['email'] ?? ''));
    }

    return is_array($row) ? $row : null;
}

if (!is_logged_in()) {
    admin_users_respond(401, [
        'success' => false,
        'message' => 'Login required.',
    ]);
}

if (current_session_role() !== 'admin') {
    admin_users_respond(403, [
        'success' => false,
        'message' => 'Admin access required.',
    ]);
}

$pdo = get_db_connection();
if (!$pdo) {
    admin_users_respond(500, [
        'success' => false,
        'message' => 'Database connection failed.',
    ]);
}

try {
    admin_users_ensure_schema($pdo);
    ers_purge_inactive_user_accounts($pdo);
} catch (Throwable $e) {
    error_log('admin_users schema error: ' . $e->getMessage());
    admin_users_respond(500, [
        'success' => false,
        'message' => 'Unable to prepare users table schema.',
    ]);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    try {
        ers_sync_responder_vehicle_resources($pdo);
        admin_users_respond(200, [
            'success' => true,
            'users' => admin_users_fetch_rows($pdo),
        ]);
    } catch (Throwable $e) {
        error_log('admin_users load error: ' . $e->getMessage());
        admin_users_respond(500, [
            'success' => false,
            'message' => 'Unable to load users.',
        ]);
    }
}

if ($method !== 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $role = admin_users_normalize_role((string)($input['role'] ?? ''));
        $department = trim((string)($input['department'] ?? ''));
        $contactNumber = trim((string)($input['contact_number'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'active')));
        $assignedUnitIdProvided = array_key_exists('assigned_unit_id', $input);
        $assignedUnitId = $role === 'responder' && $assignedUnitIdProvided
            ? admin_users_normalize_assigned_unit_id($input['assigned_unit_id'] ?? null)
            : null;

        if ($id <= 0 || $name === '' || $email === '' || $department === '' || $contactNumber === '') {
            admin_users_respond(422, [
                'success' => false,
                'message' => 'Please complete all required fields.',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            admin_users_respond(422, [
                'success' => false,
                'message' => 'Please enter a valid email address.',
            ]);
        }

        if (!in_array($role, ['dispatcher', 'responder'], true)) {
            admin_users_respond(422, [
                'success' => false,
                'message' => 'Invalid user role.',
            ]);
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            admin_users_respond(422, [
                'success' => false,
                'message' => 'Invalid account status.',
            ]);
        }

        try {
            $existing = admin_users_fetch_row($pdo, $id);
            if ($existing === null) {
                admin_users_respond(404, [
                    'success' => false,
                    'message' => 'User account not found.',
                ]);
            }

            $emailStmt = $pdo->prepare('SELECT `id` FROM `users` WHERE LOWER(`email`) = LOWER(?) AND `id` <> ? LIMIT 1');
            $emailStmt->execute([$email, $id]);
            if ($emailStmt->fetchColumn()) {
                admin_users_respond(409, [
                    'success' => false,
                    'message' => 'Email is already in use.',
                ]);
            }

            if ($role === 'responder' && !$assignedUnitIdProvided) {
                $assignedUnitId = admin_users_normalize_assigned_unit_id($existing['assigned_unit_id'] ?? null);
            }

            $previousAssignedUnitId = admin_users_normalize_assigned_unit_id($existing['assigned_unit_id'] ?? null);
            if (
                $assignedUnitIdProvided &&
                $assignedUnitId !== null &&
                !admin_users_unit_assignable_to_responder(
                    $pdo,
                    $assignedUnitId,
                    $id,
                    (string)($existing['email'] ?? ''),
                    $previousAssignedUnitId
                )
            ) {
                admin_users_respond(422, [
                    'success' => false,
                    'message' => 'Selected unit is already assigned to another responder or no longer available.',
                ]);
            }

            $shouldUpdateUserUnitFields = $role !== 'responder' || $assignedUnitIdProvided;
            $unitAssignment = $role === 'responder'
                ? admin_users_apply_unit_assignment_overrides(
                    admin_users_fetch_unit_assignment($pdo, $assignedUnitId),
                    $input
                )
                : admin_users_empty_unit_assignment();
            $inactiveSql = $status === 'inactive'
                ? "`inactive_at` = COALESCE(`inactive_at`, NOW())"
                : "`inactive_at` = NULL";

            $pdo->beginTransaction();

            $userSets = [
                '`name` = ?',
                '`email` = ?',
                '`department` = ?',
                '`role` = ?',
                '`status` = ?',
                $inactiveSql,
            ];
            $userParams = [$name, $email, $department, $role, $status];

            if ($shouldUpdateUserUnitFields) {
                $userSets[] = '`unit_code` = ?';
                $userSets[] = '`unit_type` = ?';
                $userSets[] = '`vehicle_plate` = ?';
                $userSets[] = '`unit_status` = ?';
                $userParams[] = $unitAssignment['unit_code'];
                $userParams[] = $unitAssignment['unit_type'];
                $userParams[] = $unitAssignment['vehicle_plate'];
                $userParams[] = $unitAssignment['unit_status'];
            }

            $userParams[] = $id;

            $updateStmt = $pdo->prepare(
                "UPDATE `users`
                 SET " . implode(",\n                     ", $userSets) . "
                 WHERE `id` = ?
                   AND LOWER(`role`) IN ('dispatcher', 'responder', 'operator')"
            );
            $updateStmt->execute($userParams);

            admin_users_sync_responder_record(
                $pdo,
                $name,
                $email,
                $role,
                $department,
                $contactNumber,
                $status,
                $assignedUnitId,
                (string)$existing['email']
            );
            if ($previousAssignedUnitId !== null && $previousAssignedUnitId !== $assignedUnitId) {
                admin_users_clear_unit_responder_assignment($pdo, $previousAssignedUnitId);
            }
            if ($role === 'responder') {
                admin_users_assign_unit_to_responder($pdo, $assignedUnitId, $name);
            }

            $pdo->commit();

            $row = admin_users_fetch_row($pdo, $id);
            if ($row === null) {
                throw new RuntimeException('Updated user could not be reloaded.');
            }

            admin_users_respond(200, [
                'success' => true,
                'message' => 'User account updated.',
                'user' => admin_users_row_to_payload($row),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin_users update error: ' . $e->getMessage());
            admin_users_respond(500, [
                'success' => false,
                'message' => 'Unable to update user.',
            ]);
        }
    }

    if ($method === 'DELETE') {
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            admin_users_respond(422, [
                'success' => false,
                'message' => 'Missing user id.',
            ]);
        }

        try {
            $existing = admin_users_fetch_row($pdo, $id);
            if ($existing === null) {
                admin_users_respond(404, [
                    'success' => false,
                    'message' => 'User account not found.',
                ]);
            }

            $pdo->beginTransaction();

            admin_users_release_responder_vehicle_assignment($pdo, $existing);

            $deleteStmt = $pdo->prepare(
                "DELETE FROM `users`
                 WHERE `id` = ?
                   AND LOWER(`role`) IN ('dispatcher', 'responder', 'operator')"
            );
            $deleteStmt->execute([$id]);

            if ($deleteStmt->rowCount() === 0) {
                $pdo->rollBack();
                admin_users_respond(404, [
                    'success' => false,
                    'message' => 'User account not found.',
                ]);
            }

            admin_users_delete_responder_record($pdo, (string)$existing['email']);
            $pdo->commit();

            admin_users_respond(200, [
                'success' => true,
                'message' => 'User account permanently deleted.',
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin_users delete error: ' . $e->getMessage());
            admin_users_respond(500, [
                'success' => false,
                'message' => 'Unable to delete user.',
            ]);
        }
    }

    admin_users_respond(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$role = admin_users_normalize_role((string)($input['role'] ?? ''));
$department = trim((string)($input['department'] ?? ''));
$contactNumber = trim((string)($input['contact_number'] ?? ''));
$status = strtolower(trim((string)($input['status'] ?? 'active')));
$passwordRequired = $role !== 'responder';
$assignedUnitId = $role === 'responder'
    ? admin_users_normalize_assigned_unit_id($input['assigned_unit_id'] ?? null)
    : null;

if ($name === '' || $email === '' || $department === '' || $contactNumber === '' || ($passwordRequired && $password === '')) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Please complete all required fields.',
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Please enter a valid email address.',
    ]);
}

if (!in_array($role, ['dispatcher', 'responder'], true)) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Invalid user role.',
    ]);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Invalid account status.',
    ]);
}

if ($assignedUnitId !== null && !admin_users_unit_assignable_to_responder($pdo, $assignedUnitId)) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Selected unit is already assigned to another responder or no longer available.',
    ]);
}

$passwordErrors = $password !== '' ? admin_users_password_errors($password) : [];
if ($passwordErrors !== []) {
    admin_users_respond(422, [
        'success' => false,
        'message' => 'Password must contain ' . implode(', ', $passwordErrors) . '.',
    ]);
}

try {
    $checkStmt = $pdo->prepare('SELECT `id` FROM `users` WHERE LOWER(`email`) = LOWER(?) LIMIT 1');
    $checkStmt->execute([$email]);
    if ($checkStmt->fetchColumn()) {
        admin_users_respond(409, [
            'success' => false,
            'message' => 'Email is already in use.',
        ]);
    }

    $passwordHash = $password !== ''
        ? password_hash($password, PASSWORD_DEFAULT)
        : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $unitAssignment = $role === 'responder'
        ? admin_users_apply_unit_assignment_overrides(
            admin_users_fetch_unit_assignment($pdo, $assignedUnitId),
            $input
        )
        : admin_users_empty_unit_assignment();

    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        'INSERT INTO `users` (`email`, `password`, `name`, `department`, `unit_code`, `unit_type`, `vehicle_plate`, `unit_status`, `role`, `status`, `inactive_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $email,
        $passwordHash,
        $name,
        $department,
        $unitAssignment['unit_code'],
        $unitAssignment['unit_type'],
        $unitAssignment['vehicle_plate'],
        $unitAssignment['unit_status'],
        $role,
        $status,
        $status === 'inactive' ? date('Y-m-d H:i:s') : null,
    ]);

    $userId = (int)$pdo->lastInsertId();

    admin_users_sync_responder_record($pdo, $name, $email, $role, $department, $contactNumber, $status, $assignedUnitId, null, $passwordHash);
    if ($role === 'responder') {
        admin_users_assign_unit_to_responder($pdo, $assignedUnitId, $name);
    }

    $pdo->commit();

    $row = admin_users_fetch_row($pdo, $userId);

    if (!$row) {
        throw new RuntimeException('Inserted user could not be reloaded.');
    }

    admin_users_respond(201, [
        'success' => true,
        'message' => 'New user account added.',
        'user' => admin_users_row_to_payload($row),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('admin_users create error: ' . $e->getMessage());
    admin_users_respond(500, [
        'success' => false,
        'message' => 'Unable to save user.',
    ]);
}
