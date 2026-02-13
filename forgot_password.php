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
