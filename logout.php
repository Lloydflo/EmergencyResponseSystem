<?php
/**
 * Logout Page
 * Handles user logout and redirects to login page
 */
require_once __DIR__ . '/includes/auth.php';

// Logout user
logout_user();

// Redirect to login page
header('Location: login.php?logged_out=1');
exit;
