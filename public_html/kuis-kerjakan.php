<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Halaman Pengerjaan Kuis Interaktif Mahasiswa (v3.6)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/quiz_helper.php';

require_login();

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
    set_flash('error', 'Kuis atau ujian tidak ditemukan.');
    header('Location: /dashboard');
    exit;
}

$idMK = $kuis['id_mk'];
$urlSlug = $kuis['kode_mk'] ?: $idMK;

// Jika kuis menggunakan tautan eksternal (Google Forms dsb), langsung alihkan ke URL tersebut
if (!empty($kuis['url_embed'])) {
    header("Location: " . $kuis['url_embed']);
    exit;
}

// Verifikasi enrollment jika bukan Dosen/Admin
if (!is_dosen()) {
    $stmtEnr = $db->prepare("SELECT 1 FROM enrollment WHERE id_user = ? AND id_mk = ?");
    $stmtEnr->execute([$currentUser['id_user'], $idMK]);
    if (!$stmtEnr->fetch()) {
        set_flash('error', 'Akses ditolak: Anda belum terdaftar dalam mata kuliah ini.');
        header('Location: /dashboard');
        exit;
    }
}

// Ambil daftar butir soal dalam kuis ini
$stmtSoal = $db->prepare("
    SELECT ks.id_kuis_soal, ks.urutan, ks.bobot_nilai,
           bs.id_soal, bs.tipe_soal, bs.pertanyaan, bs.kunci_jawaban_teks
    FROM kuis_soal ks
    JOIN bank_soal bs ON ks.id_soal = bs.id_soal
    WHERE ks.id_konten = ?
    ORDER BY ks.urutan ASC, ks.id_kuis_soal ASC
");
$stmtSoal->execute([$idKonten]);
$daftarSoal = $stmtSoal->fetchAll();

if (empty($daftarSoal)) {
    set_flash('error', 'Kuis ini belum memiliki butir soal yang diatur oleh dosen pengampu.');
    header("Location: /kelas/{$urlSlug}/pertemuan");
    exit;
}

// Ambil opsi jawaban untuk semua soal dalam kuis
$soalIds = array_column($daftarSoal, 'id_soal');
$opsiMap = [];
if (!empty($soalIds)) {
    $ph = implode(',', array_fill(0, count($soalIds), '?'));
    $stmtOps = $db->prepare("SELECT * FROM bank_soal_opsi WHERE id_soal IN ({$ph}) ORDER BY urutan ASC");
    $stmtOps->execute($soalIds);
    foreach ($stmtOps->fetchAll() as $op) {
        $opsiMap[$op['id_soal']][] = $op;
    }
}

// -------------------------------------------------------------
// CEK / INISIALISASI SESI PENGERJAAN MAHASISWA
// -------------------------------------------------------------
$stmtSesi = $db->prepare("SELECT * FROM kuis_sesi WHERE id_konten = ? AND id_user = ?");
$stmtSesi->execute([$idKonten, $currentUser['id_user']]);
$sesi = $stmtSesi->fetch();

$now = time();
$deadlinePassed = !empty($kuis['deadline']) && (strtotime($kuis['deadline']) < $now);

// Jika belum ada sesi dan deadline sudah lewat, tolak pengerjaan baru
if (!$sesi && $deadlinePassed) {
    set_flash('error', 'Tenggat waktu pengerjaan kuis ini telah berakhir.');
    header("Location: /kelas/{$urlSlug}/pertemuan");
    exit;
}

// Jika belum ada sesi dan user menekan tombol mulai (atau akses langsung)
if (!$sesi) {
    $idSesi = generate_id('SES', 'kuis_sesi', 'id_sesi');
    $stmtNewSesi = $db->prepare("
        INSERT INTO kuis_sesi (id_sesi, id_konten, id_user, waktu_mulai, status)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, 'sedang_mengerjakan')
    ");
    $stmtNewSesi->execute([$idSesi, $idKonten, $currentUser['id_user']]);

    log_activity('MULAI_KUIS', "Mahasiswa '{$currentUser['nama']}' mulai mengerjakan kuis '{$kuis['judul']}'.");

    // Inisialisasi baris kuis_jawaban kosong
    $stmtInitJaw = $db->prepare("
        INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, nilai_butir, status_koreksi)
        VALUES (?, ?, ?, 0.00, 'otomatis')
    ");
    foreach ($daftarSoal as $s) {
        $idJaw = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
        $stmtInitJaw->execute([$idJaw, $idSesi, $s['id_soal']]);
    }

    $sesi = [
        'id_sesi'       => $idSesi,
        'id_konten'     => $idKonten,
        'id_user'       => $currentUser['id_user'],
        'waktu_mulai'   => date('Y-m-d H:i:s'),
        'waktu_selesai' => null,
        'status'        => 'sedang_mengerjakan',
        'total_nilai'   => null,
    ];
}

$idSesi = $sesi['id_sesi'];

// Proteksi Sesi Aktif: Tandai sesi sedang mengerjakan kuis agar terlindung dari idle logout
if ($sesi['status'] === 'sedang_mengerjakan') {
    $_SESSION['active_quiz_id'] = $idKonten;
} else {
    unset($_SESSION['active_quiz_id']);
}

// Hitung durasi dan sisa waktu
$durasiMenit = !empty($kuis['durasi_menit']) ? (int)$kuis['durasi_menit'] : null;
$sisaDetik = null;
if ($durasiMenit !== null && $sesi['status'] === 'sedang_mengerjakan') {
    $waktuMulaiTs = strtotime($sesi['waktu_mulai']);
    $waktuHabisTs = $waktuMulaiTs + ($durasiMenit * 60);
    $sisaDetik = max(0, $waktuHabisTs - $now);
}

// -------------------------------------------------------------
// POST HANDLER: SUBMIT JAWABAN KUIS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_kuis' && $sesi['status'] === 'sedang_mengerjakan') {
        $jawabanPG = $_POST['jawaban_pg'] ?? [];
        $jawabanBS = $_POST['jawaban_bs'] ?? [];
        $jawabanIsian = $_POST['jawaban_isian'] ?? [];
        $jawabanEssay = $_POST['jawaban_essay'] ?? [];

        try {
            $db->beginTransaction();

            // Simpan setiap jawaban mahasiswa
            foreach ($daftarSoal as $soal) {
                $idSoal = $soal['id_soal'];
                $tipe = $soal['tipe_soal'];
                $idOpsiDipilih = null;
                $jawabanTeks = null;

                if ($tipe === 'pg') {
                    $idOpsiDipilih = isset($jawabanPG[$idSoal]) ? trim((string)$jawabanPG[$idSoal]) : null;
                } elseif ($tipe === 'benar_salah') {
                    $idOpsiDipilih = isset($jawabanBS[$idSoal]) ? trim((string)$jawabanBS[$idSoal]) : null;
                } elseif ($tipe === 'isian') {
                    $jawabanTeks = isset($jawabanIsian[$idSoal]) ? trim((string)$jawabanIsian[$idSoal]) : null;
                } elseif ($tipe === 'essay') {
                    $jawabanTeks = isset($jawabanEssay[$idSoal]) ? trim((string)$jawabanEssay[$idSoal]) : null;
                }

                // Cek apakah record jawaban sudah ada
                $stmtCekJaw = $db->prepare("SELECT id_jawaban FROM kuis_jawaban WHERE id_sesi = ? AND id_soal = ?");
                $stmtCekJaw->execute([$idSesi, $idSoal]);
                $idExistingJaw = $stmtCekJaw->fetchColumn();

                if ($idExistingJaw) {
                    $stmtUpdJaw = $db->prepare("
                        UPDATE kuis_jawaban 
                        SET id_opsi_dipilih = ?, jawaban_teks = ?
                        WHERE id_jawaban = ?
                    ");
                    $stmtUpdJaw->execute([$idOpsiDipilih, $jawabanTeks, $idExistingJaw]);
                } else {
                    $idJawBaru = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
                    $stmtInsJaw = $db->prepare("
                        INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, id_opsi_dipilih, jawaban_teks, nilai_butir, status_koreksi)
                        VALUES (?, ?, ?, ?, ?, 0.00, 'otomatis')
                    ");
                    $stmtInsJaw->execute([$idJawBaru, $idSesi, $idSoal, $idOpsiDipilih, $jawabanTeks]);
                }
            }

            // Hitung nilai otomatis
            $hasilGrading = hitung_nilai_kuis_sesi($db, $idSesi);
            $nilaiAkhir = $hasilGrading['skor_skala_100'];

            // Update status sesi kuis menjadi selesai
            $stmtFinish = $db->prepare("
                UPDATE kuis_sesi 
                SET waktu_selesai = CURRENT_TIMESTAMP, status = 'selesai', total_nilai = ?
                WHERE id_sesi = ?
            ");
            $stmtFinish->execute([$nilaiAkhir, $idSesi]);

            // Sinkronkan ke tabel pengumpulan_tugas untuk Tab Rekap Nilai & CSV export
            $feedbackNotice = $hasilGrading['ada_essay'] 
                ? "Kuis diserahkan. Nilai sementara (PG/BS/Isian): {$nilaiAkhir}. Menunggu koreksi essay dari dosen."
                : "Kuis daring terkoreksi otomatis secara penuh: Skor {$nilaiAkhir} / 100.";

            sync_kuis_ke_pengumpulan_tugas($db, $idKonten, $currentUser['id_user'], $nilaiAkhir, $feedbackNotice);

            $db->commit();

            // Lepaskan proteksi kuis aktif setelah diserahkan
            unset($_SESSION['active_quiz_id']);

            log_activity('SELESAI_KUIS', "Mahasiswa '{$currentUser['nama']}' menyerahkan kuis '{$kuis['judul']}' (Skor: {$nilaiAkhir}).");
            set_flash('success', 'Kuis berhasil diselesaikan dan jawaban telah tersimpan.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', 'Terjadi kesalahan saat menyimpan kuis: ' . $e->getMessage());
        }

        header("Location: /kuis/kerjakan/{$idKonten}");
        exit;
    }
}

// Ambil jawaban yang tersimpan saat ini
$stmtCurrentJaw = $db->prepare("SELECT * FROM kuis_jawaban WHERE id_sesi = ?");
$stmtCurrentJaw->execute([$idSesi]);
$jawabanTersimpan = [];
foreach ($stmtCurrentJaw->fetchAll() as $cj) {
    $jawabanTersimpan[$cj['id_soal']] = $cj;
}

$pageTitle = 'Pengerjaan Kuis: ' . $kuis['judul'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-4xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Header & Info Perkuliahan -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div class="space-y-1.5">
            <nav class="flex items-center space-x-2 text-xs text-slate-500 mb-1">
                <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" class="hover:text-cyan-600 transition flex items-center space-x-1">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <span><?= htmlspecialchars($kuis['kode_mk']) ?> - Ruang Kelas</span>
                </a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400"></i>
                <span class="text-slate-800 font-semibold"><?= htmlspecialchars($kuis['judul']) ?></span>
            </nav>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200">
                <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-700"></i>
                <span>Lembar Asesmen Mahasiswa</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                <?= htmlspecialchars($kuis['judul']) ?>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 max-w-2xl leading-relaxed">
                Mata Kuliah: <strong><?= htmlspecialchars($kuis['nama_mk']) ?></strong> • Minggu ke-<?= (int)$kuis['minggu_ke'] ?>
            </p>
        </div>

        <div class="shrink-0 sm:self-center">
            <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan?open_minggu=<?= (int)$kuis['minggu_ke'] ?>#minggu-<?= (int)$kuis['minggu_ke'] ?>" 
               class="inline-flex items-center space-x-1.5 px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition shadow-xs">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                <span>Kembali ke Kelas</span>
            </a>
        </div>
    </div>

    <!-- JIKA STATUS KUIS SUDAH SELESAI -->
    <?php if ($sesi['status'] === 'selesai' || $sesi['status'] === 'waktu_habis'): ?>
        <?php 
            $adaEssay = cek_apakah_kuis_punya_essay($db, $idKonten);
        ?>
        <div class="p-8 rounded-3xl bg-white border border-slate-200/80 shadow-md text-center space-y-5">
            <div class="w-16 h-16 mx-auto rounded-3xl bg-emerald-100 text-emerald-700 flex items-center justify-center shadow-inner">
                <i data-lucide="check-circle-2" class="w-9 h-9"></i>
            </div>
            
            <div class="space-y-1">
                <h2 class="text-xl font-bold text-slate-900 font-heading">Kuis Berhasil Diselesaikan!</h2>
                <p class="text-xs text-slate-500">
                    Waktu Penyerahan: <?= date('d M Y, H:i', strtotime($sesi['waktu_selesai'] ?: 'now')) ?> WIT
                </p>
            </div>

            <!-- Kartu Skor -->
            <div class="max-w-xs mx-auto p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Perolehan Skor Anda</span>
                <div class="text-4xl font-black text-maritime-700 font-heading">
                    <?= htmlspecialchars((string)($sesi['total_nilai'] ?? 0)) ?> <span class="text-sm font-normal text-slate-500">/ 100</span>
                </div>
                <?php if ($adaEssay): ?>
                    <p class="text-[11px] text-purple-700 font-semibold pt-1">
                        * Terdapat soal essay yang menunggu koreksi & penambahan nilai dari dosen.
                    </p>
                <?php else: ?>
                    <p class="text-[11px] text-emerald-700 font-semibold pt-1">
                        * Kuis objektif dinilai otomatis 100% oleh sistem LMS.
                    </p>
                <?php endif; ?>
            </div>

            <div class="pt-2">
                <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan?open_minggu=<?= (int)$kuis['minggu_ke'] ?>#minggu-<?= (int)$kuis['minggu_ke'] ?>" 
                   class="inline-flex items-center space-x-2 px-5 py-2.5 rounded-xl font-semibold text-xs text-white bg-maritime-700 hover:bg-maritime-800 shadow-md transition">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span>Kembali ke Halaman Perkuliahan</span>
                </a>
            </div>
        </div>

    <?php else: ?>

        <!-- BILAH INFORMASI & TIMER HITUNG MUNDUR (STICKY) -->
        <div class="sticky top-20 z-30 p-4 rounded-2xl bg-abyssal-900 text-white shadow-xl flex flex-wrap items-center justify-between gap-3 border border-slate-800">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-950 border border-cyan-500/40 text-cyan-400 flex items-center justify-center shrink-0">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                </div>
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Sisa Waktu Pengerjaan</span>
                    <div id="quiz-timer" class="text-lg font-black font-mono text-cyan-300">
                        <?= $sisaDetik !== null ? sprintf('%02d:%02d', floor($sisaDetik / 60), $sisaDetik % 60) : 'Tanpa Batas' ?>
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-2 text-xs text-slate-300">
                <span class="hidden sm:inline">Total: <strong><?= count($daftarSoal) ?> Butir Soal</strong></span>
                <button type="button" onclick="confirmSubmitQuiz()" 
                        class="px-4 py-2 rounded-xl font-bold text-xs text-slate-950 bg-gradient-to-r from-amber-400 to-amber-300 hover:from-amber-300 hover:to-amber-200 shadow transition">
                    Selesai & Kumpulkan Kuis &rarr;
                </button>
            </div>
        </div>

        <!-- FORM LEMBAR JAWABAN INTERAKTIF -->
        <form id="form-kerjakan-kuis" action="/kuis/kerjakan/<?= urlencode($idKonten) ?>" method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="submit_kuis">

            <!-- Navigasi Cepat Butir Soal -->
            <div class="p-3.5 rounded-2xl bg-white border border-slate-200 shadow-xs flex flex-wrap items-center gap-2">
                <span class="text-xs font-bold text-slate-600 mr-2">Nomor Soal:</span>
                <?php foreach ($daftarSoal as $idx => $s): ?>
                    <a href="#soal-<?= $idx + 1 ?>" id="nav-btn-<?= $s['id_soal'] ?>" 
                       class="w-7 h-7 rounded-lg text-xs font-bold flex items-center justify-center border border-slate-200 bg-slate-50 text-slate-700 hover:bg-cyan-100 transition">
                        <?= $idx + 1 ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Daftar Pertanyaan -->
            <div class="space-y-4">
                <?php foreach ($daftarSoal as $idx => $soal): ?>
                    <?php 
                        $idSoal = $soal['id_soal'];
                        $opsi = $opsiMap[$idSoal] ?? [];
                        $currentJaw = $jawabanTersimpan[$idSoal] ?? null;
                        $selectedOpsi = $currentJaw['id_opsi_dipilih'] ?? '';
                        $jawabanTeks = $currentJaw['jawaban_teks'] ?? '';
                    ?>
                    <div id="soal-<?= $idx + 1 ?>" class="p-6 rounded-2xl bg-white border border-slate-200/90 shadow-xs space-y-4 scroll-mt-36">
                        
                        <!-- Header Soal: Nomor, Tipe, Bobot -->
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div class="flex items-center space-x-2.5">
                                <span class="w-7 h-7 rounded-lg bg-maritime-700 text-white font-bold text-xs flex items-center justify-center">
                                    <?= $idx + 1 ?>
                                </span>
                                <?= get_tipe_soal_badge($soal['tipe_soal']) ?>
                            </div>
                            <span class="text-xs font-semibold text-slate-500">Bobot: <strong><?= (float)$soal['bobot_nilai'] ?> Poin</strong></span>
                        </div>

                        <!-- Teks Pertanyaan -->
                        <div class="text-sm font-medium text-slate-900 leading-relaxed">
                            <?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?>
                        </div>

                        <!-- INPUT JAWABAN SESUAI TIPE -->
                        <div class="pt-2">
                            <?php if ($soal['tipe_soal'] === 'pg'): ?>
                                <!-- Pilihan Ganda -->
                                <div class="space-y-2">
                                    <?php foreach ($opsi as $op): ?>
                                        <label class="flex items-start space-x-3 p-3 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-cyan-50/50 cursor-pointer transition">
                                            <input type="radio" name="jawaban_pg[<?= htmlspecialchars($idSoal) ?>]" 
                                                   value="<?= htmlspecialchars($op['id_opsi']) ?>" 
                                                   <?= $selectedOpsi === $op['id_opsi'] ? 'checked' : '' ?>
                                                   onchange="markAnswered('<?= htmlspecialchars($idSoal) ?>')"
                                                   class="mt-0.5 text-cyan-600 focus:ring-cyan-500 h-4 w-4">
                                            <div class="flex items-start space-x-2 flex-1">
                                                <span class="w-5 h-5 rounded bg-slate-200 text-slate-800 font-bold text-xs flex items-center justify-center shrink-0">
                                                    <?= htmlspecialchars($op['label_opsi']) ?>
                                                </span>
                                                <span class="text-xs text-slate-800 font-medium leading-relaxed">
                                                    <?= htmlspecialchars($op['teks_opsi']) ?>
                                                </span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($soal['tipe_soal'] === 'benar_salah'): ?>
                                <!-- Benar / Salah -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <?php foreach ($opsi as $op): ?>
                                        <label class="flex items-center space-x-3 p-3.5 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-cyan-50/50 cursor-pointer transition">
                                            <input type="radio" name="jawaban_bs[<?= htmlspecialchars($idSoal) ?>]" 
                                                   value="<?= htmlspecialchars($op['id_opsi']) ?>" 
                                                   <?= $selectedOpsi === $op['id_opsi'] ? 'checked' : '' ?>
                                                   onchange="markAnswered('<?= htmlspecialchars($idSoal) ?>')"
                                                   class="text-cyan-600 focus:ring-cyan-500 h-4 w-4">
                                            <span class="text-xs font-bold <?= $op['label_opsi'] === 'Benar' ? 'text-emerald-800' : 'text-rose-800' ?>">
                                                <?= htmlspecialchars($op['label_opsi']) ?> (<?= htmlspecialchars($op['teks_opsi']) ?>)
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($soal['tipe_soal'] === 'isian'): ?>
                                <!-- Isian Singkat -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 mb-1">Tuliskan Jawaban Singkat Anda:</label>
                                    <input type="text" name="jawaban_isian[<?= htmlspecialchars($idSoal) ?>]" 
                                           value="<?= htmlspecialchars($jawabanTeks) ?>" 
                                           oninput="markAnswered('<?= htmlspecialchars($idSoal) ?>', this.value)"
                                           placeholder="Ketik jawaban Anda di sini..." 
                                           class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2.5 px-3 bg-white">
                                </div>

                            <?php elseif ($soal['tipe_soal'] === 'essay'): ?>
                                <!-- Essay / Uraian -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 mb-1">Tuliskan Penjelasan Lengkap / Analisis Anda:</label>
                                    <textarea name="jawaban_essay[<?= htmlspecialchars($idSoal) ?>]" rows="5" 
                                              oninput="markAnswered('<?= htmlspecialchars($idSoal) ?>', this.value)"
                                              placeholder="Tuliskan uraian jawaban Anda di sini secara rinci dan komprehensif..." 
                                              class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 p-3 leading-relaxed bg-white"><?= htmlspecialchars($jawabanTeks) ?></textarea>
                                </div>

                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Tombol Selesai Bawah -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
                <span class="text-xs text-slate-500">
                    Pastikan seluruh nomor soal telah terjawab sebelum mengklik tombol penyerahan kuis.
                </span>
                <button type="button" onclick="confirmSubmitQuiz()" 
                        class="w-full sm:w-auto inline-flex items-center justify-center space-x-2 px-6 py-3 rounded-xl font-bold text-xs text-white bg-maritime-700 hover:bg-maritime-800 shadow-md transition">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Selesai & Kumpulkan Kuis Sekarang</span>
                </button>
            </div>
        </form>

        <script>
        // Sisa waktu dari PHP
        let remainingSeconds = <?= $sisaDetik !== null ? (int)$sisaDetik : 'null' ?>;

        if (remainingSeconds !== null) {
            const timerEl = document.getElementById('quiz-timer');
            const timerInterval = setInterval(function() {
                if (remainingSeconds <= 0) {
                    clearInterval(timerInterval);
                    timerEl.textContent = '00:00 (Waktu Habis)';
                    alert('Waktu pengerjaan kuis telah habis! Jawaban Anda akan diserahkan secara otomatis.');
                    document.getElementById('form-kerjakan-kuis').submit();
                    return;
                }

                remainingSeconds--;
                const mins = Math.floor(remainingSeconds / 60);
                const secs = remainingSeconds % 60;
                timerEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

                if (remainingSeconds < 300) {
                    timerEl.classList.remove('text-cyan-300');
                    timerEl.classList.add('text-rose-400', 'animate-pulse');
                }
            }, 1000);
        }

        // Keep-Alive Heartbeat: Memastikan sesi pengerjaan kuis mahasiswa tetap aktif dan tidak logout
        setInterval(function() {
            fetch('/heartbeat.php', { credentials: 'same-origin' })
                .catch(function(e) { /* silent ignore network drop */ });
        }, 120000);

        function markAnswered(idSoal, val = 'filled') {
            const btn = document.getElementById('nav-btn-' + idSoal);
            if (btn) {
                if (val && val.trim() !== '') {
                    btn.classList.remove('bg-slate-50', 'text-slate-700');
                    btn.classList.add('bg-emerald-600', 'text-white', 'border-emerald-700');
                } else {
                    btn.classList.remove('bg-emerald-600', 'text-white', 'border-emerald-700');
                    btn.classList.add('bg-slate-50', 'text-slate-700');
                }
            }
        }

        function confirmSubmitQuiz() {
            if (confirm('Apakah Anda yakin telah selesai dan ingin mengumpulkan kuis ini sekarang? Jawaban tidak dapat diubah setelah kuis diserahkan.')) {
                document.getElementById('form-kerjakan-kuis').submit();
            }
        }
        </script>

    <?php endif; ?>

</main>

<?php 
function cek_apakah_kuis_punya_essay(PDO $db, string $idKonten): bool {
    $stmt = $db->prepare("
        SELECT 1 
        FROM kuis_soal ks
        JOIN bank_soal bs ON ks.id_soal = bs.id_soal
        WHERE ks.id_konten = ? AND bs.tipe_soal = 'essay'
        LIMIT 1
    ");
    $stmt->execute([$idKonten]);
    return (bool)$stmt->fetchColumn();
}
require_once __DIR__ . '/../includes/footer.php'; 
?>

