<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Automated Test Suite & Verification Script
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/storage_helper.php';
require_once __DIR__ . '/../includes/auth_check.php';

echo "=========================================================\n";
echo " LMS POLIKANT × KELAUTAN.ID - AUTOMATED TEST SUITE\n";
echo "=========================================================\n";

$db = get_db();
$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

// 1. Cek Tabel
$tables = ['pengaturan_sistem', 'users', 'tahun_akademik', 'mata_kuliah', 'enrollment', 'konten_mingguan', 'pengumpulan_tugas'];
foreach ($tables as $t) {
    try {
        $cnt = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        assert_test("Tabel {$t} ada dan dapat di-query", $cnt !== false, "Count: {$cnt}");
    } catch (Throwable $e) {
        assert_test("Tabel {$t} ada dan dapat di-query", false, $e->getMessage());
    }
}

// 2. Cek Kredensial Pengujian & Multi-Role Dosen (v1.1)
$testUsers = [
    'admin'   => ['pass' => 'dosen123', 'role' => 'Dosen Admin'],
    'dosen1'  => ['pass' => 'dosen123', 'role' => 'Dosen'],
    '2024001' => ['pass' => 'mhs123',   'role' => 'Mahasiswa'],
    '2024002' => ['pass' => 'mhs123',   'role' => 'Mahasiswa'],
];
foreach ($testUsers as $uname => $cfg) {
    $stmt = $db->prepare("SELECT password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$uname]);
    $row = $stmt->fetch();
    $validPass = $row && password_verify($cfg['pass'], $row['password_hash']);
    $validRole = $row && (strcasecmp($row['role'], $cfg['role']) === 0 || ($uname === 'admin' && in_array(strtolower($row['role']), ['dosen admin', 'dosen', 'admin'], true)));
    assert_test("Verifikasi user '{$uname}' (Role: {$row['role']})", $validPass && $validRole, "Password atau role tidak valid");
}

// 3. Cek Tahun Akademik Aktif
$activeTA = get_active_ta();
assert_test("Tahun Akademik Aktif terdefinisi", $activeTA !== null && $activeTA['status'] === 'Aktif', "TA: " . ($activeTA['nama_ta'] ?? 'None'));

// 4. Cek Branding Dinamis & Footer Config (v1.1)
$branding = get_branding();
assert_test("Branding: platform_name terkonfigurasi", !empty($branding['platform_name']), $branding['platform_name']);
assert_test("Branding: app_name terkonfigurasi", !empty($branding['app_name']), $branding['app_name']);
assert_test("Branding: url_portal terkonfigurasi", !empty($branding['url_portal']), $branding['url_portal']);
assert_test("Branding v1.1: footer_desc terkonfigurasi", !empty($branding['footer_desc']), $branding['footer_desc'] ?? '');
assert_test("Branding v1.1: contact_email terkonfigurasi", !empty($branding['contact_email']), $branding['contact_email'] ?? '');

// 4b. Cek Kolom Baru v1.1 (foto_profil & id_dosen)
try {
    $checkUserCols = $db->query("SELECT foto_profil FROM users LIMIT 1");
    assert_test("Kolom users.foto_profil tersedia", $checkUserCols !== false);
} catch (Throwable $e) {
    assert_test("Kolom users.foto_profil tersedia", false, $e->getMessage());
}

try {
    $checkMKCols = $db->query("SELECT id_dosen FROM mata_kuliah LIMIT 1");
    assert_test("Kolom mata_kuliah.id_dosen tersedia", $checkMKCols !== false);
} catch (Throwable $e) {
    assert_test("Kolom mata_kuliah.id_dosen tersedia", false, $e->getMessage());
}

try {
    $checkDMK = $db->query("SELECT id_mk, id_user, urutan FROM dosen_mata_kuliah LIMIT 1");
    assert_test("Tabel relasi dosen_mata_kuliah (Team Teaching) tersedia", $checkDMK !== false);
} catch (Throwable $e) {
    assert_test("Tabel relasi dosen_mata_kuliah (Team Teaching) tersedia", false, $e->getMessage());
}

// 5. Cek Mata Kuliah & Konten 16 Pertemuan
$mk = $db->query("SELECT * FROM mata_kuliah WHERE kode_mk = 'TKL-2101'")->fetch();
assert_test("Mata Kuliah TKL-2101 (Oseanografi Geologi) terdaftar", $mk !== false);

if ($mk) {
    $kontenCount = (int)$db->query("SELECT COUNT(*) FROM konten_mingguan WHERE id_mk = '{$mk['id_mk']}'")->fetchColumn();
    assert_test("Konten mingguan terisi (>= 5 modul)", $kontenCount >= 5, "Total modul: {$kontenCount}");

    $enrCount = (int)$db->query("SELECT COUNT(*) FROM enrollment WHERE id_mk = '{$mk['id_mk']}'")->fetchColumn();
    assert_test("Peserta kelas terdaftar (>= 2 mahasiswa)", $enrCount >= 2, "Total peserta: {$enrCount}");
}

// 6. Cek Penyimpanan Storage & Berkas RPS Contoh
$storageDirs = ['01_Materi_Perkuliahan', '02_RPS_dan_Silabus', '03_Pengumpulan_Tugas'];
foreach ($storageDirs as $sd) {
    $full = get_storage_path($sd);
    assert_test("Direktori storage/{$sd} ada dan writable", is_dir($full) && is_writable($full));
}

// 7. Simulasi Upload & Pengumpulan Tugas Mahasiswa
$dummyPdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R/Resources<<>>/Contents 4 0 R>>endobj\n4 0 obj<</Length 40>>stream\nBT /F1 12 Tf 50 780 Td (Test Submission) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\n0000000216 00000 n\ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n307\n%%EOF";
$tmpFile = tempnam(sys_get_temp_dir(), 'test_task_');
file_put_contents($tmpFile, $dummyPdf);

$fileArray = [
    'name'     => 'laporan_geologi.pdf',
    'tmp_name' => $tmpFile,
    'size'     => strlen($dummyPdf),
    'error'    => UPLOAD_ERR_OK,
];

// Simulasi upload dengan copy
$targetDir = get_storage_path('03_Pengumpulan_Tugas');
$destName = '2024001_Tugas_Batimetri_Test.pdf';
$destPath = $targetDir . '/' . $destName;
copy($tmpFile, $destPath);
unlink($tmpFile);

assert_test("Berkas pengumpulan tugas berhasil disimpan di storage/03_Pengumpulan_Tugas", file_exists($destPath));

// Catat pengumpulan di database
$idSubmit = generate_id('SUB', 'pengumpulan_tugas', 'id_submit');
if (get_active_db_driver() === 'sqlite') {
    $stmtSub = $db->prepare("INSERT OR REPLACE INTO pengumpulan_tugas (id_submit, id_konten, id_user, file_path, file_name, catatan_mhs, waktu_submit) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
} else {
    $stmtSub = $db->prepare("REPLACE INTO pengumpulan_tugas (id_submit, id_konten, id_user, file_path, file_name, catatan_mhs, waktu_submit) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
}
$stmtSub->execute([$idSubmit, 'KNT-00003', 'USR-00002', '03_Pengumpulan_Tugas/' . $destName, $destName, 'Catatan pengujian tugas batimetri']);

$checkSub = $db->query("SELECT COUNT(*) FROM pengumpulan_tugas WHERE id_user = 'USR-00002'")->fetchColumn();
assert_test("Record pengumpulan tugas tersimpan di tabel pengumpulan_tugas", (int)$checkSub > 0);

// 4.b Pengaturan Batas Ukuran Upload Berkas & Tautan Eksternal
assert_test("Batas upload materi (MB) terdefinisi", get_max_upload_materi_mb() >= 5 && get_max_upload_materi_mb() <= 100, "Nilai: " . get_max_upload_materi_mb() . " MB");
assert_test("Batas upload tugas (MB) terdefinisi", get_max_upload_tugas_mb() >= 2 && get_max_upload_tugas_mb() <= 100, "Nilai: " . get_max_upload_tugas_mb() . " MB");

// Simulasi Penilaian Dosen & Tautan Eksternal
$testDriveLink = 'https://drive.google.com/file/d/1A2B3C4D5E6F/view?usp=sharing';
$stmtGrade = $db->prepare("UPDATE pengumpulan_tugas SET link_eksternal = ?, nilai = 92.5, feedback = 'Analisis kontur sangat detail dan rapi.' WHERE id_submit = ?");
$stmtGrade->execute([$testDriveLink, $idSubmit]);
$graded = $db->query("SELECT nilai, feedback, link_eksternal FROM pengumpulan_tugas WHERE id_submit = '{$idSubmit}'")->fetch();
assert_test("Penilaian tugas (skor 92.5 & feedback) tersimpan", (float)$graded['nilai'] === 92.5 && !empty($graded['feedback']));
assert_test("Tautan link eksternal (Google Drive) tersimpan di pengumpulan_tugas", $graded['link_eksternal'] === $testDriveLink);

// Validasi Ekstensi Ditolak & Diizinkan
$fakeExe = tempnam(sys_get_temp_dir(), 'fake_exe_');
file_put_contents($fakeExe, 'binary content');
$resExe = handle_task_submission([
    'name' => 'malicious.exe',
    'tmp_name' => $fakeExe,
    'size' => 100,
    'error' => UPLOAD_ERR_OK
], '2024001', 'Uji Coba');
unlink($fakeExe);
assert_test("Penolakan berkas selain PDF/PPT/Word/Excel (.exe ditolak)", $resExe['success'] === false);

// 8. Verifikasi Fitur Sortir & Filter Angkatan Peserta Kelas
$mhsRows = $db->query("SELECT angkatan FROM users WHERE role = 'Mahasiswa'")->fetchAll(PDO::FETCH_COLUMN);
$uniqueAngkatan = array_values(array_unique(array_filter($mhsRows)));
assert_test("Data angkatan mahasiswa terdefinisi di database", !empty($uniqueAngkatan), "Angkatan ditemukan: " . implode(', ', $uniqueAngkatan));

// 9. Verifikasi Resolusi Ruang Kelas via Kode MK & Backward Compatibility (ID MK)
$sampleMK = $db->query("SELECT id_mk, kode_mk, nama_mk FROM mata_kuliah LIMIT 1")->fetch();
if ($sampleMK) {
    $stmtSlug = $db->prepare("SELECT id_mk, kode_mk FROM mata_kuliah WHERE kode_mk = ? OR id_mk = ? LIMIT 1");
    
    // Uji pencarian menggunakan kode_mk
    $stmtSlug->execute([$sampleMK['kode_mk'], $sampleMK['kode_mk']]);
    $foundByKode = $stmtSlug->fetch();
    assert_test("Resolusi kelas via Kode MK ({$sampleMK['kode_mk']})", $foundByKode && $foundByKode['id_mk'] === $sampleMK['id_mk']);

    // Uji backward compatibility menggunakan id_mk
    $stmtSlug->execute([$sampleMK['id_mk'], $sampleMK['id_mk']]);
    $foundById = $stmtSlug->fetch();
    assert_test("Backward compatibility kelas via ID MK ({$sampleMK['id_mk']})", $foundById && $foundById['kode_mk'] === $sampleMK['kode_mk']);
}

// 10. Pengujian Fitur v3.3 (Logo, Favicon, Footer Text, Pengingat Deadline)
assert_test("v3.3: Logo URL terdefinisi dengan fallback valid", !empty(get_logo_url()));
assert_test("v3.3: Favicon URL terdefinisi dengan fallback valid", !empty(get_favicon_url()));
assert_test("v3.3: Teks Footer sesuai format baru", str_contains((string)get_branding('footer_text'), 'Kelautan.ID - Portal Maritim Indonesia.') && !str_contains((string)get_branding('footer_text'), 'Politeknik Perikanan Negeri Tual. Dikembangkan'));

// 11. Pengujian Fitur Pengingat Deadline Mahasiswa
$stmtDeadlineTest = $db->prepare("
    SELECT km.id_konten, km.tipe, km.deadline, pt.id_submit
    FROM konten_mingguan km
    JOIN enrollment e ON e.id_mk = km.id_mk
    LEFT JOIN pengumpulan_tugas pt ON (pt.id_konten = km.id_konten AND pt.id_user = e.id_user)
    WHERE e.id_user = 'USR-00002'
      AND km.tipe IN ('Tugas', 'Kuis', 'Ujian')
      AND km.deadline IS NOT NULL
");
$stmtDeadlineTest->execute();
$deadlinesFound = $stmtDeadlineTest->fetchAll();
assert_test("v3.3: Query pengingat deadline mahasiswa berfungsi dan mendeteksi tugas/kuis", count($deadlinesFound) > 0);

// 12. Pengujian Fitur Login-As (User Impersonation) untuk Dosen Admin
assert_test("v3.3: Fungsi helper is_impersonating terdefinisi", function_exists('is_impersonating'));
assert_test("v3.3: Fungsi helper impersonator_user terdefinisi", function_exists('impersonator_user'));

// Simulasi alur Login-As Mahasiswa
$_SESSION['user_id'] = 'USR-00001';
$_SESSION['username'] = 'admin';
$_SESSION['nama'] = 'Administrator Sistem';
$_SESSION['role'] = 'Dosen Admin';
unset($_SESSION['impersonator']);

assert_test("v3.3: State awal adalah Dosen Admin", is_dosen_admin() && !is_impersonating());

// Impersonate Mahasiswa USR-00002
$targetMhs = $db->query("SELECT * FROM users WHERE username = '2024001'")->fetch();
$_SESSION['impersonator'] = [
    'user_id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'nama' => $_SESSION['nama'],
    'role' => $_SESSION['role'],
];
$_SESSION['user_id'] = $targetMhs['id_user'];
$_SESSION['username'] = $targetMhs['username'];
$_SESSION['nama'] = $targetMhs['nama'];
$_SESSION['role'] = 'Mahasiswa';

assert_test("v3.3: Login-As Mahasiswa aktif", is_impersonating() && is_mahasiswa() && !is_dosen_admin());
assert_test("v3.3: Data admin asli tersimpan di impersonator_user", impersonator_user()['username'] === 'admin');

// Revert kembali ke Admin
$orig = $_SESSION['impersonator'];
$_SESSION['user_id'] = $orig['user_id'];
$_SESSION['username'] = $orig['username'];
$_SESSION['nama'] = $orig['nama'];
$_SESSION['role'] = $orig['role'];
unset($_SESSION['impersonator']);

assert_test("v3.3: Revert kembali ke Dosen Admin dari Mahasiswa", is_dosen_admin() && !is_impersonating());

// Simulasi alur Login-As Dosen Pengampu
$targetDosen = $db->query("SELECT * FROM users WHERE username = 'dosen1'")->fetch();
$_SESSION['impersonator'] = [
    'user_id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'nama' => $_SESSION['nama'],
    'role' => $_SESSION['role'],
];
$_SESSION['user_id'] = $targetDosen['id_user'];
$_SESSION['username'] = $targetDosen['username'];
$_SESSION['nama'] = $targetDosen['nama'];
$_SESSION['role'] = 'Dosen';

assert_test("v3.3: Login-As Dosen aktif", is_impersonating() && is_dosen_biasa() && !is_dosen_admin());

// Revert kembali ke Admin dari Dosen
$orig = $_SESSION['impersonator'];
$_SESSION['user_id'] = $orig['user_id'];
$_SESSION['username'] = $orig['username'];
$_SESSION['nama'] = $orig['nama'];
$_SESSION['role'] = $orig['role'];
unset($_SESSION['impersonator']);

assert_test("v3.3: Revert kembali ke Dosen Admin dari Dosen", is_dosen_admin() && !is_impersonating());

// Test Safeguard: Jika impersonator tidak sengaja tertinggal dan sama dengan admin asli
$_SESSION['impersonator'] = [
    'user_id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'nama' => $_SESSION['nama'],
    'role' => $_SESSION['role'],
];
assert_test("v3.3: Safeguard auto-heal membersihkan impersonator saat user_id sama", !is_impersonating() && empty($_SESSION['impersonator']));

// 13. Pengujian Fitur Log Aktivitas & Audit Trail
assert_test("v3.3: Tabel log_aktivitas tersedia di database", (function() use ($db) {
    try {
        $db->query("SELECT COUNT(*) FROM log_aktivitas");
        return true;
    } catch (Throwable $e) {
        return false;
    }
})());

assert_test("v3.3: Fungsi log_activity terdefinisi", function_exists('log_activity'));

// Uji pencatatan log normal (Mahasiswa mengunggah tugas)
$_SESSION['user_id'] = 'USR-00002';
$_SESSION['username'] = '2024001';
$_SESSION['nama'] = 'Ahmad Kelautan';
$_SESSION['role'] = 'Mahasiswa';
unset($_SESSION['impersonator']);

log_activity('UPLOAD_TUGAS', "Mahasiswa mengunggah tugas 'Tugas 1: Batimetri'. Berkas: '2024001_Tugas_Batimetri.pdf'");

$lastLog = $db->query("SELECT * FROM log_aktivitas WHERE tipe_aktivitas = 'UPLOAD_TUGAS' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("v3.3: Log aktivitas upload tugas berhasil tersimpan", !empty($lastLog) && $lastLog['username'] === '2024001' && $lastLog['is_impersonated'] == 0);

// Uji pencatatan log saat dalam mode simulasi (Login-As)
$_SESSION['impersonator'] = [
    'user_id' => 'USR-00001',
    'username' => 'admin',
    'nama' => 'Administrator Sistem',
    'role' => 'Dosen Admin',
];
$_SESSION['user_id'] = 'USR-00002';
$_SESSION['username'] = '2024001';
$_SESSION['nama'] = 'Ahmad Kelautan';
$_SESSION['role'] = 'Mahasiswa';

log_activity('UPDATE_TUGAS', "Pengujian pembaruan tugas saat mode simulasi");

$impersonatedLog = $db->query("SELECT * FROM log_aktivitas WHERE tipe_aktivitas = 'UPDATE_TUGAS' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("v3.3: Log aktivitas simulasi (Login-As) mendeteksi impersonator_id", !empty($impersonatedLog) && $impersonatedLog['is_impersonated'] == 1 && $impersonatedLog['impersonator_id'] === 'USR-00001');

// 14. Pengujian Fitur Edit Konten Mingguan (v3.4)
$testKonten = $db->query("SELECT * FROM konten_mingguan LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($testKonten) {
    $newTitle = 'Morfologi Dasar Laut Terupdate (Testing Edit)';
    $upStmt = $db->prepare("UPDATE konten_mingguan SET judul = ? WHERE id_konten = ?");
    $upStmt->execute([$newTitle, $testKonten['id_konten']]);

    $checkUpdated = $db->query("SELECT judul FROM konten_mingguan WHERE id_konten = '{$testKonten['id_konten']}'")->fetchColumn();
    assert_test("v3.4: Edit konten mingguan berhasil memperbarui judul komponen di database", $checkUpdated === $newTitle);

    // Kembalikan judul asli
    $db->prepare("UPDATE konten_mingguan SET judul = ? WHERE id_konten = ?")->execute([$testKonten['judul'], $testKonten['id_konten']]);
}

// 15. Pengujian Fitur Rekap Nilai Tugas & Ekspor CSV (v3.5)
assert_test("v3.5: Berkas public_html/rekap-nilai.php tersedia", file_exists(__DIR__ . '/../public_html/rekap-nilai.php'));

$testCourse = $db->query("SELECT id_mk, kode_mk FROM mata_kuliah LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($testCourse) {
    // Pastikan query rekapitulasi nilai tugas berfungsi tanpa error SQL
    $stmtRekapTasks = $db->prepare("SELECT id_konten, minggu_ke, judul FROM konten_mingguan WHERE id_mk = ? AND tipe = 'Tugas'");
    $stmtRekapTasks->execute([$testCourse['id_mk']]);
    $tasksList = $stmtRekapTasks->fetchAll(PDO::FETCH_ASSOC);

    $stmtRekapMhs = $db->prepare("
        SELECT u.id_user, u.username, u.nama, pt.nilai
        FROM enrollment e
        JOIN users u ON e.id_user = u.id_user
        LEFT JOIN pengumpulan_tugas pt ON pt.id_user = u.id_user
        WHERE e.id_mk = ?
    ");
    $stmtRekapMhs->execute([$testCourse['id_mk']]);
    $mhsMatrix = $stmtRekapMhs->fetchAll(PDO::FETCH_ASSOC);

    assert_test("v3.5: Query matriks rekapitulasi nilai kelas berhasil dijalankan", is_array($mhsMatrix));
}

// 16. Pengujian Bank Soal & Sistem Kuis Interaktif (v3.6)
require_once __DIR__ . '/../includes/quiz_helper.php';

assert_test("v3.6: Berkas public_html/bank-soal.php tersedia", file_exists(__DIR__ . '/../public_html/bank-soal.php'));
assert_test("v3.6: Berkas public_html/kuis-kelola.php tersedia", file_exists(__DIR__ . '/../public_html/kuis-kelola.php'));
assert_test("v3.6: Berkas public_html/kuis-kerjakan.php tersedia", file_exists(__DIR__ . '/../public_html/kuis-kerjakan.php'));
assert_test("v3.6: Berkas public_html/kuis-nilai.php tersedia", file_exists(__DIR__ . '/../public_html/kuis-nilai.php'));

// Uji Pembuatan Butir Soal 4 Tipe (PG, Benar/Salah, Isian, Essay)
$testIdMK = $testCourse['id_mk'] ?? 'MK-00001';

// 1. Soal Pilihan Ganda (PG)
$idSoalPG = generate_id('SOL', 'bank_soal', 'id_soal');
$stmtInPG = $db->prepare("
    INSERT INTO bank_soal (id_soal, id_mk, id_dosen, tipe_soal, pertanyaan, bobot_default, created_at)
    VALUES (?, ?, 'USR-00001', 'pg', 'Alat oseanografi yang digunakan untuk mengukur salinitas, suhu, dan kedalaman air laut adalah?', 25.0, CURRENT_TIMESTAMP)
");
$stmtInPG->execute([$idSoalPG, $testIdMK]);

$idOpsA = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
$db->prepare("INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan) VALUES (?, ?, 'A', 'CTD Profiler', 1, 1)")->execute([$idOpsA, $idSoalPG]);
$idOpsB = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
$db->prepare("INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan) VALUES (?, ?, 'B', 'Secchi Disk', 0, 2)")->execute([$idOpsB, $idSoalPG]);

// 2. Soal Benar / Salah
$idSoalBS = generate_id('SOL', 'bank_soal', 'id_soal');
$db->prepare("
    INSERT INTO bank_soal (id_soal, id_mk, id_dosen, tipe_soal, pertanyaan, bobot_default, created_at)
    VALUES (?, ?, 'USR-00001', 'benar_salah', 'Palung Mariana merupakan titik terdalam di samudera bumi.', 25.0, CURRENT_TIMESTAMP)
")->execute([$idSoalBS, $testIdMK]);
$idOpsBS1 = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
$db->prepare("INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan) VALUES (?, ?, 'Benar', 'Pernyataan ini Benar', 1, 1)")->execute([$idOpsBS1, $idSoalBS]);
$idOpsBS2 = generate_id('OPS', 'bank_soal_opsi', 'id_opsi');
$db->prepare("INSERT INTO bank_soal_opsi (id_opsi, id_soal, label_opsi, teks_opsi, is_benar, urutan) VALUES (?, ?, 'Salah', 'Pernyataan ini Salah', 0, 2)")->execute([$idOpsBS2, $idSoalBS]);

// 3. Soal Isian Singkat
$idSoalIsian = generate_id('SOL', 'bank_soal', 'id_soal');
$db->prepare("
    INSERT INTO bank_soal (id_soal, id_mk, id_dosen, tipe_soal, pertanyaan, kunci_jawaban_teks, bobot_default, created_at)
    VALUES (?, ?, 'USR-00001', 'isian', 'Fenomena aliran massa air dari Samudera Pasifik menuju Hindia melalui selat di Indonesia disingkat sebagai?', 'Arlindo, Arus Lintas Indonesia', 25.0, CURRENT_TIMESTAMP)
")->execute([$idSoalIsian, $testIdMK]);

// 4. Soal Essay
$idSoalEssay = generate_id('SOL', 'bank_soal', 'id_soal');
$db->prepare("
    INSERT INTO bank_soal (id_soal, id_mk, id_dosen, tipe_soal, pertanyaan, kunci_jawaban_teks, bobot_default, created_at)
    VALUES (?, ?, 'USR-00001', 'essay', 'Jelaskan pengaruh pergerakan lempeng tektonik terhadap pembentukan morfologi dasar laut di kepulauan Maluku!', 'Penjelasan lempeng Pasifik, Indo-Australia, subduksi dan busur vulkanik', 25.0, CURRENT_TIMESTAMP)
")->execute([$idSoalEssay, $testIdMK]);

assert_test("v3.6: Pembuatan 4 tipe butir soal (PG, B/S, Isian, Essay) di Bank Soal berhasil", 
    !empty($idSoalPG) && !empty($idSoalBS) && !empty($idSoalIsian) && !empty($idSoalEssay)
);

// Uji Pembuatan Komponen Kuis & Perangkaian Soal
$idKontenKuis = generate_id('KNT', 'konten_mingguan', 'id_konten');
$db->prepare("
    INSERT INTO konten_mingguan (id_konten, id_mk, minggu_ke, tipe, judul, durasi_menit, created_at)
    VALUES (?, ?, 4, 'Kuis', 'Kuis Oseanografi Dasar & Morfologi Laut', 30, CURRENT_TIMESTAMP)
")->execute([$idKontenKuis, $testIdMK]);

// Rangkai 4 soal ke dalam kuis
$idKS1 = generate_id('KS', 'kuis_soal', 'id_kuis_soal');
$db->prepare("INSERT INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, 1, 25.0)")->execute([$idKS1, $idKontenKuis, $idSoalPG]);
$idKS2 = generate_id('KS', 'kuis_soal', 'id_kuis_soal');
$db->prepare("INSERT INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, 2, 25.0)")->execute([$idKS2, $idKontenKuis, $idSoalBS]);
$idKS3 = generate_id('KS', 'kuis_soal', 'id_kuis_soal');
$db->prepare("INSERT INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, 3, 25.0)")->execute([$idKS3, $idKontenKuis, $idSoalIsian]);
$idKS4 = generate_id('KS', 'kuis_soal', 'id_kuis_soal');
$db->prepare("INSERT INTO kuis_soal (id_kuis_soal, id_konten, id_soal, urutan, bobot_nilai) VALUES (?, ?, ?, 4, 25.0)")->execute([$idKS4, $idKontenKuis, $idSoalEssay]);

$countTerpasang = $db->query("SELECT COUNT(*) FROM kuis_soal WHERE id_konten = '{$idKontenKuis}'")->fetchColumn();
assert_test("v3.6: Perangkaian 4 soal dari Bank Soal ke Kuis Mingguan berhasil", (int)$countTerpasang === 4);

// Uji Simulasi Pengerjaan Kuis oleh Mahasiswa (USR-00003)
$idSesiTest = generate_id('SES', 'kuis_sesi', 'id_sesi');
$db->prepare("
    INSERT INTO kuis_sesi (id_sesi, id_konten, id_user, waktu_mulai, status)
    VALUES (?, ?, 'USR-00003', CURRENT_TIMESTAMP, 'sedang_mengerjakan')
")->execute([$idSesiTest, $idKontenKuis]);

// Simpan jawaban: PG benar ($idOpsA), B/S benar ($idOpsBS1), Isian benar ('arlindo'), Essay terisi
$idJaw1 = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
$db->prepare("INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, id_opsi_dipilih) VALUES (?, ?, ?, ?)")->execute([$idJaw1, $idSesiTest, $idSoalPG, $idOpsA]);
$idJaw2 = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
$db->prepare("INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, id_opsi_dipilih) VALUES (?, ?, ?, ?)")->execute([$idJaw2, $idSesiTest, $idSoalBS, $idOpsBS1]);
$idJaw3 = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
$db->prepare("INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, jawaban_teks) VALUES (?, ?, ?, ?)")->execute([$idJaw3, $idSesiTest, $idSoalIsian, 'arlindo']);
$idJaw4 = generate_id('JAW', 'kuis_jawaban', 'id_jawaban');
$db->prepare("INSERT INTO kuis_jawaban (id_jawaban, id_sesi, id_soal, jawaban_teks) VALUES (?, ?, ?, ?)")->execute([$idJaw4, $idSesiTest, $idSoalEssay, 'Zona subduksi lempeng menyebabkan terbentuknya palung dan palung Weber di Maluku.']);

// Uji Fungsi Auto-Grading (PG, B/S, Isian auto-graded, Essay pending)
$grading = hitung_nilai_kuis_sesi($db, $idSesiTest);
assert_test("v3.6: Auto-grading kuis berhasil (PG + B/S + Isian = 75 skor skala 100)", 
    (float)$grading['skor_skala_100'] === 75.0 && $grading['ada_essay'] === true && $grading['perlu_koreksi'] === true
);

// Uji Sinkronisasi ke Tabel Rekap Pengumpulan Tugas
sync_kuis_ke_pengumpulan_tugas($db, $idKontenKuis, 'USR-00003', (float)$grading['skor_skala_100'], 'Hasil Auto-Grading Kuis');
$syncedSubmit = $db->query("SELECT * FROM pengumpulan_tugas WHERE id_konten = '{$idKontenKuis}' AND id_user = 'USR-00003'")->fetch(PDO::FETCH_ASSOC);
assert_test("v3.6: Sinkronisasi nilai kuis ke pengumpulan_tugas (Rekap Nilai) berhasil", 
    !empty($syncedSubmit) && (float)$syncedSubmit['nilai'] === 75.0
);

// Uji Penilaian Manual Soal Essay oleh Dosen (memberikan 25 poin untuk essay)
$db->prepare("UPDATE kuis_jawaban SET nilai_butir = 25.0, status_koreksi = 'dinilai' WHERE id_jawaban = ?")->execute([$idJaw4]);
$gradingFinal = hitung_nilai_kuis_sesi($db, $idSesiTest);
assert_test("v3.6: Koreksi essay oleh dosen memperbarui skor total menjadi 100", 
    (float)$gradingFinal['skor_skala_100'] === 100.0 && $gradingFinal['perlu_koreksi'] === false
);

// Kembalikan session ke admin
unset($_SESSION['impersonator']);
$_SESSION['user_id'] = 'USR-00001';
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'Dosen Admin';

echo "=========================================================\n";
echo " HASIL PENGUJIAN: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
