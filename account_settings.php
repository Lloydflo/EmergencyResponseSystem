<?php
$rootDir = __DIR__;
require_once $rootDir . '/includes/auth.php';
require_login('account_settings.php');
require_once $rootDir . '/includes/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$userInfo = [];
$feedbackMessage = '';
$feedbackType = '';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
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
                <span class="account-settings-kicker">Account Center</span>
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p>Update your basic account details and review your current access information.</p>
            </section>

            <section class="account-settings-container">
                <?php if ($feedbackMessage !== ''): ?>
                    <div class="<?php echo $feedbackType === 'success' ? 'success-message' : 'error-message'; ?>">
                        <?php echo htmlspecialchars($feedbackMessage); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="account_settings.php" class="account-settings-form">
                    <label for="name">Name</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo htmlspecialchars((string)($userInfo['name'] ?? '')); ?>"
                        required
                    >

                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars((string)($userInfo['email'] ?? '')); ?>"
                        required
                    >

                    <label for="password">New Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Leave blank to keep current password"
                    >

                    <button type="submit" name="save_settings">Save Changes</button>
                </form>

                <div class="account-info">
                    <h2>Account Information</h2>
                    <ul>
                        <li><strong>Role:</strong> <?php echo htmlspecialchars((string)($userInfo['role'] ?? '')); ?></li>
                        <li><strong>Status:</strong> <?php echo htmlspecialchars((string)($userInfo['status'] ?? '')); ?></li>
                        <li><strong>Last Login:</strong> <?php echo htmlspecialchars((string)($userInfo['last_login'] ?? 'Never')); ?></li>
                        <li><strong>Created At:</strong> <?php echo htmlspecialchars((string)($userInfo['created_at'] ?? '')); ?></li>
                        <li><strong>Last Updated:</strong> <?php echo htmlspecialchars((string)($userInfo['updated_at'] ?? '')); ?></li>
                    </ul>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
