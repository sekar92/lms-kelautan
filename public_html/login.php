<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Halaman Otentikasi & Login (Server-Side MPA - Versi Cerah & Ramah Pengguna)
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Jika sudah login, langsung alihkan ke dashboard
if (is_logged_in()) {
    header('Location: /dashboard');
    exit;
}

$branding = get_branding();
$errorMsg = null;

// Proses Form Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    if ($username === '' || $password === '') {
        $errorMsg = 'Silakan masukkan Username/NIM dan Kata Sandi Anda.';
    } else {
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT id_user, username, password_hash, nama, role, angkatan, foto_profil FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Regenerasi ID Sesi untuk mencegah session fixation
                session_regenerate_id(true);

                // Normalisasi role
                $rawRole = strtolower(trim((string)$user['role']));
                if (in_array($rawRole, ['dosen admin', 'admin'], true) || strtolower($user['username']) === 'admin') {
                    $normalizedRole = 'Dosen Admin';
                } elseif (in_array($rawRole, ['dosen', 'dosen biasa', 'pengajar'], true)) {
                    $normalizedRole = 'Dosen';
                } else {
                    $normalizedRole = 'Mahasiswa';
                }

                $_SESSION['user_id']     = $user['id_user'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['nama']        = $user['nama'];
                $_SESSION['role']        = $normalizedRole;
                $_SESSION['angkatan']    = $user['angkatan'];
                $_SESSION['foto_profil'] = $user['foto_profil'] ?? null;
                $_SESSION['remember_me'] = $rememberMe;
                $_SESSION['last_activity'] = time();
                unset($_SESSION['impersonator']);

                // Konfigurasi Cookie Sesi sesuai pilihan "Ingat Saya"
                if ($rememberMe) {
                    $lifetime = 30 * 86400; // 30 Hari
                    $cookieParams = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        session_id(),
                        [
                            'expires'  => time() + $lifetime,
                            'path'     => '/',
                            'domain'   => $cookieParams['domain'],
                            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]
                    );
                }

                // Catat log aktivitas masuk berhasil
                log_activity('LOGIN', "Pengguna '{$user['nama']}' ({$normalizedRole}) berhasil masuk ke sistem.", [
                    'id_user'  => $user['id_user'],
                    'username' => $user['username'],
                    'nama'     => $user['nama'],
                    'role'     => $normalizedRole,
                ]);

                set_flash('success', "Selamat datang kembali, {$user['nama']}!");
                header('Location: /dashboard');
                exit;
            } else {
                // Catat percobaan login gagal
                log_activity('LOGIN_GAGAL', "Percobaan masuk gagal untuk identitas: '{$username}'. Kata sandi salah atau akun tidak ditemukan.", [
                    'id_user'  => null,
                    'username' => $username,
                    'nama'     => 'Tidak Dikenal',
                    'role'     => 'Tamu',
                ]);

                $errorMsg = 'Username/NIM atau Kata Sandi yang Anda masukkan salah.';
            }
        } catch (Throwable $e) {
            $errorMsg = 'Terjadi kesalahan sistem saat memproses login. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Masuk ke Sistem';
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($branding['app_name']) ?></title>
    
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(get_favicon_url()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function() {
            const _warn = console.warn;
            console.warn = function(...args) {
                if (args[0] && typeof args[0] === 'string' && args[0].includes('cdn.tailwindcss.com')) return;
                _warn.apply(console, args);
            };
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        abyssal: { 950: '#060D18', 900: '#0A192F', 800: '#0F172A', 700: '#1E293B' },
                        maritime: { 900: '#1E3A8A', 800: '#1E40AF', 700: '#1D4ED8', 600: '#2563EB', 500: '#0284C7' },
                        ocean: { 400: '#38BDF8', 500: '#0EA5E9', DEFAULT: '#06B6D4', cyan: '#22D3EE' },
                        coral: '#F59E0B',
                        seafoam: '#10B981',
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        heading: ['"Outfit"', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-full flex flex-col justify-center py-10 sm:px-6 lg:px-8 relative overflow-x-hidden bg-gradient-to-br from-slate-50 via-sky-50 to-blue-50/70 text-slate-800 antialiased">

    <!-- Soft Oceanic Ambient Glow (Light & Fresh) -->
    <div class="fixed inset-0 pointer-events-none opacity-60">
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-sky-200/50 rounded-full blur-[100px]"></div>
        <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-cyan-200/50 rounded-full blur-[110px]"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[550px] h-[550px] bg-blue-100/40 rounded-full blur-[120px]"></div>
    </div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4">
        <!-- Logo & Branding Identity -->
        <div class="text-center">
            <a href="<?= htmlspecialchars($branding['url_portal']) ?>" target="_blank" class="inline-block transition transform hover:scale-105">
                <img src="<?= htmlspecialchars(get_logo_url()) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="h-14 mx-auto drop-shadow-md">
            </a>
            <h1 class="mt-4 text-2xl font-extrabold text-slate-900 tracking-tight font-heading">
                <?= htmlspecialchars($branding['app_name']) ?>
            </h1>
            <p class="mt-1 text-xs font-semibold text-cyan-700 tracking-wider uppercase">
                <?= htmlspecialchars($branding['platform_name']) ?>
            </p>
            <p class="mt-1 text-xs text-slate-500 font-medium">
                <?= htmlspecialchars($branding['study_program']) ?> — <?= htmlspecialchars($branding['institution_name']) ?>
            </p>
        </div>

        <!-- Login Card (Cerah, Bersih & Elegan) -->
        <div class="mt-7 bg-white/95 backdrop-blur-xl py-8 px-6 sm:px-8 shadow-xl shadow-sky-950/5 rounded-3xl border border-sky-100/80 text-slate-800 relative">
            
            <?php if ($errorMsg): ?>
                <div class="mb-5 p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center space-x-2.5">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 flex-shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($errorMsg) ?></span>
                </div>
            <?php endif; ?>

            <?php $flash = get_flash(); if ($flash): ?>
                <div class="mb-5 p-3.5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center space-x-2.5">
                    <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form (POST ke /login) -->
            <form action="/login" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label for="username" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Username atau NIM
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <input id="username" name="username" type="text" autocomplete="username" required
                               placeholder="Contoh: admin atau 2024001"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               class="block w-full pl-10 pr-3.5 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-2xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white focus:border-transparent transition shadow-sm">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Kata Sandi (Password)
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </div>
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                               placeholder="Masukkan kata sandi"
                               class="block w-full pl-10 pr-3.5 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-2xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white focus:border-transparent transition shadow-sm">
                    </div>
                </div>

                <!-- Checkbox Ingat Saya (Remember Me) -->
                <div class="pt-1">
                    <label class="flex items-start space-x-2.5 cursor-pointer select-none">
                        <input type="checkbox" name="remember_me" value="1" 
                               class="w-4 h-4 mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-cyan-500 focus:ring-offset-0 cursor-pointer">
                        <div class="text-xs text-slate-600 leading-snug">
                            <span class="font-semibold text-slate-800">Ingat saya di perangkat ini</span>
                            <p class="text-[11px] text-slate-400 mt-0.5">Sesi aktif 30 hari. Jika tidak dicentang, sesi akan terlogout otomatis setelah 30 menit tidak aktif.</p>
                        </div>
                    </label>
                </div>

                <div class="pt-2">
                    <button type="submit"
                            class="w-full flex items-center justify-center space-x-2 py-3 px-4 rounded-2xl text-sm font-bold text-white bg-gradient-to-r from-blue-700 via-sky-600 to-cyan-500 hover:from-blue-800 hover:to-cyan-600 shadow-lg shadow-sky-600/25 hover:shadow-cyan-500/30 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-cyan-500 transition transform active:scale-[0.99]">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        <span>Masuk ke Pembelajaran</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Portal Shortcut & Footer -->
        <div class="mt-8 text-center space-y-2">
            <p class="text-xs text-slate-500">
                Terintegrasi dengan ekosistem <a href="<?= htmlspecialchars($branding['url_portal']) ?>" target="_blank" class="text-cyan-700 font-semibold hover:underline">Kelautan.ID</a>
            </p>
            <p class="text-xs text-slate-500">
                <?= htmlspecialchars($branding['footer_text']) ?>
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
