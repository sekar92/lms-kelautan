<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Manajemen Data Mahasiswa & Impor Massal Excel (Khusus Dosen)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_dosen_admin();

$pageTitle = 'Manajemen Sivitas & Mahasiswa';
$db = get_db();

// Tangani Form Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? 'single';

    if ($action === 'single') {
        $nim = trim((string)($_POST['nim'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'Mahasiswa'));
        if (!in_array($role, ['Mahasiswa', 'Dosen', 'Dosen Admin'], true)) {
            $role = 'Mahasiswa';
        }
        $angkatan = ($role === 'Mahasiswa') ? trim((string)($_POST['angkatan'] ?? date('Y'))) : null;
        $password = trim((string)($_POST['password'] ?? ''));
        if ($password === '') {
            $password = $nim; // Default kata sandi adalah NIM / NIP / Username
        }

        if ($nim === '' || $nama === '') {
            set_flash('error', 'Username/NIM dan Nama Lengkap wajib diisi.');
        } else {
            try {
                // Cek duplikasi
                $stmtCheck = $db->prepare("SELECT id_user FROM users WHERE username = ?");
                $stmtCheck->execute([$nim]);
                if ($stmtCheck->fetch()) {
                    set_flash('error', "Gagal: Pengguna dengan username '{$nim}' sudah terdaftar dalam sistem.");
                } else {
                    $idUser = generate_id('USR', 'users', 'id_user');
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmtIns = $db->prepare("INSERT INTO users (id_user, username, password_hash, nama, role, angkatan) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtIns->execute([$idUser, $nim, $hash, $nama, $role, $angkatan]);
                    set_flash('success', "Pengguna '{$nama}' ({$role} - {$nim}) berhasil ditambahkan.");
                }
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menambahkan data pengguna: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'bulk') {
        $rawText = trim((string)($_POST['bulk_data'] ?? ''));
        if ($rawText === '') {
            set_flash('error', 'Data teks impor massal masih kosong.');
        } else {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $rawText));
            $insertedCount = 0;
            $skippedNIMs = [];

            try {
                $db->beginTransaction();

                $stmtCheck = $db->prepare("SELECT id_user FROM users WHERE username = ?");
                $stmtIns = $db->prepare("INSERT INTO users (id_user, username, password_hash, nama, role, angkatan) VALUES (?, ?, ?, ?, 'Mahasiswa', ?)");

                foreach ($lines as $line) {
                    $cleanLine = trim($line);
                    if ($cleanLine === '') continue;

                    // Pisahkan berdasarkan TAB, Koma, Titik Koma, atau Pipe
                    $parts = preg_split('/[\t,;|]+/', $cleanLine);
                    if (!$parts || count($parts) < 2) continue;

                    $nim = trim($parts[0]);
                    $nama = trim($parts[1]);
                    $angkatan = isset($parts[2]) ? trim($parts[2]) : date('Y');

                    if ($nim === '' || $nama === '') continue;

                    // Cek duplikasi NIM
                    $stmtCheck->execute([$nim]);
                    if ($stmtCheck->fetch()) {
                        $skippedNIMs[] = $nim;
                        continue;
                    }

                    $idUser = generate_id('USR', 'users', 'id_user');
                    $hash = password_hash($nim, PASSWORD_BCRYPT); // Password awal = NIM
                    $stmtIns->execute([$idUser, $nim, $hash, $nama, $angkatan]);
                    $insertedCount++;
                }

                $db->commit();

                $msg = "Impor massal selesai: {$insertedCount} mahasiswa berhasil ditambahkan.";
                if (!empty($skippedNIMs)) {
                    $msg .= " (" . count($skippedNIMs) . " data dilewati karena NIM sudah ada: " . implode(', ', array_slice($skippedNIMs, 0, 5)) . (count($skippedNIMs) > 5 ? '...' : '') . ")";
                }
                set_flash($insertedCount > 0 ? 'success' : 'warning', $msg);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('error', 'Gagal memproses impor massal: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        $idUser = trim((string)($_POST['id_user'] ?? ''));
        $currentUser = current_user();
        if ($idUser !== '' && $idUser !== $currentUser['id_user']) {
            try {
                $stmt = $db->prepare("DELETE FROM users WHERE id_user = ?");
                $stmt->execute([$idUser]);
                set_flash('success', 'Data pengguna berhasil dihapus.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menghapus pengguna: ' . $e->getMessage());
            }
        } elseif ($idUser === $currentUser['id_user']) {
            set_flash('error', 'Anda tidak dapat menghapus akun Anda sendiri saat sedang login.');
        }
    } elseif ($action === 'reset_password') {
        $idUser = trim((string)($_POST['id_user'] ?? ''));
        if ($idUser !== '') {
            try {
                $stmtFetch = $db->prepare("SELECT username FROM users WHERE id_user = ?");
                $stmtFetch->execute([$idUser]);
                $u = $stmtFetch->fetch();
                if ($u) {
                    $newHash = password_hash($u['username'], PASSWORD_BCRYPT);
                    $stmtUp = $db->prepare("UPDATE users SET password_hash = ? WHERE id_user = ?");
                    $stmtUp->execute([$newHash, $idUser]);
                    set_flash('success', "Kata sandi pengguna '{$u['username']}' berhasil direset ke default (sama dengan username/NIM).");
                }
            } catch (Throwable $e) {
                set_flash('error', 'Gagal mereset kata sandi: ' . $e->getMessage());
            }
        }
    }

    header('Location: /mahasiswa');
    exit;
}

// Pencarian & Filter Sivitas
$search = trim((string)($_GET['q'] ?? ''));
$filterAngkatan = trim((string)($_GET['angkatan'] ?? ''));
$filterRole = trim((string)($_GET['role'] ?? ''));

$sql = "
    SELECT u.*, 
           (SELECT COUNT(*) FROM enrollment WHERE id_user = u.id_user) AS total_kelas
    FROM users u
    WHERE 1=1
";
$params = [];

if ($filterRole !== '') {
    $sql .= " AND LOWER(u.role) = LOWER(?)";
    $params[] = $filterRole;
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

// Hitung total data untuk pagination (20 baris per halaman)
$countSql = "SELECT COUNT(*) FROM users u WHERE 1=1";
if ($filterRole !== '') {
    $countSql .= " AND LOWER(u.role) = LOWER(?)";
}
if ($search !== '') {
    $countSql .= " AND (u.nama LIKE ? OR u.username LIKE ?)";
}
if ($filterAngkatan !== '') {
    $countSql .= " AND u.angkatan = ?";
}

$stmtCount = $db->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY CASE WHEN u.role IN ('Dosen Admin', 'Admin') THEN 1 WHEN u.role = 'Dosen' THEN 2 ELSE 3 END, u.angkatan DESC, u.username ASC";
$sql .= " LIMIT {$perPage} OFFSET {$offset}";

$stmtStudents = $db->prepare($sql);
$stmtStudents->execute($params);
$students = $stmtStudents->fetchAll();

// Ambil list angkatan unik untuk filter
$angkatanList = $db->query("SELECT DISTINCT angkatan FROM users WHERE angkatan IS NOT NULL AND angkatan != '' ORDER BY angkatan DESC")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
            <div class="flex items-center space-x-2 text-xs font-semibold text-cyan-700 uppercase tracking-wider">
                <i data-lucide="graduation-cap" class="w-4 h-4"></i>
                <span>Sivitas Akademika</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 font-heading mt-1">
                Data Mahasiswa & Impor Massal
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Kelola akun mahasiswa dan impor data secara instan dari Microsoft Excel atau Google Sheets.
            </p>
        </div>
        <div class="flex items-center space-x-2.5">
            <span class="px-3.5 py-1.5 rounded-full text-xs font-semibold bg-cyan-50 text-cyan-800 border border-cyan-200">
                Total <?= $totalRows ?> Sivitas (Halaman <?= $page ?>/<?= $totalPages ?>)
            </span>
        </div>
    </div>

    <!-- Layout 2 Kolom: Form Input/Import & Tabel Mahasiswa -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Kolom Form (5/12) -->
        <div class="lg:col-span-5 space-y-6">
            
            <!-- Tab Form Pengisian (Satuan vs Bulk) -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
                
                <!-- Section 1: Impor Massal Excel/Sheets -->
                <div class="mb-6 pb-6 border-b border-slate-100">
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i data-lucide="file-spreadsheet" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900 font-heading">Impor Massal (Excel / Sheets)</h2>
                            <p class="text-xs text-slate-500">Salin & tempel ratusan baris sekaligus</p>
                        </div>
                    </div>

                    <form action="/mahasiswa" method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="bulk">

                        <div>
                            <label for="bulk_data" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                Tempel Data Mahasiswa
                            </label>
                            <textarea id="bulk_data" name="bulk_data" rows="6" required
                                      placeholder="2024001	Fajar Bahari	2024&#10;2024002	Siti Samudra	2024&#10;2024003,Rian Kelana,2024"
                                      class="w-full px-3.5 py-2.5 text-xs font-mono bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition"></textarea>
                            <div class="mt-1 text-[11px] text-slate-500 space-y-0.5">
                                <p><span class="font-semibold">Format:</span> <code>NIM [TAB/Koma] Nama [TAB/Koma] Angkatan</code></p>
                                <p class="text-slate-400">Kata sandi awal otomatis disetel sama dengan NIM mahasiswa.</p>
                            </div>
                        </div>

                        <button type="submit" 
                                class="w-full flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 shadow transition">
                            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
                            <span>Simpan Impor Massal</span>
                        </button>
                    </form>
                </div>

                <!-- Section 2: Tambah Mahasiswa Satuan -->
                <div>
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                            <i data-lucide="user-plus" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900 font-heading">Tambah Satuan</h2>
                            <p class="text-xs text-slate-500">Input individual mahasiswa baru</p>
                        </div>
                    </div>

                    <form action="/mahasiswa" method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="single">

                        <div class="space-y-3">
                            <div>
                                <label for="role" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                    Peran / Hak Akses (Role) *
                                </label>
                                <select id="role" name="role" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                    <option value="Mahasiswa" selected>Mahasiswa</option>
                                    <option value="Dosen">Dosen Biasa (Hanya Kelas yang Ditugaskan)</option>
                                    <option value="Dosen Admin">Dosen Admin (Super Admin LMS)</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="nim" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                        NIM / NIP / Username *
                                    </label>
                                    <input id="nim" name="nim" type="text" required placeholder="NIM / NIP"
                                           class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                </div>
                                <div>
                                    <label for="angkatan" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                        Angkatan (Mhs)
                                    </label>
                                    <input id="angkatan" name="angkatan" type="text" value="<?= date('Y') ?>"
                                           class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                </div>
                            </div>

                            <div>
                                <label for="nama" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                    Nama Lengkap Sivitas *
                                </label>
                                <input id="nama" name="nama" type="text" required placeholder="Nama lengkap beserta gelar (jika ada)"
                                       class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            </div>

                            <div>
                                <label for="password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                    Kata Sandi Awal (Opsional)
                                </label>
                                <input id="password" name="password" type="text" placeholder="Kosongkan untuk otomatis sama dengan Username/NIM"
                                       class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            </div>
                        </div>

                        <button type="submit" 
                                class="w-full flex items-center justify-center space-x-2 py-2 px-4 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                            <i data-lucide="plus" class="w-4 h-4"></i>
                            <span>Simpan Pengguna</span>
                        </button>
                    </form>
                </div>

            </div>
        </div>

        <!-- Kolom Tabel Mahasiswa (7/12) -->
        <div class="lg:col-span-7 space-y-4">
            
            <!-- Filter Bar -->
            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex flex-col sm:flex-row gap-3">
                <form action="/mahasiswa" method="GET" class="flex-1 flex gap-2">
                    <div class="relative flex-1">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-3 text-slate-400"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Cari nama atau username/NIM..."
                               class="w-full pl-9 pr-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>
                    <select name="role" class="px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                        <option value="">Semua Peran</option>
                        <option value="Dosen Admin" <?= strtolower($filterRole) === 'dosen admin' ? 'selected' : '' ?>>Dosen Admin</option>
                        <option value="Dosen" <?= strtolower($filterRole) === 'dosen' ? 'selected' : '' ?>>Dosen Biasa</option>
                        <option value="Mahasiswa" <?= strtolower($filterRole) === 'mahasiswa' ? 'selected' : '' ?>>Mahasiswa</option>
                    </select>
                    <?php if (!empty($angkatanList)): ?>
                        <select name="angkatan" class="px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                            <option value="">Semua Angkatan</option>
                            <?php foreach ($angkatanList as $akt): ?>
                                <option value="<?= htmlspecialchars($akt) ?>" <?= $filterAngkatan === $akt ? 'selected' : '' ?>>
                                    Angkatan <?= htmlspecialchars($akt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="px-3.5 py-2 text-xs font-semibold rounded-xl text-white bg-slate-800 hover:bg-slate-900 transition">
                        Filter
                    </button>
                    <?php if ($search !== '' || $filterAngkatan !== '' || $filterRole !== ''): ?>
                        <a href="/mahasiswa" class="px-3 py-2 text-xs font-semibold rounded-xl text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                            Reset
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tabel Data Sivitas & Mahasiswa -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                <th class="py-3.5 px-5">Sivitas Akademika</th>
                                <th class="py-3.5 px-3">Peran (Role)</th>
                                <th class="py-3.5 px-3">Kelas / Angkatan</th>
                                <th class="py-3.5 px-5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-slate-400">
                                        Tidak ada data pengguna yang cocok.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $mhs): ?>
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-3.5 px-5">
                                            <div class="flex items-center space-x-3">
                                                <?php 
                                                $mhsAvatarUrl = get_avatar_url($mhs['foto_profil'] ?? null); 
                                                ?>
                                                <?php if ($mhsAvatarUrl): ?>
                                                    <img src="<?= htmlspecialchars($mhsAvatarUrl) ?>" 
                                                         alt="<?= htmlspecialchars($mhs['nama']) ?>" 
                                                         class="w-9 h-9 rounded-xl object-cover border border-slate-200 shrink-0"
                                                         onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean text-white font-bold text-xs items-center justify-center shrink-0" style="display:none;">
                                                        <?= strtoupper(substr($mhs['nama'], 0, 1)) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean text-white font-bold text-xs flex items-center justify-center shrink-0">
                                                        <?= strtoupper(substr($mhs['nama'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-bold text-slate-900 font-heading">
                                                        <?= htmlspecialchars($mhs['nama']) ?>
                                                    </div>
                                                    <div class="text-xs font-mono text-cyan-800 font-medium">
                                                        <?= htmlspecialchars($mhs['username']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-3">
                                            <?php 
                                            $rRole = strtolower($mhs['role']);
                                            if ($rRole === 'dosen admin' || $rRole === 'admin'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                                    Dosen Admin
                                                </span>
                                            <?php elseif ($rRole === 'dosen'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-cyan-100 text-cyan-800 border border-cyan-200">
                                                    Dosen Biasa
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                    Mahasiswa
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-3">
                                            <?php if (strtolower($mhs['role']) === 'mahasiswa'): ?>
                                                <div class="text-xs font-semibold text-maritime-700">
                                                    <?= (int)$mhs['total_kelas'] ?> Kelas
                                                </div>
                                                <div class="text-[11px] text-slate-400">
                                                    Angkatan <?= htmlspecialchars($mhs['angkatan'] ?? '-') ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-500 font-medium">Tenaga Pengajar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-5 text-right">
                                            <div class="flex items-center justify-end space-x-1.5">
                                                <?php if (is_dosen_admin() && $mhs['id_user'] !== $_SESSION['user_id']): ?>
                                                    <!-- Login-As Form (POST) -->
                                                    <form action="/login-as" method="POST" class="inline" onsubmit="return confirm('Masuk ke sistem sebagai <?= htmlspecialchars(addslashes($mhs['nama'])) ?> (<?= htmlspecialchars($mhs['role']) ?>)?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="impersonate">
                                                        <input type="hidden" name="id_user" value="<?= htmlspecialchars($mhs['id_user']) ?>">
                                                        <button type="submit" title="Login Sebagai Pengguna Ini (Mode Simulasi)" class="p-1.5 text-xs text-ocean hover:text-ocean-cyan hover:bg-cyan-50 rounded-lg transition">
                                                            <i data-lucide="log-in" class="w-4 h-4"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Reset Password Form (POST) -->
                                                <form action="/mahasiswa" method="POST" class="inline" onsubmit="return confirm('Reset kata sandi mahasiswa ini ke default (sama dengan NIM)?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="id_user" value="<?= htmlspecialchars($mhs['id_user']) ?>">
                                                    <button type="submit" title="Reset Kata Sandi ke NIM" class="p-1.5 text-xs text-amber-600 hover:bg-amber-50 rounded-lg transition">
                                                        <i data-lucide="key" class="w-4 h-4"></i>
                                                    </button>
                                                </form>

                                                <!-- Hapus Mahasiswa (POST) -->
                                                <form action="/mahasiswa" method="POST" class="inline" onsubmit="return confirm('Hapus mahasiswa ini dari sistem? Seluruh pendaftaran kelas dan tugas terkait akan ikut terhapus!');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id_user" value="<?= htmlspecialchars($mhs['id_user']) ?>">
                                                    <button type="submit" title="Hapus Mahasiswa" class="p-1.5 text-xs text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition">
                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Kontrol Navigasi Pagination (20 Baris per Halaman) -->
                <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                        <div>
                            Menampilkan <span class="font-bold text-slate-800"><?= $totalRows > 0 ? ($offset + 1) : 0 ?> - <?= min($offset + count($students), $totalRows) ?></span> dari <span class="font-bold text-slate-800"><?= $totalRows ?></span> sivitas
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <?php
                            $buildPageUrl = function(int $p) use ($search, $filterRole, $filterAngkatan): string {
                                $qParams = ['page' => $p];
                                if ($search !== '') $qParams['q'] = $search;
                                if ($filterRole !== '') $qParams['role'] = $filterRole;
                                if ($filterAngkatan !== '') $qParams['angkatan'] = $filterAngkatan;
                                return '/mahasiswa?' . http_build_query($qParams);
                            };
                            ?>

                            <!-- Tombol Sebelumnya -->
                            <?php if ($page > 1): ?>
                                <a href="<?= $buildPageUrl($page - 1) ?>" 
                                   class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold transition">
                                    &larr; Sebelumnya
                                </a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 rounded-xl border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed select-none">
                                    &larr; Sebelumnya
                                </span>
                            <?php endif; ?>

                            <!-- Angka Halaman -->
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            if ($startPage > 1) {
                                echo '<a href="' . $buildPageUrl(1) . '" class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-bold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700">1</a>';
                                if ($startPage > 2) echo '<span class="px-1 text-slate-400">...</span>';
                            }
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="<?= $buildPageUrl($i) ?>" 
                                   class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-bold transition <?= $i === $page ? 'bg-maritime-700 text-white shadow-sm' : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; 
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) echo '<span class="px-1 text-slate-400">...</span>';
                                echo '<a href="' . $buildPageUrl($totalPages) . '" class="w-8 h-8 flex items-center justify-center rounded-xl text-xs font-bold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700">' . $totalPages . '</a>';
                            }
                            ?>

                            <!-- Tombol Berikutnya -->
                            <?php if ($page < $totalPages): ?>
                                <a href="<?= $buildPageUrl($page + 1) ?>" 
                                   class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold transition">
                                    Berikutnya &rarr;
                                </a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 rounded-xl border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed select-none">
                                    Berikutnya &rarr;
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
