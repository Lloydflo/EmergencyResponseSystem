<?php
require_once __DIR__ . '/auth.php';

function require_admin_api_access(bool $json = false): void {
    if (!is_logged_in()) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Authentication required']);
            exit;
        }

        header('Location: ../login.php?redirect=' . urlencode('admin/report.php'));
        exit;
    }

    if (current_session_role() !== 'admin') {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Admin access required']);
        } else {
            echo 'Admin access required';
        }
        exit;
    }
}
