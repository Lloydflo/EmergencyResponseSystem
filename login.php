<?php
// DEBUG: Enable error reporting for troubleshooting on remote server
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function debug_log($msg) {
    file_put_contents(__DIR__ . '/debug_login.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}
debug_log('--- LOGIN.PHP START ---');

require_once __DIR__ . '/includes/auth.php';
debug_log('auth.php loaded');

$pageTitle = 'Admin Login';
$error_message = '';
$success_message = '';

// If already logged in, redirect to index
if (is_logged_in()) {
    debug_log('Already logged in, redirecting to index');
    header('Location: index.php');
    exit;
}

// Check for logout message
if (isset($_GET['logged_out']) && $_GET['logged_out'] == '1') {
    $success_message = 'You have been successfully logged out.';
}
// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log('POST request received');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    debug_log('Email: ' . $email);
    if (empty($email) || empty($password)) {
        $error_message = 'Please enter both email and password.';
        debug_log('Missing email or password');
    } else {
        $result = login_user($email, $password);
        debug_log('login_user result: ' . json_encode($result));
        if ($result['success']) {
            require_once __DIR__ . '/includes/mail_helper.php';
            $otp = rand(100000, 999999);
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_email'] = $email;
            $_SESSION['otp_expiry'] = time() + 180; // 3 minutes
            $otpSaved = saveOtpToDatabase($email, $otp, 3);
            if (!$otpSaved) {
                debug_log('OTP save failed');
                $error_message = 'Unable to save OTP. Please contact support.';
                unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry']);
            } else {
                debug_log('OTP generated and saved');
                $emailSent = sendOtpEmail($email, $otp);
                debug_log($emailSent ? 'OTP email sent' : 'OTP email failed');
                if (!$emailSent) {
                    $error_message = 'OTP email sending failed. Please try again later.';
                    unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry']);
                } else {
                    header('Location: otp.php');
                    debug_log('Redirecting to otp.php');
                    exit;
                }
            }
        } else {
            $error_message = $result['message'];
            debug_log('Login failed: ' . $error_message);
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
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <!-- Logo Section -->
            <div class="login-logo">
                <img src="images/logo.svg" alt="LERTARA Logo" class="logo-img">
            </div>

            <!-- Header -->
            <div class="login-header">
                <h1 class="login-title">Admin Login</h1>
                <p class="login-subtitle">
                    Emergency Response System<br>
                    Administrative Panel
                </p>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="login-error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="login-success-message">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Information Boxes -->
            <div class="info-boxes">
                <!-- Secure Access Box -->
                <div class="info-box info-box-secure">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure Admin Access Only</span>
                    <i class="fas fa-lock"></i>
                </div>

                <!-- Security Notice Box -->
                <div class="info-box info-box-notice">
                    <div class="notice-content">
                        <i class="fas fa-info-circle"></i>
                        <span>Security Notice: This is a restricted administrative area. Access is logged and monitored. Ensure you are using a secure connection.</span>
                    </div>
                </div>
            </div>

            <!-- Login Form -->
            <form class="login-form" method="POST" action="login.php">
                <!-- Email Field -->
                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="admin@example.com"
                            required
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="Enter your password"
                            required
                        >
                        <button 
                            type="button" 
                            class="password-toggle" 
                            id="passwordToggle"
                            aria-label="Toggle password visibility"
                        >
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="form-options">
                    <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                </div>

                <!-- Sign In Button -->
                <button type="submit" class="btn-signin">
                    <i class="fas fa-arrow-right-to-bracket"></i>
                    <span>Sign In</span>
                </button>
            </form>

            <!-- Contact Support -->
            <div class="login-footer">
                <p>Need help? <a href="#" class="support-link">Contact Support</a></p>
            </div>
        </div>
    </div>

    <script src="js/login.js"></script>
    <script>
        // Password visibility toggle
        document.addEventListener('DOMContentLoaded', function() {
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');
            if (passwordToggle && passwordInput) {
                passwordToggle.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    if (type === 'password') {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                });
            }
        });
    </script>
    <style>
    /* Spinner for Sign In button */
    .btn-signin.loading {
        opacity: 0.8;
        pointer-events: none;
    }
    .btn-signin .spinner {
        display: inline-block;
        vertical-align: middle;
    }
    </style>
</body>
</html>
