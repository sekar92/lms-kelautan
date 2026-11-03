<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Handler Pengumpulan Berkas Jawaban Tugas Mahasiswa (Wajib PDF < 10 MB)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

require_role('Mahasiswa');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard');
    exit;
}

validate_csrf_or_abort();

$db = get_db();
$currentUser = current_user();

$idMK = trim((string)($_POST['id_mk'] ?? ''));
$idKonten = trim((string)($_POST['id_konten'] ?? ''));
$taskTitle = trim((string)($_POST['task_title'] ?? 'Tugas'));
$catatanMhs = trim((string)($_POST['catatan_mhs'] ?? ''));
$kodeMK = trim((string)($_POST['kode_mk'] ?? ''));

if ($idMK === '' || $idKonten === '') {
    set_flash('error', 'Parameter tugas tidak valid.');
    header('Location: /dashboard');
    exit;
}

if ($kodeMK === '') {
    $stmtMK = $db->prepare("SELECT kode_mk FROM mata_kuliah WHERE id_mk = ? LIMIT 1");
    $stmtMK->execute([$idMK]);
    $kodeMK = (string)$stmtMK->fetchColumn();
}
$urlSlug = !empty($kodeMK) ? $kodeMK : $idMK;

// Ambil link eksternal jika ada
$linkEksternal = trim((string)($_POST['link_eksternal'] ?? ''));

// Validasi format URL jika diisi
if ($linkEksternal !== '') {
    if (!filter_var($linkEksternal, FILTER_VALIDATE_URL)) {
        set_flash('error', 'Format tautan link eksternal tidak valid. Mohon sertakan protokol lengkap (contoh: https://drive.google.com/...).');
        header("Location: /kelas/{$urlSlug}/pertemuan");
        exit;
    }
}

// Cek apakah berkas diunggah
$hasFile = !empty($_FILES['jawaban_file']['name']) && (($_FILES['jawaban_file']['error'] ?? -1) !== UPLOAD_ERR_NO_FILE);

// Cek pengumpulan sebelumnya
$stmtCheck = $db->prepare("SELECT id_submit, file_path, file_name, link_eksternal FROM pengumpulan_tugas WHERE id_konten = ? AND id_user = ?");
$stmtCheck->execute([$idKonten, $currentUser['id_user']]);
$existing = $stmtCheck->fetch();

// Wajib menyertakan minimal salah satu: berkas fisik atau tautan eksternal
if (!$hasFile && $linkEksternal === '' && (!$existing || (empty($existing['file_path']) && empty($existing['link_eksternal'])))) {
    set_flash('error', 'Silakan pilih berkas jawaban tugas (PDF, PPT, Word, Excel) atau cantumkan tautan link eksternal (Google Drive / cloud storage).');
    header("Location: /kelas/{$urlSlug}/pertemuan");
    exit;
}

$filePath = $existing['file_path'] ?? null;
$fileName = $existing['file_name'] ?? null;

if ($hasFile) {
    $nim = $currentUser['username'];
    $uploadResult = handle_task_submission($_FILES['jawaban_file'], $nim, $taskTitle);
    if (!$uploadResult['success']) {
        set_flash('error', $uploadResult['error'] ?? 'Gagal mengunggah berkas tugas.');
        header("Location: /kelas/{$urlSlug}/pertemuan");
        exit;
    }
    $filePath = $uploadResult['path'];
    $fileName = $uploadResult['file_name'];
}

$finalLink = ($linkEksternal !== '') ? $linkEksternal : ($existing['link_eksternal'] ?? null);

try {
    if ($existing) {
        $stmtUp = $db->prepare("
            UPDATE pengumpulan_tugas 
            SET file_path = ?, file_name = ?, link_eksternal = ?, catatan_mhs = ?, waktu_submit = CURRENT_TIMESTAMP
            WHERE id_submit = ?
        ");
        $stmtUp->execute([
            $filePath,
            $fileName,
            $finalLink,
            $catatanMhs,
            $existing['id_submit']
        ]);
        // Ambil nama mata kuliah untuk log aktivitas
        $stmtMKName = $db->prepare("SELECT nama_mk FROM mata_kuliah WHERE id_mk = ? LIMIT 1");
        $stmtMKName->execute([$idMK]);
        $mkName = (string)$stmtMKName->fetchColumn() ?: 'Mata Kuliah';

        $desc = "Mahasiswa '{$currentUser['nama']}' (NIM: {$currentUser['username']}) memperbarui tugas '{$taskTitle}' [Kelas: {$mkName}]. " .
            ($hasFile ? "Berkas: '{$fileName}'" : "Berkas lama dipertahankan") .
            (!empty($finalLink) ? " | Tautan: {$finalLink}" : "") .
            (!empty($catatanMhs) ? " | Catatan: {$catatanMhs}" : "");
        log_activity('UPDATE_TUGAS', $desc);

        set_flash('success', "Berkas jawaban & tautan tugas '{$taskTitle}' berhasil diperbarui!");
    } else {
        $idSubmit = generate_id('SUB', 'pengumpulan_tugas', 'id_submit');
        $stmtIns = $db->prepare("
            INSERT INTO pengumpulan_tugas (id_submit, id_konten, id_user, file_path, file_name, link_eksternal, catatan_mhs, waktu_submit)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmtIns->execute([
            $idSubmit,
            $idKonten,
            $currentUser['id_user'],
            $filePath,
            $fileName,
            $finalLink,
            $catatanMhs
        ]);

        // Ambil nama mata kuliah untuk log aktivitas
        $stmtMKName = $db->prepare("SELECT nama_mk FROM mata_kuliah WHERE id_mk = ? LIMIT 1");
        $stmtMKName->execute([$idMK]);
        $mkName = (string)$stmtMKName->fetchColumn() ?: 'Mata Kuliah';

        $desc = "Mahasiswa '{$currentUser['nama']}' (NIM: {$currentUser['username']}) berhasil mengunggah tugas '{$taskTitle}' [Kelas: {$mkName}]. " .
            ($hasFile ? "Berkas: '{$fileName}'" : "Tanpa berkas fisik") .
            (!empty($finalLink) ? " | Tautan: {$finalLink}" : "") .
            (!empty($catatanMhs) ? " | Catatan: {$catatanMhs}" : "");
        log_activity('UPLOAD_TUGAS', $desc);

        set_flash('success', "Jawaban tugas '{$taskTitle}' berhasil dikumpulkan ke server Kelautan.ID!");
    }
} catch (Throwable $e) {
    set_flash('error', 'Gagal mencatat status pengumpulan tugas di database: ' . $e->getMessage());
}

header("Location: /kelas/{$urlSlug}/pertemuan");
exit;
