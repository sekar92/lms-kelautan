<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Evaluasi & Penilaian Tugas Mahasiswa (Khusus Dosen)
 * URL: tugas-nilai.php?id_konten=KNT-XXXXX
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_dosen();

$db = get_db();
$currentUser = current_user();
$idKonten = trim((string)($_GET['id_konten'] ?? ''));

if ($idKonten === '') {
    set_flash('error', 'ID Tugas tidak valid.');
    header('Location: /dashboard');
    exit;
}

// Ambil data Tugas & Mata Kuliah
$stmtTask = $db->prepare("
    SELECT k.*, mk.id_mk, mk.nama_mk, mk.kode_mk, mk.dosen_pengampu, ta.nama_ta
    FROM konten_mingguan k
    JOIN mata_kuliah mk ON k.id_mk = mk.id_mk
    LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
    WHERE k.id_konten = ? AND k.tipe = 'Tugas'
    LIMIT 1
");
$stmtTask->execute([$idKonten]);
$task = $stmtTask->fetch();

if (!$task) {
    set_flash('error', 'Tugas tidak ditemukan.');
    header('Location: /dashboard');
    exit;
}

// Cek hak akses dosen biasa pada mata kuliah ini
if (is_dosen_biasa()) {
    if (!is_dosen_assigned_to_course($task['id_mk'], $currentUser['id_user'])) {
        set_flash('error', 'Akses ditolak: Anda tidak ditugaskan sebagai dosen pengampu pada mata kuliah ini.');
        header('Location: /dashboard');
        exit;
    }
}

// Tangani Form Input Nilai & Feedback Dosen (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $idSubmit = trim((string)($_POST['id_submit'] ?? ''));
    $nilai = trim((string)($_POST['nilai'] ?? ''));
    $feedback = trim((string)($_POST['feedback'] ?? ''));

    if ($idSubmit !== '') {
        try {
            $numNilai = ($nilai !== '') ? (float)$nilai : null;
            if ($numNilai !== null && ($numNilai < 0 || $numNilai > 100)) {
                set_flash('error', 'Nilai harus berada dalam rentang 0 s/d 100.');
            } else {
                $stmtUp = $db->prepare("UPDATE pengumpulan_tugas SET nilai = ?, feedback = ? WHERE id_submit = ?");
                $stmtUp->execute([$numNilai, $feedback, $idSubmit]);

                // Catat log aktivitas penilaian
                $stmtMhsInfo = $db->prepare("SELECT u.nama, u.username FROM pengumpulan_tugas pt JOIN users u ON u.id_user = pt.id_user WHERE pt.id_submit = ?");
                $stmtMhsInfo->execute([$idSubmit]);
                $mhsInfo = $stmtMhsInfo->fetch();
                $mhsNama = $mhsInfo['nama'] ?? 'Mahasiswa';
                $mhsNim = $mhsInfo['username'] ?? '-';
                log_activity('NILAI_TUGAS', "Dosen '{$currentUser['nama']}' memberikan penilaian skor '{$numNilai}' untuk mahasiswa '{$mhsNama}' (NIM: {$mhsNim}) pada tugas '{$task['judul']}'.");

                set_flash('success', 'Nilai dan evaluasi kualitatif berhasil disimpan.');
            }
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menyimpan nilai: ' . $e->getMessage());
        }
    }

    header("Location: /nilai/{$idKonten}");
    exit;
}

// Ambil daftar seluruh mahasiswa peserta kelas ini beserta status pengumpulannya
$stmtStudents = $db->prepare("
    SELECT u.id_user, u.username AS nim, u.nama, u.angkatan,
           pt.id_submit, pt.file_path, pt.file_name, pt.link_eksternal, pt.catatan_mhs, pt.nilai, pt.feedback, pt.waktu_submit
    FROM enrollment e
    JOIN users u ON e.id_user = u.id_user
    LEFT JOIN pengumpulan_tugas pt ON pt.id_user = u.id_user AND pt.id_konten = ?
    WHERE e.id_mk = ?
    ORDER BY u.username ASC
");
$stmtStudents->execute([$idKonten, $task['id_mk']]);
$submissions = $stmtStudents->fetchAll();

// Hitung Statistik
$totalPeserta = count($submissions);
$totalDikumpulkan = count(array_filter($submissions, fn($s) => !empty($s['id_submit'])));
$totalDinilai = count(array_filter($submissions, fn($s) => !empty($s['id_submit']) && $s['nilai'] !== null));
$totalBelumDinilai = $totalDikumpulkan - $totalDinilai;

$pageTitle = 'Evaluasi & Penilaian: ' . $task['judul'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Header Tugas & Rekap -->
    <div class="relative overflow-hidden rounded-3xl bg-maritime-header text-white p-6 sm:p-8 shadow-xl border border-slate-700">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="space-y-2">
                <div class="flex items-center space-x-2">
                    <a href="/kelas/<?= urlencode(!empty($task['kode_mk']) ? $task['kode_mk'] : $task['id_mk']) ?>/pertemuan" 
                       class="text-xs font-semibold text-cyan-300 hover:text-white flex items-center space-x-1">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                        <span>Kembali ke Ruang Kelas</span>
                    </a>
                    <span class="text-slate-500">•</span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                        Minggu ke-<?= (int)$task['minggu_ke'] ?>
                    </span>
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight font-heading">
                    <?= htmlspecialchars($task['judul']) ?>
                </h1>

                <p class="text-xs text-slate-300">
                    Mata Kuliah: <strong class="text-white"><?= htmlspecialchars($task['nama_mk']) ?> (<?= htmlspecialchars($task['kode_mk']) ?>)</strong> 
                    • Dosen: <?= htmlspecialchars($task['dosen_pengampu']) ?>
                </p>

                <?php if (!empty($task['deadline'])): ?>
                    <div class="text-xs text-cyan-300 flex items-center space-x-1 pt-1">
                        <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                        <span>Batas Waktu: <?= date('d M Y, H:i', strtotime($task['deadline'])) ?> WIT</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pratinjau Soal & Aksi Cepat -->
            <div class="shrink-0 flex items-center space-x-2">
                <?php if (!empty($task['file_path'])): ?>
                    <button type="button" 
                            onclick="openPdfModal('/unduh.php?jenis=materi&id_konten=<?= urlencode($idKonten) ?>&mode=inline', 'Soal Tugas - <?= htmlspecialchars(addslashes($task['judul'])) ?>')"
                            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-cyan-300 bg-cyan-950 hover:bg-cyan-900 border border-cyan-800 transition">
                        <i data-lucide="file-text" class="w-4 h-4"></i>
                        <span>Lihat Soal PDF</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ringkasan Statistik Penilaian -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase">Peserta Kelas</p>
            <p class="text-2xl font-extrabold text-abyssal-900 font-heading mt-1"><?= $totalPeserta ?></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase">Sudah Mengumpulkan</p>
            <p class="text-2xl font-extrabold text-cyan-700 font-heading mt-1"><?= $totalDikumpulkan ?></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase">Telah Dinilai</p>
            <p class="text-2xl font-extrabold text-emerald-700 font-heading mt-1"><?= $totalDinilai ?></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase">Perlu Dinilai</p>
            <p class="text-2xl font-extrabold text-amber-600 font-heading mt-1"><?= $totalBelumDinilai ?></p>
        </div>
    </div>

    <!-- Tabel Mahasiswa & Penilaian Dosen -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900 font-heading">
                    Rekap Jawaban Mahasiswa
                </h2>
                <p class="text-xs text-slate-500">
                    Periksa berkas jawaban PDF mahasiswa dan masukkan nilai evaluasi beserta feedback.
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                        <th class="py-3.5 px-6">Mahasiswa</th>
                        <th class="py-3.5 px-4">Status & Waktu Submit</th>
                        <th class="py-3.5 px-4">Berkas Jawaban</th>
                        <th class="py-3.5 px-6">Penilaian & Feedback Dosen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400">
                                Belum ada mahasiswa yang terdaftar di kelas ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): ?>
                            <tr class="hover:bg-slate-50/60 transition">
                                
                                <!-- Kolom Mahasiswa -->
                                <td class="py-4 px-6 align-top">
                                    <div class="font-bold text-slate-900 font-heading">
                                        <?= htmlspecialchars($sub['nama']) ?>
                                    </div>
                                    <div class="text-xs font-mono text-cyan-800">
                                        NIM: <?= htmlspecialchars($sub['nim']) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        Angkatan <?= htmlspecialchars($sub['angkatan'] ?? '-') ?>
                                    </div>
                                </td>

                                <!-- Kolom Status & Waktu Submit -->
                                <td class="py-4 px-4 align-top">
                                    <?php if (empty($sub['id_submit'])): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-500">
                                            Belum Mengumpulkan
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200">
                                            Sudah Mengumpulkan
                                        </span>
                                        <div class="text-[11px] text-slate-500 mt-1 flex items-center space-x-1">
                                            <i data-lucide="clock" class="w-3 h-3 text-slate-400"></i>
                                            <span><?= date('d M Y, H:i', strtotime($sub['waktu_submit'])) ?> WIT</span>
                                        </div>
                                        <?php if (!empty($sub['catatan_mhs'])): ?>
                                            <div class="mt-2 text-xs text-slate-600 bg-slate-50 p-2 rounded-lg border border-slate-200/80">
                                                <span class="font-semibold text-slate-700">Catatan Mhs:</span><br>
                                                <?= nl2br(htmlspecialchars($sub['catatan_mhs'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Kolom Berkas Jawaban & Tautan -->
                                <td class="py-4 px-4 align-top">
                                    <?php if (!empty($sub['id_submit'])): ?>
                                        <div class="space-y-2">
                                            <!-- Berkas Fisik jika ada -->
                                            <?php if (!empty($sub['file_name'])): ?>
                                                <?php 
                                                    $ext = strtolower(pathinfo($sub['file_name'], PATHINFO_EXTENSION));
                                                    $isPdf = ($ext === 'pdf');
                                                ?>
                                                <div class="p-2 rounded-lg bg-slate-50 border border-slate-200 space-y-1">
                                                    <div class="text-xs font-medium text-slate-700 truncate max-w-[210px]" title="<?= htmlspecialchars($sub['file_name']) ?>">
                                                        <?= htmlspecialchars($sub['file_name']) ?>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <?php if ($isPdf): ?>
                                                            <button type="button" 
                                                                    onclick="openPdfModal('/unduh.php?jenis=tugas_mhs&id_submit=<?= urlencode($sub['id_submit']) ?>&mode=inline', 'Jawaban - <?= htmlspecialchars(addslashes($sub['nama'])) ?>')"
                                                                    class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-cyan-800 bg-cyan-100 hover:bg-cyan-200 border border-cyan-300 transition">
                                                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                                <span>Periksa PDF</span>
                                                            </button>
                                                        <?php endif; ?>
                                                        <a href="/unduh.php?jenis=tugas_mhs&id_submit=<?= urlencode($sub['id_submit']) ?>&mode=download" 
                                                           class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-slate-700 bg-white hover:bg-slate-100 border border-slate-300 shadow-sm transition" title="Unduh Berkas">
                                                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                                            <span>Unduh (.<?= strtoupper($ext) ?>)</span>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Link Eksternal jika ada -->
                                            <?php if (!empty($sub['link_eksternal'])): ?>
                                                <div class="p-2 rounded-lg bg-blue-50/80 border border-blue-200 space-y-1">
                                                    <span class="text-[10px] uppercase font-bold text-blue-700 block">Tautan Eksternal:</span>
                                                    <a href="<?= htmlspecialchars($sub['link_eksternal']) ?>" target="_blank" rel="noopener noreferrer"
                                                       class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 transition shadow-sm max-w-[210px]">
                                                        <i data-lucide="external-link" class="w-3.5 h-3.5 shrink-0"></i>
                                                        <span class="truncate">Buka Link Tugas</span>
                                                        <i data-lucide="arrow-up-right" class="w-3 h-3 shrink-0"></i>
                                                    </a>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (empty($sub['file_name']) && empty($sub['link_eksternal'])): ?>
                                                <span class="text-xs text-slate-400 italic">Belum ada lampiran berkas</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400 italic">Tidak ada berkas</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Kolom Form Penilaian -->
                                <td class="py-4 px-6 align-top">
                                    <?php if (!empty($sub['id_submit'])): ?>
                                        <form action="/nilai/<?= urlencode($idKonten) ?>" method="POST" class="space-y-2">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="id_submit" value="<?= htmlspecialchars($sub['id_submit']) ?>">

                                            <div class="flex items-center space-x-2">
                                                <div class="w-24">
                                                    <label class="block text-[10px] font-semibold text-slate-500 uppercase">Nilai (0-100)</label>
                                                    <input type="number" step="0.5" min="0" max="100" name="nilai" 
                                                           value="<?= htmlspecialchars((string)($sub['nilai'] ?? '')) ?>"
                                                           placeholder="Skor"
                                                           class="w-full px-2.5 py-1.5 text-xs font-bold text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white">
                                                </div>
                                                <div class="flex-1">
                                                    <label class="block text-[10px] font-semibold text-slate-500 uppercase">Feedback Kualitatif Dosen</label>
                                                    <input type="text" name="feedback" 
                                                           value="<?= htmlspecialchars($sub['feedback'] ?? '') ?>"
                                                           placeholder="Catatan perbaikan / apresiasi..."
                                                           class="w-full px-2.5 py-1.5 text-xs text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white">
                                                </div>
                                                <div class="pt-3">
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition shrink-0">
                                                        Simpan
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Menunggu pengumpulan dari mahasiswa</span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
