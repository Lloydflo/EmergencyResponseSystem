<?php
/**
 * Authentication Helper Functions
 * Handles user login, logout, and session management
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        try {
            session_start();
        } catch (Exception $e) {
            // Session already started or headers sent
            error_log('Session start error: ' . $e->getMessage());
        }
    }
}

/**
 * Check if user is logged in
 * @return bool
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']) && (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true);
}

/**
 * Get current logged in user data
 * @return array|null
 */
function get_logged_in_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    
    $effectiveRole = $_SESSION['login_role'] ?? $_SESSION['user_role'] ?? 'viewer';

    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'name' => $_SESSION['user_name'] ?? 'User',
        'role' => $effectiveRole
    ];
}

/**
 * Normalize a role string.
 * @param string|null $role
 * @return string
 */
function normalize_role(?string $role): string {
    $value = strtolower(trim((string)$role));
    $value = str_replace(['-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value ?? '');
    return trim((string)$value);
}

/**
 * Convert role labels/aliases to canonical roles used by UI flow.
 * @param string|null $role
 * @return string admin|dispatcher|viewer|unknown
 */
function canonical_role(?string $role): string {
    $normalized = normalize_role($role);

    if ($normalized === 'admin' || $normalized === 'administrator') {
        return 'admin';
    }

    if (
        $normalized === 'dispatcher' ||
        $normalized === 'dispatch' ||
        $normalized === 'dispatch operator' ||
        $normalized === 'operator'
    ) {
        return 'dispatcher';
    }

    if ($normalized === 'viewer') {
        return 'viewer';
    }

    return 'unknown';
}

/**
 * Get canonical role of current session.
 * @return string admin|dispatcher|viewer|unknown
 */
function current_session_role(): string {
    if (!is_logged_in()) {
        return 'unknown';
    }
    return canonical_role((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? ''));
}

/**
 * Build role home path considering current folder depth.
 * @param string $role
 * @return string
 */
function role_home_path(string $role): string {
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isSubfolderPage = (strpos($scriptName, '/admin/') !== false || strpos($scriptName, '/dispatcher/') !== false);
    $prefix = $isSubfolderPage ? '../' : '';

    if ($role === 'admin') {
        return $prefix . 'admin/index.php';
    }
    if ($role === 'dispatcher') {
        return $prefix . 'dispatcher/dashboard.php';
    }
    return $prefix . 'login.php';
}

/**
 * Require login - redirect to login page if not logged in
 * @param string $redirect_url Optional redirect URL after login
 */
function require_login(string $redirect_url = ''): void {
    if (!is_logged_in()) {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $isSubfolderPage = (strpos($scriptName, '/admin/') !== false || strpos($scriptName, '/dispatcher/') !== false);
        $loginPath = $isSubfolderPage ? '../login.php' : 'login.php';

        $target = $redirect_url;
        if ($target === '') {
            $target = ltrim($scriptName, '/');
        } elseif ($isSubfolderPage && strpos($target, '/') === false) {
            $folder = basename(dirname($scriptName));
            $target = $folder . '/' . $target;
        }

        $redirect = $target !== '' ? '?redirect=' . urlencode($target) : '';
        header('Location: ' . $loginPath . $redirect);
        exit;
    }
}

/**
 * Require a specific role. Redirects logged-in user to own home if role mismatched.
 * @param string $requiredRole admin|dispatcher
 * @param string $redirect_url Optional redirect URL after login
 */
function require_role(string $requiredRole, string $redirect_url = ''): void {
    require_login($redirect_url);
    $required = canonical_role($requiredRole);
    $current = current_session_role();
    if ($required !== $current) {
        header('Location: ' . role_home_path($current));
        exit;
    }
}

/**
 * Ensure login lockout fields exist on older deployments.
 * @param PDO $pdo
 */
function ensure_login_lockout_columns(PDO $pdo): void {
    $columns = [
        'failed_login_attempts' => "ALTER TABLE users ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status",
        'locked_until' => "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER failed_login_attempts",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    $indexStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND INDEX_NAME = 'idx_users_locked_until'
    ");
    $indexStmt->execute();
    if ((int)$indexStmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD KEY idx_users_locked_until (locked_until)");
    }
}

/**
 * Clear failed login counters after a valid password or expired lock.
 * @param PDO $pdo
 * @param int $userId
 */
function reset_login_lockout(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}

/**
 * Record a bad password attempt and lock the account when the limit is reached.
 * @param PDO $pdo
 * @param int $userId
 * @param int $currentAttempts
 * @return array ['message' => string]
 */
function record_failed_login_attempt(PDO $pdo, int $userId, int $currentAttempts): array {
    $maxAttempts = 3;
    $lockMinutes = 5;
    $attempts = min($currentAttempts + 1, $maxAttempts);

    if ($attempts >= $maxAttempts) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
            WHERE id = ?
        ");
        $stmt->execute([$attempts, $lockMinutes, $userId]);

        return [
            'message' => 'Too many incorrect password attempts. Your account is locked for 5 minutes.'
        ];
    }

    $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = NULL WHERE id = ?");
    $stmt->execute([$attempts, $userId]);

    $remaining = $maxAttempts - $attempts;
    return [
        'message' => 'Invalid email or password. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining before account lock.'
    ];
}

/**
 * Login user
 * @param string $email
 * @param string $password
 * @param string|null $requiredRole
 * @return array ['success' => bool, 'message' => string, 'user' => array|null]
 */
function login_user(string $email, string $password, ?string $requiredRole = null): array {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/user_account_cleanup.php';
    
    $pdo = get_db_connection();
    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Database connection failed',
            'user' => null
        ];
    }
    
    try {
        try {
            ers_purge_inactive_user_accounts($pdo);
        } catch (Throwable $cleanupError) {
            error_log('Inactive user cleanup error: ' . $cleanupError->getMessage());
        }

        ensure_login_lockout_columns($pdo);

        // Get user by email
        $stmt = $pdo->prepare("
            SELECT
                id,
                email,
                password,
                name,
                role,
                status,
                failed_login_attempts,
                locked_until,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
                GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS lock_seconds_remaining
            FROM users
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid email or password',
                'user' => null
            ];
        }

        if ((int)($user['is_locked'] ?? 0) === 1) {
            $secondsRemaining = max(1, (int)($user['lock_seconds_remaining'] ?? 0));
            $minutesRemaining = max(1, (int)ceil($secondsRemaining / 60));

            return [
                'success' => false,
                'message' => 'Your account is locked. Please try again in ' . $minutesRemaining . ' minute' . ($minutesRemaining === 1 ? '' : 's') . '.',
                'user' => null
            ];
        }

        if (!empty($user['locked_until'])) {
            reset_login_lockout($pdo, (int)$user['id']);
            $user['failed_login_attempts'] = 0;
            $user['locked_until'] = null;
        }
        
        // Check if user is active
        if ($user['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact administrator.',
                'user' => null
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            $failedAttempt = record_failed_login_attempt($pdo, (int)$user['id'], (int)($user['failed_login_attempts'] ?? 0));
            return [
                'success' => false,
                'message' => $failedAttempt['message'],
                'user' => null
            ];
        }

        reset_login_lockout($pdo, (int)$user['id']);
        
        // Ensure selected role is compatible with account role before creating session
        if ($requiredRole !== null) {
            $expectedRole = canonical_role($requiredRole);
            $accountRole = canonical_role((string)($user['role'] ?? ''));
            $allowed = false;

            if ($expectedRole === 'admin') {
                $allowed = ($accountRole === 'admin');
            } elseif ($expectedRole === 'dispatcher') {
                $allowed = ($accountRole === 'dispatcher');
            } else {
                $allowed = false;
            }

            if (!$allowed) {
                return [
                    'success' => false,
                    'message' => 'Selected role does not match your account role.',
                    'user' => null
                ];
            }
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_role'] = $requiredRole ? canonical_role($requiredRole) : canonical_role((string)$user['role']);
        $_SESSION['logged_in'] = true;
        
        // Update last login
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role']
            ]
        ];
        
    } catch (PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred. Please try again later.',
            'user' => null
        ];
    }
}

/**
 * Logout user
 */
function logout_user(): void {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $userName = trim((string)($_SESSION['user_name'] ?? 'User'));
    $rawRole = trim((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? 'user'));
    $roleLabel = ucwords(str_replace(['_', '-'], ' ', $rawRole !== '' ? $rawRole : 'user'));

    if ($userId !== null && $userId > 0) {
        require_once __DIR__ . '/activity_log.php';
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/user_presence.php';
        if (!empty($_SESSION['logged_in']) || !empty($_SESSION['otp_verified'])) {
            $normalizedRole = canonical_role($rawRole);
            $source = $normalizedRole === 'dispatcher' ? 'dispatcher_web' : ($normalizedRole === 'admin' ? 'admin_web' : 'server_api');
            log_operational_event(
                $userId,
                'logout',
                'auth',
                $userId,
                trim($roleLabel . ' ' . $userName . ' signed out'),
                [
                    'actor_role' => $normalizedRole !== 'unknown' ? $normalizedRole : 'user',
                    'source_channel' => $source,
                    'event_category' => 'authentication',
                    'event_outcome' => 'success',
                    'metadata' => ['session_event' => 'logout'],
                ]
            );
        }
        $pdo = get_db_connection();
        if ($pdo instanceof PDO) {
            mark_user_offline($pdo, $userId);
        }
    }

    // Unset all session variables
    $_SESSION = [];
    
    // Destroy session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
}
