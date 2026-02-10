<?php
// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Database configuration (adjust as needed for XAMPP)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'ers_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
?>
