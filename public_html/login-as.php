<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Pusat Simulasi Akun (Login-As / User Impersonation)
 * Khusus Dosen Admin / Super Admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

$db = get_db();
$currentUser = current_user();

// =========================================================================
// 1. PROSES POST ACTION (IMPERSONATE / REVERT)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = trim((string)($_POST['action'] ?? ''));

    // Aksi 1: Kembali ke Akun Admin (Revert Impersonation)
    if ($action === 'revert') {
        if (!is_impersonating()) {
            set_flash('warning', 'Anda tidak sedang dalam mode simulasi.');
            header('Location: /dashboard');
            exit;
        }

        $impersonator = impersonator_user();
        $adminName = $impersonator['nama'] ?? 'Admin';

        // Pulihkan sesi admin asli
        $_SESSION['user_id']     = $impersonator['user_id'];
        $_SESSION['username']    = $impersonator['username'];
        $_SESSION['nama']        = $impersonator['nama'];
        $_SESSION['role']        = $impersonator['role'];
        $_SESSION['angkatan']    = $impersonator['angkatan'] ?? null;
        $_SESSION['foto_profil'] = $impersonator['foto_profil'] ?? null;
        $_SESSION['last_activity'] = time();

        // Catat aktivitas penghentian simulasi
        $targetSimulatedName = $currentUser['nama'] ?? 'Pengguna';
        $targetSimulatedRole = $currentUser['role'] ?? 'Peran';
        log_activity('REVERT_ADMIN', "Dosen Admin '{$adminName}' mengakhiri mode simulasi dari akun '{$targetSimulatedName}' ({$targetSimulatedRole}).", [
            'id_user'  => $impersonator['user_id'],
            'username' => $impersonator['username'],
            'nama'     => $impersonator['nama'],
            'role'     => $impersonator['role'],
        ]);

        // Hapus penanda sesi simulasi agar kembali normal
        unset($_SESSION['impersonator']);

        set_flash('success', "Mode simulasi berhasil diakhiri. Selamat datang kembali di akun Dosen Admin ({$adminName}).");
        header('Location: /dashboard');
        exit;
    }

    // Aksi 2: Memulai Simulasi Akun (Login-As)
    if ($action === 'impersonate') {
        // Hanya Dosen Admin asli yang boleh memulai impersonasi
        if (!is_dosen_admin() || is_impersonating()) {
            http_response_code(403);
            set_flash('error', 'Akses ditolak: Hanya Dosen Admin yang dapat memulai mode simulasi.');
            header('Location: /dashboard');
            exit;
        }

        $targetUserId = trim((string)($_POST['id_user'] ?? ''));
        if ($targetUserId === '' || $targetUserId === $_SESSION['user_id']) {
            set_flash('error', 'ID pengguna tidak valid atau Anda mencoba melakukan simulasi ke akun Anda sendiri.');
            header('Location: /login-as');
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT id_user, username, nama, role, angkatan, foto_profil FROM users WHERE id_user = ? LIMIT 1");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                set_flash('error', 'Pengguna target tidak ditemukan di sistem.');
                header('Location: /login-as');
                exit;
            }

            // Normalisasi role target
            $rawRole = strtolower(trim((string)$targetUser['role']));
            if (in_array($rawRole, ['dosen admin', 'admin'], true) || strtolower($targetUser['username']) === 'admin') {
                $normalizedRole = 'Dosen Admin';
            } elseif (in_array($rawRole, ['dosen', 'dosen biasa', 'pengajar'], true)) {
                $normalizedRole = 'Dosen';
            } else {
                $normalizedRole = 'Mahasiswa';
            }

            // Catat log aktivitas simulasi oleh Dosen Admin
            log_activity('LOGIN_AS', "Dosen Admin '{$_SESSION['nama']}' memulai simulasi akun sebagai '{$targetUser['nama']}' (ID/NIM: {$targetUser['username']}, Peran: {$normalizedRole}).", [
                'id_user'  => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'nama'     => $_SESSION['nama'],
                'role'     => $_SESSION['role'],
            ]);

            // Simpan snapshot admin asli
            $_SESSION['impersonator'] = [
                'user_id'     => $_SESSION['user_id'],
                'username'    => $_SESSION['username'],
                'nama'        => $_SESSION['nama'],
                'role'        => $_SESSION['role'],
                'angkatan'    => $_SESSION['angkatan'] ?? null,
                'foto_profil' => $_SESSION['foto_profil'] ?? null,
                'started_at'  => time(),
            ];

            // Terapkan sesi target pengguna
            $_SESSION['user_id']     = $targetUser['id_user'];
            $_SESSION['username']    = $targetUser['username'];
            $_SESSION['nama']        = $targetUser['nama'];
            $_SESSION['role']        = $normalizedRole;
            $_SESSION['angkatan']    = $targetUser['angkatan'] ?? null;
            $_SESSION['foto_profil'] = $targetUser['foto_profil'] ?? null;
            $_SESSION['last_activity'] = time();

            set_flash('info', "Mode Simulasi Aktif: Anda kini masuk sebagai {$targetUser['nama']} ({$normalizedRole}). Anda dapat menguji seluruh fitur dari perspektif akun ini.");
            header('Location: /dashboard');
            exit;
        } catch (Throwable $e) {
            set_flash('error', 'Gagal memproses simulasi akun: ' . $e->getMessage());
            header('Location: /login-as');
            exit;
        }
    }

    set_flash('error', 'Aksi tidak dikenali.');
    header('Location: /login-as');
    exit;
}

// =========================================================================
// 2. OTORISASI HALAMAN VIEW (GET)
// =========================================================================
if (!is_dosen_admin() && !is_impersonating()) {
    require_dosen_admin();
}

$pageTitle = 'Pusat Simulasi Akun (Login-As)';
$branding = get_branding();

// Parameter Pencarian & Filter
$search = trim((string)($_GET['q'] ?? ''));
$filterRole = trim((string)($_GET['role'] ?? ''));
$filterAngkatan = trim((string)($_GET['angkatan'] ?? ''));

// Hitung Statistik Sivitas
$totalDosenCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('dosen', 'dosen biasa', 'pengajar')")->fetchColumn();
$totalMhsCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'mahasiswa'")->fetchColumn();

// Query Pengguna dengan Filter & Pagination
$sql = "
    SELECT u.*,
           (SELECT COUNT(*) FROM enrollment WHERE id_user = u.id_user) AS total_kelas_mhs,
           (SELECT COUNT(*) FROM mata_kuliah WHERE id_dosen = u.id_user) AS total_kelas_dosen
    FROM users u
    WHERE 1=1
";
$params = [];

if ($filterRole !== '') {
    if (strtolower($filterRole) === 'dosen') {
        $sql .= " AND LOWER(u.role) IN ('dosen', 'dosen biasa', 'pengajar')";
    } elseif (strtolower($filterRole) === 'dosen admin') {
        $sql .= " AND (LOWER(u.role) IN ('dosen admin', 'admin') OR LOWER(u.username) = 'admin')";
    } else {
        $sql .= " AND LOWER(u.role) = LOWER(?)";
        $params[] = $filterRole;
    }
}

if ($search !== '') {
    $sql .= " AND (u.nama LIKE ? OR u.username LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($filterAngkatan !== '') {
    $sql .= " AND u.angkatan = ?";
    $params[] = $filterAngkatan;
}

// Hitung total untuk pagination
$countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS subquery";
$stmtCount = $db->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY CASE WHEN u.role IN ('Dosen Admin', 'Admin') THEN 1 WHEN u.role = 'Dosen' THEN 2 ELSE 3 END, u.angkatan DESC, u.username ASC";
$sql .= " LIMIT {$perPage} OFFSET {$offset}";

$stmtUsers = $db->prepare($sql);
$stmtUsers->execute($params);
$userList = $stmtUsers->fetchAll();

// List Angkatan Unik
$angkatanList = $db->query("SELECT DISTINCT angkatan FROM users WHERE angkatan IS NOT NULL AND angkatan != '' ORDER BY angkatan DESC")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200 mb-2">
                <i data-lucide="shield" class="w-3.5 h-3.5 text-ocean"></i>
                <span>Super Admin Tools</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Pusat Simulasi Akun (Login-As)
            </h1>
            <p class="text-sm text-slate-500 max-w-2xl mt-1">
                Uji dan jelajahi antarmuka pembelajaran, materi perkuliahan, pengumpulan tugas, kuis, dan sistem penilaian langsung dari sudut pandang Dosen Pengampu maupun Mahasiswa tanpa memerlukan kata sandi mereka.
            </p>
        </div>

        <?php if (is_impersonating()): ?>
            <div class="shrink-0">
                <form action="/login-as" method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="revert">
                    <button type="submit" 
                            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-bold text-slate-950 bg-amber-400 hover:bg-amber-300 shadow-md transition">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                        <span>Kembali ke Akun Admin</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info Cards Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center space-x-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center shrink-0">
                <i data-lucide="graduation-cap" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Mahasiswa</p>
                <p class="text-2xl font-bold text-abyssal-900 font-heading"><?= $totalMhsCount ?></p>
                <p class="text-[11px] text-slate-400">Siap disimulasikan</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center space-x-4">
            <div class="w-12 h-12 rounded-xl bg-cyan-50 text-ocean flex items-center justify-center shrink-0">
                <i data-lucide="users" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Dosen Pengampu</p>
                <p class="text-2xl font-bold text-abyssal-900 font-heading"><?= $totalDosenCount ?></p>
                <p class="text-[11px] text-slate-400">Tenaga pendidik aktif</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center space-x-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <i data-lucide="lock" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aman & Terisolasi</p>
                <p class="text-sm font-bold text-abyssal-900 font-heading">1-Klik Kembali ke Admin</p>
                <p class="text-[11px] text-slate-400">Bilah kembali selalu tersedia</p>
            </div>
        </div>
    </div>

    <!-- Panduan Cepat & Aturan Keamanan -->
    <div class="bg-gradient-to-r from-blue-50/70 via-cyan-50/50 to-transparent p-5 rounded-2xl border border-blue-200/80 flex items-start space-x-3.5">
        <div class="w-8 h-8 rounded-lg bg-blue-100 text-maritime-700 flex items-center justify-center shrink-0 mt-0.5">
            <i data-lucide="info" class="w-4 h-4"></i>
        </div>
        <div class="text-xs text-slate-700 space-y-1">
            <p class="font-bold text-maritime-900 text-sm">Prinsip Kerja Fitur Simulasi (Login-As):</p>
            <ul class="list-disc list-inside space-y-0.5 text-slate-600">
                <li>Memungkinkan Dosen Admin memeriksa langsung kendala teknis mahasiswa (misal: pengumpulan tugas, tampilan kuis, keanggotaan kelas, pengingat deadline).</li>
                <li>Kata sandi asli pengguna <strong>tidak diubah dan tidak perlu diketahui</strong> oleh Admin.</li>
                <li>Saat mode simulasi berlangsung, bilah kuning <strong>"Mode Simulasi"</strong> akan selalu menempel di bagian paling atas seluruh halaman, lengkap dengan tombol untuk kembali ke akun admin dalam 1 klik.</li>
            </ul>
        </div>
    </div>

    <!-- Filter & Pencarian Bar -->
    <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm">
        <form action="/login-as" method="GET" class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-3 text-slate-400"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Cari berdasarkan nama lengkap atau NIM / NIDN / username..."
                       class="w-full pl-10 pr-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
            </div>

            <select name="role" class="px-3.5 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                <option value="">Semua Peran</option>
                <option value="Dosen" <?= strtolower($filterRole) === 'dosen' ? 'selected' : '' ?>>Dosen Biasa</option>
                <option value="Mahasiswa" <?= strtolower($filterRole) === 'mahasiswa' ? 'selected' : '' ?>>Mahasiswa</option>
                <option value="Dosen Admin" <?= strtolower($filterRole) === 'dosen admin' ? 'selected' : '' ?>>Dosen Admin</option>
            </select>

            <?php if (!empty($angkatanList)): ?>
                <select name="angkatan" class="px-3.5 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                    <option value="">Semua Angkatan</option>
                    <?php foreach ($angkatanList as $akt): ?>
                        <option value="<?= htmlspecialchars($akt) ?>" <?= $filterAngkatan === $akt ? 'selected' : '' ?>>
                            Angkatan <?= htmlspecialchars($akt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" class="inline-flex items-center justify-center space-x-1.5 px-5 py-2 text-xs sm:text-sm font-semibold rounded-xl text-white bg-slate-900 hover:bg-slate-800 transition shadow-xs">
                <i data-lucide="filter" class="w-4 h-4"></i>
                <span>Terapkan</span>
            </button>

            <?php if ($search !== '' || $filterAngkatan !== '' || $filterRole !== ''): ?>
                <a href="/login-as" class="inline-flex items-center justify-center px-4 py-2 text-xs sm:text-sm font-semibold rounded-xl text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                    Reset
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Daftar Pengguna untuk Simulasi -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                        <th class="py-4 px-6">Pengguna (Sivitas)</th>
                        <th class="py-4 px-4">Peran (Role)</th>
                        <th class="py-4 px-4">Keterangan / Beban</th>
                        <th class="py-4 px-6 text-right">Aksi Masuk</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($userList)): ?>
                        <tr>
                            <td colspan="4" class="py-12 text-center text-slate-400">
                                <i data-lucide="users" class="w-10 h-10 mx-auto text-slate-300 mb-2"></i>
                                <p class="text-sm font-semibold text-slate-600">Tidak ada data pengguna yang cocok dengan filter saat ini.</p>
                                <p class="text-xs text-slate-400 mt-0.5">Silakan reset kata kunci pencarian atau ganti filter peran.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($userList as $u): ?>
                            <?php 
                            $isSelf = ($u['id_user'] === $_SESSION['user_id']);
                            $rRole = strtolower($u['role']);
                            $avatarUrl = get_avatar_url($u['foto_profil'] ?? null);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-4 px-6">
                                    <div class="flex items-center space-x-3.5">
                                        <?php if ($avatarUrl): ?>
                                            <img src="<?= htmlspecialchars($avatarUrl) ?>" 
                                                 alt="Avatar" 
                                                 class="w-10 h-10 rounded-xl object-cover shadow-xs border border-slate-200"
                                                 onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean items-center justify-center font-bold text-sm text-white shadow-xs" style="display:none;">
                                                <?= strtoupper(substr($u['nama'], 0, 1)) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center font-bold text-sm text-white shadow-xs">
                                                <?= strtoupper(substr($u['nama'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-slate-900 font-heading text-sm flex items-center gap-1.5">
                                                <span><?= htmlspecialchars($u['nama']) ?></span>
                                                <?php if ($isSelf): ?>
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-cyan-100 text-cyan-800 border border-cyan-200">Akun Anda</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-slate-500 font-mono mt-0.5">
                                                ID: <?= htmlspecialchars($u['username']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="py-4 px-4">
                                    <?php if ($rRole === 'dosen admin' || $rRole === 'admin' || strtolower($u['username']) === 'admin'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                            Dosen Admin
                                        </span>
                                    <?php elseif ($rRole === 'dosen' || $rRole === 'dosen biasa' || $rRole === 'pengajar'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800 border border-cyan-200">
                                            Dosen Pengampu
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                            Mahasiswa
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-4">
                                    <?php if ($rRole === 'mahasiswa'): ?>
                                        <div class="text-xs font-semibold text-slate-800">
                                            <?= (int)$u['total_kelas_mhs'] ?> Mata Kuliah Terdaftar
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Angkatan <?= htmlspecialchars($u['angkatan'] ?? '-') ?>
                                        </div>
                                    <?php elseif ($rRole === 'dosen' || $rRole === 'dosen biasa'): ?>
                                        <div class="text-xs font-semibold text-slate-800">
                                            <?= (int)$u['total_kelas_dosen'] ?> Kelas Diampu
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Tenaga Pendidik
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-purple-700 font-medium">Administrator Platform</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-6 text-right">
                                    <?php if ($isSelf): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-400 bg-slate-100 border border-slate-200 cursor-not-allowed">
                                            <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                            <span>Sedang Aktif</span>
                                        </span>
                                    <?php else: ?>
                                        <form action="/login-as" method="POST" class="inline" onsubmit="return confirm('Mulai mode simulasi sebagai <?= htmlspecialchars(addslashes($u['nama'])) ?> (<?= htmlspecialchars($u['role']) ?>)?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="impersonate">
                                            <input type="hidden" name="id_user" value="<?= htmlspecialchars($u['id_user']) ?>">
                                            <button type="submit" 
                                                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow-xs hover:shadow transition">
                                                <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
                                                <span>Masuk Sebagai</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                <div>
                    Menampilkan <span class="font-bold text-slate-800"><?= $totalRows > 0 ? ($offset + 1) : 0 ?> - <?= min($offset + count($userList), $totalRows) ?></span> dari <span class="font-bold text-slate-800"><?= $totalRows ?></span> pengguna
                </div>
                <div class="flex items-center space-x-1.5">
                    <?php
                    $buildPageUrl = function(int $p) use ($search, $filterRole, $filterAngkatan): string {
                        $qParams = ['page' => $p];
                        if ($search !== '') $qParams['q'] = $search;
                        if ($filterRole !== '') $qParams['role'] = $filterRole;
                        if ($filterAngkatan !== '') $qParams['angkatan'] = $filterAngkatan;
                        return '/login-as?' . http_build_query($qParams);
                    };
                    ?>

                    <?php if ($page > 1): ?>
                        <a href="<?= $buildPageUrl($page - 1) ?>" 
                           class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-medium transition">
                            &larr; Sebelumnya
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="<?= $buildPageUrl($i) ?>" 
                           class="px-3 py-1.5 rounded-lg font-bold transition <?= $i === $page ? 'bg-maritime-700 text-white shadow-xs' : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= $buildPageUrl($page + 1) ?>" 
                           class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-medium transition">
                            Berikutnya &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
