<?php
// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Database/environment config
return require dirname(__DIR__) . '/db.config.php';
?>
