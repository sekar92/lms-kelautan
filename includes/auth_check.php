<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Auth Guard, CSRF Protection, Session Handler, & Flash Messages
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Arahkan session save path ke direktori internal LMS
    // Hal ini mencegah cron OS (/usr/lib/php/sessionclean di Ubuntu) menghapus sesi sebelum waktunya (default 24 menit)
    $sessionSaveDir = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($sessionSaveDir)) {
        @mkdir($sessionSaveDir, 0700, true);
    }
    if (is_dir($sessionSaveDir) && is_writable($sessionSaveDir)) {
        session_save_path($sessionSaveDir);
    }

    // Set Garbage Collection Max Lifetime ke 30 hari (2.592.000 detik)
    ini_set('session.gc_maxlifetime', (string)(30 * 86400));

    // Pengaturan cookie sesi yang aman
    $cookieParams = session_get_cookie_params();
    $hasRememberCookie = !empty($_COOKIE['lms_remember']);
    $sessionCookieLifetime = $hasRememberCookie ? (30 * 86400) : (int)($cookieParams['lifetime'] ?? 0);

    session_set_cookie_params([
        'lifetime' => $sessionCookieLifetime,
        'path'     => '/',
        'domain'   => $cookieParams['domain'],
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Generate CSRF Token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auto-Restore Sesi jika sesi PHP hilang/expired tetapi cookie "Ingat Saya" (lms_remember) ada
if (empty($_SESSION['user_id']) && !empty($_COOKIE['lms_remember'])) {
    $rememberParts = explode(':', (string)$_COOKIE['lms_remember'], 2);
    if (count($rememberParts) === 2) {
        [$cookieUid, $cookiePlainToken] = $rememberParts;
        if (!empty($cookieUid) && !empty($cookiePlainToken)) {
            try {
                if (!function_exists('get_db')) {
                    require_once __DIR__ . '/../config/database.php';
                }
                $dbRem = get_db();
                $tokenHash = hash('sha256', $cookiePlainToken);
                $stmtRem = $dbRem->prepare("
                    SELECT id_user, username, nama, role, angkatan, foto_profil, remember_expires
                    FROM users
                    WHERE id_user = ? AND remember_token = ? AND remember_expires > CURRENT_TIMESTAMP
                ");
                $stmtRem->execute([$cookieUid, $tokenHash]);
                $userRem = $stmtRem->fetch();

                if ($userRem) {
                    $rawRole = $userRem['role'];
                    if (strcasecmp($rawRole, 'admin') === 0 || strcasecmp($rawRole, 'dosen admin') === 0) {
                        $normalizedRole = 'Dosen Admin';
                    } elseif (strcasecmp($rawRole, 'dosen') === 0) {
                        $normalizedRole = 'Dosen';
                    } else {
                        $normalizedRole = 'Mahasiswa';
                    }

                    $_SESSION['user_id']        = $userRem['id_user'];
                    $_SESSION['username']       = $userRem['username'];
                    $_SESSION['nama']           = $userRem['nama'];
                    $_SESSION['role']           = $normalizedRole;
                    $_SESSION['angkatan']       = $userRem['angkatan'];
                    $_SESSION['foto_profil']    = $userRem['foto_profil'] ?? null;
                    $_SESSION['remember_me']    = true;
                    $_SESSION['last_activity']  = time();
                } else {
                    // Token tidak cocok atau sudah kadaluwarsa, hapus cookie lama
                    setcookie('lms_remember', '', time() - 42000, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
                }
            } catch (Throwable $e) {
                // Abaikan jika DB belum siap
            }
        }
    }
}

// Pengecekan Masa Kedaluwarsa Sesi (Idle Session Timeout)
// Jika pengguna TIDAK mencentang "Ingat Saya" dan TIDAK sedang dalam sesi kuis aktif,
// sesi diberi toleransi idle hingga 4 jam (14400 detik) untuk keamanan akademik tanpa memutus pengerjaan kuis.
if (!empty($_SESSION['user_id'])) {
    if (empty($_SESSION['remember_me']) && empty($_SESSION['active_quiz_id'])) {
        $maxIdleSeconds = 14400; // 4 Jam toleransi idle
        if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $maxIdleSeconds)) {
            session_unset();
            session_destroy();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            set_flash('warning', 'Sesi Anda telah kedaluwarsa karena tidak ada aktivitas selama 4 jam. Silakan masuk kembali.');
            header('Location: /login');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Validasi CSRF Token
 */
function verify_csrf_token(?string $token = null): bool {
    $token = $token ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validasi CSRF atau Hentikan Eksekusi (403)
 */
function validate_csrf_or_abort(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(403);
            die("<h1>403 Forbidden</h1><p>Sesi telah kedaluwarsa atau token CSRF tidak valid. Silakan kembali dan muat ulang halaman.</p>");
        }
    }
}

/**
 * Flash Message Handler (Pola Post/Redirect/Get)
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message,
    ];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Cek apakah user telah login
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Dapatkan data user yang sedang login
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id_user'     => $_SESSION['user_id'] ?? '',
        'username'    => $_SESSION['username'] ?? '',
        'nama'        => $_SESSION['nama'] ?? '',
        'role'        => $_SESSION['role'] ?? '',
        'angkatan'    => $_SESSION['angkatan'] ?? '',
        'foto_profil' => $_SESSION['foto_profil'] ?? null,
    ];
}

/**
 * Cek apakah user adalah Dosen Admin / Super Admin
 */
function is_dosen_admin(): bool {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $username = strtolower(trim((string)($_SESSION['username'] ?? '')));
    return in_array($role, ['dosen admin', 'admin'], true) || $username === 'admin';
}

/**
 * Cek apakah user adalah Dosen Biasa (bukan super admin)
 */
function is_dosen_biasa(): bool {
    return is_dosen() && !is_dosen_admin();
}

/**
 * Cek apakah user adalah Dosen (baik Dosen Admin maupun Dosen Biasa)
 */
function is_dosen(): bool {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    return is_dosen_admin() || in_array($role, ['dosen', 'dosen biasa', 'pengajar'], true);
}

function is_mahasiswa(): bool {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    return $role === 'mahasiswa';
}

/**
 * Cek apakah sesi saat ini sedang dalam mode simulasi / Login-As
 */
function is_impersonating(): bool {
    if (empty($_SESSION['impersonator']['user_id'])) {
        return false;
    }
    // Safeguard: Jika ID user saat ini sama dengan ID admin asli (sesi admin sudah aktif),
    // bersihkan otomatis penanda impersonator agar admin tidak terperangkap di mode simulasi.
    if (!empty($_SESSION['user_id']) && (string)$_SESSION['user_id'] === (string)$_SESSION['impersonator']['user_id']) {
        unset($_SESSION['impersonator']);
        return false;
    }
    return true;
}

/**
 * Dapatkan data admin asli yang menginisiasi simulasi / Login-As
 */
function impersonator_user(): ?array {
    return $_SESSION['impersonator'] ?? null;
}

/**
 * Dapatkan URL avatar yang valid dan aman dari root web
 * Jika berkas tidak ada di disk fisik, kembalikan null agar fallback inisial tampil
 */
function get_avatar_url(?string $path): ?string {
    if (empty($path)) {
        return null;
    }
    $clean = ltrim(trim($path), '/');
    $diskFile = dirname(__DIR__) . '/public_html/' . $clean;
    if (!file_exists($diskFile) || !is_file($diskFile)) {
        return null;
    }
    return '/' . $clean;
}

/**
 * Proteksi Khusus Dosen Admin
 */
function require_dosen_admin(): void {
    require_login();
    if (!is_dosen_admin()) {
        http_response_code(403);
        set_flash('error', 'Akses ditolak: Hanya Dosen Admin / Super Admin yang dapat mengakses halaman ini.');
        header('Location: /dashboard');
        exit;
    }
}

/**
 * Proteksi Halaman Internal: Wajib Login
 */
function require_login(string $redirect = '/login'): void {
    if (!is_logged_in()) {
        set_flash('error', 'Silakan masuk terlebih dahulu untuk mengakses sistem.');
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Proteksi Khusus Dosen (Dosen Admin maupun Dosen Biasa)
 */
function require_dosen(): void {
    require_login();
    if (!is_dosen()) {
        http_response_code(403);
        set_flash('error', 'Akses ditolak: Hanya dosen pengampu yang dapat mengakses halaman ini.');
        header('Location: /dashboard');
        exit;
    }
}

/**
 * Cek apakah Dosen tertentu ditugaskan sebagai pengampu mata kuliah
 * Dosen Admin selalu diizinkan mengakses semua mata kuliah
 */
function is_dosen_assigned_to_course(string $idMK, ?string $userId = null): bool {
    if (is_dosen_admin()) {
        return true;
    }
    $userId = $userId ?? ($_SESSION['user_id'] ?? '');
    if (empty($userId) || empty($idMK)) {
        return false;
    }
    $db = get_db();

    // 1. Cek tabel relasi tim dosen (dosen_mata_kuliah)
    try {
        $stmt = $db->prepare("SELECT 1 FROM dosen_mata_kuliah WHERE id_mk = ? AND id_user = ? LIMIT 1");
        $stmt->execute([$idMK, $userId]);
        if ($stmt->fetch()) {
            return true;
        }
    } catch (Throwable $e) {
        // Fallback jika tabel belum diinisialisasi
    }

    // 2. Fallback: Cek kolom id_dosen dan string dosen_pengampu pada tabel mata_kuliah
    try {
        $stmtMK = $db->prepare("SELECT id_dosen, dosen_pengampu FROM mata_kuliah WHERE id_mk = ? LIMIT 1");
        $stmtMK->execute([$idMK]);
        $course = $stmtMK->fetch();
        if (!$course) {
            return false;
        }

        if (!empty($course['id_dosen']) && $course['id_dosen'] === $userId) {
            return true;
        }

        $userName = (string)($_SESSION['nama'] ?? '');
        $userLogin = (string)($_SESSION['username'] ?? '');
        $dosenStr = (string)($course['dosen_pengampu'] ?? '');
        if ($userName !== '' && stripos($dosenStr, $userName) !== false) {
            return true;
        }
        if ($userLogin !== '' && stripos($dosenStr, $userLogin) !== false) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Proteksi Peran (Role Guard)
 */
function require_role(string|array $roles): void {
    require_login();
    $rolesArray = is_array($roles) ? array_map('strtolower', array_map('trim', $roles)) : [strtolower(trim($roles))];
    $userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

    // Hirarki peran: jika 'dosen' diminta, baik 'Dosen Admin' maupun 'Dosen' diizinkan
    $allowed = in_array($userRole, $rolesArray, true);
    if (!$allowed && in_array('dosen', $rolesArray, true) && is_dosen()) {
        $allowed = true;
    }

    if (!$allowed) {
        http_response_code(403);
        set_flash('error', 'Akses ditolak: Anda tidak memiliki izin untuk membuka halaman ini.');
        header('Location: /dashboard');
        exit;
    }
}

/**
 * Catat aktivitas ke tabel log_aktivitas (Audit Trail)
 *
 * @param string $tipe Kategori aktivitas (LOGIN, LOGOUT, LOGIN_AS, REVERT_ADMIN, UPLOAD_TUGAS, NILAI_TUGAS, KONTEN, dll)
 * @param string $deskripsi Penjelasan lengkap aktivitas
 * @param array|null $userOverride Informasi pengguna khusus jika berbeda dari session
 */
function log_activity(string $tipe, string $deskripsi, ?array $userOverride = null): void {
    try {
        $db = get_db();

        // 1. Tentukan Pengguna
        if ($userOverride !== null) {
            $idUser   = $userOverride['id_user'] ?? null;
            $username = $userOverride['username'] ?? null;
            $nama     = $userOverride['nama'] ?? null;
            $role     = $userOverride['role'] ?? null;
        } else {
            $user     = current_user();
            $idUser   = $user['id_user'] ?? ($_SESSION['user_id'] ?? null);
            $username = $user['username'] ?? ($_SESSION['username'] ?? null);
            $nama     = $user['nama'] ?? ($_SESSION['nama'] ?? null);
            $role     = $user['role'] ?? ($_SESSION['role'] ?? null);
        }

        // 2. IP Address
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] 
            ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
            ?? $_SERVER['REMOTE_ADDR'] 
            ?? '127.0.0.1';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ip = substr($ip, 0, 45);

        // 3. User Agent
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        // 4. Deteksi apakah aksi dilakukan via Mode Simulasi (Login-As)
        $isImpersonated = 0;
        $impersonatorId = null;
        if (is_impersonating()) {
            $isImpersonated = 1;
            $impersonator = impersonator_user();
            $impersonatorId = $impersonator['user_id'] ?? null;
            $adminName = $impersonator['nama'] ?? 'Admin';
            $deskripsi .= " (Mode Simulasi oleh Admin: {$adminName})";
        }

        // 5. Generate ID
        $idLog = generate_id('LOG', 'log_aktivitas', 'id_log');

        // 6. Simpan ke database
        $stmt = $db->prepare("
            INSERT INTO log_aktivitas (
                id_log, id_user, username, nama, role,
                tipe_aktivitas, deskripsi, ip_address, user_agent,
                is_impersonated, impersonator_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $idLog,
            $idUser,
            $username,
            $nama,
            $role,
            strtoupper(trim($tipe)),
            trim($deskripsi),
            $ip,
            $userAgent,
            $isImpersonated,
            $impersonatorId
        ]);
    } catch (Throwable $e) {
        // Logging tidak boleh menghentikan proses utama aplikasi
        error_log("LMS Activity Log Warning: " . $e->getMessage());
    }
}

// Periksa login secara otomatis untuk file yang me-require auth_check.php,
// kecuali jika didefinisikan NO_AUTH_REQUIRED
if (!defined('NO_AUTH_REQUIRED')) {
    require_login();
}
