<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/user_account_cleanup.php';

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

function admin_users_sync_responder_record(
    PDO $pdo,
    string $name,
    string $email,
    string $role,
    string $department,
    string $contactNumber,
    string $status,
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
            {$departmentSelect},
            `created_at`
        FROM `users`
        WHERE LOWER(`role`) IN ('dispatcher', 'responder', 'operator')
        ORDER BY `created_at` DESC, `id` DESC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $contacts = admin_users_responder_contact_map($pdo);

    return array_map(static function (array $row) use ($contacts): array {
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

            $inactiveSql = $status === 'inactive'
                ? "`inactive_at` = COALESCE(`inactive_at`, NOW())"
                : "`inactive_at` = NULL";

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                "UPDATE `users`
                 SET `name` = ?,
                     `email` = ?,
                     `department` = ?,
                     `role` = ?,
                     `status` = ?,
                     {$inactiveSql}
                 WHERE `id` = ?
                   AND LOWER(`role`) IN ('dispatcher', 'responder', 'operator')"
            );
            $updateStmt->execute([$name, $email, $department, $role, $status, $id]);

            admin_users_sync_responder_record(
                $pdo,
                $name,
                $email,
                $role,
                $department,
                $contactNumber,
                $status,
                (string)$existing['email']
            );

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

    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        'INSERT INTO `users` (`email`, `password`, `name`, `department`, `role`, `status`, `inactive_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $email,
        $passwordHash,
        $name,
        $department,
        $role,
        $status,
        $status === 'inactive' ? date('Y-m-d H:i:s') : null,
    ]);

    $userId = (int)$pdo->lastInsertId();

    admin_users_sync_responder_record($pdo, $name, $email, $role, $department, $contactNumber, $status, null, $passwordHash);

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
