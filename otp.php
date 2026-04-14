<?php
// OTP Verification Page
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/activity_log.php';
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
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $userName = trim((string)($_SESSION['user_name'] ?? 'User'));
            $rawRole = trim((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? 'user'));
            $roleLabel = ucwords(str_replace(['_', '-'], ' ', $rawRole !== '' ? $rawRole : 'user'));
            if ($userId !== null && $userId > 0) {
                log_activity_event(
                    $userId,
                    'login',
                    'auth',
                    $userId,
                    trim($roleLabel . ' ' . $userName . ' signed in')
                );
            }
            // Role-based redirect after OTP verification
            $selectedRole = strtolower(trim((string)($_SESSION['login_role'] ?? '')));
            $accountRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
            if ($selectedRole === 'dispatcher' || $accountRole === 'dispatcher' || $accountRole === 'operator') {
                header('Location: dispatcher/dashboard.php');
            } else {
                header('Location: admin/index.php');
            }
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
                    <label for="otp-1" class="form-label">OTP Code</label>
                    <div class="otp-input-group" data-otp-group>
                        <input type="hidden" id="otp" name="otp" value="">
                        <input type="text" id="otp-1" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" required autofocus>
                        <input type="text" id="otp-2" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" required>
                        <input type="text" id="otp-3" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" required>
                        <input type="text" id="otp-4" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" required>
                        <input type="text" id="otp-5" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" required>
                        <input type="text" id="otp-6" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" required>
                    </div>
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

const otpForm = document.querySelector('.login-form');
const otpHiddenInput = document.getElementById('otp');
const otpInputs = Array.from(document.querySelectorAll('.otp-digit-input'));

function syncOtpValue() {
    if (!otpHiddenInput) {
        return;
    }

    otpHiddenInput.value = otpInputs.map((input) => input.value).join('');
}

function handleOtpPaste(event) {
    event.preventDefault();
    const pastedText = (event.clipboardData || window.clipboardData).getData('text');
    const digits = pastedText.replace(/\D/g, '').slice(0, otpInputs.length).split('');

    otpInputs.forEach((input, index) => {
        input.value = digits[index] || '';
    });

    syncOtpValue();

    const nextIndex = Math.min(digits.length, otpInputs.length - 1);
    otpInputs[nextIndex].focus();
    otpInputs[nextIndex].select();
}

otpInputs.forEach((input, index) => {
    input.addEventListener('input', (event) => {
        const digitsOnly = event.target.value.replace(/\D/g, '');
        event.target.value = digitsOnly.slice(-1);
        syncOtpValue();

        if (event.target.value && index < otpInputs.length - 1) {
            otpInputs[index + 1].focus();
            otpInputs[index + 1].select();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && index > 0) {
            otpInputs[index - 1].focus();
            otpInputs[index - 1].select();
        }

        if (event.key === 'ArrowLeft' && index > 0) {
            otpInputs[index - 1].focus();
            otpInputs[index - 1].select();
        }

        if (event.key === 'ArrowRight' && index < otpInputs.length - 1) {
            otpInputs[index + 1].focus();
            otpInputs[index + 1].select();
        }
    });

    input.addEventListener('focus', () => {
        input.select();
    });

    input.addEventListener('paste', handleOtpPaste);
});

if (otpForm) {
    otpForm.addEventListener('submit', () => {
        syncOtpValue();
    });
}
</script>
</html>
