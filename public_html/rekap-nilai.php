<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Pusat Rekapitulasi Nilai Perkuliahan (Khusus Dosen)
 * URL: rekap-nilai.php atau /rekap-nilai
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_dosen();

$db = get_db();
$currentUser = current_user();
$branding = get_branding();
$pageTitle = 'Rekap Nilai Perkuliahan';

// Ambil daftar mata kuliah yang diampu oleh dosen yang sedang login (atau seluruh MK jika Dosen Admin)
if (is_dosen_admin()) {
    $stmtCourses = $db->query("
        SELECT mk.*, ta.nama_ta, ta.status AS ta_status,
               (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta,
               (SELECT COUNT(*) FROM konten_mingguan 
                WHERE id_mk = mk.id_mk 
                  AND (tipe = 'Tugas' OR (tipe IN ('Kuis', 'Ujian') AND (url_embed IS NULL OR TRIM(url_embed) = '')))
               ) AS total_tugas
        FROM mata_kuliah mk
        LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
        ORDER BY mk.created_at DESC
    ");
} else {
    $stmtCourses = $db->prepare("
        SELECT DISTINCT mk.*, ta.nama_ta, ta.status AS ta_status,
               (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta,
               (SELECT COUNT(*) FROM konten_mingguan 
                WHERE id_mk = mk.id_mk 
                  AND (tipe = 'Tugas' OR (tipe IN ('Kuis', 'Ujian') AND (url_embed IS NULL OR TRIM(url_embed) = '')))
               ) AS total_tugas
        FROM mata_kuliah mk
        LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
        LEFT JOIN dosen_mata_kuliah dmk ON dmk.id_mk = mk.id_mk
        WHERE mk.id_dosen = ? OR dmk.id_user = ?
        ORDER BY mk.created_at DESC
    ");
    $stmtCourses->execute([$currentUser['id_user'], $currentUser['id_user']]);
}

$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200 mb-2">
                <i data-lucide="award" class="w-3.5 h-3.5 text-emerald-600"></i>
                <span>Evaluasi Akademik</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Pusat Rekap Nilai Perkuliahan
            </h1>
            <p class="text-sm text-slate-500 max-w-2xl mt-1">
                Pilih mata kuliah untuk melihat matriks rekapitulasi nilai tugas dan kuis interaktif LMS mahasiswa per pertemuan serta mengunduh berkas rekapitulasi dalam format CSV / Excel.
            </p>
        </div>
    </div>

    <!-- Daftar Kelas Mata Kuliah -->
    <?php if (empty($courses)): ?>
        <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-sm space-y-3">
            <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                <i data-lucide="book-open" class="w-8 h-8"></i>
            </div>
            <h3 class="text-base font-bold text-slate-800 font-heading">Belum Ada Kelas Mata Kuliah</h3>
            <p class="text-xs text-slate-500 max-w-md mx-auto">
                Anda belum ditugaskan sebagai pengampu mata kuliah aktif. Hubungi Dosen Admin untuk penetapan kelas pembelajaran.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($courses as $c): ?>
                <?php 
                $slug = !empty($c['kode_mk']) ? $c['kode_mk'] : $c['id_mk'];
                ?>
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm hover:shadow-md hover:border-cyan-400 transition flex flex-col justify-between overflow-hidden group">
                    <div class="p-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-100 text-maritime-800 border border-slate-200">
                                <?= htmlspecialchars($c['kode_mk']) ?>
                            </span>
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-cyan-50 text-cyan-800 border border-cyan-200">
                                Semester <?= (int)$c['semester'] ?> • <?= (int)$c['sks'] ?> SKS
                            </span>
                        </div>

                        <div>
                            <h3 class="text-lg font-bold text-slate-900 group-hover:text-maritime-700 transition font-heading line-clamp-2">
                                <?= htmlspecialchars($c['nama_mk']) ?>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= htmlspecialchars($c['nama_ta'] ?? 'Tahun Akademik -') ?>
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100 text-xs">
                            <div class="p-2.5 rounded-xl bg-slate-50">
                                <span class="text-slate-400 block text-[10px] uppercase font-semibold">Mahasiswa</span>
                                <span class="font-bold text-slate-800 font-heading text-sm"><?= (int)$c['total_peserta'] ?> Peserta</span>
                            </div>
                            <div class="p-2.5 rounded-xl bg-slate-50">
                                <span class="text-slate-400 block text-[10px] uppercase font-semibold">Tugas & Kuis LMS</span>
                                <span class="font-bold text-cyan-700 font-heading text-sm"><?= (int)$c['total_tugas'] ?> Item</span>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 bg-slate-50/80 border-t border-slate-100 flex items-center justify-between gap-2">
                        <a href="/kelas/<?= urlencode($slug) ?>/pertemuan" 
                           class="px-3 py-2 text-xs font-semibold text-slate-600 hover:text-slate-900 rounded-xl hover:bg-slate-200/70 transition">
                            Ruang Kelas
                        </a>
                        <div class="flex items-center space-x-1.5">
                            <a href="/kelas/<?= urlencode($slug) ?>/rekap?export=csv" 
                               class="p-2 rounded-xl text-emerald-700 hover:text-white bg-emerald-50 hover:bg-emerald-600 border border-emerald-200 hover:border-emerald-600 transition shadow-2xs" 
                               title="Ekspor Rekap Lengkap (Excel/CSV)">
                                <i data-lucide="download" class="w-4 h-4"></i>
                            </a>
                            <a href="/kelas/<?= urlencode($slug) ?>/rekap" 
                               class="inline-flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                                <i data-lucide="award" class="w-3.5 h-3.5"></i>
                                <span>Rekap Nilai &rarr;</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

