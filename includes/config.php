<?php
// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Database configuration (adjust as needed for XAMPP)
// ===========================================
// APPLICATION ENVIRONMENT
// ===========================================
if (!defined('APP_ENV')) define('APP_ENV', 'development');

// ===========================================
// DATABASE CONFIGURATION
// ===========================================
// Primary Database (localhost for development)
// Set these to your LIVE server values when deployed
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', 3306); // 3306 is default for MySQL
if (!defined('DB_NAME')) define('DB_NAME', 'LGU');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', 'YsqnXk6q#145');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Fallback Database
if (!defined('DB_FALLBACK_HOST')) define('DB_FALLBACK_HOST', '127.0.0.1');
if (!defined('DB_FALLBACK_PORT')) define('DB_FALLBACK_PORT', 3000);
if (!defined('DB_FALLBACK_NAME')) define('DB_FALLBACK_NAME', 'ers_db');
if (!defined('DB_FALLBACK_USER')) define('DB_FALLBACK_USER', 'root');
if (!defined('DB_FALLBACK_PASS')) define('DB_FALLBACK_PASS', '');
?>
