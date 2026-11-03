<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Secure File Streaming (Mode Inline Preview & Download)
 * URL: unduh.php?jenis=rps|silabus|materi|tugas_mhs&id_mk=...&id_konten=...&id_submit=...&mode=inline|download
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

// Wajib login untuk mengunduh atau mempratinjau berkas
require_login();

$db = get_db();
$currentUser = current_user();
$isDosen = is_dosen();

$jenis = trim((string)($_GET['jenis'] ?? ''));
$mode = ($_GET['mode'] ?? 'inline') === 'download' ? 'download' : 'inline';

$filePath = null;
$displayName = 'dokumen.pdf';

try {
    if ($jenis === 'rps') {
        $idMK = trim((string)($_GET['id_mk'] ?? ''));
        $stmt = $db->prepare("SELECT rps_file_path, nama_mk, kode_mk FROM mata_kuliah WHERE id_mk = ? OR kode_mk = ?");
        $stmt->execute([$idMK, $idMK]);
        $row = $stmt->fetch();
        if ($row && !empty($row['rps_file_path'])) {
            $filePath = $row['rps_file_path'];
            $displayName = "RPS_{$row['kode_mk']}.pdf";
        }
    } elseif ($jenis === 'silabus') {
        $idMK = trim((string)($_GET['id_mk'] ?? ''));
        $stmt = $db->prepare("SELECT silabus_file_path, nama_mk, kode_mk FROM mata_kuliah WHERE id_mk = ? OR kode_mk = ?");
        $stmt->execute([$idMK, $idMK]);
        $row = $stmt->fetch();
        if ($row && !empty($row['silabus_file_path'])) {
            $filePath = $row['silabus_file_path'];
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $displayName = "Silabus_{$row['kode_mk']}.{$ext}";
        }
    } elseif ($jenis === 'materi') {
        $idKonten = trim((string)($_GET['id_konten'] ?? ''));
        $stmt = $db->prepare("SELECT file_path, judul, tipe FROM konten_mingguan WHERE id_konten = ?");
        $stmt->execute([$idKonten]);
        $row = $stmt->fetch();
        if ($row && !empty($row['file_path'])) {
            $filePath = $row['file_path'];
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $cleanTitle = sanitize_filename($row['judul']);
            $displayName = "{$cleanTitle}.{$ext}";
        }
    } elseif ($jenis === 'tugas_mhs') {
        $idSubmit = trim((string)($_GET['id_submit'] ?? ''));
        $stmt = $db->prepare("SELECT file_path, file_name, id_user FROM pengumpulan_tugas WHERE id_submit = ?");
        $stmt->execute([$idSubmit]);
        $row = $stmt->fetch();
        if ($row && !empty($row['file_path'])) {
            // Guard: Jika mahasiswa, hanya boleh melihat/mengunduh tugas miliknya sendiri
            if (!$isDosen && $row['id_user'] !== $currentUser['id_user']) {
                http_response_code(403);
                die("<h1>403 Forbidden</h1><p>Anda tidak memiliki akses melihat berkas tugas mahasiswa lain.</p>");
            }
            $filePath = $row['file_path'];
            $displayName = $row['file_name'];
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    die("<h1>500 Internal Server Error</h1><p>Gagal memproses permintaan berkas.</p>");
}

if (!$filePath) {
    http_response_code(404);
    die("<h1>404 Not Found</h1><p>Berkas yang Anda minta tidak ditemukan dalam database sistem.</p>");
}

// Cek keberadaan berkas fisik di storage
$fullPath = get_storage_path() . '/' . ltrim($filePath, '/');
if (!file_exists($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    die("<h1>404 Not Found</h1><p>Berkas fisik tidak ditemukan di direktori penyimpanan server.</p>");
}

// Tentukan MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($fullPath) ?: 'application/octet-stream';

// Bersihkan output buffer sebelum streaming berkas
if (ob_get_level()) {
    ob_end_clean();
}

// Set Header Keamanan & Streaming
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

$disposition = ($mode === 'download') ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . basename($displayName) . '"');

readfile($fullPath);
exit;
