<?php
// OTP Verification Page
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/user_presence.php';
$pageTitle = 'OTP Verification';
$error_message = '';

if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/mail_helper.php';
    if (isset($_POST['resend_otp'])) {
        $cooldownWait = getRecentOtpRequestWaitSeconds((string)$_SESSION['otp_email'], 60);
        if ($cooldownWait > 0) {
            $error_message = getOtpCooldownMessage($cooldownWait);
        } else {
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
                    markOtpEmailDeliveryFailed((string)$_SESSION['otp_email'], (string)$otp);
                    $error_message = getLastOtpEmailErrorMessage('Failed to resend OTP because email delivery is not configured with a working sender.');
                }
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
                $normalizedRole = canonical_role($rawRole);
                $source = $normalizedRole === 'dispatcher' ? 'dispatcher_web' : ($normalizedRole === 'admin' ? 'admin_web' : 'server_api');
                log_operational_event(
                    $userId,
                    'login',
                    'auth',
                    $userId,
                    trim($roleLabel . ' ' . $userName . ' signed in after OTP verification'),
                    [
                        'actor_role' => $normalizedRole !== 'unknown' ? $normalizedRole : 'user',
                        'source_channel' => $source,
                        'event_category' => 'authentication',
                        'event_outcome' => 'success',
                        'metadata' => ['session_event' => 'login', 'otp_verified' => true],
                    ]
                );
                $pdo = get_db_connection();
                if ($pdo instanceof PDO) {
                    mark_user_online($pdo, $userId);
                }
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
    <link rel="stylesheet" href="css/login.css?v=20260811-otp-full-v1">
</head>
<body class="login-page otp-page">
    <main class="otp-shell">
        <div class="login-container otp-container">
            <section class="login-card otp-card" aria-labelledby="otp-title">
                <div class="otp-brand">
                    <img src="images/logo.svg" alt="LERTARA Logo" class="otp-brand-logo">
                    <span class="otp-secure-label">
                        <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                            <path d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V7Zm3 9.73V18h-2v-1.27a2 2 0 1 1 2 0Z"/>
                        </svg>
                        Secure verification
                    </span>
                </div>

                <header class="login-header otp-header">
                    <div class="otp-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>
                        </svg>
                    </div>
                    <h1 class="login-title" id="otp-title">OTP Verification</h1>
                    <p class="login-subtitle" id="otp-instructions">Enter the 6-digit code sent to your email.</p>
                    <div id="otp-timer" class="otp-timer" role="status" aria-live="polite"></div>
                </header>

                <?php if (!empty($error_message)): ?>
                    <div class="login-error-message otp-message" role="alert">
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form class="login-form otp-form" method="POST" action="otp.php">
                    <fieldset class="form-group otp-fieldset">
                        <legend class="form-label">OTP Code</legend>
                        <div class="otp-input-group" data-otp-group role="group" aria-label="Six-digit OTP code" aria-describedby="otp-instructions otp-timer">
                            <input type="hidden" id="otp" name="otp" value="">
                            <input type="text" id="otp-1" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" aria-label="OTP digit 1" autocapitalize="off" spellcheck="false" required autofocus>
                            <input type="text" id="otp-2" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="OTP digit 2" autocapitalize="off" spellcheck="false" required>
                            <input type="text" id="otp-3" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="OTP digit 3" autocapitalize="off" spellcheck="false" required>
                            <input type="text" id="otp-4" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="OTP digit 4" autocapitalize="off" spellcheck="false" required>
                            <input type="text" id="otp-5" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="OTP digit 5" autocapitalize="off" spellcheck="false" required>
                            <input type="text" id="otp-6" class="otp-digit-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="OTP digit 6" autocapitalize="off" spellcheck="false" enterkeyhint="done" required>
                        </div>
                    </fieldset>
                    <button type="submit" class="btn-signin otp-verify-btn">Verify code</button>
                </form>

                <div class="otp-resend-section">
                    <span>Didn't receive the code?</span>
                    <form method="post" action="otp.php" class="otp-resend-form">
                        <button type="submit" name="resend_otp" class="otp-resend-btn">Resend code</button>
                    </form>
                </div>

                <footer class="login-footer otp-footer">
                    <p>Need to use a different account? <a href="login.php">Back to login</a></p>
                </footer>
            </section>
        </div>
    </main>
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
</body>
</html>
