<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Dashboard Utama (Multi-Page Application)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

$pageTitle = 'Dashboard Pembelajaran';
$db = get_db();
$currentUser = current_user();
$activeTA = get_active_ta();
$branding = get_branding();
$isDosen = is_dosen();

// Ambil Statistik & Data Kursus
if ($isDosen) {
    if (is_dosen_admin()) {
        // Statistik Dosen Admin (Seluruh Kampus)
        $totalMK = (int)$db->query("SELECT COUNT(*) FROM mata_kuliah")->fetchColumn();
        $totalMhs = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'Mahasiswa'")->fetchColumn();
        $totalTA = (int)$db->query("SELECT COUNT(*) FROM tahun_akademik")->fetchColumn();
        $totalSubmissions = (int)$db->query("SELECT COUNT(*) FROM pengumpulan_tugas")->fetchColumn();

        // Daftar Semua Mata Kuliah
        $stmt = $db->query("
            SELECT mk.*, ta.nama_ta, ta.status AS ta_status,
                   u_koor.nama AS nama_koordinator,
                   (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta,
                   (SELECT COUNT(*) FROM konten_mingguan WHERE id_mk = mk.id_mk) AS total_konten
            FROM mata_kuliah mk
            LEFT JOIN users u_koor ON u_koor.id_user = (
                SELECT id_user FROM dosen_mata_kuliah WHERE id_mk = mk.id_mk ORDER BY urutan ASC LIMIT 1
            )
            LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
            ORDER BY mk.created_at DESC
        ");
        $courses = $stmt->fetchAll();
    } else {
        // Statistik Dosen Biasa (Hanya Kelas yang Ditugaskan)
        $dosenId = $currentUser['id_user'];
        $dosenNama = $currentUser['nama'];
        $dosenUser = $currentUser['username'];

        $stmt = $db->prepare("
            SELECT mk.*, ta.nama_ta, ta.status AS ta_status,
                   u_koor.nama AS nama_koordinator,
                   (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta,
                   (SELECT COUNT(*) FROM konten_mingguan WHERE id_mk = mk.id_mk) AS total_konten
            FROM mata_kuliah mk
            LEFT JOIN users u_koor ON u_koor.id_user = (
                SELECT id_user FROM dosen_mata_kuliah WHERE id_mk = mk.id_mk ORDER BY urutan ASC LIMIT 1
            )
            LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
            WHERE mk.id_dosen = ? 
               OR EXISTS (SELECT 1 FROM dosen_mata_kuliah dmk WHERE dmk.id_mk = mk.id_mk AND dmk.id_user = ?)
               OR (mk.id_dosen IS NULL AND (mk.dosen_pengampu = ? OR mk.dosen_pengampu = ?))
            ORDER BY mk.created_at DESC
        ");
        $stmt->execute([$dosenId, $dosenId, $dosenNama, $dosenUser]);
        $courses = $stmt->fetchAll();

        $totalMK = count($courses);
        $totalTA = (int)$db->query("SELECT COUNT(*) FROM tahun_akademik WHERE status = 'Aktif'")->fetchColumn();

        $stmtMhsCount = $db->prepare("
            SELECT COUNT(DISTINCT e.id_user) 
            FROM enrollment e
            JOIN mata_kuliah mk ON e.id_mk = mk.id_mk
            WHERE mk.id_dosen = ? 
               OR EXISTS (SELECT 1 FROM dosen_mata_kuliah dmk WHERE dmk.id_mk = mk.id_mk AND dmk.id_user = ?)
               OR (mk.id_dosen IS NULL AND (mk.dosen_pengampu = ? OR mk.dosen_pengampu = ?))
        ");
        $stmtMhsCount->execute([$dosenId, $dosenId, $dosenNama, $dosenUser]);
        $totalMhs = (int)$stmtMhsCount->fetchColumn();

        $stmtSubCount = $db->prepare("
            SELECT COUNT(*) 
            FROM pengumpulan_tugas pt
            JOIN konten_mingguan km ON pt.id_konten = km.id_konten
            JOIN mata_kuliah mk ON km.id_mk = mk.id_mk
            WHERE mk.id_dosen = ? 
               OR EXISTS (SELECT 1 FROM dosen_mata_kuliah dmk WHERE dmk.id_mk = mk.id_mk AND dmk.id_user = ?)
               OR (mk.id_dosen IS NULL AND (mk.dosen_pengampu = ? OR mk.dosen_pengampu = ?))
        ");
        $stmtSubCount->execute([$dosenId, $dosenId, $dosenNama, $dosenUser]);
        $totalSubmissions = (int)$stmtSubCount->fetchColumn();
    }
} else {
    // Statistik & Data Mahasiswa
    $userId = $currentUser['id_user'];
    $stmt = $db->prepare("
        SELECT mk.*, ta.nama_ta, ta.status AS ta_status,
               u_koor.nama AS nama_koordinator,
               (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta,
               (SELECT COUNT(*) FROM konten_mingguan WHERE id_mk = mk.id_mk) AS total_konten,
               (SELECT COUNT(*) FROM pengumpulan_tugas pt 
                JOIN konten_mingguan km ON pt.id_konten = km.id_konten 
                WHERE km.id_mk = mk.id_mk AND pt.id_user = ?) AS tugas_dikumpulkan
        FROM enrollment e
        JOIN mata_kuliah mk ON e.id_mk = mk.id_mk
        LEFT JOIN users u_koor ON u_koor.id_user = (
            SELECT id_user FROM dosen_mata_kuliah WHERE id_mk = mk.id_mk ORDER BY urutan ASC LIMIT 1
        )
        LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
        WHERE e.id_user = ?
        ORDER BY mk.created_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    $courses = $stmt->fetchAll();

    $totalMK = count($courses);
    $totalSKS = array_reduce($courses, fn($sum, $c) => $sum + (int)$c['sks'], 0);
    $totalTugasSubmit = array_reduce($courses, fn($sum, $c) => $sum + (int)$c['tugas_dikumpulkan'], 0);
}

// -------------------------------------------------------------
// Fitur Pengingat Tenggat Waktu (Upcoming Deadlines) untuk Mahasiswa
// -------------------------------------------------------------
$pendingDeadlines = [];
$urgentCount = 0;
$now = time();

if (!$isDosen) {
    try {
        $stmtUpcoming = $db->prepare("
            SELECT km.id_konten, km.id_mk, km.minggu_ke, km.tipe, km.judul, km.deadline,
                   mk.nama_mk, mk.kode_mk,
                   pt.id_submit, pt.waktu_submit, pt.nilai
            FROM konten_mingguan km
            JOIN mata_kuliah mk ON km.id_mk = mk.id_mk
            JOIN enrollment e ON e.id_mk = mk.id_mk
            LEFT JOIN pengumpulan_tugas pt ON (pt.id_konten = km.id_konten AND pt.id_user = ?)
            WHERE e.id_user = ?
              AND km.tipe IN ('Tugas', 'Kuis', 'Ujian')
              AND km.deadline IS NOT NULL 
              AND km.deadline != ''
            ORDER BY km.deadline ASC
        ");
        $stmtUpcoming->execute([$userId, $userId]);
        $allDeadlines = $stmtUpcoming->fetchAll();

        foreach ($allDeadlines as $dl) {
            $deadlineTs = strtotime($dl['deadline']);
            $diffSeconds = ($deadlineTs !== false) ? ($deadlineTs - $now) : 0;
            $dl['deadline_ts'] = $deadlineTs;
            $dl['diff_seconds'] = $diffSeconds;
            $dl['is_submitted'] = !empty($dl['id_submit']);
            $dl['is_expired'] = ($deadlineTs !== false && $deadlineTs < $now);

            // Saring hanya tugas/kuis/ujian yang belum dikumpulkan
            if (!$dl['is_submitted']) {
                if (!$dl['is_expired'] && $diffSeconds <= (3 * 86400)) {
                    $urgentCount++;
                }
                $pendingDeadlines[] = $dl;
            }
        }
    } catch (Throwable $e) {
        $pendingDeadlines = [];
    }
}

if (!function_exists('format_deadline_datetime')) {
    function format_deadline_datetime(string $datetimeStr): string {
        $ts = strtotime($datetimeStr);
        if (!$ts) return $datetimeStr;
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int)date('w', $ts)];
        $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][(int)date('n', $ts)];
        return sprintf('%s, %s %s %s • %s WIT', $hari, date('j', $ts), $bulan, date('Y', $ts), date('H:i', $ts));
    }
}

if (!function_exists('get_deadline_badge_info')) {
    function get_deadline_badge_info(int $diffSeconds, bool $isExpired): array {
        if ($isExpired) {
            return [
                'label' => 'Tenggat Waktu Berakhir',
                'badge' => 'bg-rose-100 text-rose-800 border-rose-300',
                'urgent' => true,
                'icon'  => 'alert-circle'
            ];
        }
        
        $hours = (int)floor($diffSeconds / 3600);
        $days = (int)floor($diffSeconds / 86400);

        if ($diffSeconds < 3600) {
            $mins = max(1, (int)floor($diffSeconds / 60));
            return [
                'label' => "Sisa {$mins} mnt lagi! (Mendesak)",
                'badge' => 'bg-rose-100 text-rose-800 border-rose-300 animate-pulse font-bold',
                'urgent' => true,
                'icon'  => 'clock'
            ];
        } elseif ($hours < 24) {
            return [
                'label' => "Sisa {$hours} jam lagi! (Hari Ini)",
                'badge' => 'bg-amber-100 text-amber-900 border-amber-300 font-bold',
                'urgent' => true,
                'icon'  => 'clock'
            ];
        } elseif ($days <= 3) {
            return [
                'label' => "Sisa {$days} hari lagi (Mendekati Batas)",
                'badge' => 'bg-amber-50 text-amber-800 border-amber-200 font-semibold',
                'urgent' => true,
                'icon'  => 'calendar'
            ];
        } else {
            return [
                'label' => "Sisa {$days} hari lagi",
                'badge' => 'bg-cyan-50 text-cyan-800 border-cyan-200 font-medium',
                'urgent' => false,
                'icon'  => 'calendar'
            ];
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Hero / Welcome Banner -->
    <div class="relative overflow-hidden rounded-3xl bg-oceanic-gradient text-white p-6 sm:p-8 lg:p-10 shadow-2xl border border-cyan-500/20">
        <!-- Background subtle wave decorative SVG -->
        <div class="absolute right-0 bottom-0 opacity-15 pointer-events-none transform translate-x-12 translate-y-12">
            <svg width="450" height="300" viewBox="0 0 450 300" fill="none">
                <circle cx="225" cy="150" r="180" stroke="#38BDF8" stroke-width="4" stroke-dasharray="10 10"/>
                <path d="M50 200C120 120 200 240 300 160C380 90 420 220 450 200" stroke="#22D3EE" stroke-width="6"/>
            </svg>
        </div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="space-y-2">
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-950/60 border border-cyan-400/40 text-cyan-300 backdrop-blur-sm">
                    <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                    <span><?= htmlspecialchars($branding['app_name']) ?></span>
                </div>
                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-white tracking-tight font-heading">
                    Selamat Datang, <?= htmlspecialchars($currentUser['nama']) ?>
                </h1>
                <p class="text-sm sm:text-base text-cyan-100/90 max-w-2xl font-light leading-relaxed">
                    <?php if ($isDosen): ?>
                        Kelola silabus, ruang kelas, materi 16 minggu, serta pantau pengerjaan tugas mahasiswa <?= htmlspecialchars($branding['study_program']) ?>.
                    <?php else: ?>
                        Akses modul 16 minggu, unduh RPS & materi kuliah, serta kumpulkan tugas Anda secara langsung ke server maritim Kelautan.ID.
                    <?php endif; ?>
                </p>

                <?php if (!$isDosen && $urgentCount > 0): ?>
                    <div class="pt-2">
                        <a href="#pengingat-deadline" 
                           class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-rose-500/25 hover:bg-rose-500/35 text-rose-100 border border-rose-400/50 backdrop-blur-md transition shadow-xs">
                            <span class="flex h-2 w-2 relative">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                            </span>
                            <span>Ada <strong><?= $urgentCount ?> tenggat waktu</strong> tugas/kuis mendekati batas!</span>
                            <span class="text-cyan-200 underline ml-0.5 font-bold">Lihat Rincian &darr;</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Active Academic Year Box -->
            <div class="bg-slate-900/60 backdrop-blur-md rounded-2xl p-4 sm:p-5 border border-cyan-500/30 text-right md:text-left shadow-lg shrink-0">
                <div class="text-xs uppercase tracking-wider text-cyan-300 font-semibold mb-1">
                    Tahun Akademik Aktif
                </div>
                <div class="text-lg font-bold text-white font-heading">
                    <?= $activeTA ? htmlspecialchars($activeTA['nama_ta']) : 'Belum Ditentukan' ?>
                </div>
                <div class="mt-2 text-xs text-slate-300 flex items-center md:justify-start justify-end space-x-1.5">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span>Status: <?= $activeTA ? 'Semester Berjalan' : 'Non-Aktif' ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Statistics Grid -->
    <section>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php if ($isDosen): ?>
                <!-- Stat 1: Total MK -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Mata Kuliah</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalMK ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                            <i data-lucide="book-open" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Total kelas perkuliahan aktif
                    </div>
                </div>

                <!-- Stat 2: Total Mahasiswa -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Mahasiswa</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalMhs ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-cyan-50 text-ocean flex items-center justify-center">
                            <i data-lucide="users" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Sivitas mahasiswa terdaftar
                    </div>
                </div>

                <!-- Stat 3: Tugas Terkumpul -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Tugas Masuk</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalSubmissions ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i data-lucide="file-check" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Pengumpulan berkas jawaban PDF
                    </div>
                </div>

                <!-- Stat 4: Periode Akademik -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Tahun Akademik</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalTA ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i data-lucide="calendar" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Riwayat siklus perkuliahan
                    </div>
                </div>
            <?php else: ?>
                <!-- Stat Mahasiswa 1: MK Diikuti -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Mata Kuliah</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalMK ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                            <i data-lucide="book-open" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Kelas terdaftar semester ini
                    </div>
                </div>

                <!-- Stat Mahasiswa 2: Total SKS -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Beban SKS</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalSKS ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-cyan-50 text-ocean flex items-center justify-center">
                            <i data-lucide="layers" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Satuan Kredit Semester aktif
                    </div>
                </div>

                <!-- Stat Mahasiswa 3: Tugas Dikumpulkan -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Tugas Terkumpul</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= $totalTugasSubmit ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Jawaban tugas telah terunggah
                    </div>
                </div>

                <!-- Stat Mahasiswa 4: Angkatan -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Angkatan</p>
                            <p class="text-2xl font-extrabold text-abyssal-900 mt-1 font-heading"><?= htmlspecialchars($currentUser['angkatan'] ?? '-') ?></p>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i data-lucide="graduation-cap" class="w-6 h-6"></i>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Tahun masuk vokasi Polikant
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$isDosen): ?>
        <!-- Section Pengingat Tenggat Waktu (Upcoming Deadlines) -->
        <section id="pengingat-deadline" class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center border border-rose-200/60 shadow-xs">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold text-abyssal-900 font-heading flex items-center gap-2">
                            Pengingat Tenggat Waktu
                            <?php if ($urgentCount > 0): ?>
                                <span class="text-xs px-2.5 py-0.5 rounded-full font-bold bg-rose-100 text-rose-800 border border-rose-200 animate-pulse">
                                    <?= $urgentCount ?> Mendesak
                                </span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-xs text-slate-500">
                            Pantau sisa waktu tugas, kuis, dan ujian dari mata kuliah yang Anda ikuti agar tidak terlambat mengumpulkan.
                        </p>
                    </div>
                </div>
                
                <?php if (!empty($pendingDeadlines)): ?>
                    <span class="text-xs text-slate-500 font-medium">
                        <?= count($pendingDeadlines) ?> penugasan berbatas waktu aktif
                    </span>
                <?php endif; ?>
            </div>

            <?php if (empty($pendingDeadlines)): ?>
                <!-- Empty state: Semua tugas telah diselesaikan / belum ada penugasan baru -->
                <div class="bg-gradient-to-r from-emerald-500/10 via-cyan-500/5 to-white bg-white rounded-2xl p-6 border border-emerald-200/80 shadow-xs flex flex-col sm:flex-row items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                        <i data-lucide="check-check" class="w-6 h-6"></i>
                    </div>
                    <div class="flex-1 text-center sm:text-left">
                        <h3 class="text-sm font-bold text-emerald-950 font-heading">
                            Luar Biasa! Tidak Ada Tenggat Waktu Tertunda
                        </h3>
                        <p class="text-xs text-slate-600 mt-0.5">
                            Semua tugas berbatas waktu telah Anda kumpulkan atau belum ada penugasan baru dari dosen pengampu. Tetap pantau ruang kelas Anda!
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Grid Card Deadline -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($pendingDeadlines as $item): ?>
                        <?php 
                            $badge = get_deadline_badge_info($item['diff_seconds'], $item['is_expired']);
                            $routeTarget = !empty($item['kode_mk']) ? $item['kode_mk'] : $item['id_mk'];
                        ?>
                        <div class="bg-white rounded-2xl p-5 border <?= $badge['urgent'] ? 'border-amber-300 ring-1 ring-amber-100 shadow-sm' : 'border-slate-200 shadow-xs' ?> hover:shadow-md transition flex flex-col justify-between group">
                            <div class="space-y-3">
                                <!-- Header Card: Tipe + Minggu + Countdown Badge -->
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full <?= $item['tipe'] === 'Tugas' ? 'bg-blue-100 text-blue-800' : ($item['tipe'] === 'Kuis' ? 'bg-amber-100 text-amber-900' : 'bg-purple-100 text-purple-900') ?>">
                                            <?= htmlspecialchars($item['tipe']) ?>
                                        </span>
                                        <span class="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">
                                            Minggu <?= (int)$item['minggu_ke'] ?>
                                        </span>
                                    </div>
                                    <span class="text-[11px] px-2.5 py-1 rounded-full border <?= $badge['badge'] ?> flex items-center gap-1 shrink-0">
                                        <i data-lucide="<?= $badge['icon'] ?>" class="w-3 h-3"></i>
                                        <span><?= $badge['label'] ?></span>
                                    </span>
                                </div>

                                <!-- Course Title & Code -->
                                <div>
                                    <div class="text-[11px] font-semibold text-ocean uppercase tracking-wider">
                                        <?= htmlspecialchars($item['kode_mk'] ?? '-') ?> • <?= htmlspecialchars($item['nama_mk']) ?>
                                    </div>
                                    <h3 class="text-sm font-bold text-abyssal-900 group-hover:text-ocean-cyan transition mt-1 line-clamp-2">
                                        <?= htmlspecialchars($item['judul']) ?>
                                    </h3>
                                </div>

                                <!-- Deadline Date Time -->
                                <div class="flex items-center text-xs text-slate-500 gap-1.5 pt-1">
                                    <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                                    <span class="font-medium text-slate-700"><?= format_deadline_datetime($item['deadline']) ?></span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-[11px] text-slate-400">
                                    Status: <span class="font-semibold text-rose-600">Belum Dikumpulkan</span>
                                </span>
                                <a href="/kelas/<?= urlencode($routeTarget) ?>/pertemuan" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow-xs hover:shadow transition">
                                    <span>Buka Kelas</span>
                                    <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Course Section Header & Action Buttons -->
    <section class="space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-xl sm:text-2xl font-bold text-abyssal-900 font-heading">
                    <?= $isDosen ? 'Daftar Kelas Perkuliahan' : 'Mata Kuliah yang Anda Ikuti' ?>
                </h2>
                <p class="text-sm text-slate-500">
                    <?= $isDosen ? 'Kelola kurikulum, peserta kelas, dan materi 16 pertemuan.' : 'Pilih mata kuliah untuk mengakses materi perkuliahan dan pengumpulan tugas.' ?>
                </p>
            </div>

            <?php if (is_dosen_admin()): ?>
                <div class="flex flex-wrap items-center gap-2.5">
                    <a href="/mata-kuliah" 
                       class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Tambah Mata Kuliah</span>
                    </a>
                    <a href="/mahasiswa" 
                       class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-slate-700 bg-white hover:bg-slate-50 border border-slate-300 shadow-sm transition">
                        <i data-lucide="user-plus" class="w-4 h-4 text-cyan-600"></i>
                        <span>Impor Mahasiswa</span>
                    </a>
                </div>
            <?php elseif (is_dosen_biasa()): ?>
                <div class="flex items-center">
                    <span class="inline-flex items-center space-x-1.5 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-cyan-50 text-cyan-800 border border-cyan-200">
                        <i data-lucide="shield-check" class="w-4 h-4 text-cyan-600"></i>
                        <span>Mata Kuliah Ditugaskan Dosen Admin</span>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Course Cards Grid -->
        <?php if (empty($courses)): ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-dashed border-slate-300">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-4">
                    <i data-lucide="book-open" class="w-8 h-8"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800 font-heading">Belum Ada Mata Kuliah</h3>
                <p class="text-sm text-slate-500 max-w-md mx-auto mt-1">
                    <?php if ($isDosen): ?>
                        Anda belum menambahkan mata kuliah untuk periode ini. Silakan buat mata kuliah baru untuk memulai.
                    <?php else: ?>
                        Anda belum terdaftar dalam mata kuliah apapun. Silakan hubungi dosen pengampu atau admin program studi.
                    <?php endif; ?>
                </p>
                <?php if ($isDosen): ?>
                    <a href="/mata-kuliah" class="mt-4 inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Buat Mata Kuliah Baru</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($courses as $mk): ?>
                    <?php $mkSlug = !empty($mk['kode_mk']) ? $mk['kode_mk'] : $mk['id_mk']; ?>
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm hover:shadow-xl hover:border-cyan-500/50 transition-all duration-300 flex flex-col overflow-hidden group">
                        
                        <!-- Card Header Top Accent -->
                        <div class="h-2 bg-gradient-to-r from-maritime-700 via-ocean to-cyan-400"></div>

                        <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-100 text-maritime-700 border border-slate-200">
                                        <?= htmlspecialchars($mk['kode_mk']) ?>
                                    </span>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-cyan-50 text-cyan-800 border border-cyan-200">
                                        <?= htmlspecialchars($mk['nama_ta'] ?? 'TA -') ?>
                                    </span>
                                </div>

                                <h3 class="text-lg font-bold text-abyssal-900 group-hover:text-maritime-700 transition font-heading line-clamp-2">
                                    <a href="/kelas/<?= urlencode($mkSlug) ?>">
                                        <?= htmlspecialchars($mk['nama_mk']) ?>
                                    </a>
                                </h3>

                                <p class="text-xs text-slate-500 mt-1 line-clamp-2 leading-relaxed">
                                    <?= htmlspecialchars($mk['deskripsi_mk'] ?: 'Tidak ada deskripsi singkat.') ?>
                                </p>
                            </div>

                            <!-- Course Meta Info -->
                            <div class="pt-4 border-t border-slate-100 space-y-2 text-xs text-slate-600">
                                <div class="flex items-center justify-between">
                                    <?php $dosenKoor = $mk['nama_koordinator'] ?: $mk['dosen_pengampu']; ?>
                                    <span class="flex items-center space-x-1.5" title="Dosen Koordinator: <?= htmlspecialchars($dosenKoor) ?>">
                                        <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-600"></i>
                                        <span class="truncate max-w-[170px] font-medium text-slate-700"><?= htmlspecialchars($dosenKoor) ?></span>
                                    </span>
                                    <span class="font-semibold text-slate-700"><?= (int)$mk['sks'] ?> SKS (Smtr <?= (int)$mk['semester'] ?>)</span>
                                </div>

                                <div class="flex items-center justify-between text-slate-500 pt-1">
                                    <span class="flex items-center space-x-1">
                                        <i data-lucide="users" class="w-3.5 h-3.5 text-cyan-600"></i>
                                        <span><?= (int)$mk['total_peserta'] ?> Mahasiswa</span>
                                    </span>
                                    <span class="flex items-center space-x-1">
                                        <i data-lucide="calendar-days" class="w-3.5 h-3.5 text-emerald-600"></i>
                                        <span><?= (int)$mk['total_konten'] ?> Modul</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Action Footer -->
                        <div class="px-6 py-4 bg-slate-50/80 border-t border-slate-100 flex items-center justify-between gap-2">
                            <a href="/kelas/<?= urlencode($mkSlug) ?>" 
                                class="flex-1 inline-flex items-center justify-center space-x-2 py-2 px-3 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                                <span>Buka Ruang Kelas</span>
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                            </a>

                            <?php if ($isDosen): ?>
                                <a href="/kelas/<?= urlencode($mkSlug) ?>/peserta" 
                                   title="Kelola Peserta Kelas"
                                   class="p-2 rounded-xl text-slate-600 hover:text-cyan-700 hover:bg-cyan-50 border border-slate-200 transition">
                                    <i data-lucide="users" class="w-4 h-4"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
