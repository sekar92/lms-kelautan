<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Manajemen Mata Kuliah, Kurikulum, & Dokumen Pendukung (Khusus Dosen)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

require_dosen_admin();

$pageTitle = 'Manajemen Mata Kuliah';
$db = get_db();
$currentUser = current_user();

// Tangani Pengiriman Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create' || $action === 'update') {
        $idMK = trim((string)($_POST['id_mk'] ?? ''));
        $namaMK = trim((string)($_POST['nama_mk'] ?? ''));
        $kodeMK = trim((string)($_POST['kode_mk'] ?? ''));
        $sks = (int)($_POST['sks'] ?? 3);
        $semester = (int)($_POST['semester'] ?? 1);
        $idTA = trim((string)($_POST['id_ta'] ?? ''));
        // Ambil penugasan dosen (hingga 3 dosen untuk Team Teaching)
        $inputDosenIds = $_POST['dosen_ids'] ?? [];
        $dosenIds = [];
        if (is_array($inputDosenIds)) {
            for ($slot = 1; $slot <= 3; $slot++) {
                $uid = trim((string)($inputDosenIds[$slot] ?? ''));
                if ($uid !== '' && !in_array($uid, $dosenIds, true)) {
                    $dosenIds[$slot] = $uid;
                }
            }
        }
        // Fallback untuk id_dosen tunggal jika dikirim form lama
        if (empty($dosenIds)) {
            $legacyIdDosen = trim((string)($_POST['id_dosen'] ?? ''));
            if ($legacyIdDosen !== '') {
                $dosenIds[1] = $legacyIdDosen;
            }
        }

        $dosenNames = [];
        if (!empty($dosenIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($dosenIds), '?'));
            $stmtLecs = $db->prepare("SELECT id_user, nama FROM users WHERE id_user IN ($inPlaceholders)");
            $stmtLecs->execute(array_values($dosenIds));
            $nameMap = $stmtLecs->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($dosenIds as $slot => $uid) {
                if (!empty($nameMap[$uid])) {
                    $dosenNames[] = $nameMap[$uid];
                }
            }
        }

        $idDosen = $dosenIds[1] ?? null;
        $dosenPengampu = !empty($dosenNames) ? implode(', ', $dosenNames) : $currentUser['nama'];
        $deskripsiMK = trim((string)($_POST['deskripsi_mk'] ?? ''));
        $dokumenUrl = trim((string)($_POST['dokumen_lain_url'] ?? ''));

        if ($namaMK === '' || $kodeMK === '' || $idTA === '') {
            set_flash('error', 'Nama Mata Kuliah, Kode MK, dan Tahun Akademik wajib diisi.');
            header('Location: /mata-kuliah' . ($action === 'update' ? "?action=edit&id={$idMK}" : ''));
            exit;
        }

        try {
            $rpsPath = null;
            $silabusPath = null;

            // Jika Update, ambil path lama terlebih dahulu
            if ($action === 'update' && $idMK !== '') {
                $stmtOld = $db->prepare("SELECT rps_file_path, silabus_file_path FROM mata_kuliah WHERE id_mk = ?");
                $stmtOld->execute([$idMK]);
                $oldRow = $stmtOld->fetch();
                $rpsPath = $oldRow['rps_file_path'] ?? null;
                $silabusPath = $oldRow['silabus_file_path'] ?? null;
            }

            // Upload Berkas RPS jika ada
            if (!empty($_FILES['rps_file']['name'])) {
                $uploadRPS = handle_curriculum_upload($_FILES['rps_file'], 'rps', $kodeMK);
                if ($uploadRPS['success']) {
                    $rpsPath = $uploadRPS['path'];
                } else {
                    set_flash('warning', 'Mata kuliah disimpan, namun upload RPS gagal: ' . $uploadRPS['error']);
                }
            }

            // Upload Berkas Silabus jika ada
            if (!empty($_FILES['silabus_file']['name'])) {
                $uploadSilabus = handle_curriculum_upload($_FILES['silabus_file'], 'silabus', $kodeMK);
                if ($uploadSilabus['success']) {
                    $silabusPath = $uploadSilabus['path'];
                } else {
                    set_flash('warning', 'Mata kuliah disimpan, namun upload Silabus gagal: ' . $uploadSilabus['error']);
                }
            }

            if ($action === 'create') {
                $idMK = generate_id('MK', 'mata_kuliah', 'id_mk');
                $stmt = $db->prepare("
                    INSERT INTO mata_kuliah (id_mk, nama_mk, kode_mk, sks, semester, id_ta, dosen_pengampu, id_dosen, deskripsi_mk, rps_file_path, silabus_file_path, dokumen_lain_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$idMK, $namaMK, $kodeMK, $sks, $semester, $idTA, $dosenPengampu, $idDosen, $deskripsiMK, $rpsPath, $silabusPath, $dokumenUrl]);
                set_flash('success', "Mata Kuliah '{$namaMK}' ({$kodeMK}) berhasil ditambahkan.");
            } else {
                $stmt = $db->prepare("
                    UPDATE mata_kuliah 
                    SET nama_mk = ?, kode_mk = ?, sks = ?, semester = ?, id_ta = ?, dosen_pengampu = ?, id_dosen = ?, deskripsi_mk = ?, rps_file_path = ?, silabus_file_path = ?, dokumen_lain_url = ?
                    WHERE id_mk = ?
                ");
                set_flash('success', "Mata Kuliah '{$namaMK}' berhasil diperbarui.");
            }

            // Simpan pemetaan tim dosen pengampu (Team Teaching) ke tabel dosen_mata_kuliah
            $stmtDelDMK = $db->prepare("DELETE FROM dosen_mata_kuliah WHERE id_mk = ?");
            $stmtDelDMK->execute([$idMK]);

            if (!empty($dosenIds)) {
                $stmtInsDMK = $db->prepare("INSERT INTO dosen_mata_kuliah (id_mk, id_user, urutan) VALUES (?, ?, ?)");
                foreach ($dosenIds as $urutan => $uid) {
                    $stmtInsDMK->execute([$idMK, $uid, $urutan]);
                }
            }
        } catch (Throwable $e) {
            set_flash('error', 'Gagal memproses data mata kuliah: ' . $e->getMessage());
        }

        header('Location: /mata-kuliah');
        exit;
    } elseif ($action === 'delete') {
        $idMK = trim((string)($_POST['id_mk'] ?? ''));
        if ($idMK !== '') {
            try {
                $stmt = $db->prepare("DELETE FROM mata_kuliah WHERE id_mk = ?");
                $stmt->execute([$idMK]);
                set_flash('success', 'Mata Kuliah beserta seluruh materi dan pesertanya berhasil dihapus.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menghapus mata kuliah: ' . $e->getMessage());
            }
        }
        header('Location: /mata-kuliah');
        exit;
    }
}

// Mode Edit atau Create
$editId = $_GET['id'] ?? null;
$editItem = null;
$editDosenIds = [];
if (isset($_GET['action']) && $_GET['action'] === 'edit' && $editId) {
    $stmtEdit = $db->prepare("SELECT * FROM mata_kuliah WHERE id_mk = ?");
    $stmtEdit->execute([$editId]);
    $editItem = $stmtEdit->fetch() ?: null;

    if ($editItem) {
        try {
            $stmtDMK = $db->prepare("SELECT id_user, urutan FROM dosen_mata_kuliah WHERE id_mk = ? ORDER BY urutan ASC");
            $stmtDMK->execute([$editId]);
            $rowsDMK = $stmtDMK->fetchAll();
            foreach ($rowsDMK as $r) {
                $editDosenIds[(int)$r['urutan']] = $r['id_user'];
            }
        } catch (Throwable $e) {
            // Abaikan jika tabel relasi belum siap
        }
        if (empty($editDosenIds) && !empty($editItem['id_dosen'])) {
            $editDosenIds[1] = $editItem['id_dosen'];
        }
    }
}

// Ambil Daftar Tahun Akademik untuk Dropdown
$taOptions = $db->query("SELECT id_ta, nama_ta, status FROM tahun_akademik ORDER BY created_at DESC")->fetchAll();

// Ambil Daftar Dosen untuk Dropdown Penugasan Pengampu
$lecturers = $db->query("SELECT id_user, nama, username, role FROM users WHERE LOWER(role) IN ('dosen', 'dosen admin', 'admin') ORDER BY nama ASC")->fetchAll();

// Ambil Daftar Seluruh Mata Kuliah
$stmtCourses = $db->query("
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
$courses = $stmtCourses->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
            <div class="flex items-center space-x-2 text-xs font-semibold text-cyan-700 uppercase tracking-wider">
                <i data-lucide="book-open" class="w-4 h-4"></i>
                <span>Kurikulum & Kelas</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 font-heading mt-1">
                Manajemen Mata Kuliah
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Tambah, perbarui silabus/RPS, dan kelola mata kuliah <?= htmlspecialchars($branding['study_program']) ?>.
            </p>
        </div>
        <div class="flex items-center space-x-2.5">
            <a href="/mahasiswa" class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-sm transition">
                <i data-lucide="users" class="w-4 h-4 text-cyan-600"></i>
                <span>Kelola Mahasiswa</span>
            </a>
        </div>
    </div>

    <!-- Layout 2 Kolom: Form Input & Tabel Mata Kuliah -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Kolom Form (4/12) -->
        <div class="lg:col-span-4 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm sticky top-24">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                        <i data-lucide="<?= $editItem ? 'pencil' : 'plus-circle' ?>" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-slate-900 font-heading">
                            <?= $editItem ? 'Edit Mata Kuliah' : 'Tambah Mata Kuliah' ?>
                        </h2>
                        <p class="text-xs text-slate-500"><?= $editItem ? 'Perbarui data kelas & berkas' : 'Rancang kelas baru' ?></p>
                    </div>
                </div>
                <?php if ($editItem): ?>
                    <a href="/mata-kuliah" class="text-xs font-semibold text-rose-600 hover:underline">Batal Edit</a>
                <?php endif; ?>
            </div>

            <form action="/mata-kuliah" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'create' ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="id_mk" value="<?= htmlspecialchars($editItem['id_mk']) ?>">
                <?php endif; ?>

                <div>
                    <label for="nama_mk" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Nama Mata Kuliah *
                    </label>
                    <input id="nama_mk" name="nama_mk" type="text" required
                           value="<?= htmlspecialchars($editItem['nama_mk'] ?? '') ?>"
                           placeholder="Contoh: Oseanografi Geologi"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="kode_mk" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Kode MK *
                        </label>
                        <input id="kode_mk" name="kode_mk" type="text" required
                               value="<?= htmlspecialchars($editItem['kode_mk'] ?? '') ?>"
                               placeholder="TKL-2101"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>
                    <div>
                        <label for="sks" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Bobot SKS
                        </label>
                        <select id="sks" name="sks" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?= $i ?>" <?= (isset($editItem) && (int)$editItem['sks'] === $i) || (!isset($editItem) && $i === 3) ? 'selected' : '' ?>>
                                    <?= $i ?> SKS
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="semester" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Semester
                        </label>
                        <select id="semester" name="semester" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            <?php for ($s = 1; $s <= 8; $s++): ?>
                                <option value="<?= $s ?>" <?= (isset($editItem) && (int)$editItem['semester'] === $s) ? 'selected' : '' ?>>
                                    Semester <?= $s ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="id_ta" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Tahun Akademik *
                        </label>
                        <select id="id_ta" name="id_ta" required class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            <?php foreach ($taOptions as $ta): ?>
                                <option value="<?= htmlspecialchars($ta['id_ta']) ?>" 
                                    <?= (isset($editItem) && $editItem['id_ta'] === $ta['id_ta']) || (!isset($editItem) && $ta['status'] === 'Aktif') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ta['nama_ta']) ?> <?= $ta['status'] === 'Aktif' ? '★' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Tim Dosen Pengampu (Team Teaching 1-3 Dosen) -->
                <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/90 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider">
                            Tim Dosen Pengampu (Team Teaching: 1 s/d 3 Dosen) *
                        </label>
                        <span class="text-[11px] font-semibold text-ocean-cyan bg-cyan-50 px-2 py-0.5 rounded-full border border-cyan-200">
                            Maks. 3 Dosen
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500">
                        Setiap mata kuliah dapat diampu bersama (team teaching) oleh 1 hingga 3 orang dosen. Dosen yang terdaftar dapat mengelola kelas dan memeriksa tugas mahasiswa.
                    </p>

                    <div class="space-y-2.5">
                        <!-- Slot Dosen 1: Koordinator MK (Wajib) -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-semibold text-slate-700">Dosen Pengampu 1 (Koordinator MK) *</span>
                                <span class="text-[10px] text-emerald-600 font-bold bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">Koordinator Utama</span>
                            </div>
                            <select id="dosen_ids_1" name="dosen_ids[1]" required class="w-full px-3 py-2 text-xs sm:text-sm bg-white border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan transition">
                                <option value="">-- Pilih Dosen Koordinator Utama --</option>
                                <?php foreach ($lecturers as $lec): ?>
                                    <option value="<?= htmlspecialchars($lec['id_user']) ?>" 
                                        <?= (isset($editDosenIds[1]) && $editDosenIds[1] === $lec['id_user']) || (!isset($editItem) && $lec['id_user'] === $currentUser['id_user']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lec['nama']) ?> (<?= htmlspecialchars($lec['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Slot Dosen 2: Tim Pengajar 2 (Opsional) -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-semibold text-slate-700">Dosen Pengampu 2 (Anggota Tim Pengajar)</span>
                                <span class="text-[10px] text-slate-400 font-medium">Opsional</span>
                            </div>
                            <select id="dosen_ids_2" name="dosen_ids[2]" class="w-full px-3 py-2 text-xs sm:text-sm bg-white border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan transition">
                                <option value="">-- Belum Ditugaskan (Opsional) --</option>
                                <?php foreach ($lecturers as $lec): ?>
                                    <option value="<?= htmlspecialchars($lec['id_user']) ?>" 
                                        <?= (isset($editDosenIds[2]) && $editDosenIds[2] === $lec['id_user']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lec['nama']) ?> (<?= htmlspecialchars($lec['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Slot Dosen 3: Tim Pengajar 3 (Opsional) -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-semibold text-slate-700">Dosen Pengampu 3 (Anggota Tim Pengajar)</span>
                                <span class="text-[10px] text-slate-400 font-medium">Opsional</span>
                            </div>
                            <select id="dosen_ids_3" name="dosen_ids[3]" class="w-full px-3 py-2 text-xs sm:text-sm bg-white border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan transition">
                                <option value="">-- Belum Ditugaskan (Opsional) --</option>
                                <?php foreach ($lecturers as $lec): ?>
                                    <option value="<?= htmlspecialchars($lec['id_user']) ?>" 
                                        <?= (isset($editDosenIds[3]) && $editDosenIds[3] === $lec['id_user']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lec['nama']) ?> (<?= htmlspecialchars($lec['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="deskripsi_mk" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Deskripsi & CPMK Mata Kuliah
                    </label>
                    <textarea id="deskripsi_mk" name="deskripsi_mk" rows="3"
                              placeholder="Deskripsi ringkas, capaian pembelajaran, & prasyarat perkuliahan..."
                              class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition"><?= htmlspecialchars($editItem['deskripsi_mk'] ?? '') ?></textarea>
                </div>

                <!-- Unggah Berkas RPS & Silabus -->
                <div class="space-y-3 pt-2 border-t border-slate-100">
                    <div>
                        <label for="rps_file" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Berkas RPS (Wajib PDF)
                        </label>
                        <input id="rps_file" name="rps_file" type="file" accept=".pdf"
                               class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-800 hover:file:bg-cyan-100 cursor-pointer">
                        <?php if (!empty($editItem['rps_file_path'])): ?>
                            <p class="text-[11px] text-emerald-600 mt-1 flex items-center space-x-1">
                                <i data-lucide="check" class="w-3 h-3"></i>
                                <span>RPS tersimpan: <?= basename($editItem['rps_file_path']) ?></span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="silabus_file" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Berkas Silabus (PDF / DOCX)
                        </label>
                        <input id="silabus_file" name="silabus_file" type="file" accept=".pdf,.docx,.doc"
                               class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-800 hover:file:bg-slate-200 cursor-pointer">
                        <?php if (!empty($editItem['silabus_file_path'])): ?>
                            <p class="text-[11px] text-emerald-600 mt-1 flex items-center space-x-1">
                                <i data-lucide="check" class="w-3 h-3"></i>
                                <span>Silabus tersimpan: <?= basename($editItem['silabus_file_path']) ?></span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="dokumen_lain_url" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Tautan Literatur Kelautan.ID (Opsional)
                        </label>
                        <input id="dokumen_lain_url" name="dokumen_lain_url" type="url"
                               value="<?= htmlspecialchars($editItem['dokumen_lain_url'] ?? '') ?>"
                               placeholder="https://journal.kelautan.id/..."
                               class="w-full px-3 py-1.5 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            class="w-full flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span><?= $editItem ? 'Simpan Perubahan' : 'Simpan Mata Kuliah' ?></span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Kolom Tabel Mata Kuliah (8/12) -->
        <div class="lg:col-span-8 space-y-4">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-slate-900 font-heading">Daftar Mata Kuliah</h2>
                        <p class="text-xs text-slate-500">Total <?= count($courses) ?> kelas perkuliahan terdaftar</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                <th class="py-3.5 px-5">Kode & Mata Kuliah</th>
                                <th class="py-3.5 px-3">SKS/Smtr</th>
                                <th class="py-3.5 px-3">Peserta</th>
                                <th class="py-3.5 px-3">Dokumen</th>
                                <th class="py-3.5 px-5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-slate-400">
                                        Belum ada mata kuliah yang terdaftar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <?php $cSlug = !empty($c['kode_mk']) ? $c['kode_mk'] : $c['id_mk']; ?>
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-4 px-5">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <span class="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-slate-100 text-maritime-700 border border-slate-200">
                                                    <?= htmlspecialchars($c['kode_mk']) ?>
                                                </span>
                                                <span class="text-xs text-slate-400">
                                                    <?= htmlspecialchars($c['nama_ta'] ?? '-') ?>
                                                </span>
                                            </div>
                                            <a href="/kelas/<?= urlencode($cSlug) ?>" class="font-bold text-slate-900 hover:text-cyan-700 font-heading">
                                                <?= htmlspecialchars($c['nama_mk']) ?>
                                            </a>
                                            <div class="text-xs text-slate-500 mt-0.5 flex items-center space-x-1">
                                                <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-600 shrink-0"></i>
                                                <span class="truncate">Koordinator: <strong class="text-slate-700 font-medium"><?= htmlspecialchars($c['nama_koordinator'] ?: $c['dosen_pengampu']) ?></strong></span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-3 text-xs text-slate-700">
                                            <span class="font-semibold"><?= (int)$c['sks'] ?> SKS</span>
                                            <div class="text-slate-400">Smtr <?= (int)$c['semester'] ?></div>
                                        </td>
                                        <td class="py-4 px-3">
                                            <a href="/kelas/<?= urlencode($cSlug) ?>/peserta" 
                                               class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-cyan-50 text-cyan-800 hover:bg-cyan-100 transition">
                                                <i data-lucide="users" class="w-3.5 h-3.5"></i>
                                                <span><?= (int)$c['total_peserta'] ?> Mhs</span>
                                            </a>
                                        </td>
                                        <td class="py-4 px-3">
                                            <div class="flex items-center space-x-1.5">
                                                <?php if (!empty($c['rps_file_path'])): ?>
                                                     <button type="button" 
                                                             onclick="openPdfModal('/unduh.php?jenis=rps&id_mk=<?= urlencode($c['id_mk']) ?>&mode=inline', 'Pratinjau RPS - <?= htmlspecialchars(addslashes($c['nama_mk'])) ?>')"
                                                             title="Preview RPS PDF"
                                                             class="p-1.5 rounded-lg text-cyan-700 bg-cyan-50 hover:bg-cyan-100 transition">
                                                         <i data-lucide="file-text" class="w-4 h-4"></i>
                                                     </button>
                                                <?php endif; ?>

                                                <?php if (!empty($c['silabus_file_path'])): ?>
                                                     <a href="/unduh.php?jenis=silabus&id_mk=<?= urlencode($c['id_mk']) ?>&mode=download" 
                                                        title="Unduh Silabus"
                                                        class="p-1.5 rounded-lg text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                                                         <i data-lucide="download" class="w-4 h-4"></i>
                                                     </a>
                                                <?php endif; ?>

                                                <?php if (empty($c['rps_file_path']) && empty($c['silabus_file_path'])): ?>
                                                    <span class="text-xs text-slate-300">-</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-5 text-right">
                                            <div class="flex items-center justify-end space-x-1">
                                                <a href="/kelas/<?= urlencode($cSlug) ?>" 
                                                   class="p-2 text-xs text-cyan-700 hover:bg-cyan-50 rounded-lg transition" title="Buka Ruang Kelas">
                                                    <i data-lucide="arrow-right-circle" class="w-4 h-4"></i>
                                                </a>
                                                <a href="/mata-kuliah?action=edit&id=<?= urlencode($c['id_mk']) ?>" 
                                                   class="p-2 text-xs text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg transition" title="Edit Data">
                                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                                </a>
                                                <form action="/mata-kuliah" method="POST" class="inline" onsubmit="return confirm('Hapus mata kuliah ini? Seluruh konten mingguan dan tugas terkait akan ikut terhapus!');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id_mk" value="<?= htmlspecialchars($c['id_mk']) ?>">
                                                    <button type="submit" class="p-2 text-xs text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Hapus">
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
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
