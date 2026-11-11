<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Bank Soal & Repositori Asesmen Perkuliahan (v3.6)
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
$pageTitle = 'Bank Soal Perkuliahan';

// -------------------------------------------------------------
// POST HANDLERS: Tambah, Update, Hapus Butir Soal
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_soal' || $action === 'update_soal') {
        $idSoal = trim((string)($_POST['id_soal'] ?? ''));
        $idMK = trim((string)($_POST['id_mk'] ?? '')) ?: null;
        $tipeSoal = trim((string)($_POST['tipe_soal'] ?? 'pg'));
        $pertanyaan = trim((string)($_POST['pertanyaan'] ?? ''));
        $bobotDefault = (float)($_POST['bobot_default'] ?? 10.0);
        $pembahasan = trim((string)($_POST['pembahasan'] ?? '')) ?: null;
        $kunciJawabanTeks = trim((string)($_POST['kunci_jawaban_teks'] ?? '')) ?: null;

        if (!in_array($tipeSoal, ['pg', 'benar_salah', 'isian', 'essay'], true)) {
            $tipeSoal = 'pg';
        }

        if ($pertanyaan === '') {
            set_flash('error', 'Teks pertanyaan wajib diisi.');
            header('Location: /bank-soal');
            exit;
        }

        try {
            $db->beginTransaction();

            if ($action === 'create_soal') {
                $idSoal = generate_id('SOL', 'bank_soal', 'id_soal');
                $stmtIns = $db->prepare("
                    INSERT INTO bank_soal (id_soal, id_mk, id_dosen, tipe_soal, pertanyaan, kunci_jawaban_teks, bobot_default, pembahasan, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmtIns->execute([$idSoal, $idMK, $currentUser['id_user'], $tipeSoal, $pertanyaan, $kunciJawabanTeks, $bobotDefault, $pembahasan]);
            } else {
                // Update
                $stmtUpd = $db->prepare("
                    UPDATE bank_soal 
                    SET id_mk = ?, tipe_soal = ?, pertanyaan = ?, kunci_jawaban_teks = ?, bobot_default = ?, pembahasan = ?
                    WHERE id_soal = ? " . (!is_dosen_admin() ? "AND id_dosen = ?" : "") . "
                ");
                $params = [$idMK, $tipeSoal, $pertanyaan, $kunciJawabanTeks, $bobotDefault, $pembahasan, $idSoal];
                if (!is_dosen_admin()) {
                    $params[] = $currentUser['id_user'];
                }
                $stmtUpd->execute($params);

                // Hapus opsi lama sebelum insert ulang
                $stmtDelOps = $db->prepare("DELETE FROM bank_soal_opsi WHERE id_soal = ?");
                $stmtDelOps->execute([$idSoal]);
            }

            // Simpan Opsi Jawaban untuk PG dan Benar/Salah
            if ($tipeSoal === 'pg') {
                $opsiTeks = $_POST['opsi_pg'] ?? [];
                $kunciOpsi = $_POST['kunci_pg'] ?? 'A'; // Label opsi kunci

                $urutan = 1;
                $stmtOpsi = $db->prepare("
                    INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($opsiTeks as $label => $teks) {
                    $teksClean = trim((string)$teks);
                    if ($teksClean === '') continue;

                    $idOpsi = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
                    $isBenar = (strtoupper((string)$label) === strtoupper((string)$kunciOpsi)) ? 1 : 0;
                    $stmtOpsi->execute([$idOpsi, $idSoal, strtoupper((string)$label), $teksClean, $isBenar, $urutan++]);
                }
            } elseif ($tipeSoal === 'benar_salah') {
                $kunciBS = $_POST['kunci_bs'] ?? 'Benar'; // Benar atau Salah
                $stmtOpsi = $db->prepare("
                    INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                // Opsi 1: Benar
                $idOpsBenar = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
                $stmtOpsi->execute([$idOpsBenar, $idSoal, 'Benar', 'Pernyataan ini Benar', ($kunciBS === 'Benar' ? 1 : 0), 1]);

                // Opsi 2: Salah
                $idOpsSalah = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
                $stmtOpsi->execute([$idOpsSalah, $idSoal, 'Salah', 'Pernyataan ini Salah', ($kunciBS === 'Salah' ? 1 : 0), 2]);
            }

            $db->commit();

            log_activity(
                $action === 'create_soal' ? 'TAMBAH_SOAL' : 'EDIT_SOAL',
                "Dosen '{$currentUser['nama']}' " . ($action === 'create_soal' ? 'menambahkan' : 'memperbarui') . " soal tipe " . get_tipe_soal_label($tipeSoal) . " di Bank Soal."
            );

            set_flash('success', 'Butir soal berhasil disimpan ke Bank Soal.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', 'Gagal menyimpan butir soal: ' . $e->getMessage());
        }

        header('Location: /bank-soal');
        exit;
    }

    if ($action === 'delete_soal') {
        $idSoal = trim((string)($_POST['id_soal'] ?? ''));
        try {
            $stmtDel = $db->prepare("
                DELETE FROM bank_soal 
                WHERE id_soal = ? " . (!is_dosen_admin() ? "AND id_dosen = ?" : "") . "
            ");
            $params = [$idSoal];
            if (!is_dosen_admin()) $params[] = $currentUser['id_user'];
            $stmtDel->execute($params);

            log_activity('HAPUS_SOAL', "Dosen '{$currentUser['nama']}' menghapus butir soal ({$idSoal}) dari Bank Soal.");
            set_flash('success', 'Butir soal berhasil dihapus dari Bank Soal.');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menghapus soal: ' . $e->getMessage());
        }

        header('Location: /bank-soal');
        exit;
    }
}

// -------------------------------------------------------------
// QUERY DATA: Filter & Daftar Soal
// -------------------------------------------------------------
$filterMK = trim((string)($_GET['mk'] ?? 'all'));
$filterTipe = trim((string)($_GET['tipe'] ?? 'all'));
$searchKeyword = trim((string)($_GET['q'] ?? ''));

// Ambil daftar mata kuliah untuk dropdown filter
if (is_dosen_admin()) {
    $stmtMK = $db->query("SELECT id_mk, kode_mk, nama_mk FROM mata_kuliah ORDER BY kode_mk ASC");
} else {
    $stmtMK = $db->prepare("
        SELECT DISTINCT mk.id_mk, mk.kode_mk, mk.nama_mk 
        FROM mata_kuliah mk
        LEFT JOIN dosen_mata_kuliah dmk ON dmk.id_mk = mk.id_mk
        WHERE mk.id_dosen = ? OR dmk.id_user = ?
        ORDER BY mk.kode_mk ASC
    ");
    $stmtMK->execute([$currentUser['id_user'], $currentUser['id_user']]);
}
$listMK = $stmtMK->fetchAll();

// Query Bank Soal
$sql = "
    SELECT bs.*, mk.kode_mk, mk.nama_mk, u.nama AS nama_dosen,
           (SELECT COUNT(*) FROM kuis_soal WHERE id_soal = bs.id_soal) AS dipakai_di_kuis
    FROM bank_soal bs
    LEFT JOIN mata_kuliah mk ON bs.id_mk = mk.id_mk
    JOIN users u ON bs.id_dosen = u.id_user
    WHERE 1=1
";
$params = [];

if (!is_dosen_admin()) {
    $sql .= " AND bs.id_dosen = ? ";
    $params[] = $currentUser['id_user'];
}

if ($filterMK !== 'all' && $filterMK !== '') {
    $sql .= " AND bs.id_mk = ? ";
    $params[] = $filterMK;
}

if ($filterTipe !== 'all' && $filterTipe !== '') {
    $sql .= " AND bs.tipe_soal = ? ";
    $params[] = $filterTipe;
}

if ($searchKeyword !== '') {
    $sql .= " AND (bs.pertanyaan LIKE ? OR bs.kunci_jawaban_teks LIKE ?) ";
    $params[] = "%{$searchKeyword}%";
    $params[] = "%{$searchKeyword}%";
}

$sql .= " ORDER BY bs.created_at DESC ";

$stmtSoal = $db->prepare($sql);
$stmtSoal->execute($params);
$daftarSoal = $stmtSoal->fetchAll();

// Ambil semua opsi jawaban untuk soal-soal PG / BS yang tampil
$soalIds = array_column($daftarSoal, 'id_soal');
$opsiBySoal = [];
if (!empty($soalIds)) {
    $placeholders = implode(',', array_fill(0, count($soalIds), '?'));
    $stmtAllOpsi = $db->prepare("
        SELECT * FROM bank_soal_opsi 
        WHERE id_soal IN ({$placeholders}) 
        ORDER BY urutan ASC
    ");
    $stmtAllOpsi->execute($soalIds);
    foreach ($stmtAllOpsi->fetchAll() as $ops) {
        $opsiBySoal[$ops['id_soal']][] = $ops;
    }
}

// Statistik Cepat Bank Soal
$statTotal = count($daftarSoal);
$statPG = count(array_filter($daftarSoal, fn($s) => $s['tipe_soal'] === 'pg'));
$statBS = count(array_filter($daftarSoal, fn($s) => $s['tipe_soal'] === 'benar_salah'));
$statIsian = count(array_filter($daftarSoal, fn($s) => $s['tipe_soal'] === 'isian'));
$statEssay = count(array_filter($daftarSoal, fn($s) => $s['tipe_soal'] === 'essay'));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Top Breadcrumb & Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div class="space-y-1.5">
            <!-- Breadcrumb Navigation -->
            <nav class="flex items-center space-x-2 text-xs text-slate-500 mb-2">
                <a href="/dashboard" class="hover:text-cyan-600 font-medium transition flex items-center space-x-1">
                    <i data-lucide="home" class="w-3.5 h-3.5"></i>
                    <span>Dashboard</span>
                </a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400"></i>
                <span class="text-slate-800 font-semibold">Bank Soal Perkuliahan</span>
            </nav>

            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-200">
                <i data-lucide="help-circle" class="w-3.5 h-3.5 text-cyan-700"></i>
                <span>Repositori Soal & Asesmen Digital</span>
            </div>

            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 tracking-tight font-heading">
                Bank Soal Perkuliahan
            </h1>

            <p class="text-xs sm:text-sm text-slate-500 max-w-3xl leading-relaxed">
                Kelola master butir soal: Pilihan Ganda (PG), Benar/Salah (B/S), Isian Singkat, dan Essay untuk rangkaian kuis dan tugas mingguan.
            </p>
        </div>

        <!-- Tombol Tambah Soal Baru -->
        <div class="flex items-center space-x-2.5 shrink-0 sm:self-center">
            <button type="button" 
                    onclick="openSoalModal()" 
                    class="inline-flex items-center space-x-2 px-5 py-3 rounded-2xl font-bold text-xs sm:text-sm text-white bg-maritime-700 hover:bg-maritime-800 shadow-md hover:shadow-lg transition">
                <i data-lucide="plus-circle" class="w-4 h-4 text-cyan-300"></i>
                <span>Tambah Butir Soal Baru</span>
            </button>
        </div>
    </div>

    <!-- Statistik Cepat Bank Soal -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3.5">
        <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-slate-500">Total Soal</span>
            <div class="text-2xl font-black text-abyssal-900 font-heading"><?= $statTotal ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-indigo-50/50 border border-indigo-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-indigo-700">Pilihan Ganda</span>
            <div class="text-2xl font-black text-indigo-900 font-heading"><?= $statPG ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-emerald-50/50 border border-emerald-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-emerald-700">Benar / Salah</span>
            <div class="text-2xl font-black text-emerald-900 font-heading"><?= $statBS ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-amber-50/50 border border-amber-200/60 shadow-xs space-y-1">
            <span class="text-[11px] font-semibold text-amber-700">Isian Singkat</span>
            <div class="text-2xl font-black text-amber-900 font-heading"><?= $statIsian ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-purple-50/50 border border-purple-200/60 shadow-xs space-y-1 col-span-2 sm:col-span-1">
            <span class="text-[11px] font-semibold text-purple-700">Essay / Uraian</span>
            <div class="text-2xl font-black text-purple-900 font-heading"><?= $statEssay ?></div>
        </div>
    </div>

    <!-- Filter & Pencarian Soal -->
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs">
        <form method="GET" action="/bank-soal" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
            
            <!-- Filter Mata Kuliah -->
            <div class="sm:col-span-4">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Mata Kuliah</label>
                <select name="mk" onchange="this.form.submit()" 
                        class="w-full text-xs rounded-xl border-slate-200 focus:border-cyan-500 focus:ring-cyan-500 bg-slate-50 py-2">
                    <option value="all">Semua Mata Kuliah (Umum)</option>
                    <?php foreach ($listMK as $mk): ?>
                        <option value="<?= htmlspecialchars($mk['id_mk']) ?>" <?= $filterMK === $mk['id_mk'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mk['kode_mk']) ?> - <?= htmlspecialchars($mk['nama_mk']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Tipe Soal -->
            <div class="sm:col-span-3">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Tipe Soal</label>
                <select name="tipe" onchange="this.form.submit()" 
                        class="w-full text-xs rounded-xl border-slate-200 focus:border-cyan-500 focus:ring-cyan-500 bg-slate-50 py-2">
                    <option value="all">Semua Tipe Soal</option>
                    <option value="pg" <?= $filterTipe === 'pg' ? 'selected' : '' ?>>Pilihan Ganda (PG)</option>
                    <option value="benar_salah" <?= $filterTipe === 'benar_salah' ? 'selected' : '' ?>>Benar / Salah (B/S)</option>
                    <option value="isian" <?= $filterTipe === 'isian' ? 'selected' : '' ?>>Isian Singkat</option>
                    <option value="essay" <?= $filterTipe === 'essay' ? 'selected' : '' ?>>Essay / Uraian</option>
                </select>
            </div>

            <!-- Pencarian Teks -->
            <div class="sm:col-span-4">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Cari Pertanyaan</label>
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-2.5"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchKeyword) ?>" 
                           placeholder="Ketik kata kunci pertanyaan..." 
                           class="w-full pl-9 pr-3 py-2 text-xs rounded-xl border-slate-200 focus:border-cyan-500 focus:ring-cyan-500 bg-slate-50">
                </div>
            </div>

            <!-- Tombol Submit & Reset -->
            <div class="sm:col-span-1 sm:pt-5 flex items-center space-x-1">
                <button type="submit" class="p-2 rounded-xl bg-slate-800 hover:bg-slate-900 text-white transition" title="Terapkan Filter">
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
                <?php if ($filterMK !== 'all' || $filterTipe !== 'all' || $searchKeyword !== ''): ?>
                    <a href="/bank-soal" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 transition" title="Reset Filter">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Daftar Butir Soal -->
    <div class="rounded-2xl bg-white border border-slate-200 shadow-xs overflow-hidden">
        <?php if (empty($daftarSoal)): ?>
            <div class="p-12 text-center space-y-3">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400">
                    <i data-lucide="file-question" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Belum Ada Butir Soal</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto">
                    Repositori Bank Soal masih kosong untuk filter yang dipilih. Silakan klik tombol "Tambah Butir Soal" untuk membuat soal baru.
                </p>
                <button type="button" onclick="openSoalModal()" class="inline-flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Buat Soal Pertama</span>
                </button>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($daftarSoal as $idx => $soal): ?>
                    <?php 
                        $opsiList = $opsiBySoal[$soal['id_soal']] ?? [];
                        // Ambil opsi yang benar
                        $kunciLabel = '';
                        foreach ($opsiList as $op) {
                            if ((int)$op['is_benar'] === 1) {
                                $kunciLabel = $op['label_opsi'];
                                break;
                            }
                        }
                    ?>
                    <div class="p-5 hover:bg-slate-50/70 transition space-y-3.5">
                        
                        <!-- Top Meta Bar -->
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center space-x-2">
                                <span class="text-xs font-mono font-bold text-slate-400">#<?= $idx + 1 ?></span>
                                <?= get_tipe_soal_badge($soal['tipe_soal']) ?>
                                <?php if (!empty($soal['kode_mk'])): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-cyan-50 text-cyan-800 border border-cyan-200">
                                        <?= htmlspecialchars($soal['kode_mk']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600">
                                        Umum
                                    </span>
                                <?php endif; ?>
                                <span class="text-[11px] text-slate-500">Bobot Default: <strong><?= (float)$soal['bobot_default'] ?> Poin</strong></span>
                                <?php if ((int)$soal['dipakai_di_kuis'] > 0): ?>
                                    <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200" title="Dipakai di kuis aktif">
                                        <i data-lucide="link" class="w-3 h-3"></i>
                                        <span>Dipakai di <?= (int)$soal['dipakai_di_kuis'] ?> Kuis</span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Tombol Aksi Edit & Hapus -->
                            <div class="flex items-center space-x-1.5">
                                <button type="button" 
                                        onclick='editSoal(<?= json_encode($soal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($opsiList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-cyan-600 hover:bg-cyan-50 transition" title="Edit Soal">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>
                                <form action="/bank-soal" method="POST" class="inline" onsubmit="return confirm('Hapus butir soal ini dari Bank Soal?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="delete_soal">
                                    <input type="hidden" name="id_soal" value="<?= htmlspecialchars($soal['id_soal']) ?>">
                                    <button type="submit" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Hapus Soal">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Teks Pertanyaan -->
                        <div class="text-sm font-medium text-slate-900 leading-relaxed">
                            <?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?>
                        </div>

                        <!-- Preview Opsi / Kunci Jawaban -->
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80 text-xs space-y-2">
                            <?php if ($soal['tipe_soal'] === 'pg'): ?>
                                <p class="font-bold text-slate-600 mb-1 text-[11px] uppercase tracking-wider">Opsi Pilihan Ganda:</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($opsiList as $op): ?>
                                        <?php $isKey = ((int)$op['is_benar'] === 1); ?>
                                        <div class="p-2 rounded-lg flex items-start space-x-2 <?= $isKey ? 'bg-emerald-50 text-emerald-950 border border-emerald-300 font-semibold' : 'bg-white text-slate-700 border border-slate-200' ?>">
                                            <span class="w-5 h-5 rounded flex items-center justify-center font-bold text-xs shrink-0 <?= $isKey ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' ?>">
                                                <?= htmlspecialchars($op['label_opsi']) ?>
                                            </span>
                                            <span class="flex-1 truncate"><?= htmlspecialchars($op['teks_opsi']) ?></span>
                                            <?php if ($isKey): ?>
                                                <span class="text-[10px] font-bold text-emerald-700 uppercase shrink-0">[Kunci]</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($soal['tipe_soal'] === 'benar_salah'): ?>
                                <div class="flex items-center space-x-2">
                                    <span class="font-bold text-slate-600">Kunci Jawaban Pernyataan:</span>
                                    <span class="px-2.5 py-0.5 rounded-full font-bold text-xs <?= $kunciLabel === 'Benar' ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-rose-100 text-rose-800 border border-rose-300' ?>">
                                        <?= htmlspecialchars($kunciLabel) ?>
                                    </span>
                                </div>
                            <?php elseif ($soal['tipe_soal'] === 'isian'): ?>
                                <div class="flex items-start space-x-2">
                                    <span class="font-bold text-slate-600 shrink-0">Kunci Jawaban Singkat:</span>
                                    <span class="font-mono font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                        <?= htmlspecialchars($soal['kunci_jawaban_teks'] ?? '-') ?>
                                    </span>
                                    <span class="text-slate-400 text-[11px]">(Toleransi huruf besar/kecil otomatis)</span>
                                </div>
                            <?php elseif ($soal['tipe_soal'] === 'essay'): ?>
                                <div class="space-y-1">
                                    <span class="font-bold text-slate-600">Panduan / Rubrik Penilaian Dosen:</span>
                                    <p class="text-slate-700 italic bg-white p-2.5 rounded-lg border border-slate-200">
                                        <?= !empty($soal['kunci_jawaban_teks']) ? nl2br(htmlspecialchars($soal['kunci_jawaban_teks'])) : '<span class="text-slate-400">Tidak ada rubrik khusus (dinilai manual secara fleksibel oleh dosen).</span>' ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($soal['pembahasan'])): ?>
                                <div class="pt-2 border-t border-slate-200/60 text-slate-600">
                                    <span class="font-bold text-[11px]">Pembahasan / Catatan:</span>
                                    <p class="mt-0.5 text-[11px] leading-relaxed text-slate-500"><?= nl2br(htmlspecialchars($soal['pembahasan'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- ============================================================= -->
<!-- MODAL TAMBAH / EDIT BUTIR SOAL                                -->
<!-- ============================================================= -->
<div id="modal-soal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl border border-slate-200">
        
        <form action="/bank-soal" method="POST" id="form-soal">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" id="form_action" value="create_soal">
            <input type="hidden" name="id_soal" id="form_id_soal" value="">

            <!-- Header Modal -->
            <div class="flex items-center justify-between p-5 border-b border-slate-100 bg-slate-50/70">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-xl bg-cyan-100 text-cyan-800 flex items-center justify-center font-bold">
                        <i data-lucide="help-circle" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900 font-heading" id="modal-title">Tambah Butir Soal Baru</h3>
                        <p class="text-[11px] text-slate-500">Pilih tipe soal dan lengkapi pertanyaan serta kunci jawaban.</p>
                    </div>
                </div>
                <button type="button" onclick="closeSoalModal()" class="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Body Form -->
            <div class="p-5 space-y-4">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <!-- Pilihan Mata Kuliah -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Mata Kuliah Terkait</label>
                        <select name="id_mk" id="input_id_mk" class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2">
                            <option value="">Umum / Semua Mata Kuliah</option>
                            <?php foreach ($listMK as $mk): ?>
                                <option value="<?= htmlspecialchars($mk['id_mk']) ?>">
                                    <?= htmlspecialchars($mk['kode_mk']) ?> - <?= htmlspecialchars($mk['nama_mk']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tipe Soal -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tipe Soal *</label>
                        <select name="tipe_soal" id="input_tipe_soal" onchange="toggleTipeSoalFields(this.value)" class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2 font-semibold">
                            <option value="pg">Pilihan Ganda (PG)</option>
                            <option value="benar_salah">Benar / Salah (True / False)</option>
                            <option value="isian">Isian Singkat</option>
                            <option value="essay">Essay / Uraian</option>
                        </select>
                    </div>
                </div>

                <!-- Teks Pertanyaan -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pertanyaan / Kasus Soal *</label>
                    <textarea name="pertanyaan" id="input_pertanyaan" rows="4" required
                              placeholder="Tuliskan teks pertanyaan soal secara lengkap..."
                              class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 p-3 leading-relaxed"></textarea>
                </div>

                <!-- FORM DINAMIS: PILIHAN GANDA (PG) -->
                <div id="section_pg" class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-slate-800">Opsi Jawaban & Kunci Benar (Pilih Radio untuk Kunci)</label>
                        <span class="text-[10px] text-slate-500">Minimal 2 opsi</span>
                    </div>

                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $opt): ?>
                        <div class="flex items-center space-x-2">
                            <label class="flex items-center space-x-1.5 cursor-pointer shrink-0" title="Tandai sebagai Kunci Jawaban">
                                <input type="radio" name="kunci_pg" value="<?= $opt ?>" <?= $opt === 'A' ? 'checked' : '' ?> class="text-emerald-600 focus:ring-emerald-500 h-4 w-4">
                                <span class="w-6 h-6 rounded-lg bg-slate-200 text-slate-800 font-bold text-xs flex items-center justify-center"><?= $opt ?></span>
                            </label>
                            <input type="text" name="opsi_pg[<?= $opt ?>]" id="input_opsi_pg_<?= $opt ?>" 
                                   placeholder="Teks opsi <?= $opt ?>..." 
                                   class="flex-1 text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-1.5 px-3 bg-white">
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- FORM DINAMIS: BENAR / SALAH (B/S) -->
                <div id="section_benar_salah" class="hidden p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                    <label class="block text-xs font-bold text-slate-800 mb-1">Kunci Jawaban Pernyataan Ini:</label>
                    <div class="flex items-center space-x-4">
                        <label class="inline-flex items-center space-x-2 cursor-pointer p-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                            <input type="radio" name="kunci_bs" id="input_kunci_bs_benar" value="Benar" checked class="text-emerald-600 focus:ring-emerald-500 h-4 w-4">
                            <span class="text-xs font-bold text-emerald-800">Benar (True)</span>
                        </label>
                        <label class="inline-flex items-center space-x-2 cursor-pointer p-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                            <input type="radio" name="kunci_bs" id="input_kunci_bs_salah" value="Salah" class="text-rose-600 focus:ring-rose-500 h-4 w-4">
                            <span class="text-xs font-bold text-rose-800">Salah (False)</span>
                        </label>
                    </div>
                </div>

                <!-- FORM DINAMIS: ISIAN SINGKAT -->
                <div id="section_isian" class="hidden p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                    <label class="block text-xs font-bold text-slate-800">Kunci Jawaban Isian Singkat *</label>
                    <input type="text" name="kunci_isian" id="input_kunci_isian" 
                           placeholder="Contoh: Arlindo (atau pisahkan dengan koma: Arlindo, Arus Lintas Indonesia)" 
                           class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2 px-3 bg-white">
                    <p class="text-[10px] text-slate-500">Pencocokan jawaban mahasiswa mengabaikan huruf kapital/kecil secara otomatis.</p>
                </div>

                <!-- FORM DINAMIS: ESSAY / URAIAN -->
                <div id="section_essay" class="hidden p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                    <label class="block text-xs font-bold text-slate-800">Panduan Jawaban / Rubrik Penilaian Dosen (Opsional)</label>
                    <textarea name="rubrik_essay" id="input_rubrik_essay" rows="3" 
                              placeholder="Tuliskan poin-poin yang harus dijawab oleh mahasiswa untuk memandu dosen saat mengoreksi..." 
                              class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 p-2.5 bg-white"></textarea>
                    <p class="text-[10px] text-purple-700">Soal essay akan dikoreksi dan dinilai secara manual oleh dosen setelah kuis dikumpulkan.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <!-- Bobot Default -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Bobot Poin Default *</label>
                        <input type="number" step="0.5" min="1" max="100" name="bobot_default" id="input_bobot_default" value="10" required
                               class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2 px-3">
                        <span class="text-[10px] text-slate-400">Dapat disesuaikan kembali saat dirangkai ke kuis tertentu.</span>
                    </div>

                    <!-- Pembahasan -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Pembahasan / Rujukan (Opsional)</label>
                        <input type="text" name="pembahasan" id="input_pembahasan" 
                               placeholder="Rujukan materi modul atau penjelasan..." 
                               class="w-full text-xs rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500 py-2 px-3">
                    </div>
                </div>

            </div>

            <!-- Footer Modal -->
            <div class="flex items-center justify-end space-x-2.5 p-4 border-t border-slate-100 bg-slate-50/70">
                <button type="button" onclick="closeSoalModal()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-200 transition">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow-sm transition">
                    Simpan Butir Soal
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function toggleTipeSoalFields(tipe) {
    document.getElementById('section_pg').classList.add('hidden');
    document.getElementById('section_benar_salah').classList.add('hidden');
    document.getElementById('section_isian').classList.add('hidden');
    document.getElementById('section_essay').classList.add('hidden');

    if (tipe === 'pg') {
        document.getElementById('section_pg').classList.remove('hidden');
    } else if (tipe === 'benar_salah') {
        document.getElementById('section_benar_salah').classList.remove('hidden');
    } else if (tipe === 'isian') {
        document.getElementById('section_isian').classList.remove('hidden');
    } else if (tipe === 'essay') {
        document.getElementById('section_essay').classList.remove('hidden');
    }
}

function openSoalModal() {
    document.getElementById('form_action').value = 'create_soal';
    document.getElementById('form_id_soal').value = '';
    document.getElementById('modal-title').textContent = 'Tambah Butir Soal Baru';
    document.getElementById('input_id_mk').value = '<?= $filterMK !== 'all' ? htmlspecialchars($filterMK) : '' ?>';
    document.getElementById('input_tipe_soal').value = '<?= in_array($filterTipe, ['pg', 'benar_salah', 'isian', 'essay']) ? $filterTipe : 'pg' ?>';
    document.getElementById('input_pertanyaan').value = '';
    document.getElementById('input_bobot_default').value = '10';
    document.getElementById('input_pembahasan').value = '';
    document.getElementById('input_kunci_isian').value = '';
    document.getElementById('input_rubrik_essay').value = '';

    ['A', 'B', 'C', 'D', 'E'].forEach(opt => {
        const el = document.getElementById('input_opsi_pg_' + opt);
        if (el) el.value = '';
    });
    const radA = document.querySelector('input[name="kunci_pg"][value="A"]');
    if (radA) radA.checked = true;

    toggleTipeSoalFields(document.getElementById('input_tipe_soal').value);
    document.getElementById('modal-soal').classList.remove('hidden');
}

function editSoal(soal, opsiList) {
    document.getElementById('form_action').value = 'update_soal';
    document.getElementById('form_id_soal').value = soal.id_soal;
    document.getElementById('modal-title').textContent = 'Edit Butir Soal';
    document.getElementById('input_id_mk').value = soal.id_mk || '';
    document.getElementById('input_tipe_soal').value = soal.tipe_soal;
    document.getElementById('input_pertanyaan').value = soal.pertanyaan || '';
    document.getElementById('input_bobot_default').value = parseFloat(soal.bobot_default) || 10;
    document.getElementById('input_pembahasan').value = soal.pembahasan || '';

    toggleTipeSoalFields(soal.tipe_soal);

    // Isi data spesifik tipe
    if (soal.tipe_soal === 'pg') {
        ['A', 'B', 'C', 'D', 'E'].forEach(opt => {
            const el = document.getElementById('input_opsi_pg_' + opt);
            if (el) el.value = '';
        });
        opsiList.forEach(op => {
            const el = document.getElementById('input_opsi_pg_' + op.label_opsi);
            if (el) el.value = op.teks_opsi;
            if (parseInt(op.is_benar, 10) === 1) {
                const rad = document.querySelector(`input[name="kunci_pg"][value="${op.label_opsi}"]`);
                if (rad) rad.checked = true;
            }
        });
    } else if (soal.tipe_soal === 'benar_salah') {
        opsiList.forEach(op => {
            if (parseInt(op.is_benar, 10) === 1) {
                if (op.label_opsi === 'Benar') {
                    document.getElementById('input_kunci_bs_benar').checked = true;
                } else {
                    document.getElementById('input_kunci_bs_salah').checked = true;
                }
            }
        });
    } else if (soal.tipe_soal === 'isian') {
        document.getElementById('input_kunci_isian').value = soal.kunci_jawaban_teks || '';
    } else if (soal.tipe_soal === 'essay') {
        document.getElementById('input_rubrik_essay').value = soal.kunci_jawaban_teks || '';
    }

    document.getElementById('modal-soal').classList.remove('hidden');
}

function closeSoalModal() {
    document.getElementById('modal-soal').classList.add('hidden');
}

// Hubungkan input_kunci_isian dan input_rubrik_essay ke name="kunci_jawaban_teks" sebelum submit
document.getElementById('form-soal').addEventListener('submit', function(e) {
    const tipe = document.getElementById('input_tipe_soal').value;
    let hiddenKunci = document.getElementById('hidden_kunci_jawaban_teks');
    if (!hiddenKunci) {
        hiddenKunci = document.createElement('input');
        hiddenKunci.type = 'hidden';
        hiddenKunci.name = 'kunci_jawaban_teks';
        hiddenKunci.id = 'hidden_kunci_jawaban_teks';
        this.appendChild(hiddenKunci);
    }

    if (tipe === 'isian') {
        hiddenKunci.value = document.getElementById('input_kunci_isian').value.trim();
    } else if (tipe === 'essay') {
        hiddenKunci.value = document.getElementById('input_rubrik_essay').value.trim();
    } else {
        hiddenKunci.value = '';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

