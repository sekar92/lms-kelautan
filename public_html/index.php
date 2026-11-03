<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Public Entry Point
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);
require_once __DIR__ . '/../includes/auth_check.php';

if (is_logged_in()) {
    header('Location: /dashboard');
} else {
    header('Location: /login');
}
exit;
