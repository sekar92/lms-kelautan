<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Log Aktivitas & Audit Trail Sistem
 * Khusus Dosen Admin / Super Admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_dosen_admin();

$db = get_db();
$currentUser = current_user();
$branding = get_branding();
$pageTitle = 'Log Aktivitas & Audit Trail';

// =========================================================================
// 1. FILTER & PENCARIAN
// =========================================================================
$search = trim((string)($_GET['q'] ?? ''));
$filterTipe = trim((string)($_GET['tipe'] ?? ''));
$filterRole = trim((string)($_GET['role'] ?? ''));
$filterSimulasi = trim((string)($_GET['simulasi'] ?? ''));
$filterWaktu = trim((string)($_GET['waktu'] ?? ''));

$whereClauses = ['1=1'];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(la.deskripsi LIKE ? OR la.nama LIKE ? OR la.username LIKE ? OR la.ip_address LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($filterTipe !== '') {
    if ($filterTipe === 'AUTH') {
        $whereClauses[] = "la.tipe_aktivitas IN ('LOGIN', 'LOGOUT', 'LOGIN_GAGAL')";
    } elseif ($filterTipe === 'TUGAS') {
        $whereClauses[] = "la.tipe_aktivitas IN ('UPLOAD_TUGAS', 'UPDATE_TUGAS')";
    } elseif ($filterTipe === 'SIMULASI') {
        $whereClauses[] = "la.tipe_aktivitas IN ('LOGIN_AS', 'REVERT_ADMIN')";
    } elseif ($filterTipe === 'KONTEN') {
        $whereClauses[] = "la.tipe_aktivitas IN ('TAMBAH_KONTEN', 'HAPUS_KONTEN', 'UPDATE_PESERTA')";
    } else {
        $whereClauses[] = "la.tipe_aktivitas = ?";
        $params[] = $filterTipe;
    }
}

if ($filterRole !== '') {
    $whereClauses[] = "LOWER(la.role) = LOWER(?)";
    $params[] = $filterRole;
}

if ($filterSimulasi === '1') {
    $whereClauses[] = "la.is_impersonated = 1";
}

if ($filterWaktu !== '') {
    if ($filterWaktu === 'today') {
        $whereClauses[] = "DATE(la.created_at) = CURRENT_DATE";
    } elseif ($filterWaktu === '7days') {
        $whereClauses[] = "la.created_at >= datetime('now', '-7 days')";
    } elseif ($filterWaktu === '30days') {
        $whereClauses[] = "la.created_at >= datetime('now', '-30 days')";
    }
}

$whereSql = implode(' AND ', $whereClauses);

// =========================================================================
// 2. EKSPOR KE CSV
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $db->prepare("
        SELECT la.* 
        FROM log_aktivitas la 
        WHERE {$whereSql} 
        ORDER BY la.created_at DESC 
        LIMIT 5000
    ");
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'LMS_Log_Aktivitas_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Output BOM agar aksen dan huruf terbaca baik di Microsoft Excel
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    fputcsv($output, ['ID Log', 'Waktu (WIT)', 'ID User', 'Username/NIM', 'Nama Pengguna', 'Peran (Role)', 'Tipe Aktivitas', 'Keterangan Aktivitas', 'IP Address', 'Mode Simulasi (Login-As)', 'User Agent']);

    foreach ($exportRows as $r) {
        fputcsv($output, [
            $r['id_log'],
            $r['created_at'],
            $r['id_user'] ?? '-',
            $r['username'] ?? '-',
            $r['nama'] ?? '-',
            $r['role'] ?? '-',
            $r['tipe_aktivitas'],
            $r['deskripsi'],
            $r['ip_address'] ?? '-',
            $r['is_impersonated'] ? 'Ya' : 'Tidak',
            $r['user_agent'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

// =========================================================================
// 3. STATISTIK RINGKAS
// =========================================================================
$totalLogs = (int)$db->query("SELECT COUNT(*) FROM log_aktivitas")->fetchColumn();
$totalToday = (int)$db->query("SELECT COUNT(*) FROM log_aktivitas WHERE DATE(created_at) = CURRENT_DATE")->fetchColumn();
$totalTugasUpload = (int)$db->query("SELECT COUNT(*) FROM log_aktivitas WHERE tipe_aktivitas IN ('UPLOAD_TUGAS', 'UPDATE_TUGAS')")->fetchColumn();
$totalSimulasi = (int)$db->query("SELECT COUNT(*) FROM log_aktivitas WHERE tipe_aktivitas = 'LOGIN_AS' OR is_impersonated = 1")->fetchColumn();

// =========================================================================
// 4. HITUNG PAGINATION
// =========================================================================
$stmtCount = $db->prepare("SELECT COUNT(*) FROM log_aktivitas la WHERE {$whereSql}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$perPage = 25;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;

// Query Data Log
$stmtLogs = $db->prepare("
    SELECT la.* 
    FROM log_aktivitas la 
    WHERE {$whereSql} 
    ORDER BY la.created_at DESC 
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

/**
 * Format Badge Tipe Aktivitas
 */
function get_tipe_badge(string $tipe): array {
    return match (strtoupper($tipe)) {
        'UPLOAD_TUGAS' => [
            'label' => 'Upload Tugas',
            'class' => 'bg-emerald-100 text-emerald-800 border-emerald-300 font-bold',
            'icon'  => 'file-up'
        ],
        'UPDATE_TUGAS' => [
            'label' => 'Revisi Tugas',
            'class' => 'bg-cyan-100 text-cyan-800 border-cyan-300 font-bold',
            'icon'  => 'file-check'
        ],
        'NILAI_TUGAS' => [
            'label' => 'Penilaian Tugas',
            'class' => 'bg-amber-100 text-amber-900 border-amber-300 font-semibold',
            'icon'  => 'award'
        ],
        'LOGIN' => [
            'label' => 'Login Masuk',
            'class' => 'bg-blue-100 text-blue-800 border-blue-200 font-medium',
            'icon'  => 'log-in'
        ],
        'LOGIN_GAGAL' => [
            'label' => 'Login Gagal',
            'class' => 'bg-rose-100 text-rose-800 border-rose-300 font-bold animate-pulse',
            'icon'  => 'alert-triangle'
        ],
        'LOGOUT' => [
            'label' => 'Logout',
            'class' => 'bg-slate-100 text-slate-700 border-slate-200 font-medium',
            'icon'  => 'log-out'
        ],
        'LOGIN_AS' => [
            'label' => 'Mulai Simulasi',
            'class' => 'bg-purple-100 text-purple-900 border-purple-300 font-bold',
            'icon'  => 'user-check'
        ],
        'REVERT_ADMIN' => [
            'label' => 'Selesai Simulasi',
            'class' => 'bg-purple-50 text-purple-800 border-purple-200 font-medium',
            'icon'  => 'shield'
        ],
        'TAMBAH_KONTEN' => [
            'label' => 'Tambah Modul',
            'class' => 'bg-indigo-100 text-indigo-800 border-indigo-200 font-medium',
            'icon'  => 'plus-circle'
        ],
        'HAPUS_KONTEN' => [
            'label' => 'Hapus Modul',
            'class' => 'bg-rose-50 text-rose-700 border-rose-200 font-medium',
            'icon'  => 'trash-2'
        ],
        'UPDATE_PESERTA' => [
            'label' => 'Kelola Peserta',
            'class' => 'bg-teal-100 text-teal-800 border-teal-200 font-medium',
            'icon'  => 'users'
        ],
        'GANTI_PASSWORD' => [
            'label' => 'Ganti Sandi',
            'class' => 'bg-orange-100 text-orange-800 border-orange-200 font-medium',
            'icon'  => 'key'
        ],
        default => [
            'label' => $tipe,
            'class' => 'bg-slate-100 text-slate-700 border-slate-200 font-medium',
            'icon'  => 'activity'
        ]
    };
}

/**
 * Parsing ringkas User Agent
 */
function parse_user_agent_simple(string $ua): string {
    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) return 'Chrome';
    if (stripos($ua, 'Edg') !== false) return 'Edge';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false) return 'Mobile Browser';
    return 'Web Browser';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200 mb-2">
                <i data-lucide="clipboard-list" class="w-3.5 h-3.5 text-ocean"></i>
                <span>Audit Trail & Activity Log</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Log Aktivitas Pengguna
            </h1>
            <p class="text-sm text-slate-500 max-w-2xl mt-1">
                Pantau seluruh rekam jejak aktivitas login, pengumpulan tugas mahasiswa, penilaian dosen, serta riwayat simulasi akun (Login-As) secara transparan dan akurat.
            </p>
        </div>

        <!-- Export CSV Button -->
        <div class="flex items-center space-x-2.5 shrink-0">
            <?php
            $exportUrlParams = $_GET;
            $exportUrlParams['export'] = 'csv';
            $exportUrl = '/log-aktivitas?' . http_build_query($exportUrlParams);
            ?>
            <a href="<?= htmlspecialchars($exportUrl) ?>" 
               class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 shadow-xs hover:shadow transition">
                <i data-lucide="download" class="w-4 h-4 text-slate-600"></i>
                <span>Ekspor Data (CSV)</span>
            </a>
        </div>
    </div>

    <!-- Quick Statistics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Stat 1: Total Riwayat -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Riwayat</p>
                <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= number_format($totalLogs) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5">Seluruh log tersimpan</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                <i data-lucide="database" class="w-6 h-6"></i>
            </div>
        </div>

        <!-- Stat 2: Log Hari Ini -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aktivitas Hari Ini</p>
                <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= number_format($totalToday) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5">Catatan waktu berjalan</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-cyan-50 text-ocean flex items-center justify-center">
                <i data-lucide="clock" class="w-6 h-6"></i>
            </div>
        </div>

        <!-- Stat 3: Tugas Mahasiswa -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aktivitas Tugas</p>
                <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= number_format($totalTugasUpload) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5">Upload & revisi tugas</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <i data-lucide="file-check" class="w-6 h-6"></i>
            </div>
        </div>

        <!-- Stat 4: Simulasi (Login-As) -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Sesi Simulasi</p>
                <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= number_format($totalSimulasi) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5">Audit Login-As admin</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center">
                <i data-lucide="user-check" class="w-6 h-6"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm space-y-3">
        <form action="/log-aktivitas" method="GET" class="flex flex-col md:flex-row gap-3">
            <!-- Search Input -->
            <div class="relative flex-1">
                <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-3 text-slate-400"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Cari nama, NIM, kata kunci tugas, atau IP address..."
                       class="w-full pl-10 pr-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
            </div>

            <!-- Filter Kategori Aktivitas -->
            <select name="tipe" class="px-3.5 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                <option value="">Semua Kategori</option>
                <option value="TUGAS" <?= $filterTipe === 'TUGAS' ? 'selected' : '' ?>>Tugas Mahasiswa (Upload/Update)</option>
                <option value="NILAI_TUGAS" <?= $filterTipe === 'NILAI_TUGAS' ? 'selected' : '' ?>>Penilaian Tugas Dosen</option>
                <option value="AUTH" <?= $filterTipe === 'AUTH' ? 'selected' : '' ?>>Autentikasi (Login & Logout)</option>
                <option value="SIMULASI" <?= $filterTipe === 'SIMULASI' ? 'selected' : '' ?>>Simulasi Akun (Login-As)</option>
                <option value="KONTEN" <?= $filterTipe === 'KONTEN' ? 'selected' : '' ?>>Modul Kelas & Pertemuan</option>
                <option value="GANTI_PASSWORD" <?= $filterTipe === 'GANTI_PASSWORD' ? 'selected' : '' ?>>Ganti Kata Sandi</option>
            </select>

            <!-- Filter Peran -->
            <select name="role" class="px-3.5 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                <option value="">Semua Peran</option>
                <option value="Mahasiswa" <?= strtolower($filterRole) === 'mahasiswa' ? 'selected' : '' ?>>Mahasiswa Saja</option>
                <option value="Dosen" <?= strtolower($filterRole) === 'dosen' ? 'selected' : '' ?>>Dosen Biasa</option>
                <option value="Dosen Admin" <?= strtolower($filterRole) === 'dosen admin' ? 'selected' : '' ?>>Dosen Admin</option>
            </select>

            <!-- Filter Waktu -->
            <select name="waktu" class="px-3.5 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                <option value="">Semua Waktu</option>
                <option value="today" <?= $filterWaktu === 'today' ? 'selected' : '' ?>>Hari Ini</option>
                <option value="7days" <?= $filterWaktu === '7days' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                <option value="30days" <?= $filterWaktu === '30days' ? 'selected' : '' ?>>30 Hari Terakhir</option>
            </select>

            <!-- Filter Hanya Simulasi -->
            <label class="inline-flex items-center space-x-2 px-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl cursor-pointer select-none">
                <input type="checkbox" name="simulasi" value="1" <?= $filterSimulasi === '1' ? 'checked' : '' ?> class="rounded text-maritime-700 focus:ring-cyan-500">
                <span class="text-slate-700 font-medium">Hanya Login-As</span>
            </label>

            <!-- Submit Button -->
            <button type="submit" class="inline-flex items-center justify-center space-x-1.5 px-5 py-2 text-xs sm:text-sm font-semibold rounded-xl text-white bg-slate-900 hover:bg-slate-800 transition shadow-xs">
                <i data-lucide="filter" class="w-4 h-4"></i>
                <span>Terapkan</span>
            </button>

            <?php if ($search !== '' || $filterTipe !== '' || $filterRole !== '' || $filterSimulasi !== '' || $filterWaktu !== ''): ?>
                <a href="/log-aktivitas" class="inline-flex items-center justify-center px-4 py-2 text-xs sm:text-sm font-semibold rounded-xl text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                    Reset
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Data Log Aktivitas -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                        <th class="py-4 px-5">Waktu (WIT)</th>
                        <th class="py-4 px-4">Pengguna</th>
                        <th class="py-4 px-3">Aktivitas</th>
                        <th class="py-4 px-5">Rincian Peristiwa</th>
                        <th class="py-4 px-4 text-right">IP & Perangkat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400">
                                <i data-lucide="inbox" class="w-10 h-10 mx-auto text-slate-300 mb-2"></i>
                                <p class="text-sm font-semibold text-slate-600">Belum ada riwayat aktivitas yang tercatat dengan kriteria ini.</p>
                                <p class="text-xs text-slate-400 mt-0.5">Semua aksi login, tugas, dan perubahan data akan otomatis tercatat di sini.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php 
                            $badge = get_tipe_badge($log['tipe_aktivitas']);
                            $parsedUA = parse_user_agent_simple($log['user_agent'] ?? '');
                            $logTime = strtotime($log['created_at']);
                            ?>
                            <tr class="hover:bg-slate-50/70 transition <?= $log['is_impersonated'] ? 'bg-amber-50/20' : '' ?>">
                                <!-- 1. Waktu -->
                                <td class="py-4 px-5 whitespace-nowrap">
                                    <div class="font-medium text-slate-900 text-xs">
                                        <?= date('d M Y', $logTime) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500 font-mono flex items-center gap-1 mt-0.5">
                                        <i data-lucide="clock" class="w-3 h-3 text-slate-400"></i>
                                        <span><?= date('H:i:s', $logTime) ?> WIT</span>
                                    </div>
                                </td>

                                <!-- 2. Pengguna -->
                                <td class="py-4 px-4">
                                    <div class="flex items-center space-x-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center font-bold text-xs text-white shrink-0 shadow-xs">
                                            <?= strtoupper(substr($log['nama'] ?: ($log['username'] ?: 'U'), 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-slate-900 text-xs truncate max-w-[160px] font-heading">
                                                <?= htmlspecialchars($log['nama'] ?: ($log['username'] ?: 'Sistem/Tamu')) ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 font-mono">
                                                <?= htmlspecialchars($log['username'] ?: '-') ?> • 
                                                <span class="font-semibold text-slate-700"><?= htmlspecialchars($log['role'] ?: 'Anonim') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- 3. Badge Tipe Aktivitas -->
                                <td class="py-4 px-3 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] border <?= $badge['class'] ?>">
                                        <i data-lucide="<?= $badge['icon'] ?>" class="w-3 h-3 shrink-0"></i>
                                        <span><?= $badge['label'] ?></span>
                                    </span>
                                </td>

                                <!-- 4. Rincian Deskripsi -->
                                <td class="py-4 px-5">
                                    <p class="text-xs text-slate-800 leading-relaxed font-normal">
                                        <?= htmlspecialchars($log['deskripsi']) ?>
                                    </p>
                                    <?php if ($log['is_impersonated']): ?>
                                        <div class="mt-1 inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-amber-100 text-amber-900 border border-amber-300">
                                            <i data-lucide="shield-alert" class="w-3 h-3 text-amber-700"></i>
                                            <span>Via Simulasi Login-As Admin</span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 5. IP & Perangkat -->
                                <td class="py-4 px-4 text-right whitespace-nowrap">
                                    <div class="text-xs font-mono text-slate-700 font-medium">
                                        <?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400 mt-0.5" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
                                        <?= htmlspecialchars($parsedUA) ?>
                                    </div>
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
                    Menampilkan <span class="font-bold text-slate-800"><?= $totalRows > 0 ? ($offset + 1) : 0 ?> - <?= min($offset + count($logs), $totalRows) ?></span> dari <span class="font-bold text-slate-800"><?= number_format($totalRows) ?></span> aktivitas
                </div>
                <div class="flex items-center space-x-1.5">
                    <?php
                    $buildPageUrl = function(int $p) use ($search, $filterTipe, $filterRole, $filterSimulasi, $filterWaktu): string {
                        $qParams = ['page' => $p];
                        if ($search !== '') $qParams['q'] = $search;
                        if ($filterTipe !== '') $qParams['tipe'] = $filterTipe;
                        if ($filterRole !== '') $qParams['role'] = $filterRole;
                        if ($filterSimulasi !== '') $qParams['simulasi'] = $filterSimulasi;
                        if ($filterWaktu !== '') $qParams['waktu'] = $filterWaktu;
                        return '/log-aktivitas?' . http_build_query($qParams);
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
