<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Penilaian, Hasil & Koreksi Kuis (v3.6)
 * Khusus Dosen & Dosen Admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/quiz_helper.php';

require_dosen();

$db = get_db();
$currentUser = current_user();
$branding = get_branding();

$idKonten = trim((string)($_GET['id_konten'] ?? ''));
if ($idKonten === '') {
    set_flash('error', 'Parameter kuis tidak valid.');
    header('Location: /dashboard');
    exit;
}

// Ambil info komponen kuis dan mata kuliah
$stmtKuis = $db->prepare("
    SELECT k.*, mk.id_mk, mk.kode_mk, mk.nama_mk
    FROM konten_mingguan k
    JOIN mata_kuliah mk ON k.id_mk = mk.id_mk
    WHERE k.id_konten = ?
");
$stmtKuis->execute([$idKonten]);
$kuis = $stmtKuis->fetch();

if (!$kuis) {
    set_flash('error', 'Kuis tidak ditemukan.');
    header('Location: /dashboard');
    exit;
}

$idMK = $kuis['id_mk'];
$urlSlug = $kuis['kode_mk'] ?: $idMK;

// Verifikasi hak akses dosen
if (is_dosen_biasa() && !is_dosen_assigned_to_course($idMK, $currentUser['id_user'])) {
    set_flash('error', 'Akses ditolak: Anda tidak ditugaskan pada mata kuliah ini.');
    header('Location: /dashboard');
    exit;
}

$selectedUserId = trim((string)($_GET['user'] ?? ''));

// -------------------------------------------------------------
// POST HANDLER: Simpan Koreksi Essay & Perbarui Nilai Akhir
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'simpan_koreksi') {
        $idSesi = trim((string)($_POST['id_sesi'] ?? ''));
        $targetUser = trim((string)($_POST['id_user'] ?? ''));
        $nilaiButirList = $_POST['nilai_butir'] ?? [];
        $catatanButirList = $_POST['catatan_butir'] ?? [];
        $feedbackUmum = trim((string)($_POST['feedback_umum'] ?? ''));

        try {
            $db->beginTransaction();

            // Update nilai butir yang dikoreksi dosen (terutama essay)
            $stmtUpdJaw = $db->prepare("
                UPDATE kuis_jawaban 
                SET nilai_butir = ?, catatan_dosen = ?, status_koreksi = 'dinilai'
                WHERE id_jawaban = ? AND id_sesi = ?
            ");

            foreach ($nilaiButirList as $idJaw => $nilai) {
                $catatan = isset($catatanButirList[$idJaw]) ? trim((string)$catatanButirList[$idJaw]) : null;
                $stmtUpdJaw->execute([(float)$nilai, $catatan, $idJaw, $idSesi]);
            }

            // Hitung ulang skor skala 100
            $hasilGrading = hitung_nilai_kuis_sesi($db, $idSesi);
            $totalSkorAkhir = $hasilGrading['skor_skala_100'];

            // Update sesi
            $stmtUpdSesi = $db->prepare("
                UPDATE kuis_sesi 
                SET total_nilai = ?, catatan_koreksi = ?
                WHERE id_sesi = ?
            ");
            $stmtUpdSesi->execute([$totalSkorAkhir, $feedbackUmum, $idSesi]);

            // Sinkronkan ke pengumpulan_tugas untuk Tab Rekap Nilai & CSV export
            sync_kuis_ke_pengumpulan_tugas($db, $idKonten, $targetUser, $totalSkorAkhir, $feedbackUmum ?: "Koreksi kuis selesai oleh dosen: Skor {$totalSkorAkhir} / 100.");

            $db->commit();

            log_activity('KOREKSI_KUIS', "Dosen '{$currentUser['nama']}' menyimpan koreksi kuis untuk mahasiswa ({$targetUser}).");
            set_flash('success', 'Nilai dan hasil koreksi kuis mahasiswa berhasil disimpan.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', 'Gagal menyimpan nilai kuis: ' . $e->getMessage());
        }

        header("Location: /kuis/nilai/{$idKonten}?user=" . urlencode($targetUser));
        exit;
    }
}

// -------------------------------------------------------------
// QUERY DATA: Jika Melihat Lembar Jawaban Mahasiswa Tertentu
// -------------------------------------------------------------
$detailSesi = null;
$detailMahasiswa = null;
$detailJawaban = [];

if ($selectedUserId !== '') {
    // Ambil data mahasiswa
    $stmtMhs = $db->prepare("SELECT * FROM users WHERE id_user = ?");
    $stmtMhs->execute([$selectedUserId]);
    $detailMahasiswa = $stmtMhs->fetch();

    if ($detailMahasiswa) {
        $stmtS = $db->prepare("SELECT * FROM kuis_sesi WHERE id_konten = ? AND id_user = ?");
        $stmtS->execute([$idKonten, $selectedUserId]);
        $detailSesi = $stmtS->fetch();

        if ($detailSesi) {
            $stmtJ = $db->prepare("
                SELECT kj.*, bs.tipe_soal, bs.pertanyaan, bs.kunci_jawaban_teks, ks.bobot_nilai, ks.urutan,
                       bso.label_opsi AS label_dipilih, bso.teks_opsi AS teks_dipilih, bso.is_benar AS is_dipilih_benar
                FROM kuis_jawaban kj
                JOIN kuis_soal ks ON ks.id_konten = ? AND ks.id_soal = kj.id_soal
                JOIN bank_soal bs ON bs.id_soal = kj.id_soal
                LEFT JOIN bank_soal_opsi bso ON bso.id_opsi = kj.id_opsi_dipilih
                WHERE kj.id_sesi = ?
                ORDER BY ks.urutan ASC
            ");
            $stmtJ->execute([$idKonten, $detailSesi['id_sesi']]);
            $detailJawaban = $stmtJ->fetchAll();
        }
    }
}

// -------------------------------------------------------------
// QUERY DATA: Daftar Mahasiswa & Rekap Hasil Kuis
// -------------------------------------------------------------
$stmtPeserta = $db->prepare("
    SELECT u.id_user, u.username AS nim, u.nama, u.angkatan, u.foto_profil,
           ks.id_sesi, ks.waktu_mulai, ks.waktu_selesai, ks.status AS status_sesi, ks.total_nilai,
           (SELECT COUNT(*) FROM kuis_jawaban WHERE id_sesi = ks.id_sesi AND status_koreksi = 'perlu_koreksi') AS total_perlu_koreksi
    FROM enrollment e
    JOIN users u ON e.id_user = u.id_user
    LEFT JOIN kuis_sesi ks ON ks.id_konten = ? AND ks.id_user = u.id_user
    WHERE e.id_mk = ?
    ORDER BY u.angkatan DESC, u.nama ASC
");
$stmtPeserta->execute([$idKonten, $idMK]);
$daftarPeserta = $stmtPeserta->fetchAll();

// Hitung metrik statistik kuis
$totalMhs = count($daftarPeserta);
$sudahSubmit = count(array_filter($daftarPeserta, fn($p) => in_array($p['status_sesi'], ['selesai', 'waktu_habis'])));
$perluKoreksiCount = count(array_filter($daftarPeserta, fn($p) => (int)$p['total_perlu_koreksi'] > 0));

$nilaiSelesai = array_filter(array_column($daftarPeserta, 'total_nilai'), fn($v) => $v !== null);
$rataRata = !empty($nilaiSelesai) ? round(array_sum($nilaiSelesai) / count($nilaiSelesai), 1) : 0;
$skorTertinggi = !empty($nilaiSelesai) ? max($nilaiSelesai) : 0;
$skorTerendah = !empty($nilaiSelesai) ? min($nilaiSelesai) : 0;

$pageTitle = 'Hasil & Penilaian Kuis: ' . $kuis['judul'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header & Breadcrumb -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div class="space-y-1.5">
            <nav class="flex items-center space-x-2 text-xs text-slate-500 mb-1">
                <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" class="hover:text-cyan-600 transition flex items-center space-x-1">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <span><?= htmlspecialchars($kuis['kode_mk']) ?> - Ruang Kelas</span>
                </a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400"></i>
                <a href="/kuis/kelola/<?= urlencode($idKonten) ?>" class="hover:text-cyan-600 transition">
                    Kelola Soal
                </a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400"></i>
                <span class="text-slate-800 font-semibold">Hasil & Penilaian</span>
            </nav>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200">
                <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-700"></i>
                <span>Evaluasi Hasil Kuis Daring</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Hasil & Penilaian Kuis
            </h1>
            <p class="text-xs sm:text-sm text-slate-600 max-w-3xl leading-relaxed">
                <strong><?= htmlspecialchars($kuis['judul']) ?></strong> • Kelas: <?= htmlspecialchars($kuis['nama_mk']) ?> (Minggu ke-<?= (int)$kuis['minggu_ke'] ?>)
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 shrink-0 sm:self-center">
            <a href="/kuis/kelola/<?= urlencode($idKonten) ?>" 
               class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition shadow-xs">
                <i data-lucide="settings" class="w-4 h-4 text-slate-500"></i>
                <span>Pengaturan Butir Soal</span>
            </a>
            <a href="/kelas/<?= urlencode($urlSlug) ?>/rekap" 
               class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-maritime-700 hover:bg-maritime-800 transition shadow-md">
                <i data-lucide="award" class="w-4 h-4 text-cyan-300"></i>
                <span>Rekap Nilai Kelas &rarr;</span>
            </a>
        </div>
    </div>

    <!-- Ringkasan Statistik Nilai Kuis -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3.5">
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-slate-500">Peserta Mengerjakan</span>
            <div class="text-2xl font-black text-abyssal-900 font-heading">
                <?= $sudahSubmit ?> <span class="text-xs font-normal text-slate-400">/ <?= $totalMhs ?> Mhs</span>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-cyan-50/70 border border-cyan-200 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-cyan-800">Rata-Rata Nilai</span>
            <div class="text-2xl font-black text-cyan-950 font-heading"><?= $rataRata ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-emerald-50/50 border border-emerald-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-emerald-700">Skor Tertinggi</span>
            <div class="text-2xl font-black text-emerald-900 font-heading"><?= $skorTertinggi ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-amber-50/50 border border-amber-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-amber-700">Skor Terendah</span>
            <div class="text-2xl font-black text-amber-900 font-heading"><?= $skorTerendah ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-purple-50/50 border border-purple-200/60 shadow-xs space-y-1 col-span-2 sm:col-span-1">
            <span class="text-[11px] font-semibold text-purple-700">Menunggu Koreksi Essay</span>
            <div class="text-2xl font-black text-purple-900 font-heading"><?= $perluKoreksiCount ?> <span class="text-xs font-normal text-purple-600">Mhs</span></div>
        </div>
    </div>

    <!-- TAMPILAN 1: LEMBAR KOREKSI MAHASISWA TERTENTU -->
    <?php if ($detailMahasiswa && $detailSesi): ?>
        <div class="rounded-2xl bg-white border border-slate-200 shadow-md overflow-hidden">
            
            <!-- Header Lembar Koreksi -->
            <div class="p-5 border-b border-slate-100 bg-slate-50/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center space-x-3">
                    <div class="w-11 h-11 rounded-2xl bg-maritime-700 text-white font-bold flex items-center justify-center text-sm shadow">
                        <?= strtoupper(substr($detailMahasiswa['nama'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 class="text-base font-bold text-slate-900 font-heading"><?= htmlspecialchars($detailMahasiswa['nama']) ?></h3>
                            <span class="font-mono text-xs text-cyan-800 bg-cyan-50 px-2 py-0.5 rounded border border-cyan-200"><?= htmlspecialchars($detailMahasiswa['username']) ?></span>
                        </div>
                        <p class="text-[11px] text-slate-500">
                            Waktu Mulai: <?= date('d M Y H:i', strtotime($detailSesi['waktu_mulai'])) ?> • 
                            Selesai: <?= $detailSesi['waktu_selesai'] ? date('d M Y H:i', strtotime($detailSesi['waktu_selesai'])) : 'Sedang berjalan' ?>
                        </p>
                    </div>
                </div>

                <div class="flex items-center space-x-3">
                    <div class="text-right">
                        <span class="text-[10px] font-bold text-slate-400 uppercase">Skor Saat Ini</span>
                        <div class="text-2xl font-black text-maritime-700 font-heading">
                            <?= htmlspecialchars((string)($detailSesi['total_nilai'] ?? 0)) ?> <span class="text-xs font-normal text-slate-400">/ 100</span>
                        </div>
                    </div>
                    <a href="/kuis/nilai/<?= urlencode($idKonten) ?>" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-200 transition" title="Tutup Lembar Koreksi">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </a>
                </div>
            </div>

            <!-- Formulir Penilaian Butir Soal -->
            <form action="/kuis/nilai/<?= urlencode($idKonten) ?>?user=<?= urlencode($selectedUserId) ?>" method="POST" class="p-6 space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="simpan_koreksi">
                <input type="hidden" name="id_sesi" value="<?= htmlspecialchars($detailSesi['id_sesi']) ?>">
                <input type="hidden" name="id_user" value="<?= htmlspecialchars($selectedUserId) ?>">

                <div class="space-y-5">
                    <?php foreach ($detailJawaban as $idx => $dj): ?>
                        <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-slate-200/70">
                                <div class="flex items-center space-x-2">
                                    <span class="w-6 h-6 rounded-lg bg-slate-800 text-white font-bold text-xs flex items-center justify-center">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <?= get_tipe_soal_badge($dj['tipe_soal']) ?>
                                    <span class="text-xs text-slate-500">Bobot Maksimal: <strong><?= (float)$dj['bobot_nilai'] ?> Poin</strong></span>
                                </div>

                                <div class="flex items-center space-x-2">
                                    <label class="text-xs font-bold text-slate-700">Nilai Butir Ini:</label>
                                    <input type="number" step="0.5" min="0" max="<?= (float)$dj['bobot_nilai'] ?>" 
                                           name="nilai_butir[<?= htmlspecialchars($dj['id_jawaban']) ?>]" 
                                           value="<?= (float)$dj['nilai_butir'] ?>" 
                                           class="w-20 text-center font-bold text-xs rounded-lg border-slate-300 py-1 bg-white focus:ring-cyan-500 focus:border-cyan-500">
                                    <span class="text-xs text-slate-400">/ <?= (float)$dj['bobot_nilai'] ?></span>
                                </div>
                            </div>

                            <!-- Pertanyaan -->
                            <div class="text-xs font-medium text-slate-800 leading-relaxed">
                                <?= nl2br(htmlspecialchars($dj['pertanyaan'])) ?>
                            </div>

                            <!-- Jawaban Mahasiswa & Kunci Jawaban -->
                            <div class="p-3 rounded-lg bg-white border border-slate-200 text-xs space-y-2">
                                <?php if ($dj['tipe_soal'] === 'pg'): ?>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div>
                                            <span class="text-slate-500">Pilihan Mahasiswa:</span>
                                            <span class="font-bold ml-1 <?= (int)$dj['is_dipilih_benar'] === 1 ? 'text-emerald-700' : 'text-rose-700' ?>">
                                                <?= htmlspecialchars($dj['label_dipilih'] ?: 'Tidak dijawab') ?> - <?= htmlspecialchars($dj['teks_dipilih'] ?: '-') ?>
                                            </span>
                                        </div>
                                        <span class="text-slate-300">|</span>
                                        <div class="text-slate-500">
                                            Status: <?= (int)$dj['is_dipilih_benar'] === 1 ? '<span class="text-emerald-700 font-bold">Benar (Auto-graded)</span>' : '<span class="text-rose-600 font-bold">Salah</span>' ?>
                                        </div>
                                    </div>
                                <?php elseif ($dj['tipe_soal'] === 'benar_salah'): ?>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-slate-500">Jawaban Mahasiswa:</span>
                                        <span class="font-bold <?= (int)$dj['is_dipilih_benar'] === 1 ? 'text-emerald-700' : 'text-rose-700' ?>">
                                            <?= htmlspecialchars($dj['label_dipilih'] ?: 'Tidak dijawab') ?>
                                        </span>
                                        <span class="text-slate-400">|</span>
                                        <span><?= (int)$dj['is_dipilih_benar'] === 1 ? '<span class="text-emerald-700 font-bold">Benar</span>' : '<span class="text-rose-600 font-bold">Salah</span>' ?></span>
                                    </div>
                                <?php elseif ($dj['tipe_soal'] === 'isian'): ?>
                                    <div class="space-y-1">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-slate-500">Teks Jawaban Mahasiswa:</span>
                                            <span class="font-bold text-slate-900 bg-slate-100 px-2 py-0.5 rounded font-mono">
                                                <?= htmlspecialchars($dj['jawaban_teks'] ?: '(Kosong)') ?>
                                            </span>
                                        </div>
                                        <div class="text-slate-500 text-[11px]">
                                            Kunci Jawaban Singkat: <strong class="text-emerald-700 font-mono"><?= htmlspecialchars($dj['kunci_jawaban_teks'] ?? '-') ?></strong>
                                        </div>
                                    </div>
                                <?php elseif ($dj['tipe_soal'] === 'essay'): ?>
                                    <div class="space-y-2">
                                        <span class="text-slate-600 font-bold">Jawaban Essay Mahasiswa:</span>
                                        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-800 leading-relaxed font-normal whitespace-pre-wrap">
                                            <?= !empty($dj['jawaban_teks']) ? htmlspecialchars($dj['jawaban_teks']) : '<span class="text-slate-400 italic">Mahasiswa tidak menuliskan jawaban essay.</span>' ?>
                                        </div>
                                        <?php if (!empty($dj['kunci_jawaban_teks'])): ?>
                                            <div class="text-[11px] text-purple-800 bg-purple-50 p-2 rounded-lg border border-purple-200">
                                                <strong>Rubrik Penilaian:</strong> <?= htmlspecialchars($dj['kunci_jawaban_teks']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <input type="text" name="catatan_butir[<?= htmlspecialchars($dj['id_jawaban']) ?>]" 
                                                   value="<?= htmlspecialchars($dj['catatan_dosen'] ?? '') ?>" 
                                                   placeholder="Catatan koreksi dosen untuk butir essay ini (opsional)..." 
                                                   class="w-full text-xs rounded-lg border-slate-300 py-1.5 px-3 bg-white focus:ring-cyan-500 focus:border-cyan-500">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Feedback Umum Dosen -->
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1.5">
                    <label class="block text-xs font-bold text-slate-700">Catatan & Feedback Umum untuk Mahasiswa:</label>
                    <textarea name="feedback_umum" rows="2" 
                              placeholder="Ketik feedback evaluasi keseluruhan untuk mahasiswa..." 
                              class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 p-2.5 bg-white"><?= htmlspecialchars($detailSesi['catatan_koreksi'] ?? '') ?></textarea>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/kuis/nilai/<?= urlencode($idKonten) ?>" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center space-x-2 px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-maritime-700 hover:bg-maritime-800 shadow-md transition">
                        <i data-lucide="check-circle" class="w-4 h-4"></i>
                        <span>Simpan Perubahan Nilai & Feedback</span>
                    </button>
                </div>
            </form>

        </div>
    <?php endif; ?>

    <!-- TAMPILAN 2: TABEL DAFTAR SELURUH MAHASISWA -->
    <div class="rounded-2xl bg-white border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900 font-heading flex items-center space-x-2">
                <i data-lucide="users" class="w-4 h-4 text-maritime-700"></i>
                <span>Daftar Peserta Kelas & Status Pengerjaan (<?= $totalMhs ?> Mahasiswa)</span>
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-700 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-4 w-12 text-center">No</th>
                        <th class="py-3 px-4">NIM</th>
                        <th class="py-3 px-4">Nama Mahasiswa</th>
                        <th class="py-3 px-4">Angkatan</th>
                        <th class="py-3 px-4">Waktu Penyerahan</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Skor / 100</th>
                        <th class="py-3 px-4 text-center w-32">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($daftarPeserta)): ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-slate-400">
                                Tidak ada mahasiswa yang terdaftar di kelas ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarPeserta as $idx => $p): ?>
                            <?php 
                                $isSubmitted = in_array($p['status_sesi'], ['selesai', 'waktu_habis']);
                                $needsGrading = ((int)$p['total_perlu_koreksi'] > 0);
                            ?>
                            <tr class="hover:bg-slate-50/70 transition <?= $selectedUserId === $p['id_user'] ? 'bg-cyan-50/50' : '' ?>">
                                <td class="py-3 px-4 text-center font-bold text-slate-400"><?= $idx + 1 ?></td>
                                <td class="py-3 px-4 font-mono font-bold text-cyan-800"><?= htmlspecialchars($p['nim']) ?></td>
                                <td class="py-3 px-4 font-bold text-slate-900"><?= htmlspecialchars($p['nama']) ?></td>
                                <td class="py-3 px-4 text-slate-600"><?= htmlspecialchars($p['angkatan'] ?: '-') ?></td>
                                <td class="py-3 px-4 text-slate-500">
                                    <?= !empty($p['waktu_selesai']) ? date('d M Y, H:i', strtotime($p['waktu_selesai'])) . ' WIT' : '-' ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if (!$isSubmitted): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600">
                                            Belum Mengerjakan
                                        </span>
                                    <?php elseif ($needsGrading): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200 animate-pulse">
                                            Perlu Koreksi Essay
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            Selesai Dinilai
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right font-bold text-sm <?= $p['total_nilai'] !== null ? 'text-maritime-700' : 'text-slate-400' ?>">
                                    <?= $p['total_nilai'] !== null ? htmlspecialchars((string)$p['total_nilai']) : '-' ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($isSubmitted): ?>
                                        <a href="/kuis/nilai/<?= urlencode($idKonten) ?>?user=<?= urlencode($p['id_user']) ?>" 
                                           class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                            <span><?= $needsGrading ? 'Koreksi Essay' : 'Lihat Lembar' ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-xs">-</span>
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

