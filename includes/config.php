<?php
// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Database configuration (supports environment overrides)
// Set via environment variables on the server for production:
// DB_HOST, DB_PORT (optional), DB_NAME, DB_USER, DB_PASS, DB_CHARSET (optional)
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) {
	$portEnv = getenv('DB_PORT');
	define('DB_PORT', ($portEnv !== false && $portEnv !== '') ? $portEnv : 3306);
}
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'ers_db');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
?>
