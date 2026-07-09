<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail_helper.php';

$pageTitle = 'Forgot Password';
$error_messages = [];
$success_message = '';
$email = '';
$otp = '';
$step = 'request';

if (is_logged_in()) {
    header('Location: ' . role_home_path(current_session_role()));
    exit;
}

function clear_forgot_password_session(): void
{
    unset(
        $_SESSION['forgot_password_email'],
        $_SESSION['forgot_password_verified'],
        $_SESSION['forgot_password_verified_at']
    );
}

function detect_otp_columns(PDO $pdo): ?array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM otp_codes');
    if (!$stmt) {
        return null;
    }

    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[(string)$row['Field']] = true;
    }

    $otpCandidates = ['otp_code', 'otp', 'code'];
    $expiryCandidates = ['expires_at', 'expiry_at', 'expiry', 'expired_at'];

    $otpColumn = null;
    foreach ($otpCandidates as $candidate) {
        if (isset($columns[$candidate])) {
            $otpColumn = $candidate;
            break;
        }
    }

    $expiryColumn = null;
    foreach ($expiryCandidates as $candidate) {
        if (isset($columns[$candidate])) {
            $expiryColumn = $candidate;
            break;
        }
    }

    if (!isset($columns['email']) || $otpColumn === null || $expiryColumn === null) {
        return null;
    }

    return [
        'otp' => $otpColumn,
        'expiry' => $expiryColumn,
        'status' => isset($columns['status']) ? 'status' : null,
    ];
}

function get_latest_otp(PDO $pdo, string $email, array $schema): ?array
{
    $select = 'SELECT id, `' . $schema['otp'] . '` AS otp_value, `' . $schema['expiry'] . '` AS expiry_value';
    if (!empty($schema['status'])) {
        $select .= ', `status` AS otp_status';
    }

    $sql = $select . ' FROM otp_codes WHERE email = ?';
    if (!empty($schema['status'])) {
        $sql .= " AND `status` = 'active'";
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function mark_otp_status(PDO $pdo, int $id, string $statusValue): void
{
    $stmt = $pdo->prepare('UPDATE otp_codes SET status = ? WHERE id = ?');
    $stmt->execute([$statusValue, $id]);
}

function password_requirement_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Minimum 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'At least 1 uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'At least 1 lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'At least 1 number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'At least 1 special character';
    }

    return $errors;
}

if (isset($_GET['reset_flow']) && $_GET['reset_flow'] === '1') {
    clear_forgot_password_session();
}

if (!empty($_SESSION['forgot_password_email'])) {
    $email = (string)$_SESSION['forgot_password_email'];
}
if (!empty($_SESSION['forgot_password_verified']) && !empty($_SESSION['forgot_password_email'])) {
    $step = 'reset';
} elseif (!empty($_SESSION['forgot_password_email'])) {
    $step = 'otp';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'send_code') {
        $email = trim((string)($_POST['email'] ?? ''));
        $step = 'request';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_messages[] = 'Please enter a valid email address.';
        } else {
            $pdo = get_db_connection();
            if (!$pdo) {
                $error_messages[] = 'Database connection failed.';
            } else {
                $stmt = $pdo->prepare('SELECT id, status FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $error_messages[] = 'Email address not found.';
                } elseif (($user['status'] ?? '') !== 'active') {
                    $error_messages[] = 'This account is inactive. Please contact support.';
                } else {
                    $cooldownWait = getRecentOtpRequestWaitSeconds($email, 60);
                    if ($cooldownWait > 0) {
                        $error_messages[] = getOtpCooldownMessage($cooldownWait);
                    } else {
                        $otpCode = (string)random_int(100000, 999999);
                        $saved = saveOtpToDatabase($email, $otpCode, 3);

                        if (!$saved) {
                            $error_messages[] = 'Unable to generate verification code. Please try again.';
                        } else {
                            $sent = sendOtpEmail($email, $otpCode, 'Emergency Response System');
                            if (!$sent) {
                                markOtpEmailDeliveryFailed($email, $otpCode);
                                $error_messages[] = getLastOtpEmailErrorMessage('Verification code email could not be delivered because no working sender is configured.');
                            } else {
                                $_SESSION['forgot_password_email'] = $email;
                                $_SESSION['forgot_password_verified'] = false;
                                unset($_SESSION['forgot_password_verified_at']);

                                $step = 'otp';
                                $success_message = 'Verification code sent. Please check your email.';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'verify_code') {
        $email = (string)($_SESSION['forgot_password_email'] ?? '');
        $otp = trim((string)($_POST['otp'] ?? ''));
        $step = 'otp';

        if ($email === '') {
            $error_messages[] = 'Please request a new verification code.';
            $step = 'request';
        } elseif (!preg_match('/^[0-9]{6}$/', $otp)) {
            $error_messages[] = 'Please enter a valid 6-digit OTP code.';
        } else {
            $pdo = get_db_connection();
            if (!$pdo) {
                $error_messages[] = 'Database connection failed.';
            } else {
                $schema = detect_otp_columns($pdo);
                if (!$schema) {
                    $error_messages[] = 'OTP table configuration is invalid.';
                } else {
                    $latestOtp = get_latest_otp($pdo, $email, $schema);
                    if (!$latestOtp) {
                        $error_messages[] = 'No active OTP found. Please request a new code.';
                    } elseif (strtotime((string)$latestOtp['expiry_value']) < time()) {
                        if (!empty($schema['status'])) {
                            mark_otp_status($pdo, (int)$latestOtp['id'], 'expired');
                        }
                        $error_messages[] = 'OTP code has expired. Please resend code.';
                    } elseif ((string)$latestOtp['otp_value'] !== $otp) {
                        $error_messages[] = 'Invalid OTP code.';
                    } else {
                        if (!empty($schema['status'])) {
                            mark_otp_status($pdo, (int)$latestOtp['id'], 'used');
                        }

                        $_SESSION['forgot_password_verified'] = true;
                        $_SESSION['forgot_password_verified_at'] = time();
                        $step = 'reset';
                        $success_message = 'OTP verified. You can now set a new password.';
                    }
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $email = (string)($_SESSION['forgot_password_email'] ?? '');
        $step = 'reset';
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($email === '' || empty($_SESSION['forgot_password_verified'])) {
            $error_messages[] = 'OTP verification required before resetting password.';
            $step = 'request';
        } else {
            $passwordErrors = password_requirement_errors($newPassword);
            foreach ($passwordErrors as $passwordError) {
                $error_messages[] = $passwordError;
            }

            if ($confirmPassword === '') {
                $error_messages[] = 'Please confirm your new password.';
            } elseif ($newPassword !== $confirmPassword) {
                $error_messages[] = 'Passwords do not match.';
            }

            if (empty($error_messages)) {
                $pdo = get_db_connection();
                if (!$pdo) {
                    $error_messages[] = 'Database connection failed.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE email = ? AND status = 'active'");
                    $stmt->execute([$hash, $email]);

                    if ($stmt->rowCount() < 1) {
                        $error_messages[] = 'Unable to update password. Please request a new code and try again.';
                    } else {
                        clear_forgot_password_session();
                        header('Location: login.php?password_reset=1');
                        exit;
                    }
                }
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
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/login.css">
    <style>
    .input-wrapper-send .form-input {
        padding-right: 8.5rem;
    }
    .inline-send-btn {
        position: absolute;
        right: 0.4rem;
        top: 50%;
        transform: translateY(-50%);
        height: calc(100% - 0.8rem);
        padding: 0 0.8rem;
        border: none;
        border-radius: 6px;
        background: #4c8a89;
        color: #fff;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }
    .inline-send-btn:hover {
        background: #3d706f;
    }
    .btn-secondary {
        width: 100%;
        margin-top: 0.8rem;
        padding: 0.75rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: #fff;
        color: #374151;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
    }
    .btn-secondary:hover {
        background: #f9fafb;
    }
    .helper-links {
        margin-top: 0.9rem;
        text-align: center;
    }
    .helper-links a {
        color: #2563eb;
        text-decoration: none;
        font-size: 0.9rem;
    }
    .helper-links a:hover {
        text-decoration: underline;
    }
    .step-note {
        font-size: 0.88rem;
        color: #6b7280;
        margin-top: -1.2rem;
        margin-bottom: 1.2rem;
        text-align: left;
    }
    .requirements-list {
        margin: -0.4rem 0 1.2rem 0;
        padding-left: 1.1rem;
        color: #4b5563;
        font-size: 0.88rem;
        line-height: 1.5;
    }
    .requirements-list li.pass {
        color: #166534;
    }
    .requirements-list li.fail {
        color: #991b1b;
    }
    .back-login {
        margin-top: 1rem;
        text-align: center;
    }
    .back-login a {
        color: #2563eb;
        text-decoration: none;
        font-size: 0.9rem;
    }
    .back-login a:hover {
        text-decoration: underline;
    }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="images/logo.svg" alt="LERTARA Logo" class="logo-img">
            </div>

            <div class="login-header">
                <h1 class="login-title">Forgot Password</h1>
                <p class="login-subtitle">Recover your account access securely</p>
            </div>

            <?php if (!empty($error_messages)): ?>
                <div class="login-error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars(implode(' ', $error_messages)); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success_message !== ''): ?>
                <div class="login-success-message">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
                <form class="login-form" method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="send_code">
                    <div class="form-group">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope"></i>
                            Email Address
                        </label>
                        <div class="input-wrapper input-wrapper-send">
                            <i class="fas fa-envelope input-icon"></i>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-input"
                                placeholder="Enter your account email"
                                value="<?php echo htmlspecialchars($email); ?>"
                                autocomplete="email"
                                required
                            >
                            <button type="submit" class="inline-send-btn">Send Code</button>
                        </div>
                    </div>
                </form>
            <?php elseif ($step === 'otp'): ?>
                <form class="login-form" method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="verify_code">
                    <p class="step-note">Enter the 6-digit OTP sent to <strong><?php echo htmlspecialchars($email); ?></strong>.</p>
                    <div class="form-group">
                        <label for="otp" class="form-label">
                            <i class="fas fa-key"></i>
                            OTP Verification
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-key input-icon"></i>
                            <input
                                type="text"
                                id="otp"
                                name="otp"
                                class="form-input"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                placeholder="Enter 6-digit OTP"
                                value="<?php echo htmlspecialchars($otp); ?>"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                required
                            >
                        </div>
                    </div>
                    <button type="submit" class="btn-signin">
                        <i class="fas fa-check"></i>
                        <span>Verify OTP</span>
                    </button>
                </form>

                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="send_code">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <button type="submit" class="btn-secondary">Resend Code</button>
                </form>

                <div class="helper-links">
                    <a href="forgot_password.php?reset_flow=1">Use different email</a>
                </div>
            <?php else: ?>
                <form class="login-form" method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="reset_password">
                    <p class="step-note">Create a new password for <strong><?php echo htmlspecialchars($email); ?></strong>.</p>

                    <ul class="requirements-list" id="requirementsList">
                        <li id="req-length">Minimum 8 characters</li>
                        <li id="req-upper">At least 1 uppercase letter</li>
                        <li id="req-lower">At least 1 lowercase letter</li>
                        <li id="req-number">At least 1 number</li>
                        <li id="req-special">At least 1 special character</li>
                    </ul>

                    <div class="form-group">
                        <label for="new_password" class="form-label">
                            <i class="fas fa-lock"></i>
                            New Password
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="form-input"
                                minlength="8"
                                placeholder="Enter new password"
                                autocomplete="new-password"
                                required
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                id="newPasswordToggle"
                                aria-label="Toggle new password visibility"
                            >
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">
                            <i class="fas fa-lock"></i>
                            Confirm Password
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-input"
                                minlength="8"
                                placeholder="Confirm new password"
                                autocomplete="new-password"
                                required
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                id="confirmPasswordToggle"
                                aria-label="Toggle confirm password visibility"
                            >
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-signin">
                        <i class="fas fa-rotate-right"></i>
                        <span>Reset Password</span>
                    </button>
                </form>
            <?php endif; ?>

            <div class="back-login">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleInput = function (buttonId, inputId) {
            const btn = document.getElementById(buttonId);
            const input = document.getElementById(inputId);
            if (!btn || !input) {
                return;
            }

            btn.addEventListener('click', function () {
                const nextType = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', nextType);
                const icon = btn.querySelector('i');
                if (!icon) {
                    return;
                }
                if (nextType === 'password') {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            });
        };

        toggleInput('newPasswordToggle', 'new_password');
        toggleInput('confirmPasswordToggle', 'confirm_password');

        const passwordInput = document.getElementById('new_password');
        const requirementIds = {
            length: 'req-length',
            upper: 'req-upper',
            lower: 'req-lower',
            number: 'req-number',
            special: 'req-special'
        };

        const updateRequirement = function (elementId, pass) {
            const item = document.getElementById(elementId);
            if (!item) {
                return;
            }
            item.classList.toggle('pass', pass);
            item.classList.toggle('fail', !pass);
        };

        if (passwordInput) {
            const refreshRequirements = function () {
                const value = passwordInput.value || '';
                updateRequirement(requirementIds.length, value.length >= 8);
                updateRequirement(requirementIds.upper, /[A-Z]/.test(value));
                updateRequirement(requirementIds.lower, /[a-z]/.test(value));
                updateRequirement(requirementIds.number, /[0-9]/.test(value));
                updateRequirement(requirementIds.special, /[^A-Za-z0-9]/.test(value));
            };

            passwordInput.addEventListener('input', refreshRequirements);
            refreshRequirements();
        }
    });
    </script>
</body>
</html>
