<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * CLI Database Initialization & Seeding Script
 * 
 * Penggunaan:
 *   php cli/setup.php [--reset]
 */

declare(strict_types=1);

define('NO_AUTH_REQUIRED', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/storage_helper.php';

echo "=========================================================\n";
echo " LMS POLIKANT × KELAUTAN.ID - SETUP & INISIALISASI DB\n";
echo "=========================================================\n";

$db = get_db();
$driver = get_active_db_driver();
echo "[INFO] Menggunakan Driver Database: " . strtoupper($driver) . "\n";

// Siapkan folder storage
$storageDirs = [
    get_storage_path('01_Materi_Perkuliahan'),
    get_storage_path('02_RPS_dan_Silabus'),
    get_storage_path('03_Pengumpulan_Tugas'),
];
foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        echo "[OK] Membuat direktori: " . basename($dir) . "\n";
    }
}

// Skema DDL Multi-Driver
if ($driver === 'sqlite') {
    // SQLite Schema
    $db->exec("
        CREATE TABLE IF NOT EXISTS pengaturan_sistem (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS users (
            id_user TEXT PRIMARY KEY,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            nama TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'Mahasiswa',
            angkatan TEXT DEFAULT NULL,
            foto_profil TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS tahun_akademik (
            id_ta TEXT PRIMARY KEY,
            nama_ta TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Tidak Aktif',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mata_kuliah (
            id_mk TEXT PRIMARY KEY,
            nama_mk TEXT NOT NULL,
            kode_mk TEXT NOT NULL,
            sks INTEGER NOT NULL,
            semester INTEGER NOT NULL,
            id_ta TEXT NOT NULL,
            dosen_pengampu TEXT NOT NULL,
            id_dosen TEXT DEFAULT NULL,
            deskripsi_mk TEXT DEFAULT NULL,
            rps_file_path TEXT DEFAULT NULL,
            silabus_file_path TEXT DEFAULT NULL,
            dokumen_lain_url TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_ta) REFERENCES tahun_akademik(id_ta) ON DELETE CASCADE,
            FOREIGN KEY (id_dosen) REFERENCES users(id_user) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS dosen_mata_kuliah (
            id_mk TEXT NOT NULL,
            id_user TEXT NOT NULL,
            urutan INTEGER DEFAULT 1,
            PRIMARY KEY (id_mk, id_user),
            FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE,
            FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS enrollment (
            id_enroll TEXT PRIMARY KEY,
            id_user TEXT NOT NULL,
            id_mk TEXT NOT NULL,
            enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (id_user, id_mk),
            FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
            FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS konten_mingguan (
            id_konten TEXT PRIMARY KEY,
            id_mk TEXT NOT NULL,
            minggu_ke INTEGER NOT NULL,
            tipe TEXT NOT NULL,
            judul TEXT NOT NULL,
            deskripsi TEXT DEFAULT NULL,
            file_path TEXT DEFAULT NULL,
            url_embed TEXT DEFAULT NULL,
            durasi_menit INTEGER DEFAULT NULL,
            deadline DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS pengumpulan_tugas (
            id_submit TEXT PRIMARY KEY,
            id_konten TEXT NOT NULL,
            id_user TEXT NOT NULL,
            file_path TEXT DEFAULT NULL,
            file_name TEXT DEFAULT NULL,
            link_eksternal TEXT DEFAULT NULL,
            catatan_mhs TEXT DEFAULT NULL,
            nilai NUMERIC DEFAULT NULL,
            feedback TEXT DEFAULT NULL,
            waktu_submit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (id_konten, id_user),
            FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS log_aktivitas (
            id_log TEXT PRIMARY KEY,
            id_user TEXT DEFAULT NULL,
            username TEXT DEFAULT NULL,
            nama TEXT DEFAULT NULL,
            role TEXT DEFAULT NULL,
            tipe_aktivitas TEXT NOT NULL,
            deskripsi TEXT NOT NULL,
            ip_address TEXT DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            is_impersonated INTEGER DEFAULT 0,
            impersonator_id TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        -- Bank Soal & Kuis
        CREATE TABLE IF NOT EXISTS bank_soal (
            id_soal TEXT PRIMARY KEY,
            id_mk TEXT DEFAULT NULL,
            id_dosen TEXT NOT NULL,
            tipe_soal TEXT NOT NULL,
            pertanyaan TEXT NOT NULL,
            kunci_jawaban_teks TEXT DEFAULT NULL,
            bobot_default NUMERIC DEFAULT 10.00,
            pembahasan TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE SET NULL,
            FOREIGN KEY (id_dosen) REFERENCES users(id_user) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS bank_soal_opsi (
            id_opsi TEXT PRIMARY KEY,
            id_soal TEXT NOT NULL,
            label_opsi TEXT NOT NULL,
            teks_opsi TEXT NOT NULL,
            is_benar INTEGER DEFAULT 0,
            urutan INTEGER DEFAULT 1,
            FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS kuis_soal (
            id_kuis_soal TEXT PRIMARY KEY,
            id_konten TEXT NOT NULL,
            id_soal TEXT NOT NULL,
            urutan INTEGER DEFAULT 1,
            bobot_nilai NUMERIC DEFAULT 10.00,
            UNIQUE (id_konten, id_soal),
            FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS kuis_sesi (
            id_sesi TEXT PRIMARY KEY,
            id_konten TEXT NOT NULL,
            id_user TEXT NOT NULL,
            waktu_mulai DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            waktu_selesai DATETIME DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'sedang_mengerjakan',
            total_nilai NUMERIC DEFAULT NULL,
            catatan_koreksi TEXT DEFAULT NULL,
            UNIQUE (id_konten, id_user),
            FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS kuis_jawaban (
            id_jawaban TEXT PRIMARY KEY,
            id_sesi TEXT NOT NULL,
            id_soal TEXT NOT NULL,
            id_opsi_dipilih TEXT DEFAULT NULL,
            jawaban_teks TEXT DEFAULT NULL,
            nilai_butir NUMERIC DEFAULT 0.00,
            status_koreksi TEXT NOT NULL DEFAULT 'otomatis',
            catatan_dosen TEXT DEFAULT NULL,
            UNIQUE (id_sesi, id_soal),
            FOREIGN KEY (id_sesi) REFERENCES kuis_sesi(id_sesi) ON DELETE CASCADE,
            FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE
        );
    ");
} else {
    // MariaDB / MySQL InnoDB Schema
    $db->exec("
        CREATE TABLE IF NOT EXISTS pengaturan_sistem (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS users (
            id_user VARCHAR(20) PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            nama VARCHAR(150) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'Mahasiswa',
            angkatan CHAR(4) DEFAULT NULL,
            foto_profil VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_role (role),
            INDEX idx_angkatan (angkatan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS tahun_akademik (
            id_ta VARCHAR(20) PRIMARY KEY,
            nama_ta VARCHAR(100) NOT NULL,
            status ENUM('Aktif', 'Tidak Aktif') NOT NULL DEFAULT 'Tidak Aktif',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS mata_kuliah (
            id_mk VARCHAR(20) PRIMARY KEY,
            nama_mk VARCHAR(150) NOT NULL,
            kode_mk VARCHAR(30) NOT NULL,
            sks TINYINT UNSIGNED NOT NULL CHECK (sks BETWEEN 1 AND 6),
            semester TINYINT UNSIGNED NOT NULL CHECK (semester BETWEEN 1 AND 8),
            id_ta VARCHAR(20) NOT NULL,
            dosen_pengampu VARCHAR(150) NOT NULL,
            id_dosen VARCHAR(20) DEFAULT NULL,
            deskripsi_mk TEXT DEFAULT NULL,
            rps_file_path VARCHAR(255) DEFAULT NULL,
            silabus_file_path VARCHAR(255) DEFAULT NULL,
            dokumen_lain_url VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_mk_ta FOREIGN KEY (id_ta) REFERENCES tahun_akademik(id_ta) ON DELETE CASCADE,
            CONSTRAINT fk_mk_dosen FOREIGN KEY (id_dosen) REFERENCES users(id_user) ON DELETE SET NULL,
            INDEX idx_mk_ta (id_ta),
            INDEX idx_kode_mk (kode_mk),
            INDEX idx_id_dosen (id_dosen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS dosen_mata_kuliah (
            id_mk VARCHAR(20) NOT NULL,
            id_user VARCHAR(20) NOT NULL,
            urutan TINYINT UNSIGNED DEFAULT 1,
            PRIMARY KEY (id_mk, id_user),
            CONSTRAINT fk_dmk_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE,
            CONSTRAINT fk_dmk_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
            INDEX idx_dmk_user (id_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS enrollment (
            id_enroll VARCHAR(20) PRIMARY KEY,
            id_user VARCHAR(20) NOT NULL,
            id_mk VARCHAR(20) NOT NULL,
            enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_mk (id_user, id_mk),
            CONSTRAINT fk_enroll_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
            CONSTRAINT fk_enroll_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS konten_mingguan (
            id_konten VARCHAR(20) PRIMARY KEY,
            id_mk VARCHAR(20) NOT NULL,
            minggu_ke TINYINT UNSIGNED NOT NULL CHECK (minggu_ke BETWEEN 1 AND 16),
            tipe ENUM('Materi', 'Tugas', 'Kuis', 'Ujian') NOT NULL,
            judul VARCHAR(200) NOT NULL,
            deskripsi TEXT DEFAULT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            url_embed VARCHAR(500) DEFAULT NULL,
            durasi_menit SMALLINT UNSIGNED DEFAULT NULL,
            deadline DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_konten_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE,
            INDEX idx_mk_minggu (id_mk, minggu_ke)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS pengumpulan_tugas (
            id_submit VARCHAR(20) PRIMARY KEY,
            id_konten VARCHAR(20) NOT NULL,
            id_user VARCHAR(20) NOT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            file_name VARCHAR(200) DEFAULT NULL,
            link_eksternal VARCHAR(500) DEFAULT NULL,
            catatan_mhs TEXT DEFAULT NULL,
            nilai DECIMAL(5,2) DEFAULT NULL,
            feedback TEXT DEFAULT NULL,
            waktu_submit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_submit_mhs (id_konten, id_user),
            CONSTRAINT fk_submit_konten FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            CONSTRAINT fk_submit_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS log_aktivitas (
            id_log VARCHAR(30) NOT NULL PRIMARY KEY,
            id_user VARCHAR(30) DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            nama VARCHAR(255) DEFAULT NULL,
            role VARCHAR(50) DEFAULT NULL,
            tipe_aktivitas VARCHAR(50) NOT NULL,
            deskripsi TEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            is_impersonated TINYINT(1) DEFAULT 0,
            impersonator_id VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_log_created (created_at DESC),
            INDEX idx_log_user (id_user),
            INDEX idx_log_tipe (tipe_aktivitas)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- Bank Soal & Kuis
        CREATE TABLE IF NOT EXISTS bank_soal (
            id_soal VARCHAR(30) NOT NULL PRIMARY KEY,
            id_mk VARCHAR(20) DEFAULT NULL,
            id_dosen VARCHAR(20) NOT NULL,
            tipe_soal ENUM('pg', 'benar_salah', 'isian', 'essay') NOT NULL,
            pertanyaan MEDIUMTEXT NOT NULL,
            kunci_jawaban_teks TEXT DEFAULT NULL,
            bobot_default DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            pembahasan TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_bs_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE SET NULL,
            CONSTRAINT fk_bs_dosen FOREIGN KEY (id_dosen) REFERENCES users(id_user) ON DELETE CASCADE,
            INDEX idx_bank_soal_mk (id_mk),
            INDEX idx_bank_soal_dosen (id_dosen),
            INDEX idx_bank_soal_tipe (tipe_soal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS bank_soal_opsi (
            id_opsi VARCHAR(30) NOT NULL PRIMARY KEY,
            id_soal VARCHAR(30) NOT NULL,
            label_opsi VARCHAR(10) NOT NULL,
            teks_opsi TEXT NOT NULL,
            is_benar TINYINT(1) NOT NULL DEFAULT 0,
            urutan TINYINT UNSIGNED NOT NULL DEFAULT 1,
            CONSTRAINT fk_bso_soal FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE,
            INDEX idx_bso_soal (id_soal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS kuis_soal (
            id_kuis_soal VARCHAR(30) NOT NULL PRIMARY KEY,
            id_konten VARCHAR(20) NOT NULL,
            id_soal VARCHAR(30) NOT NULL,
            urutan TINYINT UNSIGNED NOT NULL DEFAULT 1,
            bobot_nilai DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            UNIQUE KEY uq_konten_soal (id_konten, id_soal),
            CONSTRAINT fk_ks_konten FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            CONSTRAINT fk_ks_soal FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE,
            INDEX idx_ks_konten (id_konten)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS kuis_sesi (
            id_sesi VARCHAR(30) NOT NULL PRIMARY KEY,
            id_konten VARCHAR(20) NOT NULL,
            id_user VARCHAR(20) NOT NULL,
            waktu_mulai DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            waktu_selesai DATETIME DEFAULT NULL,
            status ENUM('sedang_mengerjakan', 'selesai', 'waktu_habis') NOT NULL DEFAULT 'sedang_mengerjakan',
            total_nilai DECIMAL(5,2) DEFAULT NULL,
            catatan_koreksi TEXT DEFAULT NULL,
            UNIQUE KEY uq_sesi_konten_user (id_konten, id_user),
            CONSTRAINT fk_kses_konten FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
            CONSTRAINT fk_kses_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
            INDEX idx_kses_konten (id_konten),
            INDEX idx_kses_user (id_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS kuis_jawaban (
            id_jawaban VARCHAR(30) NOT NULL PRIMARY KEY,
            id_sesi VARCHAR(30) NOT NULL,
            id_soal VARCHAR(30) NOT NULL,
            id_opsi_dipilih VARCHAR(30) DEFAULT NULL,
            jawaban_teks MEDIUMTEXT DEFAULT NULL,
            nilai_butir DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status_koreksi ENUM('otomatis', 'perlu_koreksi', 'dinilai') NOT NULL DEFAULT 'otomatis',
            catatan_dosen TEXT DEFAULT NULL,
            UNIQUE KEY uq_jawaban_sesi_soal (id_sesi, id_soal),
            CONSTRAINT fk_kjaw_sesi FOREIGN KEY (id_sesi) REFERENCES kuis_sesi(id_sesi) ON DELETE CASCADE,
            CONSTRAINT fk_kjaw_soal FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE,
            INDEX idx_kjaw_sesi (id_sesi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}
echo "[OK] Struktur 8 tabel berhasil diverifikasi/dibuat.\n";

// Jalankan migrasi schema otomatis untuk v1.1
ensure_schema_up_to_date($db);
echo "[OK] Verifikasi migrasi schema v1.1 selesai.\n";

// Inisialisasi Pengaturan Branding & Footer
$brandDefaults = [
    'platform_name'      => 'Kelautan.ID - Portal Maritim Indonesia',
    'app_name'           => 'LMS Polikant × Kelautan.ID',
    'app_tagline'        => 'Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia',
    'institution_name'   => 'Politeknik Perikanan Negeri Tual',
    'study_program'      => 'Program Studi Teknologi Kelautan',
    'url_portal'         => 'https://kelautan.id',
    'url_lms'            => 'https://lms.kelautan.id',
    'url_journal'        => 'https://journal.kelautan.id',
    'url_shortener'      => 'https://go.kelautan.id',
    'support_email'      => 'teknologikelautanid@gmail.com',
    'footer_text'        => '© 2026 Kelautan.ID - Portal Maritim Indonesia × Politeknik Perikanan Negeri Tual. Hak cipta dilindungi undang-undang.',
    'footer_desc'        => 'Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia. Dikelola dalam kemitraan akademik bersama Politeknik Perikanan Negeri Tual untuk kemajuan riset vokasi kelautan Indonesia.',
    'footer_links_title' => 'Tautan Kelautan.ID',
    'footer_link1_label' => 'Portal Utama Kelautan.ID',
    'footer_link1_url'   => 'https://kelautan.id',
    'footer_link2_label' => 'Journal & Riset Maritim',
    'footer_link2_url'   => 'https://journal.kelautan.id',
    'footer_link3_label' => 'Shortener & Pengalihan URL',
    'footer_link3_url'   => 'https://go.kelautan.id',
    'contact_title'      => 'Bantuan & Kontak',
    'contact_email'      => 'teknologikelautanid@gmail.com',
    'contact_address'    => 'Kampus Polikant, Jl. Raya Langgur-Tual, Maluku Tenggara',
    'contact_person'     => 'Budhi Agung Prasetyo, S.Pi., M.Si.',
];

if ($driver === 'sqlite') {
    $stmtSetting = $db->prepare("INSERT OR REPLACE INTO pengaturan_sistem (setting_key, setting_value) VALUES (?, ?)");
} else {
    $stmtSetting = $db->prepare("REPLACE INTO pengaturan_sistem (setting_key, setting_value) VALUES (?, ?)");
}

foreach ($brandDefaults as $key => $val) {
    $stmtSetting->execute([$key, $val]);
}
echo "[OK] Data identitas branding Kelautan.ID tersimpan.\n";

// Seeding Pengguna Default (PRD Section 8.1 & v1.1 Multi-Role Dosen)
$users = [
    [
        'id_user'  => 'USR-00001',
        'username' => 'admin',
        'password' => 'dosen123',
        'nama'     => 'Budhi Agung Prasetyo, S.Pi., M.Si.',
        'role'     => 'Dosen Admin',
        'angkatan' => null
    ],
    [
        'id_user'  => 'USR-00005',
        'username' => 'dosen1',
        'password' => 'dosen123',
        'nama'     => 'Dr. Ir. Ahmad Kelautan, M.T.',
        'role'     => 'Dosen',
        'angkatan' => null
    ],
    [
        'id_user'  => 'USR-00002',
        'username' => '2024001',
        'password' => 'mhs123',
        'nama'     => 'Fajar Bahari',
        'role'     => 'Mahasiswa',
        'angkatan' => '2024'
    ],
    [
        'id_user'  => 'USR-00003',
        'username' => '2024002',
        'password' => 'mhs123',
        'nama'     => 'Siti Samudra',
        'role'     => 'Mahasiswa',
        'angkatan' => '2024'
    ],
    [
        'id_user'  => 'USR-00004',
        'username' => '2024003',
        'password' => 'mhs123',
        'nama'     => 'Rian Kelana',
        'role'     => 'Mahasiswa',
        'angkatan' => '2024'
    ],
];

foreach ($users as $u) {
    $check = $db->prepare("SELECT id_user FROM users WHERE username = ?");
    $check->execute([$u['username']]);
    if (!$check->fetch()) {
        $hash = password_hash($u['password'], PASSWORD_BCRYPT);
        $ins = $db->prepare("INSERT INTO users (id_user, username, password_hash, nama, role, angkatan) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$u['id_user'], $u['username'], $hash, $u['nama'], $u['role'], $u['angkatan']]);
        echo "[OK] Pengguna dibuat: {$u['username']} ({$u['role']}) - Kata Sandi: {$u['password']}\n";
    }
}

// Seeding Tahun Akademik Default
$checkTA = $db->query("SELECT COUNT(*) FROM tahun_akademik")->fetchColumn();
if ((int)$checkTA === 0) {
    $db->exec("INSERT INTO tahun_akademik (id_ta, nama_ta, status) VALUES 
        ('TA-00001', '2026/2027 Ganjil', 'Aktif'),
        ('TA-00002', '2025/2026 Genap', 'Tidak Aktif')");
    echo "[OK] Tahun akademik default dibuat (2026/2027 Ganjil: Aktif).\n";
}

// Buat Dokumen Contoh PDF RPS & Panduan di Storage
$sampleRpsPath = get_storage_path('02_RPS_dan_Silabus') . '/RPS_TKL-2101_Sample.pdf';
if (!file_exists($sampleRpsPath)) {
    // Generate minimal valid PDF file
    $minimalPdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R/Resources<<>>/Contents 4 0 R>>endobj\n4 0 obj<</Length 85>>stream\nBT /F1 18 Tf 50 780 Td (RPS Oseanografi Geologi - Polikant x Kelautan.ID) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\n0000000216 00000 n\ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n352\n%%EOF";
    file_put_contents($sampleRpsPath, $minimalPdf);
    @chmod($sampleRpsPath, 0664);
}

// Seeding Mata Kuliah Default (Oseanografi Geologi & Meteorologi Laut)
$checkMK = $db->query("SELECT COUNT(*) FROM mata_kuliah")->fetchColumn();
if ((int)$checkMK === 0) {
    $desc1 = "Mata kuliah ini membahas proses-proses geologi kelautan, morfologi dasar laut, lempeng tektonik, sedimentasi marin, serta survei batimetri terintegrasi dengan portal riset kelautan.id.";
    $stmtMK = $db->prepare("INSERT INTO mata_kuliah (id_mk, nama_mk, kode_mk, sks, semester, id_ta, dosen_pengampu, id_dosen, deskripsi_mk, rps_file_path, dokumen_lain_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtMK->execute([
        'MK-00001',
        'Oseanografi Geologi',
        'TKL-2101',
        3,
        3,
        'TA-00001',
        'Budhi Agung Prasetyo, S.Pi., M.Si.',
        'USR-00001',
        $desc1,
        '02_RPS_dan_Silabus/RPS_TKL-2101_Sample.pdf',
        'https://kelautan.id'
    ]);

    $desc2 = "Mata kuliah ini mempelajari sirkulasi massa air laut, gelombang oseanografi, pasang surut, fenomena ENSO/IOD, dan parameter iklim maritim Nusantara.";
    $stmtMK->execute([
        'MK-00002',
        'Meteorologi & Dinamika Oseanografi',
        'TKL-2102',
        3,
        3,
        'TA-00001',
        'Dr. Ir. Ahmad Kelautan, M.T.',
        'USR-00005',
        $desc2,
        null,
        'https://journal.kelautan.id'
    ]);
    echo "[OK] Mata kuliah default 'Oseanografi Geologi' (TKL-2101) & 'Meteorologi & Dinamika Oseanografi' (TKL-2102) dibuat.\n";

    // Seeding Tim Dosen Pengampu (Team Teaching untuk TKL-2101: Budhi Agung & Dr. Ir. Ahmad Kelautan)
    $stmtDMK = $db->prepare("INSERT INTO dosen_mata_kuliah (id_mk, id_user, urutan) VALUES (?, ?, ?)");
    $stmtDMK->execute(['MK-00001', 'USR-00001', 1]);
    $stmtDMK->execute(['MK-00001', 'USR-00005', 2]);
    $stmtDMK->execute(['MK-00002', 'USR-00005', 1]);
    echo "[OK] Tim Dosen Pengampu (Team Teaching) default di-assign ke mata kuliah.\n";
}

// Seeding Enrollment (Mahasiswa 2024001 dan 2024002)
$checkEnr = $db->query("SELECT COUNT(*) FROM enrollment")->fetchColumn();
if ((int)$checkEnr === 0) {
    $db->exec("INSERT INTO enrollment (id_enroll, id_user, id_mk) VALUES 
        ('ENR-00001', 'USR-00002', 'MK-00001'),
        ('ENR-00002', 'USR-00003', 'MK-00001')");
    echo "[OK] Pendaftaran peserta (Mahasiswa 2024001 & 2024002) pada MK-00001 berhasil.\n";
}

// Seeding Konten Mingguan (Pertemuan 1 - 16)
$checkKonten = $db->query("SELECT COUNT(*) FROM konten_mingguan")->fetchColumn();
if ((int)$checkKonten === 0) {
    // Buat file sample materi & soal PDF di storage
    $sampleSoalPath = get_storage_path('01_Materi_Perkuliahan') . '/Soal_Tugas_Batimetri_Minggu3.pdf';
    if (!file_exists($sampleSoalPath)) {
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R/Resources<<>>/Contents 4 0 R>>endobj\n4 0 obj<</Length 110>>stream\nBT /F1 16 Tf 50 780 Td (Soal Tugas: Analisis Kontur Batimetri Perairan Kei - Kelautan.ID) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\n0000000216 00000 n\ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n377\n%%EOF";
        file_put_contents($sampleSoalPath, $pdfContent);
        @chmod($sampleSoalPath, 0664);
    }

    $kontenItems = [
        [
            'id'       => 'KNT-00001',
            'minggu'   => 1,
            'tipe'     => 'Materi',
            'judul'    => 'Pengantar Oseanografi Geologi & Fisiografi Laut Dunia',
            'deskripsi'=> 'Pengenalan ruang lingkup geologi kelautan, morfologi paparan benua (continental shelf), lereng benua (slope), dan palung laut dalam. Rujukan utama: Portal Kelautan.ID.',
            'file'     => null,
            'url'      => 'https://kelautan.id',
            'durasi'   => null,
            'deadline' => null,
        ],
        [
            'id'       => 'KNT-00002',
            'minggu'   => 2,
            'tipe'     => 'Materi',
            'judul'    => 'Tektonik Lempeng dan Dinamika Cekungan Busur Belakang Indonesia',
            'deskripsi'=> 'Kajian pertemuan lempeng Indo-Australia, Eurasia, dan Pasifik yang membentuk tatanan geologi kompleks di perairan Indonesia Timur.',
            'file'     => null,
            'url'      => 'https://journal.kelautan.id',
            'durasi'   => null,
            'deadline' => null,
        ],
        [
            'id'       => 'KNT-00003',
            'minggu'   => 3,
            'tipe'     => 'Tugas',
            'judul'    => 'Tugas 1: Analisis Kontur Batimetri Kepulauan Kei & Maluku Tenggara',
            'deskripsi'=> 'Pelajari berkas soal PDF terlampir. Lakukan interpretasi morfologi dasar laut dan upload laporan hasil analisa Anda dalam format PDF (maks. 10 MB).',
            'file'     => '01_Materi_Perkuliahan/Soal_Tugas_Batimetri_Minggu3.pdf',
            'url'      => null,
            'durasi'   => null,
            'deadline' => date('Y-m-d 23:59:59', strtotime('+14 days')),
        ],
        [
            'id'       => 'KNT-00004',
            'minggu'   => 4,
            'tipe'     => 'Materi',
            'judul'    => 'Sedimentasi Laut Dalam & Sebaran Mineral Laut',
            'deskripsi'=> 'Klasifikasi sedimen lithogenous, biogenous, hydrogenous, dan cosmogenous pada zona abisal perairan Maluku.',
            'file'     => null,
            'url'      => null,
            'durasi'   => null,
            'deadline' => null,
        ],
        [
            'id'       => 'KNT-00005',
            'minggu'   => 5,
            'tipe'     => 'Kuis',
            'judul'    => 'Kuis Interaktif: Fisiografi & Tektonik Laut',
            'deskripsi'=> 'Kerjakan 20 butir soal kuis interaktif pada tautan go.kelautan.id dengan batas waktu pengerjaan 30 menit.',
            'file'     => null,
            'url'      => 'https://go.kelautan.id/kuis-geologilaut',
            'durasi'   => 30,
            'deadline' => date('Y-m-d 23:59:59', strtotime('+7 days')),
        ],
        [
            'id'       => 'KNT-00006',
            'minggu'   => 8,
            'tipe'     => 'Ujian',
            'judul'    => 'Ujian Tengah Semester (UTS) Oseanografi Geologi',
            'deskripsi'=> 'Ujian evaluasi capaian pembelajaran minggu 1 sampai dengan minggu 7 secara daring.',
            'file'     => null,
            'url'      => 'https://go.kelautan.id/uts-oseanografi',
            'durasi'   => 90,
            'deadline' => date('Y-m-d 23:59:59', strtotime('+21 days')),
        ],
        [
            'id'       => 'KNT-00007',
            'minggu'   => 16,
            'tipe'     => 'Ujian',
            'judul'    => 'Ujian Akhir Semester (UAS) Oseanografi Geologi',
            'deskripsi'=> 'Ujian komprehensif teori dan studi kasus geologi maritim terapan.',
            'file'     => null,
            'url'      => 'https://go.kelautan.id/uas-oseanografi',
            'durasi'   => 120,
            'deadline' => date('Y-m-d 23:59:59', strtotime('+60 days')),
        ],
    ];

    $stmtK = $db->prepare("INSERT INTO konten_mingguan (id_konten, id_mk, minggu_ke, tipe, judul, deskripsi, file_path, url_embed, durasi_menit, deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($kontenItems as $item) {
        $stmtK->execute([
            $item['id'],
            'MK-00001',
            $item['minggu'],
            $item['tipe'],
            $item['judul'],
            $item['deskripsi'],
            $item['file'],
            $item['url'],
            $item['durasi'],
            $item['deadline'],
        ]);
    }
    echo "[OK] Konten pembelajaran 16 pertemuan berhasil diisi.\n";
}

echo "=========================================================\n";
echo " INISIALISASI SELESAI!\n";
echo " Kredensial Pengujian Bawaan:\n";
echo " 1. Dosen / Admin:   Username: admin    | Password: dosen123\n";
echo " 2. Mahasiswa 1:     Username: 2024001  | Password: mhs123\n";
echo " 3. Mahasiswa 2:     Username: 2024002  | Password: mhs123\n";
echo " Akses Halaman: http://localhost:8000/login.php\n";
echo "=========================================================\n";
