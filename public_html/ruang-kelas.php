<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Ruang Kelas Pembelajaran (Multi-Page Application)
 * URL: ruang-kelas.php?id=MK-XXXXX&tab=pertemuan|rps|peserta
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

$db = get_db();
$currentUser = current_user();
$isDosen = is_dosen();

$requestedSlug = trim((string)($_GET['id'] ?? ''));
if ($requestedSlug === '') {
    set_flash('error', 'Mata kuliah tidak valid.');
    header('Location: /dashboard');
    exit;
}

// Ambil Informasi Mata Kuliah (Mendukung Kode MK seperti TKL-2101 maupun ID internal MK-00001)
$stmtMK = $db->prepare("
    SELECT mk.*, ta.nama_ta, ta.status AS ta_status,
           (SELECT COUNT(*) FROM enrollment WHERE id_mk = mk.id_mk) AS total_peserta
    FROM mata_kuliah mk
    LEFT JOIN tahun_akademik ta ON mk.id_ta = ta.id_ta
    WHERE mk.kode_mk = ? OR mk.id_mk = ?
    LIMIT 1
");
$stmtMK->execute([$requestedSlug, $requestedSlug]);
$course = $stmtMK->fetch();

if (!$course) {
    set_flash('error', 'Mata kuliah tidak ditemukan.');
    header('Location: /dashboard');
    exit;
}

$idMK = $course['id_mk'];
$urlSlug = !empty($course['kode_mk']) ? $course['kode_mk'] : $course['id_mk'];

// Ambil Tim Dosen Pengampu Mata Kuliah
$stmtDMK = $db->prepare("
    SELECT dmk.urutan, u.id_user, u.nama, u.username, u.role
    FROM dosen_mata_kuliah dmk
    JOIN users u ON dmk.id_user = u.id_user
    WHERE dmk.id_mk = ?
    ORDER BY dmk.urutan ASC
");
$stmtDMK->execute([$idMK]);
$teamDosen = $stmtDMK->fetchAll();

$dosenKoordinator = null;
$dosenAnggota = [];

if (!empty($teamDosen)) {
    $dosenKoordinator = $teamDosen[0]['nama'];
    for ($i = 1; $i < count($teamDosen); $i++) {
        $dosenAnggota[] = $teamDosen[$i]['nama'];
    }
} else {
    // Fallback jika belum di-mapping di tabel relasi
    $dosenKoordinator = $course['dosen_pengampu'] ?: 'Belum ditentukan';
}

// Cek Hak Akses Pengguna
if (!$isDosen) {
    // Mahasiswa harus terdaftar dalam kelas ini
    $stmtCheckEnr = $db->prepare("SELECT 1 FROM enrollment WHERE id_user = ? AND id_mk = ?");
    $stmtCheckEnr->execute([$currentUser['id_user'], $idMK]);
    if (!$stmtCheckEnr->fetch()) {
        set_flash('error', 'Akses ditolak: Anda belum terdaftar dalam mata kuliah ini. Silakan hubungi dosen pengampu.');
        header('Location: /dashboard');
        exit;
    }
} elseif (is_dosen_biasa()) {
    // Dosen biasa hanya dapat masuk ke kelas yang di-assign oleh Dosen Admin
    if (!is_dosen_assigned_to_course($idMK, $currentUser['id_user'])) {
        set_flash('error', 'Akses ditolak: Anda tidak ditugaskan sebagai dosen pengampu pada mata kuliah ini.');
        header('Location: /dashboard');
        exit;
    }
}

$tab = $_GET['tab'] ?? 'pertemuan';
if (!in_array($tab, ['pertemuan', 'rps', 'peserta', 'rekap'], true)) {
    $tab = 'pertemuan';
}

// -------------------------------------------------------------
// POST HANDLERS (Dosen Only)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    // Guard: Mahasiswa dilarang keras mengirim POST di ruang kelas
    if (!$isDosen) {
        http_response_code(403);
        die("<h1>403 Forbidden</h1><p>Mahasiswa tidak memiliki hak mengubah konfigurasi kelas atau peserta.</p>");
    }

    $action = $_POST['action'] ?? '';

    // 1. Simpan Perubahan Peserta Kelas (Tab Peserta)
    if ($action === 'update_peserta' && $tab === 'peserta') {
        $selectedIds = $_POST['mahasiswa_ids'] ?? [];
        if (!is_array($selectedIds)) $selectedIds = [];

        try {
            $db->beginTransaction();
            // Hapus pendaftaran lama di MK ini
            $stmtDel = $db->prepare("DELETE FROM enrollment WHERE id_mk = ?");
            $stmtDel->execute([$idMK]);

            // Masukkan peserta baru
            $stmtIns = $db->prepare("INSERT INTO enrollment (id_enroll, id_user, id_mk) VALUES (?, ?, ?)");
            foreach ($selectedIds as $uid) {
                $idEnroll = generate_id('ENR', 'enrollment', 'id_enroll');
                $stmtIns->execute([$idEnroll, $uid, $idMK]);
            }
            $db->commit();

            log_activity('UPDATE_PESERTA', "Dosen '{$currentUser['nama']}' memperbarui peserta kelas '{$course['nama_mk']}' (" . count($selectedIds) . " mahasiswa terdaftar).");

            set_flash('success', 'Daftar peserta kelas berhasil diperbarui (' . count($selectedIds) . ' mahasiswa terdaftar).');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            set_flash('error', 'Gagal memperbarui peserta kelas: ' . $e->getMessage());
        }

        header("Location: /kelas/{$urlSlug}/peserta");
        exit;
    }

    // 2. Tambah Konten Pembelajaran Mingguan (Tab Pertemuan)
    if ($action === 'create_konten' && $tab === 'pertemuan') {
        $mingguKe = (int)($_POST['minggu_ke'] ?? 1);
        $tipe = $_POST['tipe'] ?? 'Materi';
        $judul = trim((string)($_POST['judul'] ?? ''));
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
        $urlEmbed = trim((string)($_POST['url_embed'] ?? ''));
        $durasi = !empty($_POST['durasi_menit']) ? (int)$_POST['durasi_menit'] : null;
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

        // Normalisasi URL jika ada input tanpa protokol http/https
        if ($urlEmbed !== '' && !preg_match('#^https?://#i', $urlEmbed)) {
            $urlEmbed = 'https://' . $urlEmbed;
        }

        if ($judul === '' || !in_array($tipe, ['Materi', 'Tugas', 'Kuis', 'Ujian'], true)) {
            set_flash('error', 'Judul dan tipe komponen wajib diisi dengan benar.');
        } else {
            try {
                $filePath = null;
                if (!empty($_FILES['file_lampiran']['name'])) {
                    $uploadRes = handle_material_upload($_FILES['file_lampiran'], $idMK, $mingguKe);
                    if ($uploadRes['success']) {
                        $filePath = $uploadRes['path'];
                    } else {
                        set_flash('error', 'Gagal mengunggah berkas: ' . $uploadRes['error']);
                        header("Location: /kelas/{$urlSlug}/pertemuan?open_minggu={$mingguKe}#minggu-{$mingguKe}");
                        exit;
                    }
                }

                $idKonten = generate_id('KNT', 'konten_mingguan', 'id_konten');
                $stmt = $db->prepare("
                    INSERT INTO konten_mingguan (id_konten, id_mk, minggu_ke, tipe, judul, deskripsi, file_path, url_embed, durasi_menit, deadline)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$idKonten, $idMK, $mingguKe, $tipe, $judul, $deskripsi, $filePath, $urlEmbed, $durasi, $deadline]);

                log_activity('TAMBAH_KONTEN', "Dosen '{$currentUser['nama']}' menambahkan {$tipe} '{$judul}' pada Minggu ke-{$mingguKe} di kelas '{$course['nama_mk']}'.");

                set_flash('success', "Komponen '{$tipe}: {$judul}' berhasil ditambahkan ke Minggu ke-{$mingguKe}.");
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menambahkan konten: ' . $e->getMessage());
            }
        }

        header("Location: /kelas/{$urlSlug}/pertemuan?open_minggu={$mingguKe}&sukses_konten=1&tipe=" . urlencode($tipe) . "&judul=" . urlencode($judul) . "&minggu={$mingguKe}#minggu-{$mingguKe}");
        exit;
    }

    // 2b. Edit Konten Pembelajaran Mingguan (Tab Pertemuan)
    if ($action === 'update_konten' && $tab === 'pertemuan') {
        $idKonten = trim((string)($_POST['id_konten'] ?? ''));
        $mingguKe = (int)($_POST['minggu_ke'] ?? 1);
        $tipe = $_POST['tipe'] ?? 'Materi';
        $judul = trim((string)($_POST['judul'] ?? ''));
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
        $urlEmbed = trim((string)($_POST['url_embed'] ?? ''));
        $durasi = !empty($_POST['durasi_menit']) ? (int)$_POST['durasi_menit'] : null;
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $hapusFile = !empty($_POST['hapus_file']);

        if ($urlEmbed !== '' && !preg_match('#^https?://#i', $urlEmbed)) {
            $urlEmbed = 'https://' . $urlEmbed;
        }

        if ($idKonten === '' || $judul === '' || !in_array($tipe, ['Materi', 'Tugas', 'Kuis', 'Ujian'], true)) {
            set_flash('error', 'ID, judul, dan tipe komponen wajib diisi dengan benar.');
        } else {
            try {
                // Ambil data konten lama
                $stmtCheck = $db->prepare("SELECT * FROM konten_mingguan WHERE id_konten = ? AND id_mk = ? LIMIT 1");
                $stmtCheck->execute([$idKonten, $idMK]);
                $oldKonten = $stmtCheck->fetch();

                if (!$oldKonten) {
                    set_flash('error', 'Komponen pembelajaran tidak ditemukan.');
                    header("Location: /kelas/{$urlSlug}/pertemuan");
                    exit;
                }

                $filePath = $oldKonten['file_path'];

                // Jika pengguna memilih hapus berkas lampiran lama
                if ($hapusFile && !empty($filePath)) {
                    $diskPath = get_storage_path($filePath);
                    if (file_exists($diskPath) && is_file($diskPath)) {
                        @unlink($diskPath);
                    }
                    $filePath = null;
                }

                // Jika ada berkas baru diunggah
                if (!empty($_FILES['file_lampiran']['name'])) {
                    $uploadRes = handle_material_upload($_FILES['file_lampiran'], $idMK, $mingguKe);
                    if ($uploadRes['success']) {
                        // Hapus berkas lama jika ada berkas pengganti
                        if (!empty($filePath)) {
                            $oldDiskPath = get_storage_path($filePath);
                            if (file_exists($oldDiskPath) && is_file($oldDiskPath)) {
                                @unlink($oldDiskPath);
                            }
                        }
                        $filePath = $uploadRes['path'];
                    } else {
                        set_flash('error', 'Gagal mengunggah berkas baru: ' . $uploadRes['error']);
                        header("Location: /kelas/{$urlSlug}/pertemuan?open_minggu={$mingguKe}#minggu-{$mingguKe}");
                        exit;
                    }
                }

                // Jika tipe diubah menjadi Kuis/Ujian yang tidak memakai file fisik
                if (in_array($tipe, ['Kuis', 'Ujian'], true) && $hapusFile) {
                    $filePath = null;
                }

                $stmtUpdate = $db->prepare("
                    UPDATE konten_mingguan 
                    SET minggu_ke = ?, tipe = ?, judul = ?, deskripsi = ?, file_path = ?, url_embed = ?, durasi_menit = ?, deadline = ?
                    WHERE id_konten = ? AND id_mk = ?
                ");
                $stmtUpdate->execute([$mingguKe, $tipe, $judul, $deskripsi, $filePath, $urlEmbed, $durasi, $deadline, $idKonten, $idMK]);

                log_activity('UPDATE_KONTEN', "Dosen '{$currentUser['nama']}' memperbarui {$tipe} '{$judul}' pada Minggu ke-{$mingguKe} di kelas '{$course['nama_mk']}'.");

                set_flash('success', "Komponen '{$tipe}: {$judul}' berhasil diperbarui.");
            } catch (Throwable $e) {
                set_flash('error', 'Gagal memperbarui konten: ' . $e->getMessage());
            }
        }

        header("Location: /kelas/{$urlSlug}/pertemuan?open_minggu={$mingguKe}#minggu-{$mingguKe}");
        exit;
    }

    // 3. Hapus Konten Mingguan
    if ($action === 'delete_konten') {
        $idKonten = trim((string)($_POST['id_konten'] ?? ''));
        if ($idKonten !== '') {
            try {
                $stmt = $db->prepare("DELETE FROM konten_mingguan WHERE id_konten = ? AND id_mk = ?");
                $stmt->execute([$idKonten, $idMK]);

                log_activity('HAPUS_KONTEN', "Dosen '{$currentUser['nama']}' menghapus konten pembelajaran [ID: {$idKonten}] dari kelas '{$course['nama_mk']}'.");

                set_flash('success', 'Konten mingguan berhasil dihapus.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menghapus konten: ' . $e->getMessage());
            }
        }
        header("Location: /kelas/{$urlSlug}/pertemuan");
        exit;
    }
}

// -------------------------------------------------------------
// QUERY DATA SESUAI TAB
// -------------------------------------------------------------
$kontenByMinggu = [];
if ($tab === 'pertemuan') {
    // Ambil seluruh konten mingguan untuk mata kuliah ini
    $stmtKonten = $db->prepare("
        SELECT k.*,
               (SELECT COUNT(*) FROM pengumpulan_tugas WHERE id_konten = k.id_konten) AS total_submit,
               (SELECT COUNT(*) FROM kuis_soal WHERE id_konten = k.id_konten) AS total_soal_kuis,
               (SELECT COUNT(*) FROM kuis_sesi WHERE id_konten = k.id_konten AND status IN ('selesai', 'waktu_habis')) AS total_submit_kuis
        FROM konten_mingguan k
        WHERE k.id_mk = ?
        ORDER BY k.minggu_ke ASC, k.created_at ASC
    ");
    $stmtKonten->execute([$idMK]);
    $allKonten = $stmtKonten->fetchAll();

    for ($m = 1; $m <= 16; $m++) {
        $kontenByMinggu[$m] = [];
    }
    foreach ($allKonten as $k) {
        $kontenByMinggu[(int)$k['minggu_ke']][] = $k;
    }

    // Jika Mahasiswa, ambil riwayat pengumpulan tugas & sesi kuis miliknya
    $userSubmissions = [];
    $userQuizSessions = [];
    if (!$isDosen) {
        $stmtSub = $db->prepare("SELECT * FROM pengumpulan_tugas WHERE id_user = ?");
        $stmtSub->execute([$currentUser['id_user']]);
        $rowsSub = $stmtSub->fetchAll();
        foreach ($rowsSub as $sub) {
            $userSubmissions[$sub['id_konten']] = $sub;
        }

        $stmtQS = $db->prepare("SELECT * FROM kuis_sesi WHERE id_user = ?");
        $stmtQS->execute([$currentUser['id_user']]);
        foreach ($stmtQS->fetchAll() as $qs) {
            $userQuizSessions[$qs['id_konten']] = $qs;
        }
    }
} elseif ($tab === 'peserta') {
    $sortParam = $_GET['sort_mahasiswa'] ?? 'angkatan_desc';
    $filterAngkatanParam = $_GET['filter_angkatan'] ?? 'all';
    $filterStatusParam = $_GET['filter_status'] ?? 'all';

    // Ambil daftar seluruh mahasiswa di politeknik
    $stmtAllMhs = $db->query("
        SELECT u.id_user, u.username AS nim, u.nama, u.angkatan
        FROM users u
        WHERE u.role = 'Mahasiswa'
        ORDER BY u.angkatan DESC, u.nama ASC
    ");
    $allStudents = $stmtAllMhs->fetchAll();

    // Ambil daftar id_user yang sudah terdaftar di kelas ini
    $stmtEnrolled = $db->prepare("SELECT id_user FROM enrollment WHERE id_mk = ?");
    $stmtEnrolled->execute([$idMK]);
    $enrolledUserIds = $stmtEnrolled->fetchAll(PDO::FETCH_COLUMN);

    // Ekstrak daftar angkatan unik terurut menurun
    $daftarAngkatan = array_values(array_unique(array_filter(array_map(function($st) {
        return !empty($st['angkatan']) ? (string)$st['angkatan'] : null;
    }, $allStudents))));
    rsort($daftarAngkatan);

    // Urutkan awal $allStudents di PHP sesuai parameter sortir
    usort($allStudents, function($a, $b) use ($sortParam, $enrolledUserIds) {
        $angA = (int)($a['angkatan'] ?? 0);
        $angB = (int)($b['angkatan'] ?? 0);
        $namaA = strtolower($a['nama'] ?? '');
        $namaB = strtolower($b['nama'] ?? '');
        $nimA = strtolower($a['nim'] ?? '');
        $nimB = strtolower($b['nim'] ?? '');
        $enrolledA = in_array($a['id_user'], $enrolledUserIds, true) ? 1 : 0;
        $enrolledB = in_array($b['id_user'], $enrolledUserIds, true) ? 1 : 0;

        return match ($sortParam) {
            'angkatan_asc' => ($angA !== $angB) ? ($angA <=> $angB) : strcmp($namaA, $namaB),
            'nama_asc'     => strcmp($namaA, $namaB),
            'nama_desc'    => strcmp($namaB, $namaA),
            'nim_asc'      => strcmp($nimA, $nimB),
            'status_enrolled' => ($enrolledA !== $enrolledB) ? ($enrolledB <=> $enrolledA) : (($angA !== $angB) ? ($angB <=> $angA) : strcmp($namaA, $namaB)),
            default        => ($angA !== $angB) ? ($angB <=> $angA) : strcmp($namaA, $namaB),
        };
    });
} elseif ($tab === 'rekap') {
    // Ambil seluruh tugas perkuliahan untuk kelas ini
    $stmtTasks = $db->prepare("
        SELECT id_konten, minggu_ke, tipe, judul, deadline
        FROM konten_mingguan
        WHERE id_mk = ? AND tipe = 'Tugas'
        ORDER BY minggu_ke ASC, created_at ASC
    ");
    $stmtTasks->execute([$idMK]);
    $rekapTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

    // Ambil seluruh mahasiswa terdaftar
    $stmtEnrolledMhs = $db->prepare("
        SELECT u.id_user, u.username AS nim, u.nama, u.angkatan
        FROM enrollment e
        JOIN users u ON e.id_user = u.id_user
        WHERE e.id_mk = ?
        ORDER BY u.username ASC
    ");
    $stmtEnrolledMhs->execute([$idMK]);
    $rekapStudents = $stmtEnrolledMhs->fetchAll(PDO::FETCH_ASSOC);

    // Ambil seluruh submission nilai tugas kelas ini
    $stmtSubmissions = $db->prepare("
        SELECT pt.id_user, pt.id_konten, pt.nilai, pt.waktu_submit, pt.file_name, pt.link_eksternal
        FROM pengumpulan_tugas pt
        JOIN konten_mingguan km ON pt.id_konten = km.id_konten
        WHERE km.id_mk = ? AND km.tipe = 'Tugas'
    ");
    $stmtSubmissions->execute([$idMK]);
    $allSubs = $stmtSubmissions->fetchAll(PDO::FETCH_ASSOC);

    // Petakan nilai per [id_user][id_konten]
    $rekapMatrix = [];
    foreach ($allSubs as $s) {
        $rekapMatrix[$s['id_user']][$s['id_konten']] = [
            'nilai'        => $s['nilai'],
            'waktu_submit' => $s['waktu_submit'],
            'file_name'    => $s['file_name'],
            'link'         => $s['link_eksternal'],
        ];
    }

    // EXPORT REKAP KE CSV / EXCEL
    if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel', 'tsv'], true)) {
        $selectedTaskId = trim((string)($_GET['task_id'] ?? ''));
        $taskMap = [];
        foreach ($rekapTasks as $t) {
            $taskMap[$t['id_konten']] = $t;
        }

        // Tentukan delimiter: Gunakan semicolon (;) untuk standar Indonesia/Excel atau comma (,) via query ?delim=comma
        $delimiter = (isset($_GET['delim']) && $_GET['delim'] === 'comma') ? ',' : ';';
        $decimalSep = ($delimiter === ';') ? ',' : '.';

        // 1. Ekspor Per Tugas Tertentu
        if ($selectedTaskId !== '' && isset($taskMap[$selectedTaskId])) {
            $currT = $taskMap[$selectedTaskId];
            $cleanTitle = sanitize_filename($currT['judul']);
            $filename = "Rekap_Nilai_{$course['kode_mk']}_M{$currT['minggu_ke']}_{$cleanTitle}_" . date('Ymd_His') . ".csv";

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM untuk Excel

            // Petunjuk pemisah bagi Excel otomatis
            fputcsv($out, ["sep={$delimiter}"], $delimiter, '"', "\\");

            fputcsv($out, ['LAPORAN REKAPITULASI NILAI TUGAS PERTEMUAN'], $delimiter, '"', "\\");
            fputcsv($out, ['Mata Kuliah', $course['nama_mk'] . ' (' . $course['kode_mk'] . ')'], $delimiter, '"', "\\");
            fputcsv($out, ['Semester / SKS', 'Semester ' . $course['semester'] . ' / ' . $course['sks'] . ' SKS'], $delimiter, '"', "\\");
            fputcsv($out, ['Alokasi Pertemuan', 'Minggu ke-' . $currT['minggu_ke']], $delimiter, '"', "\\");
            fputcsv($out, ['Judul Tugas', $currT['judul']], $delimiter, '"', "\\");
            fputcsv($out, ['Tenggat Waktu', $currT['deadline'] ? date('d/m/Y H:i', strtotime($currT['deadline'])) . ' WIT' : 'Tidak ada batas waktu'], $delimiter, '"', "\\");
            fputcsv($out, ['Total Peserta', count($rekapStudents)], $delimiter, '"', "\\");
            fputcsv($out, ['Waktu Ekspor', date('d/m/Y H:i:s') . ' WIT'], $delimiter, '"', "\\");
            fputcsv($out, [], $delimiter, '"', "\\"); // Baris kosong pembatas

            fputcsv($out, [
                'No',
                'NIM',
                'Nama Mahasiswa',
                'Angkatan',
                'Status Pengumpulan',
                'Nilai (0-100)',
                'Waktu Pengumpulan',
                'Nama Berkas Unggahan',
                'Tautan Eksternal (Google Drive / Cloud)'
            ], $delimiter, '"', "\\");

            $no = 1;
            foreach ($rekapStudents as $st) {
                $subData = $rekapMatrix[$st['id_user']][$selectedTaskId] ?? null;
                $statusKumpul = 'Belum Mengumpulkan';
                $scoreStr = '-';
                $waktuStr = '-';
                $fileStr = '-';
                $linkStr = '-';

                if ($subData) {
                    $statusKumpul = ($subData['nilai'] !== null) ? 'Sudah Dinilai' : 'Sudah Kumpul (Menunggu Penilaian)';
                    $scoreStr = ($subData['nilai'] !== null) ? number_format((float)$subData['nilai'], 1, $decimalSep, '') : '-';
                    $waktuStr = !empty($subData['waktu_submit']) ? date('d/m/Y H:i', strtotime($subData['waktu_submit'])) . ' WIT' : '-';
                    $fileStr = !empty($subData['file_name']) ? $subData['file_name'] : '-';
                    $linkStr = !empty($subData['link']) ? $subData['link'] : '-';
                }

                fputcsv($out, [
                    $no++,
                    (string)$st['nim'],
                    $st['nama'],
                    $st['angkatan'] ?? '-',
                    $statusKumpul,
                    $scoreStr,
                    $waktuStr,
                    $fileStr,
                    $linkStr
                ], $delimiter, '"', "\\");
            }

            fclose($out);
            exit;
        }

        // 2. Ekspor Seluruh Nilai Tugas (Semester Lengkap 16 Minggu)
        $filename = "Rekap_Nilai_Lengkap_{$course['kode_mk']}_" . date('Ymd_His') . ".csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM untuk Excel

        // Petunjuk pemisah bagi Excel otomatis
        fputcsv($out, ["sep={$delimiter}"], $delimiter, '"', "\\");

        fputcsv($out, ['LAPORAN REKAPITULASI NILAI TUGAS KELAS (LENGKAP 16 MINGGU)'], $delimiter, '"', "\\");
        fputcsv($out, ['Mata Kuliah', $course['nama_mk'] . ' (' . $course['kode_mk'] . ')'], $delimiter, '"', "\\");
        fputcsv($out, ['Tahun Akademik', $course['nama_ta'] ?? '-'], $delimiter, '"', "\\");
        fputcsv($out, ['Semester / SKS', 'Semester ' . $course['semester'] . ' / ' . $course['sks'] . ' SKS'], $delimiter, '"', "\\");
        fputcsv($out, ['Total Mahasiswa', count($rekapStudents)], $delimiter, '"', "\\");
        fputcsv($out, ['Total Tugas', count($rekapTasks)], $delimiter, '"', "\\");
        fputcsv($out, ['Waktu Ekspor', date('d/m/Y H:i:s') . ' WIT'], $delimiter, '"', "\\");
        fputcsv($out, [], $delimiter, '"', "\\"); // Baris kosong pembatas

        // Header Tabel
        $headerCols = ['No', 'NIM', 'Nama Mahasiswa', 'Angkatan'];
        foreach ($rekapTasks as $t) {
            $headerCols[] = "M{$t['minggu_ke']}: " . $t['judul'];
        }
        $headerCols[] = 'Tugas Selesai';
        $headerCols[] = 'Rata-rata Nilai';
        fputcsv($out, $headerCols, $delimiter, '"', "\\");

        $no = 1;
        foreach ($rekapStudents as $st) {
            $row = [
                $no++,
                (string)$st['nim'],
                $st['nama'],
                $st['angkatan'] ?? '-',
            ];

            $scores = [];
            $completedCount = 0;
            foreach ($rekapTasks as $t) {
                $subData = $rekapMatrix[$st['id_user']][$t['id_konten']] ?? null;
                if ($subData && $subData['nilai'] !== null) {
                    $row[] = number_format((float)$subData['nilai'], 1, $decimalSep, '');
                    $scores[] = (float)$subData['nilai'];
                    $completedCount++;
                } elseif ($subData) {
                    $row[] = 'Kumpul (Belum Dinilai)';
                    $completedCount++;
                } else {
                    $row[] = '-';
                }
            }

            $avgScore = !empty($scores) ? number_format(array_sum($scores) / count($scores), 2, $decimalSep, '') : '-';
            $row[] = $completedCount . ' / ' . count($rekapTasks);
            $row[] = $avgScore;

            fputcsv($out, $row, $delimiter, '"', "\\");
        }

        fclose($out);
        exit;
    }
}

$isSuksesKonten = isset($_GET['sukses_konten']) && $_GET['sukses_konten'] === '1';
$suksesTipe = trim((string)($_GET['tipe'] ?? 'Materi'));
$suksesJudul = trim((string)($_GET['judul'] ?? 'Komponen Pembelajaran'));
$suksesMinggu = (int)($_GET['minggu'] ?? ($openMinggu ?? 1));

$pageTitle = $course['nama_mk'] . ' (' . $course['kode_mk'] . ')';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Course Header Banner -->
    <div class="relative overflow-hidden rounded-3xl bg-maritime-header text-white p-6 sm:p-8 shadow-xl border border-slate-700">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-3 py-1 rounded-lg text-xs font-mono font-bold bg-white/10 text-cyan-300 border border-white/20">
                        <?= htmlspecialchars($course['kode_mk']) ?>
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-medium bg-cyan-950/80 text-cyan-300 border border-cyan-500/30">
                        <?= htmlspecialchars($course['nama_ta'] ?? 'TA -') ?>
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-950/80 text-emerald-300 border border-emerald-500/30">
                        <?= (int)$course['sks'] ?> SKS (Semester <?= (int)$course['semester'] ?>)
                    </span>
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight font-heading">
                    <?= htmlspecialchars($course['nama_mk']) ?>
                </h1>

                <div class="pt-2 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 text-xs text-slate-300">
                    <div class="inline-flex items-center space-x-1.5">
                        <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-cyan-400/20 text-cyan-300 border border-cyan-400/30">
                            <i data-lucide="award" class="w-3.5 h-3.5 text-cyan-400"></i>
                            <span>Dosen Koordinator:</span>
                        </span>
                        <strong class="text-white font-semibold"><?= htmlspecialchars($dosenKoordinator) ?></strong>
                    </div>

                    <?php if (!empty($dosenAnggota)): ?>
                        <div class="inline-flex items-center space-x-1.5 text-slate-300">
                            <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-white/10 text-slate-200 border border-white/20">
                                <i data-lucide="users" class="w-3.5 h-3.5 text-cyan-300"></i>
                                <span>Dosen Pengampu:</span>
                            </span>
                            <span class="text-slate-100 font-medium"><?= htmlspecialchars(implode(', ', $dosenAnggota)) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Action Links -->
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <?php if (!empty($course['rps_file_path'])): ?>
                    <button type="button" 
                            onclick="openPdfModal('/unduh.php?jenis=rps&id_mk=<?= urlencode($idMK) ?>&mode=inline', 'RPS - <?= htmlspecialchars(addslashes($course['nama_mk'])) ?>')"
                            class="inline-flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-cyan-300 bg-cyan-950 hover:bg-cyan-900 border border-cyan-800 transition">
                        <i data-lucide="file-text" class="w-4 h-4"></i>
                        <span>Lihat RPS</span>
                    </button>
                <?php endif; ?>

                <?php if ($isDosen): ?>
                    <a href="/mata-kuliah?action=edit&id=<?= urlencode($idMK) ?>" 
                       class="inline-flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-300 bg-slate-800 hover:bg-slate-700 border border-slate-600 transition">
                        <i data-lucide="settings" class="w-4 h-4"></i>
                        <span>Pengaturan MK</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 4-Tab Navigation Bar -->
    <div class="border-b border-slate-200">
        <nav class="flex space-x-6 sm:space-x-8 overflow-x-auto" aria-label="Tabs">
            <a href="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" 
               class="py-4 px-1 inline-flex items-center space-x-2 border-b-2 text-sm font-semibold transition shrink-0 <?= $tab === 'pertemuan' ? 'border-maritime-700 text-maritime-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                <i data-lucide="calendar-days" class="w-4 h-4"></i>
                <span>16 Minggu Perkuliahan</span>
            </a>

            <a href="/kelas/<?= urlencode($urlSlug) ?>/rps" 
               class="py-4 px-1 inline-flex items-center space-x-2 border-b-2 text-sm font-semibold transition shrink-0 <?= $tab === 'rps' ? 'border-maritime-700 text-maritime-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                <span>Deskripsi & RPS</span>
            </a>

            <a href="/kelas/<?= urlencode($urlSlug) ?>/peserta" 
               class="py-4 px-1 inline-flex items-center space-x-2 border-b-2 text-sm font-semibold transition shrink-0 <?= $tab === 'peserta' ? 'border-maritime-700 text-maritime-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                <i data-lucide="users" class="w-4 h-4"></i>
                <span>Peserta Kelas (<?= (int)$course['total_peserta'] ?>)</span>
            </a>

            <?php if ($isDosen): ?>
                <a href="/kelas/<?= urlencode($urlSlug) ?>/rekap" 
                   class="py-4 px-1 inline-flex items-center space-x-2 border-b-2 text-sm font-semibold transition shrink-0 <?= $tab === 'rekap' ? 'border-maritime-700 text-maritime-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                    <i data-lucide="award" class="w-4 h-4"></i>
                    <span>Rekap Nilai Tugas</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- TAB 1: 16 MINGGU PERTEMUAN -->
    <?php if ($tab === 'pertemuan'): ?>
        <div class="space-y-6">
            
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-abyssal-900 font-heading">
                        Alur 16 Minggu Perkuliahan
                    </h2>
                    <p class="text-xs text-slate-500">
                        Akses modul materi, tautan referensi kelautan.id, kuis daring, dan pengumpulan tugas perkuliahan.
                    </p>
                </div>

                <?php if ($isDosen): ?>
                    <!-- Tombol Buka Modal Tambah Konten -->
                    <button type="button" onclick="openAddContentModal(1)"
                            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Tambah Konten Pembelajaran</span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Akordeon 16 Minggu Perkuliahan -->
            <?php $openMinggu = isset($_GET['open_minggu']) ? (int)$_GET['open_minggu'] : 1; ?>
            <div class="space-y-3">
                <?php for ($m = 1; $m <= 16; $m++): ?>
                    <?php 
                    $items = $kontenByMinggu[$m] ?? []; 
                    $isExamWeek = in_array($m, [8, 16], true);
                    $weekTitle = match($m) {
                        8  => "Minggu ke-8: Ujian Tengah Semester (UTS)",
                        16 => "Minggu ke-16: Ujian Akhir Semester (UAS)",
                        default => "Minggu ke-{$m}"
                    };
                    $hasContent = !empty($items);
                    $isOpen = ($m === $openMinggu);
                    ?>
                    <div id="minggu-<?= $m ?>" class="bg-white rounded-2xl border <?= $isExamWeek ? 'border-amber-200 bg-amber-50/20' : 'border-slate-200' ?> shadow-sm overflow-hidden transition">
                        
                        <!-- Accordion Header Button -->
                        <button type="button" 
                                data-target="minggu-body-<?= $m ?>"
                                class="accordion-toggle w-full px-5 py-4 flex items-center justify-between text-left hover:bg-slate-50/80 transition focus:outline-none">
                            <div class="flex items-center space-x-3.5">
                                <div class="w-8 h-8 rounded-xl <?= $isExamWeek ? 'bg-amber-100 text-amber-800 font-bold' : ($hasContent ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-100 text-slate-400') ?> flex items-center justify-center text-xs font-semibold shrink-0">
                                    M<?= $m ?>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 font-heading">
                                        <?= htmlspecialchars($weekTitle) ?>
                                    </h3>
                                    <div class="text-[11px] text-slate-500">
                                        <?= count($items) ?> komponen pembelajaran aktif
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <?php if ($isExamWeek): ?>
                                    <span class="hidden sm:inline-block px-2.5 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800">
                                        Evaluasi Semester
                                    </span>
                                <?php endif; ?>
                                <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-slate-400 transition-transform duration-200 <?= $isOpen ? 'rotate-180' : '' ?>"></i>
                            </div>
                        </button>

                        <!-- Accordion Body -->
                        <div id="minggu-body-<?= $m ?>" class="<?= $isOpen ? '' : 'hidden' ?> px-5 pb-5 pt-1 border-t border-slate-100 space-y-4">
                            <?php if (empty($items)): ?>
                                <div class="py-4 text-center">
                                    <p class="text-xs text-slate-400 italic mb-2">
                                        Belum ada materi atau tugas yang diunggah untuk minggu ini.
                                    </p>
                                    <?php if ($isDosen): ?>
                                        <button type="button" onclick="openAddContentModal(<?= $m ?>)"
                                                class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-cyan-800 bg-cyan-50 hover:bg-cyan-100 transition">
                                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                            <span>Tambah di Minggu Ini</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3 pt-2">
                                    <?php foreach ($items as $item): ?>
                                        <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/60 hover:bg-white transition space-y-3">
                                            
                                            <!-- Top Line: Type Badge, Title, & Actions -->
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="space-y-1">
                                                    <div class="flex items-center space-x-2">
                                                        <?php
                                                        $badgeColor = match ($item['tipe']) {
                                                            'Materi' => 'bg-blue-100 text-blue-800 border-blue-200',
                                                            'Tugas'  => 'bg-amber-100 text-amber-800 border-amber-200',
                                                            'Kuis'   => 'bg-purple-100 text-purple-800 border-purple-200',
                                                            'Ujian'  => 'bg-rose-100 text-rose-800 border-rose-200',
                                                        };
                                                        ?>
                                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border <?= $badgeColor ?>">
                                                            <?= htmlspecialchars($item['tipe']) ?>
                                                        </span>
                                                        <h4 class="text-sm font-bold text-slate-900 font-heading">
                                                            <?= htmlspecialchars($item['judul']) ?>
                                                        </h4>
                                                    </div>

                                                    <?php if (!empty($item['deskripsi'])): ?>
                                                        <p class="text-xs text-slate-600 leading-relaxed max-w-3xl">
                                                            <?= nl2br(htmlspecialchars($item['deskripsi'])) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Dosen Edit & Delete Action -->
                                                <?php if ($isDosen): ?>
                                                    <div class="flex items-center space-x-1 shrink-0">
                                                        <?php if ($item['tipe'] === 'Tugas'): ?>
                                                            <a href="/nilai/<?= urlencode($item['id_konten']) ?>" 
                                                               class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                                                                <i data-lucide="check-square" class="w-3.5 h-3.5"></i>
                                                                <span>Periksa & Nilai (<?= (int)$item['total_submit'] ?>)</span>
                                                            </a>
                                                        <?php elseif (in_array($item['tipe'], ['Kuis', 'Ujian'])): ?>
                                                            <a href="/kuis/kelola/<?= urlencode($item['id_konten']) ?>" 
                                                               class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-cyan-900 bg-cyan-100 hover:bg-cyan-200 border border-cyan-300 transition"
                                                               title="Kelola butir soal kuis dari Bank Soal">
                                                                <i data-lucide="help-circle" class="w-3.5 h-3.5"></i>
                                                                <span>Kelola Soal (<?= (int)($item['total_soal_kuis'] ?? 0) ?>)</span>
                                                            </a>
                                                            <a href="/kuis/nilai/<?= urlencode($item['id_konten']) ?>" 
                                                               class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition"
                                                               title="Lihat hasil & koreksi nilai kuis mahasiswa">
                                                                <i data-lucide="award" class="w-3.5 h-3.5"></i>
                                                                <span>Nilai (<?= (int)($item['total_submit_kuis'] ?? 0) ?>)</span>
                                                            </a>
                                                        <?php endif; ?>

                                                        <!-- Tombol Edit Konten -->
                                                        <button type="button" 
                                                                onclick='openEditContentModal(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                                class="p-1.5 text-slate-400 hover:text-cyan-600 rounded-lg hover:bg-cyan-50 transition" 
                                                                title="Edit Komponen Pembelajaran">
                                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                                        </button>

                                                        <form action="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" method="POST" class="inline" onsubmit="return confirm('Hapus komponen pembelajaran ini?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <input type="hidden" name="action" value="delete_konten">
                                                            <input type="hidden" name="id_konten" value="<?= htmlspecialchars($item['id_konten']) ?>">
                                                            <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition" title="Hapus Konten">
                                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                             <!-- Meta Bar & Links (File / Quiz / Embed / Deadline) -->
                                            <div class="flex flex-wrap items-center gap-2 pt-2.5 border-t border-slate-200/80 text-xs">
                                                
                                                <!-- Lampiran File Materi / Berkas Soal -->
                                                <?php if (!empty($item['file_path'])): ?>
                                                    <?php 
                                                    $fileExt = strtolower(pathinfo($item['file_path'], PATHINFO_EXTENSION));
                                                    $isPdf = ($fileExt === 'pdf');
                                                    $fileBadge = match($fileExt) {
                                                        'pdf' => ['color' => 'bg-rose-100 text-rose-800 border-rose-200', 'icon' => 'file-text', 'label' => 'PDF'],
                                                        'ppt', 'pptx' => ['color' => 'bg-amber-100 text-amber-800 border-amber-200', 'icon' => 'presentation', 'label' => 'PPT'],
                                                        'doc', 'docx' => ['color' => 'bg-blue-100 text-blue-800 border-blue-200', 'icon' => 'file-text', 'label' => 'WORD'],
                                                        'xls', 'xlsx', 'csv' => ['color' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'icon' => 'file-spreadsheet', 'label' => 'EXCEL'],
                                                        default => ['color' => 'bg-slate-100 text-slate-800 border-slate-200', 'icon' => 'file', 'label' => strtoupper($fileExt)]
                                                    };
                                                    ?>
                                                    <!-- Badge Format File -->
                                                    <span class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-[11px] font-bold border <?= $fileBadge['color'] ?>">
                                                        <i data-lucide="<?= $fileBadge['icon'] ?>" class="w-3.5 h-3.5"></i>
                                                        <span><?= $fileBadge['label'] ?></span>
                                                    </span>

                                                    <!-- Tombol Preview Berkas -->
                                                    <button type="button" 
                                                            onclick="openPdfModal('/unduh.php?jenis=materi&id_konten=<?= urlencode($item['id_konten']) ?>&mode=inline', '<?= htmlspecialchars(addslashes($item['judul'])) ?>')"
                                                            class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl font-semibold text-xs text-cyan-800 bg-cyan-100 hover:bg-cyan-200 transition shadow-sm">
                                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                        <span>Preview <?= $item['tipe'] === 'Tugas' ? 'Soal' : 'Dokumen' ?></span>
                                                    </button>

                                                    <!-- Tombol Unduh Berkas -->
                                                    <a href="/unduh.php?jenis=materi&id_konten=<?= urlencode($item['id_konten']) ?>&mode=download" 
                                                       class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl font-semibold text-xs text-slate-700 bg-slate-200 hover:bg-slate-300 transition shadow-sm">
                                                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                                        <span>Unduh Berkas</span>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Link Eksternal / URL Ujian / Portal Kelautan.ID -->
                                                <?php if (!empty($item['url_embed'])): ?>
                                                    <!-- Tombol Preview Modal Link Eksternal -->
                                                    <button type="button" 
                                                            onclick="openPdfModal('<?= htmlspecialchars(addslashes($item['url_embed'])) ?>', 'Pratinjau Tautan: <?= htmlspecialchars(addslashes($item['judul'])) ?>')"
                                                            class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl font-semibold text-xs text-teal-800 bg-teal-100 hover:bg-teal-200 transition shadow-sm">
                                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                        <span>Preview Link</span>
                                                    </button>

                                                    <!-- Tombol Buka di Tab Baru -->
                                                    <a href="<?= htmlspecialchars($item['url_embed']) ?>" target="_blank" rel="noopener noreferrer"
                                                       class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl font-semibold text-xs text-emerald-800 bg-emerald-100 hover:bg-emerald-200 border border-emerald-300/80 transition shadow-sm">
                                                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                                                        <span><?= in_array($item['tipe'], ['Kuis', 'Ujian']) ? 'Buka ' . $item['tipe'] . ' Daring' : 'Buka Link Eksternal' ?></span>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Durasi Kuis / Ujian jika ada -->
                                                <?php if (!empty($item['durasi_menit'])): ?>
                                                    <span class="flex items-center space-x-1 text-slate-500">
                                                        <i data-lucide="clock" class="w-3.5 h-3.5 text-slate-400"></i>
                                                        <span>Durasi: <?= (int)$item['durasi_menit'] ?> Menit</span>
                                                    </span>
                                                <?php endif; ?>

                                                <!-- Batas Waktu jika ada -->
                                                <?php if (!empty($item['deadline'])): ?>
                                                    <?php $isDeadlinePassed = strtotime($item['deadline']) < time(); ?>
                                                    <span class="flex items-center space-x-1 text-xs <?= $isDeadlinePassed ? 'text-rose-600 font-medium' : 'text-slate-500' ?>">
                                                        <i data-lucide="<?= $isDeadlinePassed ? 'clock-alert' : 'calendar' ?>" class="w-3.5 h-3.5 <?= $isDeadlinePassed ? 'text-rose-500' : 'text-slate-400' ?>"></i>
                                                        <span>Batas: <?= date('d M Y, H:i', strtotime($item['deadline'])) ?> WIT<?= $isDeadlinePassed ? ' <span class="text-[10px] uppercase font-bold text-rose-500 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">Selesai</span>' : '' ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- AREA PENGUMPULAN TUGAS KHUSUS MAHASISWA -->
                                            <?php if (!$isDosen && $item['tipe'] === 'Tugas'): ?>
                                                <?php $mySubmit = $userSubmissions[$item['id_konten']] ?? null; ?>
                                                <div class="mt-3 p-3.5 rounded-xl bg-white border border-slate-200 space-y-3">
                                                    
                                                    <!-- Status Badge Pengerjaan Mahasiswa -->
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-semibold text-slate-700">Status Pengumpulan Tugas Anda:</span>
                                                        <?php if (!$mySubmit): ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
                                                                Belum Mengumpulkan
                                                            </span>
                                                        <?php elseif ($mySubmit['nilai'] !== null): ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center space-x-1">
                                                                <i data-lucide="award" class="w-3.5 h-3.5 text-emerald-600"></i>
                                                                <span>Sudah Dinilai: Skor <?= htmlspecialchars($mySubmit['nilai']) ?> / 100</span>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-cyan-100 text-cyan-800 border border-cyan-300">
                                                                Sudah Mengumpulkan (<?= date('d M Y H:i', strtotime($mySubmit['waktu_submit'])) ?>)
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Jika Sudah Dinilai, tampilkan feedback kualitatif -->
                                                    <?php if ($mySubmit && !empty($mySubmit['feedback'])): ?>
                                                        <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs text-emerald-900">
                                                            <p class="font-bold mb-0.5">Catatan & Feedback Dosen Pengampu:</p>
                                                            <p><?= nl2br(htmlspecialchars($mySubmit['feedback'])) ?></p>
                                                        </div>
                                                    <?php endif; ?>

                                                    <!-- Detail Berkas & Tautan Mahasiswa jika sudah pernah mengumpulkan -->
                                                    <?php if ($mySubmit): ?>
                                                        <div class="space-y-2">
                                                            <!-- Berkas fisik jika ada -->
                                                            <?php if (!empty($mySubmit['file_name'])): ?>
                                                                <?php 
                                                                    $extSub = strtolower(pathinfo($mySubmit['file_name'], PATHINFO_EXTENSION));
                                                                    $isPdfSub = ($extSub === 'pdf');
                                                                ?>
                                                                <div class="flex items-center justify-between text-xs p-2.5 rounded-xl bg-slate-50 border border-slate-200">
                                                                    <div class="flex items-center space-x-2 truncate">
                                                                        <i data-lucide="file-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                                                                        <span class="font-medium text-slate-800 truncate"><?= htmlspecialchars($mySubmit['file_name']) ?></span>
                                                                    </div>
                                                                    <div class="flex items-center space-x-2 shrink-0">
                                                                        <?php if ($isPdfSub): ?>
                                                                            <button type="button" 
                                                                                    onclick="openPdfModal('/unduh.php?jenis=tugas_mhs&id_submit=<?= urlencode($mySubmit['id_submit']) ?>&mode=inline', 'Berkas Jawaban Anda')"
                                                                                    class="text-cyan-700 hover:underline font-semibold flex items-center space-x-1">
                                                                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                                                <span>Lihat PDF</span>
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <a href="/unduh.php?jenis=tugas_mhs&id_submit=<?= urlencode($mySubmit['id_submit']) ?>&mode=download" 
                                                                           class="text-maritime-700 hover:underline font-semibold flex items-center space-x-1">
                                                                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                                                            <span>Unduh Berkas</span>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <!-- Tautan eksternal jika ada -->
                                                            <?php if (!empty($mySubmit['link_eksternal'])): ?>
                                                                <div class="flex items-center justify-between text-xs p-2.5 rounded-xl bg-blue-50/80 border border-blue-200">
                                                                    <div class="flex items-center space-x-2 truncate">
                                                                        <i data-lucide="external-link" class="w-4 h-4 text-blue-600 shrink-0"></i>
                                                                        <div class="truncate">
                                                                            <span class="text-[10px] uppercase font-bold text-blue-700 block">Tautan Eksternal Tugas:</span>
                                                                            <a href="<?= htmlspecialchars($mySubmit['link_eksternal']) ?>" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-900 hover:underline truncate block">
                                                                                <?= htmlspecialchars($mySubmit['link_eksternal']) ?>
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                    <a href="<?= htmlspecialchars($mySubmit['link_eksternal']) ?>" target="_blank" rel="noopener noreferrer"
                                                                       class="inline-flex items-center space-x-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 transition shrink-0 shadow-sm">
                                                                        <span>Buka Link</span>
                                                                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <!-- Form Pengumpulan Jawaban -->
                                                    <div class="pt-3 border-t border-slate-100 space-y-3">
                                                        <div class="flex items-center justify-between">
                                                            <p class="text-xs font-bold text-slate-800 font-heading">
                                                                <?= $mySubmit ? 'Perbarui Berkas / Tautan Jawaban Tugas:' : 'Unggah Berkas & Tautan Jawaban Tugas:' ?>
                                                            </p>
                                                            <span class="text-[11px] text-slate-500 font-medium">
                                                                Batas Berkas: <?= get_max_upload_tugas_mb() ?> MB
                                                            </span>
                                                        </div>

                                                        <!-- Peringatan Akses Link Eksternal (Google Drive dsb) -->
                                                        <div class="p-3 rounded-xl bg-amber-50/90 border border-amber-200 text-amber-900 text-xs space-y-1">
                                                            <div class="flex items-center space-x-1.5 font-bold text-amber-950">
                                                                <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-600 shrink-0"></i>
                                                                <span>Peringatan Akses Tautan Eksternal (Google Drive / Cloud):</span>
                                                            </div>
                                                            <p class="text-[11px] leading-relaxed text-amber-800">
                                                                Jika menyertakan tautan Google Drive / OneDrive, pastikan pengaturan berbagi (sharing) diset ke: 
                                                                <strong class="text-amber-950 underline decoration-amber-500">"Siapa saja yang memiliki link dapat melihat (Viewer)"</strong> agar dosen pengampu dapat membuka dan menilai tugas Anda tanpa kendala izin akses.
                                                            </p>
                                                        </div>

                                                        <form action="/tugas-kumpul" method="POST" enctype="multipart/form-data" class="form-tugas-kumpul space-y-2.5">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <input type="hidden" name="id_mk" value="<?= htmlspecialchars($idMK) ?>">
                                                            <input type="hidden" name="kode_mk" value="<?= htmlspecialchars($urlSlug) ?>">
                                                            <input type="hidden" name="id_konten" value="<?= htmlspecialchars($item['id_konten']) ?>">
                                                            <input type="hidden" name="task_title" value="<?= htmlspecialchars($item['judul']) ?>">

                                                            <!-- Input File Jawaban -->
                                                            <div class="space-y-1">
                                                                <label class="block text-[11px] font-semibold text-slate-700">
                                                                    Unggah Berkas Tugas (Format: PDF, PPT, Word, Excel - Maks. <?= get_max_upload_tugas_mb() ?> MB):
                                                                </label>
                                                                <input type="file" name="jawaban_file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx"
                                                                       class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-800 hover:file:bg-cyan-100 cursor-pointer bg-slate-50 border border-slate-200 rounded-xl p-1">
                                                                <p class="text-[10px] text-slate-500">
                                                                    Format diizinkan: <strong>.pdf, .ppt, .pptx, .doc, .docx, .xls, .xlsx</strong>. Format lain tidak diizinkan.
                                                                </p>
                                                            </div>

                                                            <!-- Input Link Eksternal -->
                                                            <div class="space-y-1">
                                                                <label class="block text-[11px] font-semibold text-slate-700">
                                                                    Tautan / Link Eksternal (Opsional jika mengunggah berkas, atau sebaliknya):
                                                                </label>
                                                                <div class="relative">
                                                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                                                        <i data-lucide="link-2" class="w-3.5 h-3.5"></i>
                                                                    </div>
                                                                    <input type="url" name="link_eksternal" 
                                                                           value="<?= htmlspecialchars($mySubmit['link_eksternal'] ?? '') ?>"
                                                                           placeholder="https://drive.google.com/... (Google Drive / OneDrive / Dropbox)"
                                                                           class="w-full pl-9 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                                                </div>
                                                            </div>

                                                            <!-- Catatan Tambahan & Tombol Kirim -->
                                                            <div class="flex flex-col sm:flex-row gap-2 pt-1">
                                                                <input type="text" name="catatan_mhs" 
                                                                       value="<?= htmlspecialchars($mySubmit['catatan_mhs'] ?? '') ?>"
                                                                       placeholder="Catatan tambahan untuk dosen (opsional)..."
                                                                       class="px-3 py-1.5 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-800 flex-1 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white">
                                                                <button type="submit" 
                                                                        class="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition shrink-0 flex items-center justify-center space-x-1.5">
                                                                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                                                                    <span><?= $mySubmit ? 'Perbarui Pengumpulan' : 'Kirim Jawaban Tugas' ?></span>
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <!-- AREA PENGERJAAN KUIS INTERAKTIF KHUSUS MAHASISWA -->
                                            <?php if (!$isDosen && in_array($item['tipe'], ['Kuis', 'Ujian'])): ?>
                                                <?php 
                                                    $mySesi = $userQuizSessions[$item['id_konten']] ?? null; 
                                                    $isKuisSubmitted = $mySesi && in_array($mySesi['status'], ['selesai', 'waktu_habis']);
                                                ?>
                                                <div class="mt-3 p-4 rounded-xl bg-white border border-slate-200 space-y-3">
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-semibold text-slate-700">Status Pengerjaan Kuis Daring:</span>
                                                        <?php if (!$mySesi): ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
                                                                Belum Mengerjakan
                                                            </span>
                                                        <?php elseif ($isKuisSubmitted): ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center space-x-1">
                                                                <i data-lucide="award" class="w-3.5 h-3.5 text-emerald-600"></i>
                                                                <span>Sudah Selesai: Skor <?= htmlspecialchars((string)($mySesi['total_nilai'] ?? 0)) ?> / 100</span>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-300 animate-pulse">
                                                                Sedang Dikerjakan
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2 border-t border-slate-100">
                                                        <div class="flex items-center space-x-3 text-xs text-slate-500">
                                                            <span class="flex items-center space-x-1">
                                                                <i data-lucide="help-circle" class="w-3.5 h-3.5 text-slate-400"></i>
                                                                <span><?= (int)($item['total_soal_kuis'] ?? 0) ?> Butir Soal</span>
                                                            </span>
                                                            <span>•</span>
                                                            <span class="flex items-center space-x-1">
                                                                <i data-lucide="clock" class="w-3.5 h-3.5 text-slate-400"></i>
                                                                <span>Durasi: <?= !empty($item['durasi_menit']) ? (int)$item['durasi_menit'] . ' Menit' : 'Bebas' ?></span>
                                                            </span>
                                                        </div>

                                                        <div>
                                                            <?php if (!$isKuisSubmitted): ?>
                                                                <a href="/kuis/kerjakan/<?= urlencode($item['id_konten']) ?>" 
                                                                   class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-maritime-700 hover:bg-maritime-800 shadow-sm transition">
                                                                    <i data-lucide="play" class="w-3.5 h-3.5 text-cyan-300"></i>
                                                                    <span><?= $mySesi ? 'Lanjutkan Kuis' : 'Mulai Kerjakan Kuis' ?> &rarr;</span>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="/kuis/kerjakan/<?= urlencode($item['id_konten']) ?>" 
                                                                   class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition">
                                                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                                    <span>Lihat Hasil Nilai</span>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 2: DESKRIPSI & RPS -->
    <?php if ($tab === 'rps'): ?>
        <div class="space-y-6">
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                <div>
                    <h2 class="text-xl font-bold text-abyssal-900 font-heading">
                        Deskripsi & Capaian Pembelajaran (CPMK)
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Pedoman kurikulum dan capaian pembelajaran mata kuliah <?= htmlspecialchars($branding['study_program']) ?> <?= htmlspecialchars($branding['institution_name']) ?>.
                    </p>
                </div>

                <div class="prose max-w-none text-sm text-slate-700 leading-relaxed space-y-3">
                    <p><?= nl2br(htmlspecialchars($course['deskripsi_mk'] ?: 'Belum ada uraian deskripsi kurikulum yang ditambahkan untuk mata kuliah ini.')) ?></p>
                </div>

                <!-- Dokumen Kurikulum Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                    
                    <!-- Kartu Berkas RPS -->
                    <div class="p-5 rounded-2xl bg-cyan-50/50 border border-cyan-200/80 flex items-start justify-between">
                        <div class="space-y-1">
                            <div class="flex items-center space-x-2 text-cyan-800 font-bold text-sm font-heading">
                                <i data-lucide="file-text" class="w-5 h-5 text-cyan-600"></i>
                                <span>Rencana Pembelajaran Semester (RPS)</span>
                            </div>
                            <p class="text-xs text-slate-600">
                                <?= !empty($course['rps_file_path']) ? basename($course['rps_file_path']) : 'Berkas RPS PDF belum diunggah.' ?>
                            </p>
                            <?php if (!empty($course['rps_file_path'])): ?>
                                <div class="pt-2 flex items-center space-x-2">
                                    <button type="button" 
                                            onclick="openPdfModal('/unduh.php?jenis=rps&id_mk=<?= urlencode($idMK) ?>&mode=inline', 'Pratinjau RPS - <?= htmlspecialchars(addslashes($course['nama_mk'])) ?>')"
                                            class="inline-flex items-center space-x-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                        <span>Preview Modal</span>
                                    </button>
                                    <a href="/unduh.php?jenis=rps&id_mk=<?= urlencode($idMK) ?>&mode=download" 
                                       class="inline-flex items-center space-x-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition">
                                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                        <span>Unduh</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Kartu Berkas Silabus -->
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 flex items-start justify-between">
                        <div class="space-y-1">
                            <div class="flex items-center space-x-2 text-slate-800 font-bold text-sm font-heading">
                                <i data-lucide="file-spreadsheet" class="w-5 h-5 text-slate-600"></i>
                                <span>Silabus & Bahan Kuliah</span>
                            </div>
                            <p class="text-xs text-slate-600">
                                <?= !empty($course['silabus_file_path']) ? basename($course['silabus_file_path']) : 'Berkas Silabus belum diunggah.' ?>
                            </p>
                            <?php if (!empty($course['silabus_file_path'])): ?>
                                <div class="pt-2">
                                    <a href="/unduh.php?jenis=silabus&id_mk=<?= urlencode($idMK) ?>&mode=download" 
                                       class="inline-flex items-center space-x-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition">
                                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                        <span>Unduh Silabus</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- Link Literatur Ekosistem Kelautan.ID -->
                <?php if (!empty($course['dokumen_lain_url'])): ?>
                    <div class="p-4 rounded-xl bg-blue-50/60 border border-blue-200 text-xs flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <i data-lucide="globe" class="w-4 h-4 text-maritime-700"></i>
                            <span class="text-slate-700">Rujukan Literatur & Artikel Ilmiah: <strong><?= htmlspecialchars($course['dokumen_lain_url']) ?></strong></span>
                        </div>
                        <a href="<?= htmlspecialchars($course['dokumen_lain_url']) ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center space-x-1 px-3 py-1 rounded-lg font-semibold text-white bg-maritime-700 hover:bg-maritime-800 transition">
                            <span>Kunjungi</span>
                            <i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: PESERTA KELAS (ENROLLMENT) -->
    <?php if ($tab === 'peserta'): ?>
        <div class="space-y-6">
            
            <?php if ($isDosen): ?>
                <!-- TAMPILAN DOSEN: KELOLA PESERTA LENGKAP (FORM + CHECKBOX) -->
                <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <h2 class="text-xl font-bold text-abyssal-900 font-heading">
                                Kelola Peserta Kelas (Enrollment)
                            </h2>
                            <p class="text-xs text-slate-500">
                                Centang mahasiswa yang terdaftar di kelas <?= htmlspecialchars($course['nama_mk']) ?>.
                            </p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <span id="selected-counter" class="px-3.5 py-1.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800 border border-cyan-200">
                                <?= count($enrolledUserIds) ?> Terdaftar dari <?= count($allStudents) ?> Mahasiswa
                            </span>
                        </div>
                    </div>

                    <!-- Toolbar Filter, Sortir & Pencarian Peserta Kelas -->
                    <div class="p-4 sm:p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <!-- Sortir Mahasiswa -->
                            <div>
                                <label for="sort-mahasiswa" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    <i data-lucide="arrow-up-down" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i>Sortir Peserta
                                </label>
                                <select id="sort-mahasiswa" class="w-full px-3 py-2 text-xs bg-white border border-slate-300 rounded-xl text-slate-900 font-medium focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                                    <option value="angkatan_desc" selected>Angkatan: Terbaru ke Terlama</option>
                                    <option value="angkatan_asc">Angkatan: Terlama ke Terbaru</option>
                                    <option value="nama_asc">Nama Mahasiswa: A ke Z</option>
                                    <option value="nama_desc">Nama Mahasiswa: Z ke A</option>
                                    <option value="nim_asc">NIM: Terkecil ke Terbesar</option>
                                    <option value="status_enrolled">Status: Terdaftar di Atas</option>
                                </select>
                            </div>

                            <!-- Filter Angkatan -->
                            <div>
                                <label for="filter-angkatan" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    <i data-lucide="calendar" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i>Filter Angkatan
                                </label>
                                <select id="filter-angkatan" class="w-full px-3 py-2 text-xs bg-white border border-slate-300 rounded-xl text-slate-900 font-medium focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                                    <option value="all">Semua Angkatan (<?= count($allStudents) ?>)</option>
                                    <?php foreach ($daftarAngkatan as $ang): ?>
                                        <?php 
                                            $countAng = count(array_filter($allStudents, fn($s) => ($s['angkatan'] ?? '') == $ang));
                                        ?>
                                        <option value="<?= htmlspecialchars($ang) ?>">Angkatan <?= htmlspecialchars($ang) ?> (<?= $countAng ?> Mhs)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Filter Status Pendaftaran -->
                            <div>
                                <label for="filter-status" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    <i data-lucide="user-check" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i>Status Pendaftaran
                                </label>
                                <select id="filter-status" class="w-full px-3 py-2 text-xs bg-white border border-slate-300 rounded-xl text-slate-900 font-medium focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                                    <option value="all">Semua Status</option>
                                    <option value="enrolled">Sudah Terdaftar Saja (<?= count($enrolledUserIds) ?>)</option>
                                    <option value="not_enrolled">Belum Terdaftar Saja (<?= count($allStudents) - count($enrolledUserIds) ?>)</option>
                                </select>
                            </div>

                            <!-- Pencarian Cepat Nama / NIM -->
                            <div>
                                <label for="search-mahasiswa" class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                    <i data-lucide="search" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i>Cari Nama / NIM
                                </label>
                                <div class="relative">
                                    <input type="text" id="search-mahasiswa" placeholder="Ketik nama atau NIM..." 
                                           class="w-full pl-8 pr-3 py-2 text-xs bg-white border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400">
                                        <i data-lucide="search" class="w-3.5 h-3.5"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi Cepat & Keterangan Status Filter -->
                        <div class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-200">
                            <div class="flex flex-wrap items-center gap-2">
                                <button id="btn-apply-sort" type="button" 
                                        class="inline-flex items-center space-x-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition active:scale-95">
                                    <i data-lucide="arrow-down-up" class="w-3.5 h-3.5"></i>
                                    <span>Terapkan Sortir</span>
                                </button>
                                <button id="btn-select-all" type="button" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-cyan-800 bg-cyan-100 hover:bg-cyan-200 border border-cyan-300 transition">
                                    Pilih Semua yang Tampil
                                </button>
                                <button id="btn-deselect-all" type="button" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 bg-white hover:bg-slate-100 border border-slate-200 transition">
                                    Batal Semua yang Tampil
                                </button>
                                <button id="btn-reset-filter" type="button" class="px-2.5 py-1.5 rounded-lg text-xs font-medium text-slate-500 hover:text-slate-800 transition">
                                    Reset
                                </button>
                            </div>

                            <div class="text-[11px] text-slate-500 font-medium flex items-center space-x-2">
                                <span id="sort-status-badge" class="hidden px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 transition">
                                    ✓ Tersortir Otomatis
                                </span>
                                <span id="visible-counter">Menampilkan <?= count($allStudents) ?> mahasiswa</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Enrollment Dosen (POST) -->
                    <form action="/kelas/<?= urlencode($urlSlug) ?>/peserta" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="update_peserta">

                        <div id="student-list-container" class="border border-slate-200 rounded-2xl overflow-hidden max-h-[550px] overflow-y-auto divide-y divide-slate-100 bg-white">
                            <?php foreach ($allStudents as $st): ?>
                                <?php $isEnrolled = in_array($st['id_user'], $enrolledUserIds, true); ?>
                                <label class="mhs-row flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition cursor-pointer select-none"
                                       data-nama="<?= htmlspecialchars(strtolower($st['nama'])) ?>"
                                       data-nim="<?= htmlspecialchars(strtolower($st['nim'])) ?>"
                                       data-angkatan="<?= htmlspecialchars((string)($st['angkatan'] ?? '')) ?>"
                                       data-enrolled="<?= $isEnrolled ? '1' : '0' ?>">
                                    <div class="flex items-center space-x-3.5">
                                        <input type="checkbox" name="mahasiswa_ids[]" value="<?= htmlspecialchars($st['id_user']) ?>" 
                                               <?= $isEnrolled ? 'checked' : '' ?>
                                               class="mhs-checkbox w-4 h-4 text-maritime-700 rounded border-slate-300 focus:ring-cyan-500">
                                        <div>
                                            <div class="text-sm font-bold text-slate-900 font-heading">
                                                <?= htmlspecialchars($st['nama']) ?>
                                            </div>
                                            <div class="text-xs font-mono text-cyan-800">
                                                NIM: <?= htmlspecialchars($st['nim']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                            Angkatan <?= htmlspecialchars($st['angkatan'] ?? '-') ?>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>

                            <div id="no-students-matched" class="hidden p-8 text-center text-slate-400">
                                <i data-lucide="users" class="w-8 h-8 mx-auto text-slate-300 mb-2"></i>
                                <p class="text-xs font-semibold text-slate-600">Tidak ada mahasiswa yang cocok dengan kriteria sortir / filter saat ini.</p>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button type="submit" 
                                    class="w-full sm:w-auto inline-flex items-center justify-center space-x-2 px-6 py-3 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                                <i data-lucide="check-circle" class="w-4 h-4"></i>
                                <span>Simpan Perubahan Peserta</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- TAMPILAN MAHASISWA: STRICTLY READ-ONLY (DILENGKAPI FILTER ANGKATAN) -->
                <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <h2 class="text-xl font-bold text-abyssal-900 font-heading">
                                Rekan Sekelas Terdaftar
                            </h2>
                            <p class="text-xs text-slate-500">
                                Daftar mahasiswa yang mengikuti mata kuliah <?= htmlspecialchars($course['nama_mk']) ?>.
                            </p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <!-- Filter Angkatan untuk Mahasiswa -->
                            <div class="flex items-center space-x-2">
                                <span class="text-xs text-slate-500 font-medium">Filter:</span>
                                <select id="mhs-filter-angkatan" class="px-3 py-1.5 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                                    <option value="all">Semua Angkatan</option>
                                    <?php foreach ($daftarAngkatan as $ang): ?>
                                        <option value="<?= htmlspecialchars($ang) ?>">Angkatan <?= htmlspecialchars($ang) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span class="px-3.5 py-1.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800 border border-cyan-200">
                                Total <?= count($enrolledUserIds) ?> Mahasiswa
                            </span>
                        </div>
                    </div>

                    <!-- Grid Kartu Mahasiswa (Read Only) -->
                    <div id="mhs-classmates-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($allStudents as $st): ?>
                            <?php if (!in_array($st['id_user'], $enrolledUserIds, true)) continue; ?>
                            <?php $isMe = ($st['id_user'] === $currentUser['id_user']); ?>
                            <div class="mhs-card p-4 rounded-2xl border <?= $isMe ? 'border-cyan-400 bg-cyan-50/40 ring-2 ring-cyan-200' : 'border-slate-200 bg-white' ?> flex items-center justify-between"
                                 data-angkatan="<?= htmlspecialchars((string)($st['angkatan'] ?? '')) ?>">
                                <div class="flex items-center space-x-3 truncate">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr <?= $isMe ? 'from-cyan-600 to-ocean text-white' : 'from-slate-700 to-slate-900 text-slate-100' ?> flex items-center justify-center font-bold text-sm shrink-0 shadow-sm">
                                        <?= strtoupper(substr($st['nama'], 0, 1)) ?>
                                    </div>
                                    <div class="truncate">
                                        <div class="text-sm font-bold text-slate-900 truncate font-heading flex items-center space-x-1">
                                            <span><?= htmlspecialchars($st['nama']) ?></span>
                                            <?php if ($isMe): ?>
                                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-cyan-600 text-white">[Anda]</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs font-mono text-cyan-800">
                                            <?= htmlspecialchars($st['nim']) ?> (<?= htmlspecialchars($st['angkatan'] ?? '-') ?>)
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0">
                                    Terdaftar
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="mhs-empty-notice" class="hidden p-8 text-center text-slate-400">
                        <p class="text-xs font-medium">Tidak ada rekan sekelas untuk angkatan yang dipilih.</p>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- Script Khusus Tab Peserta: Sortir & Filter Otomatis + Tombol Aksi Manual -->
        <script>
        (function() {
            function initEnrollmentSorting() {
                const sortSelect = document.getElementById('sort-mahasiswa');
                const filterAngkatanSelect = document.getElementById('filter-angkatan');
                const filterStatusSelect = document.getElementById('filter-status');
                const searchInput = document.getElementById('search-mahasiswa');
                const btnApply = document.getElementById('btn-apply-sort');
                const btnSelectAll = document.getElementById('btn-select-all');
                const btnDeselectAll = document.getElementById('btn-deselect-all');
                const btnResetFilter = document.getElementById('btn-reset-filter');
                const listContainer = document.getElementById('student-list-container');
                const noMatchedNotice = document.getElementById('no-students-matched');
                const visibleCounter = document.getElementById('visible-counter');
                const sortBadge = document.getElementById('sort-status-badge');
                const counterBadge = document.getElementById('selected-counter');

                if (!listContainer) return;

                function updateCounter() {
                    if (!counterBadge) return;
                    const total = document.querySelectorAll('.mhs-checkbox').length;
                    const checked = document.querySelectorAll('.mhs-checkbox:checked').length;
                    counterBadge.textContent = `${checked} Terdaftar dari ${total} Mahasiswa`;
                }

                function applyStudentFiltersAndSort(showBadge = false) {
                    const sortVal = sortSelect ? sortSelect.value : 'angkatan_desc';
                    const filterAngkatan = filterAngkatanSelect ? filterAngkatanSelect.value : 'all';
                    const filterStatus = filterStatusSelect ? filterStatusSelect.value : 'all';
                    const searchKeyword = searchInput ? searchInput.value.trim().toLowerCase() : '';

                    const rows = Array.from(listContainer.querySelectorAll('.mhs-row'));
                    let visibleCount = 0;

                    // 1. Filter baris
                    rows.forEach(row => {
                        const rowNama = row.getAttribute('data-nama') || '';
                        const rowNim = row.getAttribute('data-nim') || '';
                        const rowAngkatan = (row.getAttribute('data-angkatan') || '').trim();
                        const cb = row.querySelector('.mhs-checkbox');
                        const isChecked = cb ? cb.checked : false;

                        const matchAngkatan = (filterAngkatan === 'all') || (rowAngkatan === filterAngkatan);
                        const matchStatus = (filterStatus === 'all') ||
                                            (filterStatus === 'enrolled' && isChecked) ||
                                            (filterStatus === 'not_enrolled' && !isChecked);
                        const matchSearch = (searchKeyword === '') ||
                                            (rowNama.includes(searchKeyword)) ||
                                            (rowNim.includes(searchKeyword));

                        if (matchAngkatan && matchStatus && matchSearch) {
                            row.classList.remove('hidden');
                            visibleCount++;
                        } else {
                            row.classList.add('hidden');
                        }
                    });

                    // 2. Sortir urutan baris
                    rows.sort((a, b) => {
                        const angkatanA = parseInt(a.getAttribute('data-angkatan') || '0', 10);
                        const angkatanB = parseInt(b.getAttribute('data-angkatan') || '0', 10);
                        const namaA = a.getAttribute('data-nama') || '';
                        const namaB = b.getAttribute('data-nama') || '';
                        const nimA = a.getAttribute('data-nim') || '';
                        const nimB = b.getAttribute('data-nim') || '';
                        const cbA = a.querySelector('.mhs-checkbox');
                        const cbB = b.querySelector('.mhs-checkbox');
                        const isEnrolledA = cbA && cbA.checked ? 1 : 0;
                        const isEnrolledB = cbB && cbB.checked ? 1 : 0;

                        switch (sortVal) {
                            case 'angkatan_asc':
                                if (angkatanA !== angkatanB) return angkatanA - angkatanB;
                                return namaA.localeCompare(namaB);
                            case 'nama_asc':
                                return namaA.localeCompare(namaB);
                            case 'nama_desc':
                                return namaB.localeCompare(namaA);
                            case 'nim_asc':
                                return nimA.localeCompare(nimB);
                            case 'status_enrolled':
                                if (isEnrolledA !== isEnrolledB) return isEnrolledB - isEnrolledA;
                                if (angkatanA !== angkatanB) return angkatanB - angkatanA;
                                return namaA.localeCompare(namaB);
                            case 'angkatan_desc':
                            default:
                                if (angkatanA !== angkatanB) return angkatanB - angkatanA;
                                return namaA.localeCompare(namaB);
                        }
                    });

                    // Re-append element ke DOM sesuai urutan (state checked tetap utuh!)
                    rows.forEach(row => listContainer.appendChild(row));

                    if (noMatchedNotice) {
                        if (visibleCount === 0) noMatchedNotice.classList.remove('hidden');
                        else noMatchedNotice.classList.add('hidden');
                    }

                    if (visibleCounter) {
                        visibleCounter.textContent = `Menampilkan ${visibleCount} dari ${rows.length} mahasiswa`;
                    }

                    // Tampilkan feedback visual lencana hijau
                    if (showBadge && sortBadge) {
                        sortBadge.classList.remove('hidden');
                        setTimeout(() => {
                            sortBadge.classList.add('hidden');
                        }, 2200);
                    }
                }

                // Event listener: Otomatis berubah seketika saat dropdown dipilih
                if (sortSelect) {
                    sortSelect.addEventListener('change', () => applyStudentFiltersAndSort(true));
                }
                if (filterAngkatanSelect) {
                    filterAngkatanSelect.addEventListener('change', () => applyStudentFiltersAndSort(true));
                }
                if (filterStatusSelect) {
                    filterStatusSelect.addEventListener('change', () => applyStudentFiltersAndSort(true));
                }
                if (searchInput) {
                    searchInput.addEventListener('input', () => applyStudentFiltersAndSort(false));
                }

                // Tombol aksi manual
                if (btnApply) {
                    btnApply.addEventListener('click', (e) => {
                        e.preventDefault();
                        applyStudentFiltersAndSort(true);
                    });
                }

                if (btnSelectAll) {
                    btnSelectAll.addEventListener('click', (e) => {
                        e.preventDefault();
                        const visibleCheckboxes = listContainer.querySelectorAll('.mhs-row:not(.hidden) .mhs-checkbox');
                        visibleCheckboxes.forEach(cb => cb.checked = true);
                        updateCounter();
                        if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                            applyStudentFiltersAndSort(false);
                        }
                    });
                }

                if (btnDeselectAll) {
                    btnDeselectAll.addEventListener('click', (e) => {
                        e.preventDefault();
                        const visibleCheckboxes = listContainer.querySelectorAll('.mhs-row:not(.hidden) .mhs-checkbox');
                        visibleCheckboxes.forEach(cb => cb.checked = false);
                        updateCounter();
                        if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                            applyStudentFiltersAndSort(false);
                        }
                    });
                }

                if (btnResetFilter) {
                    btnResetFilter.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (sortSelect) sortSelect.value = 'angkatan_desc';
                        if (filterAngkatanSelect) filterAngkatanSelect.value = 'all';
                        if (filterStatusSelect) filterStatusSelect.value = 'all';
                        if (searchInput) searchInput.value = '';
                        applyStudentFiltersAndSort(true);
                    });
                }

                document.querySelectorAll('.mhs-checkbox').forEach(cb => {
                    cb.addEventListener('change', () => {
                        updateCounter();
                        if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                            applyStudentFiltersAndSort(false);
                        }
                    });
                });

                // Jalankan saat halaman dibuka
                applyStudentFiltersAndSort(false);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initEnrollmentSorting);
            } else {
                initEnrollmentSorting();
            }

            // Handler Filter Angkatan untuk Mahasiswa (Rekan Sekelas)
            const mhsFilterAngkatan = document.getElementById('mhs-filter-angkatan');
            const mhsClassmatesGrid = document.getElementById('mhs-classmates-grid');
            if (mhsFilterAngkatan && mhsClassmatesGrid) {
                mhsFilterAngkatan.addEventListener('change', () => {
                    const val = mhsFilterAngkatan.value;
                    const cards = mhsClassmatesGrid.querySelectorAll('.mhs-card');
                    let matched = 0;
                    cards.forEach(card => {
                        const cardAngkatan = (card.getAttribute('data-angkatan') || '').trim();
                        if (val === 'all' || cardAngkatan === val) {
                            card.classList.remove('hidden');
                            matched++;
                        } else {
                            card.classList.add('hidden');
                        }
                    });
                    const emptyNotice = document.getElementById('mhs-empty-notice');
                    if (emptyNotice) {
                        if (matched === 0) emptyNotice.classList.remove('hidden');
                        else emptyNotice.classList.add('hidden');
                    }
                });
            }
        })();
        </script>
    <?php endif; ?>

    <!-- TAB 4: REKAP NILAI TUGAS (KHUSUS DOSEN) -->
    <?php if ($tab === 'rekap' && $isDosen): ?>
        <div class="space-y-6">
            
            <!-- Header Tab Rekap Nilai -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-abyssal-900 font-heading">
                        Rekapitulasi Nilai Tugas Mahasiswa
                    </h2>
                    <p class="text-xs text-slate-500">
                        Pantau seluruh capaian pengumpulan tugas mingguan, nilai angka, rata-rata kelas, dan ekspor ke format CSV / Excel.
                    </p>
                </div>

                <!-- Tombol Ekspor Dropdown / Menu -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Tombol Ekspor Semua Nilai (Lengkap 1 Semester) -->
                    <a href="/kelas/<?= urlencode($urlSlug) ?>/rekap?export=csv" 
                       class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 shadow-sm transition"
                       title="Ekspor CSV Format Standar (Titik Koma / Kompatibel Excel Indonesia & Global)">
                        <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                        <span>Ekspor Semua Nilai (Excel / CSV)</span>
                    </a>

                    <!-- Alternatif format Koma murni jika diperlukan untuk sistem eksternal -->
                    <a href="/kelas/<?= urlencode($urlSlug) ?>/rekap?export=csv&delim=comma" 
                       class="inline-flex items-center space-x-1 px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition"
                       title="Ekspor CSV Format Koma (Kompatibel Google Sheets / US Format)">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                        <span>CSV (Format Koma)</span>
                    </a>
                </div>
            </div>

            <!-- Kartu Ringkasan Kelas -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs">
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Total Mahasiswa</p>
                    <p class="text-2xl font-bold text-abyssal-900 font-heading mt-1"><?= count($rekapStudents) ?></p>
                    <p class="text-[11px] text-slate-400">Peserta terdaftar</p>
                </div>

                <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs">
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Tugas Perkuliahan</p>
                    <p class="text-2xl font-bold text-cyan-600 font-heading mt-1"><?= count($rekapTasks) ?></p>
                    <p class="text-[11px] text-slate-400">Pertemuan penugasan</p>
                </div>

                <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs">
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Tugas Telah Dikumpul</p>
                    <?php
                    $totalSubmittedCount = 0;
                    foreach ($rekapStudents as $st) {
                        foreach ($rekapTasks as $t) {
                            if (!empty($rekapMatrix[$st['id_user']][$t['id_konten']])) {
                                $totalSubmittedCount++;
                            }
                        }
                    }
                    ?>
                    <p class="text-2xl font-bold text-blue-600 font-heading mt-1"><?= $totalSubmittedCount ?></p>
                    <p class="text-[11px] text-slate-400">Total berkas jawaban</p>
                </div>

                <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs">
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Rata-rata Kelas</p>
                    <?php
                    $allScoresArray = [];
                    foreach ($rekapStudents as $st) {
                        foreach ($rekapTasks as $t) {
                            $sc = $rekapMatrix[$st['id_user']][$t['id_konten']]['nilai'] ?? null;
                            if ($sc !== null) {
                                $allScoresArray[] = (float)$sc;
                            }
                        }
                    }
                    $classAvg = !empty($allScoresArray) ? number_format(array_sum($allScoresArray) / count($allScoresArray), 1) : '-';
                    ?>
                    <p class="text-2xl font-bold text-emerald-600 font-heading mt-1"><?= $classAvg ?></p>
                    <p class="text-[11px] text-slate-400">Skala 0 - 100</p>
                </div>
            </div>

            <!-- Panel Unduh Per Pertemuan Tugas -->
            <?php if (!empty($rekapTasks)): ?>
                <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm space-y-3">
                    <div class="flex items-center space-x-2 pb-2 border-b border-slate-100">
                        <i data-lucide="download" class="w-4 h-4 text-cyan-600"></i>
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider font-heading">
                            Unduh Rekap Nilai Khusus Per Pertemuan
                        </h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach ($rekapTasks as $t): ?>
                            <a href="/kelas/<?= urlencode($urlSlug) ?>/rekap?export=csv&task_id=<?= urlencode($t['id_konten']) ?>" 
                               class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-50 hover:bg-cyan-50 text-slate-700 hover:text-cyan-800 border border-slate-200 hover:border-cyan-300 transition shadow-2xs"
                               title="Unduh Rekap CSV Minggu ke-<?= $t['minggu_ke'] ?>: <?= htmlspecialchars($t['judul']) ?>">
                                <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-bold text-[10px]">M<?= $t['minggu_ke'] ?></span>
                                <span class="truncate max-w-[180px]"><?= htmlspecialchars($t['judul']) ?></span>
                                <i data-lucide="download" class="w-3.5 h-3.5 text-slate-400"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabel Matriks Rekapitulasi Nilai -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <?php if (empty($rekapStudents)): ?>
                    <div class="py-12 text-center text-slate-400">
                        <i data-lucide="users" class="w-10 h-10 mx-auto stroke-1 mb-2"></i>
                        <p class="text-sm">Belum ada mahasiswa yang terdaftar di kelas ini.</p>
                    </div>
                <?php elseif (empty($rekapTasks)): ?>
                    <div class="py-12 text-center text-slate-400">
                        <i data-lucide="clipboard-x" class="w-10 h-10 mx-auto stroke-1 mb-2"></i>
                        <p class="text-sm font-semibold text-slate-600">Belum ada tugas pada alur 16 minggu perkuliahan ini.</p>
                        <p class="text-xs text-slate-400 mt-1">Tambahkan komponen tipe "Tugas" pada tab 16 Minggu Perkuliahan untuk memulai pengumpulan dan penilaian tugas.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 uppercase tracking-wider font-semibold">
                                    <th class="py-3.5 px-4 w-12 text-center sticky left-0 bg-slate-50 z-10 border-r border-slate-200">No</th>
                                    <th class="py-3.5 px-4 min-w-[120px] sticky left-12 bg-slate-50 z-10 border-r border-slate-200">NIM</th>
                                    <th class="py-3.5 px-4 min-w-[200px] sticky left-[168px] bg-slate-50 z-10 border-r border-slate-200 shadow-xs">Nama Mahasiswa</th>
                                    <th class="py-3.5 px-3 text-center min-w-[70px]">Angkatan</th>
                                    
                                    <!-- Kolom Dinamis Setiap Pertemuan yang Ada Tugas -->
                                    <?php foreach ($rekapTasks as $t): ?>
                                        <th class="py-3.5 px-4 text-center min-w-[140px] border-l border-slate-200">
                                            <div class="flex flex-col items-center">
                                                <a href="/nilai/<?= urlencode($t['id_konten']) ?>" 
                                                   class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 hover:bg-amber-200 transition mb-1" 
                                                   title="Buka Lembar Penilaian">
                                                    Minggu ke-<?= $t['minggu_ke'] ?>
                                                </a>
                                                <span class="truncate max-w-[130px] font-bold text-slate-800 normal-case" title="<?= htmlspecialchars($t['judul']) ?>">
                                                    <?= htmlspecialchars($t['judul']) ?>
                                                </span>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>

                                    <th class="py-3.5 px-4 text-center min-w-[100px] border-l border-slate-200 bg-slate-100/60">Tugas Selesai</th>
                                    <th class="py-3.5 px-4 text-center min-w-[110px] border-l border-slate-200 bg-slate-100/80 font-bold text-slate-900">Rata-rata Nilai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php $no = 1; foreach ($rekapStudents as $st): ?>
                                    <tr class="hover:bg-slate-50/80 transition">
                                        <td class="py-3 px-4 text-center text-slate-400 font-medium sticky left-0 bg-white group-hover:bg-slate-50 border-r border-slate-200">
                                            <?= $no++ ?>
                                        </td>
                                        <td class="py-3 px-4 font-mono font-bold text-slate-800 sticky left-12 bg-white border-r border-slate-200">
                                            <?= htmlspecialchars($st['nim']) ?>
                                        </td>
                                        <td class="py-3 px-4 font-semibold text-slate-900 sticky left-[168px] bg-white border-r border-slate-200 shadow-xs">
                                            <div class="truncate max-w-[190px]" title="<?= htmlspecialchars($st['nama']) ?>">
                                                <?= htmlspecialchars($st['nama']) ?>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-center text-slate-500 font-medium">
                                            <?= htmlspecialchars($st['angkatan'] ?? '-') ?>
                                        </td>

                                        <!-- Nilai Per Tugas -->
                                        <?php
                                        $mhsScores = [];
                                        $mhsCompletedCount = 0;
                                        ?>
                                        <?php foreach ($rekapTasks as $t): ?>
                                            <?php 
                                            $sub = $rekapMatrix[$st['id_user']][$t['id_konten']] ?? null;
                                            ?>
                                            <td class="py-3 px-4 text-center border-l border-slate-100">
                                                <?php if (!$sub): ?>
                                                    <span class="inline-block text-slate-300 font-mono" title="Belum mengumpulkan">-</span>
                                                <?php elseif ($sub['nilai'] !== null): ?>
                                                    <?php 
                                                    $valScore = (float)$sub['nilai'];
                                                    $mhsScores[] = $valScore;
                                                    $mhsCompletedCount++;
                                                    $scoreColor = $valScore >= 80 ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($valScore >= 65 ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-amber-100 text-amber-800 border-amber-300');
                                                    ?>
                                                    <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-bold border <?= $scoreColor ?>" title="Sudah dinilai: <?= $valScore ?>">
                                                        <?= number_format($valScore, 1) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?php $mhsCompletedCount++; ?>
                                                    <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-cyan-50 text-cyan-800 border border-cyan-200" title="Sudah kumpul, menunggu penilaian dosen">
                                                        <i data-lucide="clock" class="w-3 h-3 text-cyan-600"></i>
                                                        <span>Kumpul</span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>

                                        <!-- Total Tugas Selesai -->
                                        <td class="py-3 px-4 text-center border-l border-slate-200 bg-slate-50/40">
                                            <span class="font-bold <?= $mhsCompletedCount === count($rekapTasks) ? 'text-emerald-700' : 'text-slate-700' ?>">
                                                <?= $mhsCompletedCount ?> / <?= count($rekapTasks) ?>
                                            </span>
                                        </td>

                                        <!-- Rata-rata Nilai -->
                                        <td class="py-3 px-4 text-center border-l border-slate-200 bg-slate-50/60">
                                            <?php if (!empty($mhsScores)): ?>
                                                <?php 
                                                $studentAvg = array_sum($mhsScores) / count($mhsScores);
                                                $avgBadge = $studentAvg >= 80 ? 'text-emerald-700 font-extrabold' : ($studentAvg >= 65 ? 'text-blue-700 font-bold' : 'text-amber-700 font-bold');
                                                ?>
                                                <span class="text-sm font-heading <?= $avgBadge ?>">
                                                    <?= number_format($studentAvg, 2) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-slate-400 font-mono">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</main>

<!-- Modal Tambah Konten Pembelajaran (Khusus Dosen) -->
<?php if ($isDosen): ?>
    <div id="modal-tambah-konten" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-sm transition-opacity">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="relative bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4 overflow-hidden" onclick="event.stopPropagation()">
                
                <!-- Animasi Overlay Saat Mengunggah / Menyimpan Konten Pembelajaran -->
                <div id="upload-loading-overlay" class="hidden absolute inset-0 bg-slate-950/85 backdrop-blur-md rounded-3xl z-50 flex flex-col items-center justify-center p-6 text-center text-white space-y-4 animate-in fade-in duration-200">
                    <!-- Oceanic Glowing Spinner -->
                    <div class="relative w-20 h-20 flex items-center justify-center">
                        <div class="absolute inset-0 rounded-full border-4 border-cyan-400/20 animate-ping"></div>
                        <div class="w-16 h-16 rounded-full border-4 border-cyan-400 border-t-transparent animate-spin"></div>
                        <div class="absolute inset-0 flex items-center justify-center text-cyan-300">
                            <i data-lucide="cloud-upload" class="w-7 h-7 animate-bounce"></i>
                        </div>
                    </div>

                    <div class="space-y-1.5 max-w-xs">
                        <h4 class="text-base font-bold font-heading text-white">
                            Mengunggah & Menyimpan Konten...
                        </h4>
                        <p class="text-xs text-slate-300 leading-relaxed" id="loading-detail-text">
                            Mohon tunggu, berkas dan data komponen sedang diproses ke server...
                        </p>
                    </div>

                    <!-- Shimmering Progress Indicator -->
                    <div class="w-full max-w-xs h-2 bg-slate-800 rounded-full overflow-hidden border border-slate-700/80">
                        <div class="h-full bg-gradient-to-r from-cyan-400 via-blue-500 to-cyan-300 rounded-full animate-pulse w-full"></div>
                    </div>

                    <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-cyan-950/80 text-cyan-300 border border-cyan-700/50">
                        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-cyan-400"></i>
                        <span>Enkripsi & verifikasi berkas aman</span>
                    </div>
                </div>

                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center space-x-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 text-maritime-700 flex items-center justify-center">
                            <i data-lucide="plus-circle" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-900 font-heading">
                                Tambah Komponen Pembelajaran
                            </h3>
                            <p class="text-xs text-slate-500">Rancang materi, tugas, kuis, atau ujian semester</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeAddContentModal()"
                            class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <form id="form-tambah-konten" action="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" method="POST" enctype="multipart/form-data" class="space-y-3.5 text-left">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_konten">

                    <!-- Baris 1: Pertemuan Minggu Ke- & Tipe Komponen -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="modal_minggu" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                Pertemuan Minggu Ke- *
                            </label>
                            <select id="modal_minggu" name="minggu_ke" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                <?php for ($w = 1; $w <= 16; $w++): ?>
                                    <option value="<?= $w ?>" <?= $w === ($openMinggu ?? 1) ? 'selected' : '' ?>>Minggu ke-<?= $w ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="modal_tipe" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                                Tipe Komponen *
                            </label>
                            <select id="modal_tipe" name="tipe" onchange="updateModalFields()" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 font-medium focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                                <option value="Materi" selected>Materi Presentasi / Modul</option>
                                <option value="Tugas">Tugas (Assignment)</option>
                                <option value="Kuis">Kuis (Online Quiz / Forms)</option>
                                <option value="Ujian">Ujian (UTS / UAS Embed)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Baris 2: Judul Komponen -->
                    <div>
                        <label for="modal_judul" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Judul Komponen *
                        </label>
                        <input id="modal_judul" name="judul" type="text" required placeholder="Contoh: Modul 1: Morfologi Paparan Benua"
                               class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>

                    <!-- Baris 3: Deskripsi / Petunjuk -->
                    <div>
                        <label for="modal_deskripsi" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Deskripsi / Petunjuk
                        </label>
                        <textarea id="modal_deskripsi" name="deskripsi" rows="3" placeholder="Instruksi pengerjaan tugas, petunjuk kuis, atau ringkasan capaian materi..."
                                  class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition"></textarea>
                    </div>

                    <!-- Baris 4: Unggah File (PPT, PDF, Word, Excel - Maks <?= get_max_upload_materi_mb() ?> MB) -->
                    <div id="group_file">
                        <label for="modal_file" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Unggah File (PPT, PDF, Word, Excel - Maks File Size <?= get_max_upload_materi_mb() ?> MB)
                        </label>
                        <input id="modal_file" name="file_lampiran" type="file" accept=".ppt,.pptx,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.mp4"
                               class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-800 hover:file:bg-cyan-100 cursor-pointer">
                        <p class="text-[11px] text-slate-400 mt-1">
                            Format didukung: PowerPoint (.ppt, .pptx), PDF (.pdf), Word (.doc, .docx), Excel (.xls, .xlsx), ZIP, MP4. Maks <?= get_max_upload_materi_mb() ?> MB (Disesuaikan di Pengaturan).
                        </p>
                    </div>

                    <!-- Baris 5: Link Eksternal / URL Ujian Eksternal -->
                    <div id="group_url">
                        <label id="label_url" for="modal_url" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Link Eksternal (Opsional)
                        </label>
                        <input id="modal_url" name="url_embed" type="text" placeholder="https://drive.google.com/... atau https://kelautan.id/..."
                               class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                        <p id="hint_url" class="text-[11px] text-slate-400 mt-1">
                            Opsional: Tautan materi dari Google Drive, YouTube, atau portal jurnal.
                        </p>
                    </div>

                    <!-- Baris 6: Tenggat Waktu Pengumpulan / Pengerjaan (Deadline) -->
                    <div id="group_deadline" class="hidden">
                        <label for="modal_deadline" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            <span id="label_deadline">Tenggat Waktu Pengumpulan (Deadline)</span> *
                        </label>
                        <input id="modal_deadline" name="deadline" type="datetime-local"
                               class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                        <p id="hint_deadline" class="text-[11px] text-slate-400 mt-1">
                            Batas akhir mahasiswa dapat mengumpulkan jawaban tugas berkas PDF.
                        </p>
                    </div>

                    <div class="pt-2 flex items-center justify-end space-x-2 border-t border-slate-100">
                        <button type="button" onclick="closeAddContentModal()"
                                class="px-4 py-2 text-xs font-semibold rounded-xl text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                            Batal
                        </button>
                        <button id="btn-submit-konten" type="submit" 
                                class="px-5 py-2 text-xs font-semibold rounded-xl text-white bg-maritime-700 hover:bg-maritime-800 shadow transition flex items-center space-x-1.5">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Simpan Komponen</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT KOMPONEN PEMBELAJARAN (KHUSUS DOSEN) -->
    <div id="modal-edit-konten" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs hidden flex items-center justify-center p-4">
        <div class="relative bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 max-h-[90vh] overflow-y-auto">
            
            <!-- Loading Indicator saat Proses Submit Edit Konten -->
            <div id="edit-upload-loading-overlay" class="absolute inset-0 bg-slate-950/85 backdrop-blur-sm z-30 rounded-3xl hidden flex-col items-center justify-center p-6 text-center space-y-4">
                <div class="relative w-16 h-16 flex items-center justify-center">
                    <div class="absolute inset-0 rounded-full border-4 border-cyan-500/20 animate-ping"></div>
                    <div class="w-12 h-12 rounded-full border-4 border-cyan-400 border-t-transparent animate-spin"></div>
                    <i data-lucide="refresh-cw" class="w-5 h-5 text-cyan-300 absolute animate-pulse"></i>
                </div>

                <div class="space-y-1 max-w-sm">
                    <h4 class="text-base font-bold font-heading text-white">
                        Menyimpan Perubahan Konten...
                    </h4>
                    <p class="text-xs text-slate-300 leading-relaxed" id="edit-loading-detail-text">
                        Mohon tunggu, berkas dan data komponen sedang diperbarui di server...
                    </p>
                </div>

                <div class="w-full max-w-xs h-2 bg-slate-800 rounded-full overflow-hidden border border-slate-700/80">
                    <div class="h-full bg-gradient-to-r from-cyan-400 via-blue-500 to-cyan-300 rounded-full animate-pulse w-full"></div>
                </div>

                <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-cyan-950/80 text-cyan-300 border border-cyan-700/50">
                    <i data-lucide="shield-check" class="w-3.5 h-3.5 text-cyan-400"></i>
                    <span>Pembaruan berkas & data aman</span>
                </div>
            </div>

            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-lg bg-cyan-50 text-cyan-700 flex items-center justify-center">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 font-heading">
                            Edit Komponen Pembelajaran
                        </h3>
                        <p class="text-xs text-slate-500">Perbarui materi, tugas, kuis, atau ujian</p>
                    </div>
                </div>
                <button type="button" onclick="closeEditContentModal()"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form id="form-edit-konten" action="/kelas/<?= urlencode($urlSlug) ?>/pertemuan" method="POST" enctype="multipart/form-data" class="space-y-3.5 text-left mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_konten">
                <input type="hidden" id="edit_modal_id_konten" name="id_konten" value="">

                <!-- Baris 1: Pertemuan Minggu Ke- & Tipe Komponen -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="edit_modal_minggu" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Pertemuan Minggu Ke- *
                        </label>
                        <select id="edit_modal_minggu" name="minggu_ke" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            <?php for ($w = 1; $w <= 16; $w++): ?>
                                <option value="<?= $w ?>">Minggu ke-<?= $w ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit_modal_tipe" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Tipe Komponen *
                        </label>
                        <select id="edit_modal_tipe" name="tipe" onchange="updateEditModalFields()" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 font-medium focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                            <option value="Materi">Materi Presentasi / Modul</option>
                            <option value="Tugas">Tugas (Assignment)</option>
                            <option value="Kuis">Kuis (Online Quiz / Forms)</option>
                            <option value="Ujian">Ujian (UTS / UAS Embed)</option>
                        </select>
                    </div>
                </div>

                <!-- Baris 2: Judul Komponen -->
                <div>
                    <label for="edit_modal_judul" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Judul Komponen *
                    </label>
                    <input id="edit_modal_judul" name="judul" type="text" required placeholder="Contoh: Modul 1: Morfologi Paparan Benua"
                           class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                </div>

                <!-- Baris 3: Deskripsi / Petunjuk -->
                <div>
                    <label for="edit_modal_deskripsi" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Deskripsi / Petunjuk
                    </label>
                    <textarea id="edit_modal_deskripsi" name="deskripsi" rows="3" placeholder="Instruksi pengerjaan tugas, petunjuk kuis, atau ringkasan capaian materi..."
                              class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition"></textarea>
                </div>

                <!-- Baris 4: Informasi Berkas Saat Ini & Opsi Ganti/Hapus File -->
                <div id="edit_group_file">
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Berkas Lampiran
                    </label>

                    <!-- Info Berkas Tersimpan -->
                    <div id="edit_current_file_box" class="p-2.5 rounded-xl border border-slate-200 bg-slate-50 text-xs mb-2 hidden">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center space-x-2 truncate">
                                <i data-lucide="file-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                                <span class="font-medium text-slate-800 truncate" id="edit_current_file_name"></span>
                            </div>
                            <label class="inline-flex items-center space-x-1.5 text-[11px] text-rose-600 hover:text-rose-700 cursor-pointer shrink-0 font-medium">
                                <input type="checkbox" id="edit_modal_hapus_file" name="hapus_file" value="1" class="rounded text-rose-600 focus:ring-rose-500">
                                <span>Hapus Berkas Ini</span>
                            </label>
                        </div>
                    </div>

                    <label for="edit_modal_file" class="block text-[11px] font-medium text-slate-600 mb-1">
                        Unggah Berkas Baru (Opsional, menggantikan berkas lama):
                    </label>
                    <input id="edit_modal_file" name="file_lampiran" type="file" accept=".ppt,.pptx,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.mp4"
                           class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-800 hover:file:bg-cyan-100 cursor-pointer">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Maksimal <?= get_max_upload_materi_mb() ?> MB. Format: PPT, PDF, Word, Excel, ZIP, MP4.
                    </p>
                </div>

                <!-- Baris 5: Link Eksternal / URL Ujian Eksternal -->
                <div id="edit_group_url">
                    <label id="edit_label_url" for="edit_modal_url" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        Link Eksternal (Opsional)
                    </label>
                    <input id="edit_modal_url" name="url_embed" type="text" placeholder="https://drive.google.com/... atau https://kelautan.id/..."
                           class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    <p id="edit_hint_url" class="text-[11px] text-slate-400 mt-1">
                        Opsional: Tautan materi dari Google Drive, YouTube, atau portal jurnal.
                    </p>
                </div>

                <!-- Baris 6: Tenggat Waktu Pengumpulan / Pengerjaan (Deadline) -->
                <div id="edit_group_deadline" class="hidden">
                    <label for="edit_modal_deadline" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                        <span id="edit_label_deadline">Tenggat Waktu Pengumpulan (Deadline)</span> *
                    </label>
                    <input id="edit_modal_deadline" name="deadline" type="datetime-local"
                           class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    <p id="edit_hint_deadline" class="text-[11px] text-slate-400 mt-1">
                        Batas akhir mahasiswa dapat mengumpulkan jawaban tugas berkas PDF.
                    </p>
                </div>

                <div class="pt-2 flex items-center justify-end space-x-2 border-t border-slate-100">
                    <button type="button" onclick="closeEditContentModal()"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                        Batal
                    </button>
                    <button id="btn-submit-edit-konten" type="submit" 
                            class="px-5 py-2 text-xs font-semibold rounded-xl text-white bg-cyan-700 hover:bg-cyan-800 shadow transition flex items-center space-x-1.5">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                        <span>Simpan Perubahan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pop Up Keterangan Berhasil Disimpan (Modal Dialog Sukses) -->
    <?php if ($isSuksesKonten): ?>
        <div id="modal-sukses-keterangan" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/75 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity">
            <div class="relative bg-white rounded-3xl max-w-md w-full p-6 sm:p-7 shadow-2xl border border-emerald-200 text-center space-y-5 animate-in fade-in zoom-in-95 duration-200" onclick="event.stopPropagation()">
                
                <!-- Animated Success Icon -->
                <div class="relative w-20 h-20 mx-auto flex items-center justify-center">
                    <div class="absolute inset-0 rounded-full bg-emerald-100 animate-ping opacity-60"></div>
                    <div class="w-16 h-16 rounded-full bg-emerald-50 border-2 border-emerald-400 flex items-center justify-center text-emerald-600 shadow-lg shadow-emerald-600/20">
                        <i data-lucide="check-circle-2" class="w-9 h-9 stroke-[2.5]"></i>
                    </div>
                </div>

                <div class="space-y-1">
                    <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <i data-lucide="check" class="w-3 h-3"></i>
                        <span>Tersimpan di Sistem</span>
                    </span>
                    <h3 class="text-xl font-extrabold text-slate-900 font-heading pt-1">
                        Konten Berhasil Disimpan!
                    </h3>
                    <p class="text-xs text-slate-500">
                        Komponen pembelajaran telah aktif di silabus mingguan kelas ini.
                    </p>
                </div>

                <!-- Detail Keterangan -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 text-left space-y-2.5 text-xs">
                    <div class="flex justify-between items-center pb-2 border-b border-slate-200/70">
                        <span class="text-slate-400 font-medium">Alokasi Pertemuan:</span>
                        <span class="font-bold text-maritime-700">Minggu ke-<?= htmlspecialchars((string)$suksesMinggu) ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-200/70">
                        <span class="text-slate-400 font-medium">Tipe Komponen:</span>
                        <span class="px-2 py-0.5 rounded font-semibold text-[11px] <?= match($suksesTipe) {
                            'Tugas' => 'bg-amber-100 text-amber-800',
                            'Kuis'  => 'bg-purple-100 text-purple-800',
                            'Ujian' => 'bg-rose-100 text-rose-800',
                            default => 'bg-cyan-100 text-cyan-800'
                        } ?>">
                            <?= htmlspecialchars($suksesTipe) ?>
                        </span>
                    </div>
                    <div class="pt-0.5">
                        <span class="text-slate-400 font-medium block mb-1">Judul Komponen:</span>
                        <div class="p-2.5 bg-white rounded-xl border border-slate-200 font-semibold text-slate-800 break-words text-xs leading-relaxed">
                            <?= htmlspecialchars($suksesJudul) ?>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="pt-1">
                    <button type="button" onclick="closeSuccessKeteranganModal(<?= (int)$suksesMinggu ?>)"
                            class="w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 shadow-lg shadow-emerald-600/30 transition transform hover:scale-[1.01] flex items-center justify-center space-x-2">
                        <i data-lucide="eye" class="w-4 h-4"></i>
                        <span>Tutup & Lihat Komponen Minggu <?= (int)$suksesMinggu ?></span>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
    function openAddContentModal(minggu = 1) {
        const modal = document.getElementById('modal-tambah-konten');
        const selMinggu = document.getElementById('modal_minggu');
        if (selMinggu && minggu) {
            selMinggu.value = minggu;
        }
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            updateModalFields();
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    }

    function closeAddContentModal() {
        const modal = document.getElementById('modal-tambah-konten');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    function openEditContentModal(item) {
        if (!item) return;

        const modal = document.getElementById('modal-edit-konten');
        document.getElementById('edit_modal_id_konten').value = item.id_konten || '';
        document.getElementById('edit_modal_minggu').value = item.minggu_ke || 1;
        document.getElementById('edit_modal_tipe').value = item.tipe || 'Materi';
        document.getElementById('edit_modal_judul').value = item.judul || '';
        document.getElementById('edit_modal_deskripsi').value = item.deskripsi || '';
        document.getElementById('edit_modal_url').value = item.url_embed || '';
        
        // Reset file input & checkbox hapus file
        const fileInput = document.getElementById('edit_modal_file');
        if (fileInput) fileInput.value = '';
        const chkHapus = document.getElementById('edit_modal_hapus_file');
        if (chkHapus) chkHapus.checked = false;

        // Info Berkas Lama
        const currentFileBox = document.getElementById('edit_current_file_box');
        const currentFileName = document.getElementById('edit_current_file_name');
        if (item.file_path && item.file_path.trim() !== '') {
            const fileNameOnly = item.file_path.split('/').pop();
            currentFileName.textContent = fileNameOnly;
            currentFileBox.classList.remove('hidden');
        } else {
            currentFileBox.classList.add('hidden');
        }

        // Format Deadline ke format YYYY-MM-DDTHH:MM jika ada
        const inputDeadline = document.getElementById('edit_modal_deadline');
        if (item.deadline) {
            const d = new Date(item.deadline.replace(' ', 'T'));
            if (!isNaN(d.getTime())) {
                const pad = (n) => String(n).padStart(2, '0');
                const localIso = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                inputDeadline.value = localIso;
            } else {
                inputDeadline.value = item.deadline;
            }
        } else {
            inputDeadline.value = '';
        }

        updateEditModalFields();

        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    }

    function closeEditContentModal() {
        const modal = document.getElementById('modal-edit-konten');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    function closeSuccessKeteranganModal(minggu) {
        const modal = document.getElementById('modal-sukses-keterangan');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
        // Bersihkan parameter query agar tidak muncul kembali saat halaman di-refresh
        const url = new URL(window.location.href);
        url.searchParams.delete('sukses_konten');
        url.searchParams.delete('tipe');
        url.searchParams.delete('judul');
        url.searchParams.delete('minggu');
        window.history.replaceState({}, document.title, url.toString());

        // Arahkan scroll mulus ke minggu terkait dan beri highlight sementara
        const targetAccordion = document.getElementById('minggu-' + minggu);
        if (targetAccordion) {
            targetAccordion.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetAccordion.classList.add('ring-2', 'ring-emerald-400');
            setTimeout(() => {
                targetAccordion.classList.remove('ring-2', 'ring-emerald-400');
            }, 2500);
        }
    }

    function updateModalFields() {
        const tipe = document.getElementById('modal_tipe').value;
        const groupFile = document.getElementById('group_file');
        const groupUrl = document.getElementById('group_url');
        const groupDeadline = document.getElementById('group_deadline');
        const labelUrl = document.getElementById('label_url');
        const inputUrl = document.getElementById('modal_url');
        const labelDeadline = document.getElementById('label_deadline');
        const inputDeadline = document.getElementById('modal_deadline');
        const hintDeadline = document.getElementById('hint_deadline');
        const hintUrl = document.getElementById('hint_url');

        if (tipe === 'Materi') {
            if (groupFile) groupFile.classList.remove('hidden');
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.add('hidden');
            if (labelUrl) labelUrl.textContent = 'Link Eksternal (Opsional)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'https://drive.google.com/... atau https://kelautan.id/...';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Tautan materi dari Google Drive, YouTube, atau portal jurnal.';
            if (inputDeadline) inputDeadline.required = false;
        } else if (tipe === 'Tugas') {
            if (groupFile) groupFile.classList.remove('hidden');
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.remove('hidden');
            if (labelDeadline) labelDeadline.textContent = 'Tenggat Waktu Pengumpulan (Deadline)';
            if (hintDeadline) hintDeadline.textContent = 'Batas akhir mahasiswa dapat mengumpulkan jawaban tugas berkas PDF.';
            if (labelUrl) labelUrl.textContent = 'Link Eksternal (Opsional)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'https://drive.google.com/... atau rujukan instruksi tugas...';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Tautan literatur atau panduan tugas eksternal jika ada.';
            if (inputDeadline) inputDeadline.required = true;
        } else if (tipe === 'Kuis' || tipe === 'Ujian') {
            if (groupFile) groupFile.classList.add('hidden');
            const fileInput = document.getElementById('modal_file');
            if (fileInput) fileInput.value = '';
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.remove('hidden');
            if (labelDeadline) labelDeadline.textContent = tipe === 'Kuis' ? 'Tenggat Waktu Pengerjaan Kuis (Deadline)' : 'Tenggat Waktu Pelaksanaan Ujian (Deadline)';
            if (hintDeadline) hintDeadline.textContent = tipe === 'Kuis' ? 'Batas akhir waktu pengerjaan kuis daring bagi mahasiswa.' : 'Batas akhir waktu pelaksanaan ujian (UTS / UAS) bagi mahasiswa.';
            if (labelUrl) labelUrl.textContent = 'URL Kuis Eksternal (Opsional jika gunakan Bank Soal LMS)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'Kosongkan jika menggunakan Bank Soal LMS (atau isi https://forms.gle/...)';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Kosongkan untuk menggunakan sistem Bank Soal & Kuis interaktif bawaan LMS Polikant.';
            if (inputDeadline) inputDeadline.required = true;
        }
    }

    function updateEditModalFields() {
        const tipe = document.getElementById('edit_modal_tipe').value;
        const groupFile = document.getElementById('edit_group_file');
        const groupUrl = document.getElementById('edit_group_url');
        const groupDeadline = document.getElementById('edit_group_deadline');
        const labelUrl = document.getElementById('edit_label_url');
        const inputUrl = document.getElementById('edit_modal_url');
        const labelDeadline = document.getElementById('edit_label_deadline');
        const inputDeadline = document.getElementById('edit_modal_deadline');
        const hintDeadline = document.getElementById('edit_hint_deadline');
        const hintUrl = document.getElementById('edit_hint_url');

        if (tipe === 'Materi') {
            if (groupFile) groupFile.classList.remove('hidden');
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.add('hidden');
            if (labelUrl) labelUrl.textContent = 'Link Eksternal (Opsional)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'https://drive.google.com/... atau https://kelautan.id/...';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Tautan materi dari Google Drive, YouTube, atau portal jurnal.';
            if (inputDeadline) inputDeadline.required = false;
        } else if (tipe === 'Tugas') {
            if (groupFile) groupFile.classList.remove('hidden');
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.remove('hidden');
            if (labelDeadline) labelDeadline.textContent = 'Tenggat Waktu Pengumpulan (Deadline)';
            if (hintDeadline) hintDeadline.textContent = 'Batas akhir mahasiswa dapat mengumpulkan jawaban tugas berkas PDF.';
            if (labelUrl) labelUrl.textContent = 'Link Eksternal (Opsional)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'https://drive.google.com/... atau rujukan instruksi tugas...';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Tautan literatur atau panduan tugas eksternal jika ada.';
            if (inputDeadline) inputDeadline.required = true;
        } else if (tipe === 'Kuis' || tipe === 'Ujian') {
            if (groupFile) groupFile.classList.add('hidden');
            const fileInput = document.getElementById('edit_modal_file');
            if (fileInput) fileInput.value = '';
            if (groupUrl) groupUrl.classList.remove('hidden');
            if (groupDeadline) groupDeadline.classList.remove('hidden');
            if (labelDeadline) labelDeadline.textContent = tipe === 'Kuis' ? 'Tenggat Waktu Pengerjaan Kuis (Deadline)' : 'Tenggat Waktu Pelaksanaan Ujian (Deadline)';
            if (hintDeadline) hintDeadline.textContent = tipe === 'Kuis' ? 'Batas akhir waktu pengerjaan kuis daring bagi mahasiswa.' : 'Batas akhir waktu pelaksanaan ujian (UTS / UAS) bagi mahasiswa.';
            if (labelUrl) labelUrl.textContent = 'URL Kuis Eksternal (Opsional jika gunakan Bank Soal LMS)';
            if (inputUrl) {
                inputUrl.required = false;
                inputUrl.placeholder = 'Kosongkan jika menggunakan Bank Soal LMS (atau isi https://forms.gle/...)';
            }
            if (hintUrl) hintUrl.textContent = 'Opsional: Kosongkan untuk menggunakan sistem Bank Soal & Kuis interaktif bawaan LMS Polikant.';
            if (inputDeadline) inputDeadline.required = true;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateModalFields();

        // Inisialisasi Lucide Icons untuk popup sukses jika aktif
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // Tangani form submit dengan animasi upload
        const formTambah = document.getElementById('form-tambah-konten');
        if (formTambah) {
            formTambah.addEventListener('submit', function(e) {
                const inputJudul = document.getElementById('modal_judul');
                if (!inputJudul || !inputJudul.value.trim()) {
                    return;
                }

                const selTipe = document.getElementById('modal_tipe') ? document.getElementById('modal_tipe').value : '';
                const inputDeadline = document.getElementById('modal_deadline');
                if ((selTipe === 'Tugas' || selTipe === 'Kuis' || selTipe === 'Ujian') && (!inputDeadline || !inputDeadline.value)) {
                    return;
                }

                const inputUrl = document.getElementById('modal_url');
                if ((selTipe === 'Kuis' || selTipe === 'Ujian') && (!inputUrl || !inputUrl.value.trim())) {
                    return;
                }

                const overlay = document.getElementById('upload-loading-overlay');
                const submitBtn = document.getElementById('btn-submit-konten');
                const fileInput = document.getElementById('modal_file');
                const loadingDetail = document.getElementById('loading-detail-text');

                if (fileInput && fileInput.files && fileInput.files.length > 0) {
                    const f = fileInput.files[0];
                    const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                    if (loadingDetail) {
                        const safeName = f.name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        loadingDetail.innerHTML = `Mengunggah berkas <strong>${safeName}</strong> (${sizeMb} MB) ke server...`;
                    }
                }

                if (overlay) {
                    overlay.classList.remove('hidden');
                    overlay.classList.add('flex');
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
                    submitBtn.innerHTML = `
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Menyimpan...</span>
                    `;
                }
            });
        }

        // Tangani form submit dengan animasi upload untuk form edit
        const formEdit = document.getElementById('form-edit-konten');
        if (formEdit) {
            formEdit.addEventListener('submit', function(e) {
                const inputJudul = document.getElementById('edit_modal_judul');
                if (!inputJudul || !inputJudul.value.trim()) {
                    return;
                }

                const selTipe = document.getElementById('edit_modal_tipe') ? document.getElementById('edit_modal_tipe').value : '';
                const inputDeadline = document.getElementById('edit_modal_deadline');
                if ((selTipe === 'Tugas' || selTipe === 'Kuis' || selTipe === 'Ujian') && (!inputDeadline || !inputDeadline.value)) {
                    return;
                }

                const inputUrl = document.getElementById('edit_modal_url');
                if ((selTipe === 'Kuis' || selTipe === 'Ujian') && (!inputUrl || !inputUrl.value.trim())) {
                    return;
                }

                const overlay = document.getElementById('edit-upload-loading-overlay');
                const submitBtn = document.getElementById('btn-submit-edit-konten');
                const fileInput = document.getElementById('edit_modal_file');
                const loadingDetail = document.getElementById('edit-loading-detail-text');

                if (fileInput && fileInput.files && fileInput.files.length > 0) {
                    const f = fileInput.files[0];
                    const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                    if (loadingDetail) {
                        const safeName = f.name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        loadingDetail.innerHTML = `Mengunggah berkas baru <strong>${safeName}</strong> (${sizeMb} MB) ke server...`;
                    }
                }

                if (overlay) {
                    overlay.classList.remove('hidden');
                    overlay.classList.add('flex');
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
                    submitBtn.innerHTML = `
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Menyimpan Perubahan...</span>
                    `;
                }
            });
        }
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
