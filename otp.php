<?php
// OTP Verification Page
session_start();
$pageTitle = 'OTP Verification';
$error_message = '';

if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/mail_helper.php';
    if (isset($_POST['resend_otp'])) {
        // Generate new OTP, save to DB, send email, reset timer
        $otp = rand(100000, 999999);
        $otpSaved = saveOtpToDatabase($_SESSION['otp_email'], $otp, 3);
        if (!$otpSaved) {
            $error_message = 'Failed to save OTP to database. Please try again later.';
        } else {
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_expiry'] = time() + 180; // 3 minutes
            $mailSent = sendOtpEmail($_SESSION['otp_email'], $otp);
            if ($mailSent) {
                $error_message = 'A new OTP has been sent to your email.';
            } else {
                $error_message = 'Failed to resend OTP. Please try again later.';
            }
        }
    } else {
        $input_otp = trim($_POST['otp'] ?? '');
        if (empty($input_otp)) {
            $error_message = 'Please enter the OTP.';
        } elseif (!isset($_SESSION['otp']) || !isset($_SESSION['otp_expiry']) || time() > $_SESSION['otp_expiry']) {
            $error_message = 'OTP expired. Please login again.';
            session_destroy();
        } elseif ($input_otp == $_SESSION['otp']) {
            // OTP correct, log in user
            unset($_SESSION['otp'], $_SESSION['otp_expiry']);
            // Set a flag to indicate OTP is verified
            $_SESSION['otp_verified'] = true;
            // Redirect to index or intended page
            header('Location: index.php');
            exit;
        } else {
            $error_message = 'Invalid OTP. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1 class="login-title">OTP Verification</h1>
                <p class="login-subtitle">Enter the 6-digit code sent to your email.</p>
                <div id="otp-timer" style="text-align:center; margin-top:4px; font-size:13px; color:#888;"></div>
            </div>
            <?php if (!empty($error_message)): ?>
                <div class="login-error-message">
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>
            <form class="login-form" method="POST" action="otp.php">
                <div class="form-group">
                    <label for="otp" class="form-label">OTP Code</label>
                    <input type="text" id="otp" name="otp" class="form-input" maxlength="6" pattern="[0-9]{6}" required autofocus>
                </div>
                <button type="submit" class="btn-signin">Verify</button>
            </form>
            <div style="text-align:center; margin-top:10px;">
                <form method="post" action="otp.php" style="display:inline;">
                    <button type="submit" name="resend_otp" style="font-size:12px; padding:4px 16px; border-radius:5px; background:#e0e0e0; color:#333; border:none; cursor:pointer; margin-bottom:8px;">Resend Code</button>
                </form>
            </div>
            <div class="login-footer">
                <p>Didn't receive the code? <a href="login.php">Login again</a></p>
            </div>
        </div>
    </div>
</body>
</body>
<script>
// Countdown timer for OTP expiration
const expiryTimestamp = <?php echo isset($_SESSION['otp_expiry']) ? $_SESSION['otp_expiry'] : 'null'; ?>;
if (expiryTimestamp) {
    const timerDiv = document.getElementById('otp-timer');
    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        let secondsLeft = expiryTimestamp - now;
        if (secondsLeft < 0) secondsLeft = 0;
        const min = Math.floor(secondsLeft / 60);
        const sec = secondsLeft % 60;
        timerDiv.textContent = `Code expires in ${min}:${sec.toString().padStart(2, '0')}`;
        if (secondsLeft > 0) {
            setTimeout(updateTimer, 1000);
        } else {
            timerDiv.textContent = 'Code expired. Please login again.';
        }
    }
    updateTimer();
}
</script>
</html>
