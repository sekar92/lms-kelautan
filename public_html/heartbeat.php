<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Session Keep-Alive / Heartbeat Endpoint
 * Menjaga sesi pengguna tetap aktif saat membuka halaman berdurasi lama (kuis/ujian)
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!is_logged_in()) {
    echo json_encode([
        'status'    => 'unauthenticated',
        'logged_in' => false,
        'timestamp' => time(),
    ]);
    exit;
}

$_SESSION['last_activity'] = time();

echo json_encode([
    'status'    => 'ok',
    'logged_in' => true,
    'user_id'   => $_SESSION['user_id'],
    'timestamp' => time(),
]);
exit;
