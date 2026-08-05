<?php
$rootDir = __DIR__;
require_once $rootDir . '/includes/auth.php';
require_login('account_settings.php');
require_once $rootDir . '/includes/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$userInfo = [];
$feedbackMessage = '';
$feedbackType = '';

function account_settings_format_datetime($value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Not available';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return 'Not available';
    }

    return date('M d, Y h:i A', $timestamp);
}

function account_settings_format_role($value): string {
    $label = trim((string)$value);
    if ($label === '') {
        return 'User';
    }

    $label = str_replace(['_', '-'], ' ', strtolower($label));
    return ucwords($label);
}

try {
    $pdo = get_db_connection();
} catch (Throwable $e) {
    $pdo = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && $pdo && $userId > 0) {
    $newName = trim((string)($_POST['name'] ?? ''));
    $newEmail = trim((string)($_POST['email'] ?? ''));
    $newPassword = (string)($_POST['password'] ?? '');

    if ($newName === '' || $newEmail === '') {
        $feedbackType = 'error';
        $feedbackMessage = 'Name and email are required.';
    } else {
        $params = [$newName, $newEmail];
        $updateQuery = 'UPDATE users SET name = ?, email = ?';

        if ($newPassword !== '') {
            $updateQuery .= ', password = ?';
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $updateQuery .= ', updated_at = NOW() WHERE id = ?';
        $params[] = $userId;

        $stmt = $pdo->prepare($updateQuery);
        if ($stmt->execute($params)) {
            $_SESSION['user_name'] = $newName;
            $_SESSION['user_email'] = $newEmail;
            $_SESSION['email'] = $newEmail;
            $feedbackType = 'success';
            $feedbackMessage = 'Account updated successfully!';
        } else {
            $feedbackType = 'error';
            $feedbackMessage = 'Failed to update account.';
        }
    }
}

if ($pdo && $userId > 0) {
    $stmt = $pdo->prepare('
        SELECT name, email, role, status, last_login, created_at, updated_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Account Settings';
$displayName = (string)($userInfo['name'] ?? ($_SESSION['user_name'] ?? 'User'));
$displayEmail = (string)($userInfo['email'] ?? ($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
$displayRole = account_settings_format_role($userInfo['role'] ?? '');
$statusValue = strtolower(trim((string)($userInfo['status'] ?? 'active')));
$statusLabel = $statusValue !== '' ? ucfirst($statusValue) : 'Unknown';
$statusClass = $statusValue === 'active' ? 'is-active' : ($statusValue === 'inactive' ? 'is-inactive' : 'is-neutral');
$lastLoginLabel = account_settings_format_datetime($userInfo['last_login'] ?? null);
$createdAtLabel = account_settings_format_datetime($userInfo['created_at'] ?? null);
$updatedAtLabel = account_settings_format_datetime($userInfo['updated_at'] ?? null);
$avatarSource = trim((string)($_SESSION['user_avatar'] ?? ''));
if ($avatarSource === '') {
    $avatarSource = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=0f766e&color=fff&size=256';
}
$sessionRole = strtolower(trim((string)($_SESSION['user_role'] ?? 'admin')));
if ($sessionRole === 'operator') {
    $sessionRole = 'dispatcher';
}
$dashboardPath = $sessionRole === 'dispatcher' ? 'dispatcher/dashboard.php' : 'admin/index.php';
$profilePath = 'profile.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/account-settings.css">
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content">
        <div class="main-container account-settings-shell">
            <section class="account-settings-hero">
                <div class="account-settings-hero-copy">
                    <span class="account-settings-kicker">Account Center</span>
                    <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <p>Manage your profile details, refresh your login credentials, and review the current state of your account in one place.</p>
                </div>
                <div class="account-settings-hero-chips">
                    <span class="account-settings-chip"><i class="fas fa-user-shield"></i><?php echo htmlspecialchars($displayRole); ?></span>
                    <span class="account-settings-chip status-chip <?php echo htmlspecialchars($statusClass); ?>"><i class="fas fa-circle"></i><?php echo htmlspecialchars($statusLabel); ?></span>
                </div>
            </section>

            <div class="account-settings-layout">
                <aside class="account-profile-card">
                    <div class="account-profile-top">
                        <img src="<?php echo htmlspecialchars($avatarSource); ?>" alt="<?php echo htmlspecialchars($displayName); ?>" class="account-profile-avatar">
                        <div>
                            <h2><?php echo htmlspecialchars($displayName); ?></h2>
                            <p><?php echo htmlspecialchars($displayEmail); ?></p>
                        </div>
                    </div>

                    <div class="account-profile-badges">
                        <span class="account-badge"><i class="fas fa-id-badge"></i><?php echo htmlspecialchars($displayRole); ?></span>
                        <span class="account-badge <?php echo htmlspecialchars($statusClass); ?>"><i class="fas fa-signal"></i><?php echo htmlspecialchars($statusLabel); ?></span>
                    </div>

                    <div class="account-profile-actions">
                        <a href="<?php echo htmlspecialchars($profilePath); ?>" class="account-secondary-btn">
                            <i class="fas fa-user"></i>
                            <span>Open Profile</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($dashboardPath); ?>" class="account-secondary-btn account-secondary-btn-primary">
                            <i class="fas fa-house"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>

                    <div class="account-stat-grid">
                        <div class="account-stat-card">
                            <span>Account ID</span>
                            <strong>#<?php echo htmlspecialchars((string)$userId); ?></strong>
                        </div>
                        <div class="account-stat-card">
                            <span>Last Login</span>
                            <strong><?php echo htmlspecialchars($lastLoginLabel); ?></strong>
                        </div>
                        <div class="account-stat-card">
                            <span>Member Since</span>
                            <strong><?php echo htmlspecialchars($createdAtLabel); ?></strong>
                        </div>
                        <div class="account-stat-card">
                            <span>Updated</span>
                            <strong><?php echo htmlspecialchars($updatedAtLabel); ?></strong>
                        </div>
                    </div>

                    <div class="account-side-note">
                        <h3><i class="fas fa-lock"></i> Security Reminder</h3>
                        <p>Use a strong password and only change it when needed. Leaving the password field blank keeps your current password active.</p>
                    </div>
                </aside>

                <section class="account-settings-content">
                    <?php if ($feedbackMessage !== ''): ?>
                        <div class="<?php echo $feedbackType === 'success' ? 'success-message' : 'error-message'; ?>">
                            <?php echo htmlspecialchars($feedbackMessage); ?>
                        </div>
                    <?php endif; ?>

                    <section class="account-settings-panel">
                        <div class="account-panel-head">
                            <div>
                                <span class="account-panel-kicker">Profile Details</span>
                                <h2>Update Personal Information</h2>
                            </div>
                            <p>Keep your visible account details accurate so your team can identify you correctly.</p>
                        </div>

                        <form method="post" action="account_settings.php" class="account-settings-form">
                            <div class="account-form-grid">
                                <div class="account-field">
                                    <label for="name">Full Name</label>
                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        value="<?php echo htmlspecialchars((string)($userInfo['name'] ?? '')); ?>"
                                        required
                                    >
                                    <small>This is the name shown in the dashboard and account menus.</small>
                                </div>

                                <div class="account-field">
                                    <label for="email">Email Address</label>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="<?php echo htmlspecialchars((string)($userInfo['email'] ?? '')); ?>"
                                        required
                                    >
                                    <small>Use an active email address for login and account recovery.</small>
                                </div>

                                <div class="account-field account-field-full">
                                    <label for="password">New Password</label>
                                    <div class="account-password-wrap">
                                        <input
                                            type="password"
                                            id="password"
                                            name="password"
                                            placeholder="Leave blank to keep current password"
                                        >
                                        <button type="button" class="account-password-toggle" id="togglePasswordBtn" aria-label="Show password" title="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small>Set a new password only if you want to replace your current one.</small>
                                </div>
                            </div>

                            <div class="account-form-actions">
                                <button type="button" class="account-secondary-btn" id="resetAccountFormBtn">
                                    <i class="fas fa-rotate-left"></i>
                                    <span>Reset</span>
                                </button>
                                <button type="submit" name="save_settings" class="account-primary-btn">
                                    <i class="fas fa-floppy-disk"></i>
                                    <span>Save Changes</span>
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="account-settings-panel account-settings-panel-muted">
                        <div class="account-panel-head">
                            <div>
                                <span class="account-panel-kicker">Access Overview</span>
                                <h2>Current Account Information</h2>
                            </div>
                            <p>Quick reference details about your account role, activity, and system access.</p>
                        </div>

                        <div class="account-info-grid">
                            <article class="account-info-card">
                                <span>Role</span>
                                <strong><?php echo htmlspecialchars($displayRole); ?></strong>
                            </article>
                            <article class="account-info-card">
                                <span>Status</span>
                                <strong><?php echo htmlspecialchars($statusLabel); ?></strong>
                            </article>
                            <article class="account-info-card">
                                <span>Last Login</span>
                                <strong><?php echo htmlspecialchars($lastLoginLabel); ?></strong>
                            </article>
                            <article class="account-info-card">
                                <span>Created At</span>
                                <strong><?php echo htmlspecialchars($createdAtLabel); ?></strong>
                            </article>
                            <article class="account-info-card">
                                <span>Last Updated</span>
                                <strong><?php echo htmlspecialchars($updatedAtLabel); ?></strong>
                            </article>
                            <article class="account-info-card">
                                <span>Login Email</span>
                                <strong><?php echo htmlspecialchars($displayEmail !== '' ? $displayEmail : 'Not available'); ?></strong>
                            </article>
                        </div>
                    </section>
                </section>
            </div>
        </div>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.account-settings-form');
        const resetButton = document.getElementById('resetAccountFormBtn');
        const passwordInput = document.getElementById('password');
        const togglePasswordBtn = document.getElementById('togglePasswordBtn');

        if (form && resetButton) {
            resetButton.addEventListener('click', function() {
                form.reset();
                if (passwordInput) {
                    passwordInput.type = 'password';
                }
                if (togglePasswordBtn) {
                    const icon = togglePasswordBtn.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                    togglePasswordBtn.setAttribute('aria-label', 'Show password');
                    togglePasswordBtn.setAttribute('title', 'Show password');
                }
            });
        }

        if (passwordInput && togglePasswordBtn) {
            togglePasswordBtn.addEventListener('click', function() {
                const shouldShow = passwordInput.type === 'password';
                passwordInput.type = shouldShow ? 'text' : 'password';

                const icon = togglePasswordBtn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !shouldShow);
                    icon.classList.toggle('fa-eye-slash', shouldShow);
                }

                togglePasswordBtn.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
                togglePasswordBtn.setAttribute('title', shouldShow ? 'Hide password' : 'Show password');
            });
        }
    });
    </script>
</body>
</html>
