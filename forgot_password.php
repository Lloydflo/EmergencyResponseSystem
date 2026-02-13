<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail_helper.php';
// Handle forgot password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        // Generate a reset token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        $pdo = get_db_connection();
        if ($pdo) {
            // Save token to database (create table if not exists: password_resets)
            $pdo->prepare('CREATE TABLE IF NOT EXISTS password_resets (email VARCHAR(255), token VARCHAR(64), expires_at DATETIME)')->execute();
            $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
            $stmt = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$email, $token, $expires_at]);
            // Send email with reset link
            $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?email=" . urlencode($email) . "&token=" . urlencode($token);
            $subject = 'Password Reset Request';
            $body = '<p>You requested a password reset. Click the link below to reset your password:</p>';
            $body .= '<p><a href="' . $reset_link . '">' . $reset_link . '</a></p>';
            $body .= '<p>If you did not request this, please ignore this email.</p>';
            $mailSent = false;
            if (function_exists('sendOtpEmail')) {
                // Use PHPMailer if available
                $mailSent = sendOtpEmail($email, $reset_link, 'ERS Password Reset');
            } else {
                // Fallback: use mail()
                $mailSent = mail($email, $subject, $body, "Content-type: text/html; charset=UTF-8");
            }
            $success = 'If this email is registered, a password reset link has been sent.';
        } else {
            $error = 'Database connection error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>Forgot Password</h1>
                <p>Enter your email to reset your password.</p>
            </div>
            <?php if (!empty($error)): ?>
                <div class="login-error-message"> <?php echo $error; ?> </div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="login-success-message"> <?php echo $success; ?> </div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" required class="form-input">
                </div>
                <button type="submit" class="btn-signin">Send Reset Link</button>
            </form>
            <div class="login-footer">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
