<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Logout Handler
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);
require_once __DIR__ . '/../includes/auth_check.php';

// Catat aktivitas logout jika masih login & bersihkan token persisten
if (is_logged_in()) {
    $u = current_user();
    log_activity('LOGOUT', "Pengguna '{$u['nama']}' ({$u['role']}) keluar dari sistem.");

    try {
        if (!function_exists('get_db')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $db = get_db();
        $stmtClear = $db->prepare("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id_user = ?");
        $stmtClear->execute([$u['id_user']]);
    } catch (Throwable $e) {}
}

// Hapus cookie persisten lms_remember
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
setcookie('lms_remember', '', time() - 42000, '/', '', $isSecure, true);

// Hapus seluruh variabel sesi
$_SESSION = [];

// Hapus cookie sesi
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Hancurkan sesi
session_destroy();

// Mulai sesi baru untuk pesan flash logout
session_start();
set_flash('info', 'Anda telah berhasil keluar dari sistem LMS.');
header('Location: /login');
exit;
