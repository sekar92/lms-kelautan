<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Development Router for PHP Built-in Server
 * Usage: php -S localhost:8000 router.php
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDir = __DIR__ . '/public_html';
$requestedFile = $publicDir . $uri;

// 1. Sajikan langsung jika berkas statis fisik ada (CSS, JS, SVG, gambar, font)
if ($uri !== '/' && file_exists($requestedFile) && !is_dir($requestedFile)) {
    // Biarkan built-in server menangani MIME type berkas statis
    return false;
}

// 2. Beranda Utama (/)
if ($uri === '/' || $uri === '') {
    require $publicDir . '/index.php';
    exit;
}

// 3. Routing Cantik Kelas: /kelas/{id_mk}/{tab}
if (preg_match('#^/kelas/([^/]+)/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    $_GET['tab'] = $matches[2];
    require $publicDir . '/ruang-kelas.php';
    exit;
}

// 4. Routing Cantik Kelas: /kelas/{id_mk}
if (preg_match('#^/kelas/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    require $publicDir . '/ruang-kelas.php';
    exit;
}

// 5. Routing Cantik Nilai Tugas: /nilai/{id_konten}
if (preg_match('#^/nilai/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id_konten'] = $matches[1];
    require $publicDir . '/tugas-nilai.php';
    exit;
}

// 5b. Routing Cantik Kuis: Kelola, Kerjakan, Nilai
if (preg_match('#^/kuis/kelola/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id_konten'] = $matches[1];
    require $publicDir . '/kuis-kelola.php';
    exit;
}

if (preg_match('#^/kuis/kerjakan/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id_konten'] = $matches[1];
    require $publicDir . '/kuis-kerjakan.php';
    exit;
}

if (preg_match('#^/kuis/nilai/([^/]+)/?$#', $uri, $matches)) {
    $_GET['id_konten'] = $matches[1];
    require $publicDir . '/kuis-nilai.php';
    exit;
}

// 6. Clean URL tanpa ekstensi .php (misal: /dashboard -> public_html/dashboard.php)
$cleanPath = trim($uri, '/');
$targetPhp = $publicDir . '/' . $cleanPath . '.php';
if (file_exists($targetPhp)) {
    require $targetPhp;
    exit;
}

// 7. Jika ada permintaan langsung ke berkas .php
if (file_exists($requestedFile) && str_ends_with($requestedFile, '.php')) {
    require $requestedFile;
    exit;
}

// 8. 404 Not Found
http_response_code(404);
echo "<!DOCTYPE html><html lang='id'><head><meta charset='utf-8'><title>404 Halaman Tidak Ditemukan</title></head><body style='font-family:sans-serif;text-align:center;padding:50px;'>";
echo "<h1>404 — Halaman Tidak Ditemukan</h1><p>Halaman atau rute <code>" . htmlspecialchars($uri) . "</code> tidak tersedia di LMS Kelautan.ID.</p>";
echo "<a href='/dashboard' style='color:#0284c7;text-decoration:none;font-weight:bold;'>&larr; Kembali ke Dashboard</a></body></html>";
exit;
