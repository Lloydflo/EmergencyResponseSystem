<?php
// Account Settings UI
include 'includes/admin-header.php';
include 'includes/sidebar.php';
require_once 'includes/auth.php';

// Fetch user info using PDO
$user_id = $_SESSION['user_id'] ?? null;
$user_info = [];
include 'includes/db.php';
$pdo = get_db_connection();
if ($user_id && $pdo) {
    $stmt = $pdo->prepare("SELECT name, email, role, status, last_login, created_at, updated_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
?>

<div class="account-settings-container">
    <h2>Account Settings</h2>
    <form method="post" action="account_settings.php">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user_info['name'] ?? ''); ?>" required>

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" required>

        <label for="password">New Password:</label>
        <input type="password" id="password" name="password" placeholder="Leave blank to keep current">

        <button type="submit" name="save_settings">Save Changes</button>
    </form>

    <div class="account-info">
        <h3>Account Information</h3>
        <ul>
            <li><strong>Role:</strong> <?php echo htmlspecialchars($user_info['role'] ?? ''); ?></li>
            <li><strong>Status:</strong> <?php echo htmlspecialchars($user_info['status'] ?? ''); ?></li>
            <li><strong>Last Login:</strong> <?php echo htmlspecialchars($user_info['last_login'] ?? 'Never'); ?></li>
            <li><strong>Created At:</strong> <?php echo htmlspecialchars($user_info['created_at'] ?? ''); ?></li>
            <li><strong>Last Updated:</strong> <?php echo htmlspecialchars($user_info['updated_at'] ?? ''); ?></li>
        </ul>
    </div>
    <?php
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && $pdo && $user_id) {
        $new_name = trim($_POST['name']);
        $new_email = trim($_POST['email']);
        $new_password = $_POST['password'];
        $params = [$new_name, $new_email];
        $update_query = "UPDATE users SET name = ?, email = ?";
        if (!empty($new_password)) {
            $update_query .= ", password = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }
        $update_query .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $user_id;
        $stmt = $pdo->prepare($update_query);
        if ($stmt->execute($params)) {
            echo '<div class="success-message">Account updated successfully!</div>';
            // Refresh user info after update
            $stmt = $pdo->prepare("SELECT name, email, role, status, last_login, created_at, updated_at FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } else {
            echo '<div class="error-message">Failed to update account.</div>';
        }
    }
    ?>
</div>

<link rel="stylesheet" href="css/account-settings.css">
<link rel="stylesheet" href="css/global.css">
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/admin-header.css">
<style>
.account-info {
    margin-top: 32px;
    background: #f7f7f7;
    border-radius: 8px;
    padding: 18px 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.account-info h3 {
    margin-bottom: 12px;
    font-size: 1.15rem;
    color: #333;
}
.account-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.account-info li {
    margin-bottom: 8px;
    color: #555;
    font-size: 1rem;
}
.account-info li strong {
    color: #222;
    min-width: 110px;
    display: inline-block;
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
