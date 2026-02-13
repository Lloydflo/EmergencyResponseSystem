<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$valid_token = false;
$pdo = get_db_connection();
if ($pdo && $token && $email) {
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW()');
    $stmt->execute([$email, $token]);
    $reset = $stmt->fetch();
    if ($reset) {
        $valid_token = true;
    } else {
        $error = 'Invalid or expired password reset link.';
    }
} elseif (!$token || !$email) {
    $error = 'Invalid password reset link.';
}
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $token = $_POST['token'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $pdo = get_db_connection();
    $valid_token = false;
    if ($pdo && $token && $email) {
        $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW()');
        $stmt->execute([$email, $token]);
        $reset = $stmt->fetch();
        if ($reset) {
            $valid_token = true;
        } else {
            $error = 'Invalid or expired password reset link.';
        }
    } else {
        $error = 'Invalid password reset link.';
    }
    if ($valid_token) {
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in all fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE email = ?');
            if ($stmt->execute([$hashed, $email])) {
                // Delete used token
                $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
                $success = 'Password has been updated. You may now <a href="login.php">login</a>.';
            } else {
                $error = 'Failed to update password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>Reset Password</h1>
            </div>
            <?php if (!empty($error)): ?>
                <div class="login-error-message"> <?php echo $error; ?> </div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="login-success-message"> <?php echo $success; ?> </div>
            <?php endif; ?>
            <?php if (empty($success) && $valid_token): ?>
            <form method="post">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" required class="form-input">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required class="form-input">
                </div>
                <button type="submit" class="btn-signin">Update Password</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
