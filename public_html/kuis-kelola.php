<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Kelola Rangkaian Soal Kuis & Ujian (v3.6)
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

// Ambil info konten mingguan beserta mata kuliah
$stmtKuis = $db->prepare("
    SELECT k.*, mk.id_mk, mk.kode_mk, mk.nama_mk, mk.id_dosen
    FROM konten_mingguan k
    JOIN mata_kuliah mk ON k.id_mk = mk.id_mk
    WHERE k.id_konten = ?
");
$stmtKuis->execute([$idKonten]);
$kuis = $stmtKuis->fetch();

if (!$kuis) {
    set_flash('error', 'Komponen kuis atau ujian tidak ditemukan.');
    header('Location: /dashboard');
    exit;
}

$idMK = $kuis['id_mk'];
$urlSlug = $kuis['kode_mk'] ?: $idMK;

// Verifikasi hak akses dosen pada MK ini
if (is_dosen_biasa() && !is_dosen_assigned_to_course($idMK, $currentUser['id_user'])) {
    set_flash('error', 'Akses ditolak: Anda tidak ditugaskan pada mata kuliah ini.');
    header('Location: /dashboard');
    exit;
}

$pageTitle = 'Kelola Soal: ' . $kuis['judul'];

// -------------------------------------------------------------
// POST HANDLERS: Hubungkan Soal dari Bank Soal, Atur Bobot, Hapus
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    // 1. Tambahkan Soal Terpilih dari Bank Soal ke Kuis Ini
    if ($action === 'tambah_dari_bank') {
        $selectedSoalIds = $_POST['soal_ids'] ?? [];
        if (!is_array($selectedSoalIds) || empty($selectedSoalIds)) {
            set_flash('error', 'Pilih minimal satu butir soal dari Bank Soal.');
        } else {
            try {
                $db->beginTransaction();

                // Dapatkan urutan terakhir saat ini
                $stmtMaxUrut = $db->prepare("SELECT MAX(urutan) FROM kuis_soal WHERE id_konten = ?");
                $stmtMaxUrut->execute([$idKonten]);
                $lastUrutan = (int)($stmtMaxUrut->fetchColumn() ?: 0);

                // Dukungan multi-database: SQLite pakai 'INSERT OR IGNORE', MariaDB/MySQL pakai 'INSERT IGNORE'
                $driver = get_active_db_driver();
                $insertSql = ($driver === 'sqlite')
                    ? "INSERT OR IGNORE INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, ?, ?)"
                    : "INSERT IGNORE INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, ?, ?)";

                $stmtIns = $db->prepare($insertSql);

                // Ambil bobot default soal
                $placeholders = implode(',', array_fill(0, count($selectedSoalIds), '?'));
                $stmtBobot = $db->prepare("SELECT id_soal, bobot_default FROM bank_soal WHERE id_soal IN ({$placeholders})");
                $stmtBobot->execute($selectedSoalIds);
                $mapBobot = $stmtBobot->fetchAll(PDO::FETCH_KEY_PAIR);

                $addedCount = 0;
                foreach ($selectedSoalIds as $idSoal) {
                    $lastUrutan++;
                    $idKuisSoal = generate_id('KS', 'kuis_soal', 'id_kuis_soal');
                    $bobot = isset($mapBobot[$idSoal]) ? (float)$mapBobot[$idSoal] : 10.0;
                    $stmtIns->execute([$idKuisSoal, $idKonten, $idSoal, $lastUrutan, $bobot]);
                    $addedCount++;
                }

                $db->commit();
                log_activity('KELOLA_KUIS', "Dosen '{$currentUser['nama']}' menambahkan {$addedCount} soal ke kuis '{$kuis['judul']}'.");
                set_flash('success', "{$addedCount} butir soal berhasil ditambahkan ke dalam kuis.");
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('error', 'Gagal menambahkan soal: ' . $e->getMessage());
            }
        }

        header("Location: /kuis/kelola/{$idKonten}");
        exit;
    }

    // 2. Simpan Perubahan Bobot Nilai & Urutan Soal
    if ($action === 'simpan_bobot_urutan') {
        $bobotList = $_POST['bobot'] ?? [];
        $urutanList = $_POST['urutan'] ?? [];

        try {
            $db->beginTransaction();
            $stmtUpd = $db->prepare("UPDATE kuis_soal SET bobot_nilai = ?, urutan = ? WHERE id_kuis_soal = ? AND id_konten = ?");

            foreach ($bobotList as $idKS => $bbt) {
                $urut = isset($urutanList[$idKS]) ? (int)$urutanList[$idKS] : 1;
                $stmtUpd->execute([(float)$bbt, $urut, $idKS, $idKonten]);
            }

            $db->commit();
            log_activity('UPDATE_BOBOT_KUIS', "Dosen '{$currentUser['nama']}' memperbarui bobot nilai kuis '{$kuis['judul']}'.");
            set_flash('success', 'Bobot nilai dan urutan butir soal berhasil diperbarui.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', 'Gagal memperbarui bobot soal: ' . $e->getMessage());
        }

        header("Location: /kuis/kelola/{$idKonten}");
        exit;
    }

    // 3. Hapus Soal dari Kuis Ini (Hanya un-link)
    if ($action === 'hapus_dari_kuis') {
        $idKuisSoal = trim((string)($_POST['id_kuis_soal'] ?? ''));
        try {
            $stmtDel = $db->prepare("DELETE FROM kuis_soal WHERE id_kuis_soal = ? AND id_konten = ?");
            $stmtDel->execute([$idKuisSoal, $idKonten]);
            log_activity('HAPUS_SOAL_KUIS', "Dosen '{$currentUser['nama']}' mencopot butir soal dari kuis '{$kuis['judul']}'.");
            set_flash('success', 'Butir soal berhasil dicopot dari rangkaian kuis ini.');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal mencopot soal: ' . $e->getMessage());
        }

        header("Location: /kuis/kelola/{$idKonten}");
        exit;
    }
}

// -------------------------------------------------------------
// QUERY DATA: Butir Soal Terpasang & Bank Soal yang Tersedia
// -------------------------------------------------------------
// Soal terpasang di kuis ini
$stmtTerpasang = $db->prepare("
    SELECT ks.id_kuis_soal, ks.urutan, ks.bobot_nilai,
           bs.id_soal, bs.tipe_soal, bs.pertanyaan, bs.kunci_jawaban_teks, bs.pembahasan
    FROM kuis_soal ks
    JOIN bank_soal bs ON ks.id_soal = bs.id_soal
    WHERE ks.id_konten = ?
    ORDER BY ks.urutan ASC, ks.id_kuis_soal ASC
");
$stmtTerpasang->execute([$idKonten]);
$soalTerpasang = $stmtTerpasang->fetchAll();

// Ambil opsi jawaban untuk soal terpasang
$terpasangIds = array_column($soalTerpasang, 'id_soal');
$opsiMap = [];
if (!empty($terpasangIds)) {
    $ph = implode(',', array_fill(0, count($terpasangIds), '?'));
    $stmtOps = $db->prepare("SELECT * FROM bank_soal_opsi WHERE id_soal IN ({$ph}) ORDER BY urutan ASC");
    $stmtOps->execute($terpasangIds);
    foreach ($stmtOps->fetchAll() as $op) {
        $opsiMap[$op['id_soal']][] = $op;
    }
}

// Hitung statistik kuis
$totalSoal = count($soalTerpasang);
$totalBobot = array_sum(array_column($soalTerpasang, 'bobot_nilai'));
$countPG = count(array_filter($soalTerpasang, fn($s) => $s['tipe_soal'] === 'pg'));
$countBS = count(array_filter($soalTerpasang, fn($s) => $s['tipe_soal'] === 'benar_salah'));
$countIsian = count(array_filter($soalTerpasang, fn($s) => $s['tipe_soal'] === 'isian'));
$countEssay = count(array_filter($soalTerpasang, fn($s) => $s['tipe_soal'] === 'essay'));

// Ambil bank soal yang BELUM terpasang di kuis ini untuk ditampilkan di modal pemilih
$stmtBankAvail = $db->prepare("
    SELECT bs.*, mk.kode_mk, mk.nama_mk
    FROM bank_soal bs
    LEFT JOIN mata_kuliah mk ON bs.id_mk = mk.id_mk
    WHERE bs.id_soal NOT IN (SELECT id_soal FROM kuis_soal WHERE id_konten = ?)
      AND (bs.id_mk = ? OR bs.id_mk IS NULL " . (is_dosen_admin() ? "" : " OR bs.id_dosen = ?") . ")
    ORDER BY bs.created_at DESC
");
$bankParams = [$idKonten, $idMK];
if (!is_dosen_admin()) {
    $bankParams[] = $currentUser['id_user'];
}
$stmtBankAvail->execute($bankParams);
$bankAvailable = $stmtBankAvail->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Top Navigation & Title Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div class="space-y-1.5">
            <nav class="flex items-center space-x-2 text-xs text-slate-500 mb-1">
                <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" class="hover:text-cyan-600 transition flex items-center space-x-1">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <span><?= htmlspecialchars($kuis['kode_mk']) ?> - Ruang Kelas</span>
                </a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400"></i>
                <span class="text-slate-800 font-semibold">Kelola Soal Kuis</span>
            </nav>
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200">
                <i data-lucide="check-square" class="w-3.5 h-3.5 text-cyan-700"></i>
                <span>Pengaturan Butir & Bobot Soal</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Kelola Rangkaian Soal Kuis
            </h1>
            <p class="text-xs sm:text-sm text-slate-600 max-w-3xl leading-relaxed">
                <strong><?= htmlspecialchars($kuis['judul']) ?></strong> • Minggu ke-<?= (int)$kuis['minggu_ke'] ?> • 
                Kelas: <?= htmlspecialchars($kuis['nama_mk']) ?>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 shrink-0 sm:self-center">
            <!-- Tombol Pilih dari Bank Soal -->
            <button type="button" onclick="openPickerModal()" 
                    class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-maritime-700 hover:bg-maritime-800 shadow-md transition">
                <i data-lucide="folder-plus" class="w-4 h-4 text-cyan-300"></i>
                <span>Pilih dari Bank Soal</span>
            </button>
            <a href="/bank-soal?mk=<?= urlencode($idMK) ?>" target="_blank"
               class="inline-flex items-center space-x-1.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-xs transition">
                <i data-lucide="plus" class="w-4 h-4 text-cyan-600"></i>
                <span>Buat Master Soal</span>
                <i data-lucide="external-link" class="w-3 h-3 text-slate-400"></i>
            </a>
            <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan?open_minggu=<?= (int)$kuis['minggu_ke'] ?>#minggu-<?= (int)$kuis['minggu_ke'] ?>" 
               class="inline-flex items-center space-x-1.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                <span>Kembali</span>
            </a>
        </div>
    </div>

    <!-- Ringkasan Statistik Soal Kuis -->
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3.5">
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-slate-500">Total Butir Soal</span>
            <div class="text-2xl font-black text-abyssal-900 font-heading"><?= $totalSoal ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-cyan-50/70 border border-cyan-200 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-cyan-800">Total Bobot Nilai</span>
            <div class="text-2xl font-black text-cyan-950 font-heading"><?= (float)$totalBobot ?> <span class="text-xs font-normal text-cyan-700">Poin</span></div>
        </div>
        <div class="p-4 rounded-2xl bg-indigo-50/50 border border-indigo-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-indigo-700">Pilihan Ganda</span>
            <div class="text-xl font-black text-indigo-900 font-heading"><?= $countPG ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-emerald-50/50 border border-emerald-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-emerald-700">Benar / Salah</span>
            <div class="text-xl font-black text-emerald-900 font-heading"><?= $countBS ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-amber-50/50 border border-amber-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-amber-700">Isian Singkat</span>
            <div class="text-xl font-black text-amber-900 font-heading"><?= $countIsian ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-purple-50/50 border border-purple-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-purple-700">Essay / Uraian</span>
            <div class="text-xl font-black text-purple-900 font-heading"><?= $countEssay ?></div>
        </div>
    </div>

    <!-- Parameter Teknis Kuis -->
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex flex-wrap items-center gap-4 text-slate-700">
            <span class="flex items-center space-x-1.5">
                <i data-lucide="clock" class="w-4 h-4 text-maritime-700"></i>
                <span>Durasi: <strong><?= !empty($kuis['durasi_menit']) ? (int)$kuis['durasi_menit'] . ' Menit' : 'Bebas (Tanpa Timer)' ?></strong></span>
            </span>
            <span class="flex items-center space-x-1.5">
                <i data-lucide="calendar" class="w-4 h-4 text-maritime-700"></i>
                <span>Tenggat Waktu: <strong><?= !empty($kuis['deadline']) ? date('d M Y, H:i', strtotime($kuis['deadline'])) . ' WIT' : 'Tidak Dibatasi' ?></strong></span>
            </span>
            <span class="flex items-center space-x-1.5">
                <i data-lucide="cpu" class="w-4 h-4 text-emerald-600"></i>
                <span>Koreksi PG/BS/Isian: <strong>Otomatis 100%</strong></span>
            </span>
            <?php if ($countEssay > 0): ?>
                <span class="flex items-center space-x-1.5 text-purple-700 font-semibold bg-purple-50 px-2 py-0.5 rounded-lg border border-purple-200">
                    <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
                    <span>Terdapat <?= $countEssay ?> butir essay (perlu koreksi dosen)</span>
                </span>
            <?php endif; ?>
        </div>

        <div>
            <a href="/kuis/nilai/<?= urlencode($idKonten) ?>" class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl font-semibold text-xs text-white bg-slate-800 hover:bg-slate-900 transition">
                <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-400"></i>
                <span>Lihat Hasil & Koreksi Nilai Mahasiswa &rarr;</span>
            </a>
        </div>
    </div>

    <!-- Form Rangkaian Soal Terpasang -->
    <div class="rounded-2xl bg-white border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900 font-heading flex items-center space-x-2">
                <i data-lucide="list-ordered" class="w-4 h-4 text-maritime-700"></i>
                <span>Daftar Butir Soal Terpasang (<?= $totalSoal ?> Butir)</span>
            </h3>
            <span class="text-[11px] text-slate-500">Anda dapat mengubah nomor urut dan bobot per butir soal di bawah ini.</span>
        </div>

        <?php if (empty($soalTerpasang)): ?>
            <div class="p-12 text-center space-y-3">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-cyan-50 flex items-center justify-center text-cyan-600">
                    <i data-lucide="help-circle" class="w-6 h-6"></i>
                </div>
                <h4 class="text-sm font-bold text-slate-800">Kuis Ini Belum Memiliki Butir Soal</h4>
                <p class="text-xs text-slate-500 max-w-md mx-auto">
                    Kuis tidak dapat dikerjakan oleh mahasiswa sebelum Anda memasukkan soal dari Bank Soal.
                </p>
                <button type="button" onclick="openPickerModal()" class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Pilih Soal dari Bank Soal Sekarang</span>
                </button>
            </div>
        <?php else: ?>
            <form action="/kuis/kelola/<?= urlencode($idKonten) ?>" method="POST" id="form-bobot">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="simpan_bobot_urutan">

                <div class="divide-y divide-slate-100">
                    <?php foreach ($soalTerpasang as $idx => $st): ?>
                        <?php 
                            $opsi = $opsiMap[$st['id_soal']] ?? [];
                            $kunciLabel = '';
                            foreach ($opsi as $o) {
                                if ((int)$o['is_benar'] === 1) {
                                    $kunciLabel = $o['label_opsi'];
                                    break;
                                }
                            }
                        ?>
                        <div class="p-4 hover:bg-slate-50/60 transition space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                
                                <div class="flex items-start space-x-3 flex-1">
                                    <!-- Input Urutan Soal -->
                                    <div class="w-14 shrink-0 text-center">
                                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-0.5">Urut</label>
                                        <input type="number" min="1" max="99" name="urutan[<?= htmlspecialchars($st['id_kuis_soal']) ?>]" 
                                               value="<?= (int)$st['urutan'] ?>" 
                                               class="w-full text-center text-xs font-bold rounded-lg border-slate-300 py-1 bg-white focus:ring-cyan-500 focus:border-cyan-500">
                                    </div>

                                    <div class="flex-1 space-y-2">
                                        <div class="flex items-center space-x-2">
                                            <?= get_tipe_soal_badge($st['tipe_soal']) ?>
                                            <span class="text-xs font-mono text-slate-400">[<?= htmlspecialchars($st['id_soal']) ?>]</span>
                                        </div>

                                        <!-- Teks Pertanyaan -->
                                        <div class="text-xs font-medium text-slate-900 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($st['pertanyaan'])) ?>
                                        </div>

                                        <!-- Detail Jawaban/Kunci -->
                                        <div class="text-[11px] text-slate-600 bg-slate-50 p-2.5 rounded-lg border border-slate-200/80">
                                            <?php if ($st['tipe_soal'] === 'pg'): ?>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-bold text-slate-700">Kunci Jawaban:</span>
                                                    <span class="font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded">Opsi <?= htmlspecialchars($kunciLabel) ?></span>
                                                    <span class="text-slate-400">|</span>
                                                    <span class="text-slate-500">Tersedia <?= count($opsi) ?> opsi pilihan</span>
                                                </div>
                                            <?php elseif ($st['tipe_soal'] === 'benar_salah'): ?>
                                                <div class="flex items-center space-x-2">
                                                    <span class="font-bold text-slate-700">Kunci Jawaban:</span>
                                                    <span class="font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded"><?= htmlspecialchars($kunciLabel) ?></span>
                                                </div>
                                            <?php elseif ($st['tipe_soal'] === 'isian'): ?>
                                                <div class="flex items-center space-x-2">
                                                    <span class="font-bold text-slate-700">Kunci Isian:</span>
                                                    <span class="font-bold font-mono text-emerald-800 bg-emerald-100 px-2 py-0.5 rounded"><?= htmlspecialchars($st['kunci_jawaban_teks'] ?? '-') ?></span>
                                                </div>
                                            <?php elseif ($st['tipe_soal'] === 'essay'): ?>
                                                <div>
                                                    <span class="font-bold text-purple-800">Rubrik Essay:</span>
                                                    <span class="text-slate-600 italic"><?= !empty($st['kunci_jawaban_teks']) ? htmlspecialchars($st['kunci_jawaban_teks']) : 'Penilaian bebas oleh dosen' ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Kolom Bobot Poin & Tombol Copot -->
                                <div class="flex items-center space-x-3 shrink-0">
                                    <div class="text-right">
                                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-0.5">Bobot Poin</label>
                                        <div class="flex items-center space-x-1">
                                            <input type="number" step="0.5" min="0.5" max="100" name="bobot[<?= htmlspecialchars($st['id_kuis_soal']) ?>]" 
                                                   value="<?= (float)$st['bobot_nilai'] ?>" 
                                                   class="w-20 text-right text-xs font-bold rounded-lg border-slate-300 py-1 px-2 bg-white focus:ring-cyan-500 focus:border-cyan-500">
                                            <span class="text-[11px] font-bold text-slate-500">Poin</span>
                                        </div>
                                    </div>

                                    <!-- Tombol Copot dari Kuis -->
                                    <div class="pt-4">
                                        <button type="button" 
                                                onclick="hapusDariKuis('<?= htmlspecialchars($st['id_kuis_soal']) ?>')" 
                                                class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" 
                                                title="Copot butir soal ini dari kuis">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Footer Simpan Perubahan Bobot -->
                <div class="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs text-slate-600">
                        Pastikan seluruh bobot telah sesuai sebelum kuis dikerjakan oleh mahasiswa.
                    </span>
                    <button type="submit" class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow-sm transition">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span>Simpan Perubahan Bobot & Urutan</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

</main>

<!-- Form tersembunyi untuk aksi copot soal -->
<form id="form-hapus-soal" action="/kuis/kelola/<?= urlencode($idKonten) ?>" method="POST" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="action" value="hapus_dari_kuis">
    <input type="hidden" name="id_kuis_soal" id="delete_id_kuis_soal" value="">
</form>

<!-- ============================================================= -->
<!-- MODAL PEMILIH BUTIR SOAL DARI BANK SOAL                       -->
<!-- ============================================================= -->
<div id="modal-picker" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[85vh] flex flex-col shadow-2xl border border-slate-200">
        
        <form action="/kuis/kelola/<?= urlencode($idKonten) ?>" method="POST" class="flex flex-col h-full overflow-hidden">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="tambah_dari_bank">

            <!-- Header Modal -->
            <div class="flex items-center justify-between p-4 border-b border-slate-100 bg-slate-50/70 shrink-0">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-xl bg-cyan-100 text-cyan-800 flex items-center justify-center font-bold">
                        <i data-lucide="folder-check" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900 font-heading">Pilih Butir Soal dari Bank Soal</h3>
                        <p class="text-[11px] text-slate-500">Centang butir soal yang ingin Anda masukkan ke kuis ini.</p>
                    </div>
                </div>
                <button type="button" onclick="closePickerModal()" class="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Body Modal List Soal Tersedia -->
            <div class="p-4 flex-1 overflow-y-auto space-y-3">
                <?php if (empty($bankAvailable)): ?>
                    <div class="p-8 text-center text-slate-500 space-y-2">
                        <i data-lucide="info" class="w-8 h-8 text-slate-400 mx-auto"></i>
                        <p class="text-xs font-medium">Tidak ada butir soal baru di Bank Soal yang belum terpasang.</p>
                        <p class="text-[11px] text-slate-400">Silakan buat butir soal baru terlebih dahulu di menu Bank Soal.</p>
                    </div>
                <?php else: ?>
                    <div class="flex items-center justify-between px-1 text-xs text-slate-600">
                        <span class="font-semibold">Tersedia <?= count($bankAvailable) ?> butir soal di Bank Soal:</span>
                        <div class="space-x-2">
                            <button type="button" onclick="checkAllBank(true)" class="text-cyan-700 hover:underline font-semibold text-[11px]">Pilih Semua</button>
                            <span class="text-slate-300">|</span>
                            <button type="button" onclick="checkAllBank(false)" class="text-slate-500 hover:underline text-[11px]">Batal Pilih</button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <?php foreach ($bankAvailable as $bSoal): ?>
                            <label class="flex items-start space-x-3 p-3 rounded-xl border border-slate-200 bg-white hover:bg-cyan-50/40 cursor-pointer transition">
                                <input type="checkbox" name="soal_ids[]" value="<?= htmlspecialchars($bSoal['id_soal']) ?>" 
                                       class="bank-checkbox mt-1 text-cyan-600 focus:ring-cyan-500 h-4 w-4 rounded border-slate-300">
                                <div class="flex-1 space-y-1">
                                    <div class="flex items-center space-x-2">
                                        <?= get_tipe_soal_badge($bSoal['tipe_soal']) ?>
                                        <span class="text-[11px] font-mono text-slate-400"><?= htmlspecialchars($bSoal['id_soal']) ?></span>
                                        <span class="text-[11px] text-slate-500">Bobot: <strong><?= (float)$bSoal['bobot_default'] ?> Poin</strong></span>
                                        <?php if (!empty($bSoal['kode_mk'])): ?>
                                            <span class="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded text-slate-600"><?= htmlspecialchars($bSoal['kode_mk']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-800 font-medium leading-relaxed">
                                        <?= htmlspecialchars(mb_strimwidth($bSoal['pertanyaan'], 0, 160, '...')) ?>
                                    </p>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Modal -->
            <div class="flex items-center justify-between p-4 border-t border-slate-100 bg-slate-50/70 shrink-0">
                <button type="button" onclick="closePickerModal()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-200 transition">
                    Batal
                </button>
                <?php if (!empty($bankAvailable)): ?>
                    <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow-sm transition">
                        Tambahkan Soal Terpilih ke Kuis Ini
                    </button>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<script>
function openPickerModal() {
    document.getElementById('modal-picker').classList.remove('hidden');
}

function closePickerModal() {
    document.getElementById('modal-picker').classList.add('hidden');
}

function checkAllBank(val) {
    document.querySelectorAll('.bank-checkbox').forEach(cb => cb.checked = val);
}

function hapusDariKuis(idKuisSoal) {
    if (confirm('Copot butir soal ini dari rangkaian kuis? (Soal tidak akan terhapus dari Bank Soal)')) {
        document.getElementById('delete_id_kuis_soal').value = idKuisSoal;
        document.getElementById('form-hapus-soal').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

