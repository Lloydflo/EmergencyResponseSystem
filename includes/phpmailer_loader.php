<?php

if (!function_exists('ers_load_phpmailer')) {
    function ers_load_phpmailer(?string &$errorMessage = null): bool
    {
        $errorMessage = null;

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('PHPMailer\\PHPMailer\\SMTP')) {
            return true;
        }

        $projectRoot = dirname(__DIR__);
        $autoloadPath = $projectRoot . '/vendor/autoload.php';

        if (is_file($autoloadPath)) {
            require_once $autoloadPath;

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('PHPMailer\\PHPMailer\\SMTP')) {
                return true;
            }
        }

        $requiredFiles = [
            $projectRoot . '/vendor/phpmailer/phpmailer/src/Exception.php',
            $projectRoot . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
            $projectRoot . '/vendor/phpmailer/phpmailer/src/SMTP.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!is_file($file)) {
                $errorMessage = 'Email dependency PHPMailer is missing. Run composer install from the project folder.';
                error_log($errorMessage . ' Missing file: ' . $file);
                return false;
            }
        }

        foreach ($requiredFiles as $file) {
            require_once $file;
        }

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('PHPMailer\\PHPMailer\\SMTP')) {
            return true;
        }

        $errorMessage = 'Email dependency PHPMailer could not be loaded. Run composer install from the project folder.';
        error_log($errorMessage);
        return false;
    }
}
