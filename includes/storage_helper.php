<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Helper Penyimpanan Berkas & Validasi MIME
 */

declare(strict_types=1);

function get_storage_path(string $subdir = ''): string {
    $base = dirname(__DIR__) . '/storage';
    if ($subdir !== '') {
        $base .= '/' . ltrim($subdir, '/');
    }
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

function sanitize_filename(string $filename): string {
    // Hilangkan karakter non-alfanumerik berbahaya
    $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    $clean = preg_replace('/_+/', '_', (string)$clean);
    return trim((string)$clean, '_');
}

/**
 * Validasi MIME Type Asli Menggunakan finfo_file
 */
function validate_file_mime(string $tmpPath, array $allowedMimes): bool {
    if (!file_exists($tmpPath)) {
        return false;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    return in_array($mime, $allowedMimes, true);
}

/**
 * Format Pesan Error Upload PHP yang Informatif
 */
function format_upload_error(int $errCode, string $context = 'berkas'): string {
    $iniLimit = ini_get('upload_max_filesize') ?: '2M';
    return match ($errCode) {
        UPLOAD_ERR_INI_SIZE => "Ukuran {$context} melebihi batas konfigurasi upload server (upload_max_filesize saat ini: {$iniLimit}). Pastikan nilai upload_max_filesize & post_max_size pada php.ini atau .user.ini telah dinaikkan (disarankan 25M).",
        UPLOAD_ERR_FORM_SIZE => "Ukuran {$context} melebihi batas ukuran formulir.",
        UPLOAD_ERR_PARTIAL => "Proses unggah {$context} terputus dan hanya terkirim sebagian. Silakan periksa koneksi dan coba lagi.",
        UPLOAD_ERR_NO_FILE => "Tidak ada {$context} yang dipilih untuk diunggah.",
        UPLOAD_ERR_NO_TMP_DIR => "Direktori temporary upload server tidak ditemukan.",
        UPLOAD_ERR_CANT_WRITE => "Server gagal menulis berkas ke disk temporary (periksa izin /tmp atau partisi disk).",
        default => "Gagal mengunggah {$context} (Kode status PHP: {$errCode})."
    };
}

require_once __DIR__ . '/../config/branding.php';

/**
 * Upload Jawaban Tugas Mahasiswa (PDF, PPT, Word, Excel)
 * Batas ukuran disesuaikan dengan pengaturan sistem (default 10 MB)
 * Nama file otomatis: [NIM]_[Nama_Tugas_Sanitized].[ext]
 */
function handle_task_submission(array $file, string $nim, string $taskTitle): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => format_upload_error($file['error'] ?? -1, 'tugas')];
    }

    $maxMb = function_exists('get_max_upload_tugas_mb') ? get_max_upload_tugas_mb() : 10;
    $maxSize = $maxMb * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => "Ukuran berkas melebihi batas maksimum {$maxMb} MB."];
    }

    // Validasi ekstensi berkas yang diizinkan: PDF, PPT, Word, Excel
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'error' => "Format berkas .{$ext} tidak diizinkan. Tugas hanya boleh berformat PDF, PPT, Word (.doc/.docx), atau Excel (.xls/.xlsx)."];
    }

    $sanitizedTitle = sanitize_filename(str_replace(' ', '_', $taskTitle));
    $cleanNim = sanitize_filename($nim);
    $finalFilename = sprintf('%s_%s_%s.%s', $cleanNim, $sanitizedTitle, date('Ymd_His'), $ext);

    $targetDir = get_storage_path('03_Pengumpulan_Tugas');
    $destination = $targetDir . '/' . $finalFilename;
    $relativePath = '03_Pengumpulan_Tugas/' . $finalFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Gagal memindahkan berkas ke direktori penyimpanan server.'];
    }

    @chmod($destination, 0664);

    return [
        'success'   => true,
        'path'      => $relativePath,
        'file_name' => $finalFilename,
        'original'  => $file['name'],
        'ext'       => $ext,
    ];
}

/**
 * Upload RPS / Silabus Mata Kuliah
 */
function handle_curriculum_upload(array $file, string $type, string $kodeMk): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => format_upload_error($file['error'] ?? -1, 'kurikulum')];
    }

    $maxSize = 15 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Ukuran berkas melebihi 15 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ($type === 'rps') ? ['pdf'] : ['pdf', 'docx', 'doc'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'error' => "Format berkas {$ext} tidak didukung untuk {$type}."];
    }

    $cleanKode = sanitize_filename($kodeMk);
    $finalFilename = sprintf('%s_%s_%s.%s', strtoupper($type), $cleanKode, date('Ymd_His'), $ext);

    $targetDir = get_storage_path('02_RPS_dan_Silabus');
    $destination = $targetDir . '/' . $finalFilename;
    $relativePath = '02_RPS_dan_Silabus/' . $finalFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Gagal memindahkan berkas kurikulum.'];
    }

    @chmod($destination, 0664);

    return [
        'success'   => true,
        'path'      => $relativePath,
        'file_name' => $finalFilename,
    ];
}

/**
 * Upload Materi / Lampiran Konten Mingguan (PPT, PDF, Word, Excel Maks 10 MB)
 */
function handle_material_upload(array $file, string $idMk, int $minggu): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => format_upload_error($file['error'] ?? -1, 'materi perkuliahan')];
    }

    $maxMb = function_exists('get_max_upload_materi_mb') ? get_max_upload_materi_mb() : 25;
    $maxSize = $maxMb * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => "Ukuran berkas melebihi batas maksimum {$maxMb} MB."];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = [
        'pdf', 
        'pptx', 'ppt', 
        'docx', 'doc', 
        'xlsx', 'xls', 'csv', 
        'zip', 'rar', 
        'mp4', 'png', 'jpg', 'jpeg'
    ];
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'error' => "Ekstensi berkas .{$ext} tidak diizinkan. Harap unggah berkas PPT, PDF, Word, atau Excel."];
    }

    $cleanOrig = sanitize_filename(pathinfo($file['name'], PATHINFO_FILENAME));
    $finalFilename = sprintf('M%02d_%s_%s.%s', $minggu, $cleanOrig, date('Ymd_His'), $ext);

    $targetDir = get_storage_path('01_Materi_Perkuliahan');
    $destination = $targetDir . '/' . $finalFilename;
    $relativePath = '01_Materi_Perkuliahan/' . $finalFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Gagal memindahkan berkas materi ke direktori penyimpanan server.'];
    }

    @chmod($destination, 0664);

    return [
        'success'   => true,
        'path'      => $relativePath,
        'file_name' => $finalFilename,
        'original'  => $file['name'],
        'size'      => $file['size'],
        'ext'       => $ext,
    ];
}

/**
 * Upload Foto Profil Pengguna (JPG, PNG, WEBP Maks 2 MB)
 */
function handle_avatar_upload(array $file, string $userId, ?string $oldPath = null): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => format_upload_error($file['error'] ?? -1, 'foto profil')];
    }

    $maxSize = 2 * 1024 * 1024; // 2 MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Ukuran foto profil melebihi 2 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'error' => 'Format foto tidak didukung. Harap gunakan JPG, PNG, atau WEBP.'];
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validate_file_mime($file['tmp_name'], $allowedMimes)) {
        return ['success' => false, 'error' => 'Format berkas gambar tidak valid atau korup.'];
    }

    $targetDir = dirname(__DIR__) . '/public_html/assets/uploads/avatars';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    $cleanUserId = sanitize_filename($userId);
    $finalFilename = sprintf('avatar_%s_%s.%s', $cleanUserId, time(), $ext);
    $destination = $targetDir . '/' . $finalFilename;
    $webPath = '/assets/uploads/avatars/' . $finalFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Gagal menyimpan foto profil ke direktori server.'];
    }

    @chmod($destination, 0664);

    // Hapus foto lama jika ada
    if ($oldPath && !str_contains($oldPath, '..')) {
        $oldFile = dirname(__DIR__) . '/public_html/' . ltrim($oldPath, '/');
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    return [
        'success'   => true,
        'path'      => $webPath,
        'file_name' => $finalFilename,
    ];
}

/**
 * Upload Logo & Favicon Kustom Brand
 */
function handle_branding_upload(array $file, string $type = 'logo', ?string $oldPath = null): array {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => format_upload_error($file['error'] ?? -1, $type)];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($type === 'logo') {
        $allowedExts = ['svg', 'png', 'jpg', 'jpeg', 'webp'];
        $maxSize = 4 * 1024 * 1024; // 4 MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'Ukuran berkas logo melebihi 4 MB.'];
        }
        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'error' => 'Format logo tidak diizinkan. Gunakan SVG, PNG, JPG, atau WEBP.'];
        }
    } else { // favicon
        $allowedExts = ['ico', 'png', 'svg'];
        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'Ukuran berkas favicon melebihi 2 MB.'];
        }
        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'error' => 'Format favicon tidak diizinkan. Gunakan ICO, PNG, atau SVG.'];
        }
    }

    $targetDir = dirname(__DIR__) . '/public_html/assets/uploads/branding';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    $finalFilename = sprintf('%s_%s.%s', $type, date('Ymd_His'), $ext);
    $destination = $targetDir . '/' . $finalFilename;
    $webPath = '/assets/uploads/branding/' . $finalFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => "Gagal menyimpan berkas {$type} ke direktori server."];
    }

    @chmod($destination, 0664);

    // Hapus berkas lama jika ada di uploads/branding
    if ($oldPath && str_starts_with($oldPath, '/assets/uploads/branding/') && !str_contains($oldPath, '..')) {
        $oldFile = dirname(__DIR__) . '/public_html/' . ltrim($oldPath, '/');
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    return [
        'success'   => true,
        'path'      => $webPath,
        'file_name' => $finalFilename,
    ];
}
