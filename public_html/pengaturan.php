<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Pengaturan Branding Dinamis, Informasi Footer, & Pemeliharaan Database (Khusus Dosen Admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

// Guard: Hanya Dosen Admin yang berhak mengakses pengaturan
require_dosen_admin();

$db = get_db();
$currentUser = current_user();

// -------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    // 1. Simpan Pengaturan Branding & Footer
    if ($action === 'save_branding') {
        $keys = [
            'platform_name', 'app_name', 'app_tagline', 'institution_name',
            'study_program', 'url_portal', 'url_journal', 'url_shortener',
            'support_email', 'footer_text',
            'footer_desc', 'footer_links_title',
            'footer_link1_label', 'footer_link1_url',
            'footer_link2_label', 'footer_link2_url',
            'footer_link3_label', 'footer_link3_url',
            'contact_title', 'contact_email', 'contact_address', 'contact_person',
            'max_upload_materi_mb', 'max_upload_tugas_mb'
        ];

        try {
            $driver = get_active_db_driver();
            if ($driver === 'sqlite') {
                $stmt = $db->prepare("INSERT OR REPLACE INTO pengaturan_sistem (setting_key, setting_value) VALUES (?, ?)");
            } else {
                $stmt = $db->prepare("REPLACE INTO pengaturan_sistem (setting_key, setting_value) VALUES (?, ?)");
            }

            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $val = trim((string)$_POST[$k]);
                    $stmt->execute([$k, $val]);
                }
            }

            // Upload Custom Logo
            if (!empty($_FILES['custom_logo']['name']) && (($_FILES['custom_logo']['error'] ?? -1) === UPLOAD_ERR_OK)) {
                $currentLogo = get_branding('custom_logo_url');
                $upLogo = handle_branding_upload($_FILES['custom_logo'], 'logo', $currentLogo);
                if ($upLogo['success']) {
                    $stmt->execute(['custom_logo_url', $upLogo['path']]);
                } else {
                    set_flash('error', $upLogo['error']);
                }
            } elseif (isset($_POST['reset_logo']) && $_POST['reset_logo'] === '1') {
                $stmt->execute(['custom_logo_url', '']);
            }

            // Upload Custom Favicon
            if (!empty($_FILES['custom_favicon']['name']) && (($_FILES['custom_favicon']['error'] ?? -1) === UPLOAD_ERR_OK)) {
                $currentFavicon = get_branding('custom_favicon_url');
                $upFavicon = handle_branding_upload($_FILES['custom_favicon'], 'favicon', $currentFavicon);
                if ($upFavicon['success']) {
                    $stmt->execute(['custom_favicon_url', $upFavicon['path']]);
                } else {
                    set_flash('error', $upFavicon['error']);
                }
            } elseif (isset($_POST['reset_favicon']) && $_POST['reset_favicon'] === '1') {
                $stmt->execute(['custom_favicon_url', '']);
            }

            set_flash('success', 'Identitas platform, logo, favicon, informasi footer, dan kontak berhasil diperbarui!');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
        }

        header('Location: /pengaturan');
        exit;
    }

    // 2. Download Backup Database SQL
    if ($action === 'backup_sql') {
        try {
            $tables = ['pengaturan_sistem', 'users', 'tahun_akademik', 'mata_kuliah', 'enrollment', 'konten_mingguan', 'pengumpulan_tugas'];
            $sqlDump = "-- ==========================================================\n";
            $sqlDump .= "-- LMS POLIKANT x KELAUTAN.ID - DATABASE BACKUP\n";
            $sqlDump .= "-- Exported on: " . date('Y-m-d H:i:s') . "\n";
            $sqlDump .= "-- ==========================================================\n\n";

            foreach ($tables as $t) {
                $rows = $db->query("SELECT * FROM {$t}")->fetchAll();
                $sqlDump .= "-- Table: {$t}\n";
                foreach ($rows as $r) {
                    $cols = array_keys($r);
                    $vals = array_map(function($v) use ($db) {
                        return $v === null ? "NULL" : $db->quote((string)$v);
                    }, array_values($r));
                    $sqlDump .= "INSERT INTO {$t} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sqlDump .= "\n";
            }

            $filename = 'backup_lms_kelautan_' . date('Ymd_His') . '.sql';
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sqlDump));
            echo $sqlDump;
            exit;
        } catch (Throwable $e) {
            set_flash('error', 'Gagal membuat backup database: ' . $e->getMessage());
            header('Location: /pengaturan');
            exit;
        }
    }

    // 3. Reset / Inisialisasi Ulang Database (Memerlukan Konfirmasi Kata Sandi)
    if ($action === 'reset_database') {
        $confirmPassword = (string)($_POST['admin_password'] ?? '');
        
        $stmtAdmin = $db->prepare("SELECT password_hash FROM users WHERE id_user = ?");
        $stmtAdmin->execute([$currentUser['id_user']]);
        $rowAdmin = $stmtAdmin->fetch();

        if (!$rowAdmin || !password_verify($confirmPassword, $rowAdmin['password_hash'])) {
            set_flash('error', 'Konfirmasi kata sandi salah. Reset database dibatalkan demi keamanan.');
            header('Location: /pengaturan');
            exit;
        }

        try {
            // Jalankan ulang skrip CLI setup
            ob_start();
            require __DIR__ . '/../cli/setup.php';
            ob_end_clean();

            set_flash('success', 'Database berhasil direset dan diinisialisasi ulang ke data standar!');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal mereset database: ' . $e->getMessage());
        }

        header('Location: /pengaturan');
        exit;
    }
}

$branding = get_branding();
$pageTitle = 'Pengaturan Branding, Footer, & Database';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Page Header -->
    <div class="pb-4 border-b border-slate-200">
        <div class="flex items-center space-x-2 text-xs font-semibold text-cyan-700 uppercase tracking-wider">
            <i data-lucide="sliders" class="w-4 h-4"></i>
            <span>Konfigurasi Platform (Khusus Dosen Admin)</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 font-heading mt-1">
            Pengaturan Platform, Footer, & Database
        </h1>
        <p class="text-sm text-slate-500 mt-1">
            Kelola integrasi portal Kelautan.ID, teks dan tautan footer, kontak narahubung, serta pemeliharaan cadangan database.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Kolom Form Branding Dinamis (8/12) -->
        <div class="lg:col-span-8 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-8">
            
            <form action="/pengaturan" method="POST" enctype="multipart/form-data" class="space-y-8">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_branding">

                <!-- BAGIAN 1: IDENTITAS & BRANDING UTAMA -->
                <div class="space-y-4">
                    <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-maritime-700 flex items-center justify-center">
                            <i data-lucide="palette" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900 font-heading">
                                Identitas Platform & Institusi
                            </h2>
                            <p class="text-xs text-slate-500">
                                Atribut yang tampil di judul halaman, navbar, dan kartu utama
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="platform_name" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                                Nama Platform Ekosistem
                            </label>
                            <input id="platform_name" name="platform_name" type="text" required
                                   value="<?= htmlspecialchars($branding['platform_name']) ?>"
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                        </div>
                        <div>
                            <label for="app_name" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                                Nama Aplikasi LMS
                            </label>
                            <input id="app_name" name="app_name" type="text" required
                                   value="<?= htmlspecialchars($branding['app_name']) ?>"
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                        </div>
                    </div>

                    <div>
                        <label for="app_tagline" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                            Slogan / Tagline Resmi
                        </label>
                        <input id="app_tagline" name="app_tagline" type="text" required
                               value="<?= htmlspecialchars($branding['app_tagline']) ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="institution_name" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                                Nama Perguruan Tinggi
                            </label>
                            <input id="institution_name" name="institution_name" type="text" required
                                   value="<?= htmlspecialchars($branding['institution_name']) ?>"
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                        </div>
                        <div>
                            <label for="study_program" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                                Program Studi
                            </label>
                            <input id="study_program" name="study_program" type="text" required
                                   value="<?= htmlspecialchars($branding['study_program']) ?>"
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                        </div>
                    </div>

                    <!-- Upload Logo & Favicon Platform -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                        <!-- Custom Logo -->
                        <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200 space-y-3">
                            <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider">
                                Logo Platform
                            </label>
                            <div class="flex items-center space-x-3">
                                <div class="w-16 h-12 rounded-xl bg-abyssal-900 border border-slate-700 flex items-center justify-center p-2 shrink-0 shadow-inner">
                                    <img src="<?= htmlspecialchars(get_logo_url()) ?>" alt="Preview Logo" class="max-h-full max-w-full object-contain">
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    <p class="font-medium text-slate-700">Tampil di login & platform</p>
                                    <p>SVG, PNG, JPG, WEBP (Maks 4 MB)</p>
                                </div>
                            </div>
                            <input type="file" name="custom_logo" accept=".svg,.png,.jpg,.jpeg,.webp" 
                                   class="block w-full text-xs text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-100 file:text-cyan-800 hover:file:bg-cyan-200 cursor-pointer">
                            <?php if (!empty($branding['custom_logo_url'])): ?>
                                <label class="inline-flex items-center space-x-2 text-xs text-rose-600 cursor-pointer pt-1">
                                    <input type="checkbox" name="reset_logo" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                    <span>Reset ke Logo Standar Kelautan.ID</span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <!-- Custom Favicon -->
                        <div class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200 space-y-3">
                            <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider">
                                Favicon Tab Browser
                            </label>
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-xl bg-white border border-slate-200 flex items-center justify-center p-2 shrink-0 shadow-sm">
                                    <img src="<?= htmlspecialchars(get_favicon_url()) ?>" alt="Preview Favicon" class="w-6 h-6 object-contain">
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    <p class="font-medium text-slate-700">Ikon di tab peramban</p>
                                    <p>ICO, PNG, SVG (Maks 2 MB)</p>
                                </div>
                            </div>
                            <input type="file" name="custom_favicon" accept=".ico,.png,.svg" 
                                   class="block w-full text-xs text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-100 file:text-cyan-800 hover:file:bg-cyan-200 cursor-pointer">
                            <?php if (!empty($branding['custom_favicon_url'])): ?>
                                <label class="inline-flex items-center space-x-2 text-xs text-rose-600 cursor-pointer pt-1">
                                    <input type="checkbox" name="reset_favicon" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                    <span>Reset ke Favicon Standar</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 2: KAPASITAS & BATAS MAKSIMAL UPLOAD BERKAS -->
                <div class="space-y-5 pt-4 border-t border-slate-200">
                    <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center">
                            <i data-lucide="sliders" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900 font-heading">
                                Kapasitas & Batas Ukuran Upload Berkas
                            </h2>
                            <p class="text-xs text-slate-500">
                                Atur batas maksimum berkas unggahan per-file (Konfigurasi PHP VPS mengizinkan hingga 50 MB)
                            </p>
                        </div>
                    </div>

                    <!-- Alert Info Dukungan PHP VPS -->
                    <div class="p-4 rounded-2xl bg-amber-50/80 border border-amber-200 text-amber-900 text-xs flex items-start space-x-3">
                        <i data-lucide="info" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
                        <div class="space-y-1">
                            <p class="font-semibold text-amber-950">Dukungan Konfigurasi VPS (Hingga 50 MB):</p>
                            <p class="text-amber-800 leading-relaxed">
                                Server PHP VPS Anda telah mendukung ukuran berkas besar. Geser slider di bawah ini untuk menentukan limit aplikasi yang ideal agar kapasitas server dan kuota bandwidth tetap efisien.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Slider 1: Materi Dosen -->
                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label for="slider_upload_materi" class="text-xs font-bold text-slate-900 block">
                                        Upload Konten & Materi Dosen
                                    </label>
                                    <span class="text-[11px] text-slate-500">PPT, PDF, Word, Excel, Video, ZIP</span>
                                </div>
                                <span id="badge_upload_materi" class="px-3 py-1 rounded-full text-xs font-bold bg-maritime-50 text-maritime-700 border border-maritime-200 shadow-sm">
                                    <?= (int)($branding['max_upload_materi_mb'] ?? 25) ?> MB
                                </span>
                            </div>

                            <div class="space-y-2">
                                <input id="slider_upload_materi" name="max_upload_materi_mb" type="range" 
                                       min="5" max="50" step="1" 
                                       value="<?= (int)($branding['max_upload_materi_mb'] ?? 25) ?>"
                                       class="w-full h-2.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-maritime-700 focus:outline-none"
                                       oninput="document.getElementById('badge_upload_materi').textContent = this.value + ' MB'">
                                <div class="flex justify-between text-[10px] text-slate-600 font-semibold px-0.5">
                                    <span>5 MB</span>
                                    <span>25 MB (Standar)</span>
                                    <span>50 MB (Maks)</span>
                                </div>
                            </div>

                            <p class="text-[11px] text-slate-600 leading-normal">
                                Memungkinkan dosen mengunggah bahan kuliah berukuran besar, modul slide beresolusi tinggi, hingga 50 MB.
                            </p>
                        </div>

                        <!-- Slider 2: Tugas Mahasiswa -->
                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label for="slider_upload_tugas" class="text-xs font-bold text-slate-900 block">
                                        Upload Tugas Mahasiswa
                                    </label>
                                    <span class="text-[11px] text-slate-500">Khusus PDF, PPT, Word, Excel</span>
                                </div>
                                <span id="badge_upload_tugas" class="px-3 py-1 rounded-full text-xs font-bold bg-cyan-50 text-cyan-800 border border-cyan-200 shadow-sm">
                                    <?= (int)($branding['max_upload_tugas_mb'] ?? 10) ?> MB
                                </span>
                            </div>

                            <div class="space-y-2">
                                <input id="slider_upload_tugas" name="max_upload_tugas_mb" type="range" 
                                       min="2" max="50" step="1" 
                                       value="<?= (int)($branding['max_upload_tugas_mb'] ?? 10) ?>"
                                       class="w-full h-2.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-cyan-600 focus:outline-none"
                                       oninput="document.getElementById('badge_upload_tugas').textContent = this.value + ' MB'">
                                <div class="flex justify-between text-[10px] text-slate-600 font-semibold px-0.5">
                                    <span>2 MB</span>
                                    <span>10 MB (Standar)</span>
                                    <span>50 MB (Maks)</span>
                                </div>
                            </div>

                            <p class="text-[11px] text-slate-600 leading-normal">
                                Batas maksimal berkas jawaban tugas mahasiswa. Hanya berkas PDF, PPT, Word, atau Excel yang diizinkan sistem.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 3: PENGATURAN FOOTER LENGKAP -->
                <div class="space-y-4 pt-4 border-t border-slate-200">
                    <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                        <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 flex items-center justify-center">
                            <i data-lucide="layout" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900 font-heading">
                                Informasi & Teks Footer
                            </h2>
                            <p class="text-xs text-slate-500">
                                Sesuaikan deskripsi platform, tautan cepat, dan informasi kontak di bagian bawah seluruh halaman
                            </p>
                        </div>
                    </div>

                    <div>
                        <label for="footer_desc" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                            Uraian / Deskripsi Platform di Footer
                        </label>
                        <textarea id="footer_desc" name="footer_desc" rows="2.5" required
                                  class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan"><?= htmlspecialchars($branding['footer_desc'] ?? '') ?></textarea>
                    </div>

                    <!-- Tautan Ekosistem Footer -->
                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-800">Tautan Kolom Ekosistem / Cepat:</span>
                            <input type="text" name="footer_links_title" 
                                   value="<?= htmlspecialchars($branding['footer_links_title'] ?? 'Ekosistem Kelautan.ID') ?>" 
                                   placeholder="Judul Kolom Tautan"
                                   class="px-2.5 py-1 text-xs bg-white border border-slate-300 rounded-lg w-52 font-semibold">
                        </div>

                        <!-- Link 1 -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" name="footer_link1_label" 
                                   value="<?= htmlspecialchars($branding['footer_link1_label'] ?? 'Portal Utama') ?>" 
                                   placeholder="Label Link 1"
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                            <input type="url" name="footer_link1_url" 
                                   value="<?= htmlspecialchars($branding['footer_link1_url'] ?? 'https://kelautan.id') ?>" 
                                   placeholder="https://..."
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                        </div>

                        <!-- Link 2 -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" name="footer_link2_label" 
                                   value="<?= htmlspecialchars($branding['footer_link2_label'] ?? 'Journal Kelautan.ID') ?>" 
                                   placeholder="Label Link 2"
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                            <input type="url" name="footer_link2_url" 
                                   value="<?= htmlspecialchars($branding['footer_link2_url'] ?? 'https://journal.kelautan.id') ?>" 
                                   placeholder="https://..."
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                        </div>

                        <!-- Link 3 -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" name="footer_link3_label" 
                                   value="<?= htmlspecialchars($branding['footer_link3_label'] ?? 'Pemendek Link (Go)') ?>" 
                                   placeholder="Label Link 3"
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                            <input type="url" name="footer_link3_url" 
                                   value="<?= htmlspecialchars($branding['footer_link3_url'] ?? 'https://go.kelautan.id') ?>" 
                                   placeholder="https://..."
                                   class="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                        </div>
                    </div>

                    <!-- Kontak & Bantuan Footer -->
                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-800">Kolom Bantuan & Kontak:</span>
                            <input type="text" name="contact_title" 
                                   value="<?= htmlspecialchars($branding['contact_title'] ?? 'Bantuan & Kontak') ?>" 
                                   placeholder="Judul Kolom Kontak"
                                   class="px-2.5 py-1 text-xs bg-white border border-slate-300 rounded-lg w-52 font-semibold">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="contact_email" class="block text-[11px] font-semibold text-slate-600 uppercase mb-1">Email Resmi</label>
                                <input id="contact_email" type="email" name="contact_email" 
                                       value="<?= htmlspecialchars($branding['contact_email'] ?? $branding['support_email']) ?>" 
                                       placeholder="email@kelautan.id"
                                       class="w-full px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                            </div>
                            <div>
                                <label for="contact_address" class="block text-[11px] font-semibold text-slate-600 uppercase mb-1">Alamat Kampus / Lokasi</label>
                                <input id="contact_address" type="text" name="contact_address" 
                                       value="<?= htmlspecialchars($branding['contact_address'] ?? 'Polikant, Tual, Maluku') ?>" 
                                       placeholder="Kota / Alamat"
                                       class="w-full px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                            </div>
                        </div>

                        <div>
                            <label for="contact_person" class="block text-[11px] font-semibold text-slate-600 uppercase mb-1">Inisiator / Pengelola</label>
                            <input id="contact_person" type="text" name="contact_person" 
                                   value="<?= htmlspecialchars($branding['contact_person'] ?? 'Inisiator: Budhi Agung Prasetyo, S.Pi., M.Si.') ?>" 
                                   placeholder="Nama Inisiator / Kontak"
                                   class="w-full px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl text-slate-900">
                        </div>
                    </div>

                    <!-- Hak Cipta Footer -->
                    <div>
                        <label for="footer_text" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                            Teks Hak Cipta & Garis Bawah
                        </label>
                        <input id="footer_text" name="footer_text" type="text" required
                               value="<?= htmlspecialchars($branding['footer_text']) ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan">
                    </div>
                </div>

                <div class="pt-3">
                    <button type="submit" 
                            class="inline-flex items-center space-x-2 px-6 py-2.5 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span>Simpan Seluruh Pengaturan</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Kolom Pemeliharaan & Backup Database (4/12) -->
        <div class="lg:col-span-4 space-y-6">
            
            <!-- Card Backup SQL -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i data-lucide="database" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 font-heading">Cadangan Data (Backup)</h3>
                        <p class="text-xs text-slate-500">Unduh dump SQL struktur & isi tabel</p>
                    </div>
                </div>
                <p class="text-xs text-slate-600 leading-relaxed">
                    Unduh seluruh data kursus, mahasiswa, modul, serta riwayat evaluasi ke dalam berkas standar <code>.sql</code>.
                </p>
                <form action="/pengaturan" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="backup_sql">
                    <button type="submit" 
                            class="w-full flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 transition">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        <span>Unduh Backup SQL (.sql)</span>
                    </button>
                </form>
            </div>

            <!-- Card Reset Database (Terproteksi Password) -->
            <div class="bg-white rounded-3xl p-6 border border-rose-200 shadow-sm space-y-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                        <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-rose-900 font-heading">Reset Database</h3>
                        <p class="text-xs text-rose-600">Fitur Terproteksi Kata Sandi</p>
                    </div>
                </div>
                <p class="text-xs text-slate-600 leading-relaxed">
                    Tindakan ini akan mengembalikan seluruh tabel ke kondisi awal instalasi. Masukkan kata sandi akun Dosen Admin Anda untuk mengonfirmasi:
                </p>
                <form action="/pengaturan" method="POST" class="space-y-3" onsubmit="return confirm('PERINGATAN TINGKAT TINGGI: Apakah Anda yakin ingin mereset seluruh database? Data yang telah diubah akan kembali ke kondisi awal bawaan!');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="reset_database">

                    <div>
                        <label for="admin_password" class="block text-xs font-semibold text-slate-700 uppercase mb-1">
                            Konfirmasi Kata Sandi Anda
                        </label>
                        <input id="admin_password" name="admin_password" type="password" required
                               placeholder="Masukkan kata sandi login Anda"
                               class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500">
                    </div>

                    <button type="submit" 
                            class="w-full flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 shadow transition">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        <span>Reset & Inisialisasi Ulang</span>
                    </button>
                </form>
            </div>

        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
