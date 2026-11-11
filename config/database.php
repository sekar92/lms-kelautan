<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Konfigurasi Database (PDO MariaDB / MySQL dengan Resilient SQLite Local Fallback)
 */

declare(strict_types=1);

if (!defined('LMS_APP')) {
    define('LMS_APP', true);
}

// Load custom env config if available
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
}

function get_db_config(): array {
    return [
        'driver'   => getenv('DB_DRIVER') ?: 'mysql',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'db_lms_kelautan',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset'  => 'utf8mb4',
        'sqlite'   => __DIR__ . '/../storage/db_lms_kelautan.sqlite',
    ];
}

function get_db(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = get_db_config();
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Coba MySQL/MariaDB terlebih dahulu jika driver diset mysql
    if ($config['driver'] === 'mysql') {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            $pdo->exec("SET NAMES 'utf8mb4'");
            ensure_schema_up_to_date($pdo);
            return $pdo;
        } catch (PDOException $e) {
            // Jika koneksi MySQL gagal (misal server MariaDB lokal belum dinyalakan),
            // fallback ke SQLite lokal agar aplikasi tetap langsung jalan tanpa blank page
            error_log("LMS Notice: MySQL connection failed ({$e->getMessage()}), falling back to SQLite.");
        }
    }

    // SQLite Fallback / Direct Driver
    $sqlitePath = $config['sqlite'];
    $sqliteDir = dirname($sqlitePath);
    if (!is_dir($sqliteDir)) {
        @mkdir($sqliteDir, 0775, true);
    }

    $dsn = 'sqlite:' . $sqlitePath;
    $pdo = new PDO($dsn, null, null, $options);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
    // Jalankan migrasi otomatis untuk memastikan struktur kolom v1.1
    ensure_schema_up_to_date($pdo);

    return $pdo;
}

/**
 * Pastikan skema tabel memiliki kolom v1.1 (foto_profil, id_dosen, role Dosen Admin)
 */
function ensure_schema_up_to_date(PDO $pdo): void {
    static $migrated = false;
    if ($migrated) return;
    $migrated = true;

    // Pastikan folder uploads avatar tersedia
    $avatarDir = dirname(__DIR__) . '/public_html/assets/uploads/avatars';
    if (!is_dir($avatarDir)) {
        @mkdir($avatarDir, 0775, true);
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    try {
        if ($driver === 'sqlite') {
            // Cek foto_profil di tabel users
            $userCols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($userCols) && !in_array('foto_profil', $userCols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN foto_profil TEXT DEFAULT NULL");
            }

            // Cek id_dosen di tabel mata_kuliah
            $mkCols = $pdo->query("PRAGMA table_info(mata_kuliah)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($mkCols) && !in_array('id_dosen', $mkCols, true)) {
                $pdo->exec("ALTER TABLE mata_kuliah ADD COLUMN id_dosen TEXT DEFAULT NULL");
            }

            // Buat tabel relasi dosen_mata_kuliah untuk mendukung team teaching (2-3 dosen pengampu)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dosen_mata_kuliah (
                    id_mk TEXT NOT NULL,
                    id_user TEXT NOT NULL,
                    urutan INTEGER DEFAULT 1,
                    PRIMARY KEY (id_mk, id_user),
                    FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE,
                    FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
                );
            ");

            // Migrasikan id_dosen lama ke tabel relasi jika belum tercatat
            $pdo->exec("
                INSERT OR IGNORE INTO dosen_mata_kuliah (id_mk, id_user, urutan)
                SELECT id_mk, id_dosen, 1 FROM mata_kuliah WHERE id_dosen IS NOT NULL AND id_dosen != ''
            ");

            // Cocokkan juga berdasarkan nama pengampu jika id_dosen masih kosong
            $pdo->exec("
                INSERT OR IGNORE INTO dosen_mata_kuliah (id_mk, id_user, urutan)
                SELECT mk.id_mk, u.id_user, 1 
                FROM mata_kuliah mk
                JOIN users u ON (u.role IN ('Dosen Admin', 'Dosen', 'Admin') OR u.username = 'admin') 
                  AND (mk.dosen_pengampu = u.nama OR mk.dosen_pengampu = u.username)
                WHERE mk.id_mk NOT IN (SELECT id_mk FROM dosen_mata_kuliah)
            ");

            // Perbarui id_dosen pada tabel mata_kuliah jika masih kosong
            $pdo->exec("
                UPDATE mata_kuliah 
                SET id_dosen = (SELECT id_user FROM dosen_mata_kuliah WHERE dosen_mata_kuliah.id_mk = mata_kuliah.id_mk ORDER BY urutan ASC LIMIT 1)
                WHERE (id_dosen IS NULL OR id_dosen = '') AND id_mk IN (SELECT id_mk FROM dosen_mata_kuliah)
            ");

            // Cek kolom link_eksternal di tabel pengumpulan_tugas
            $tugasCols = $pdo->query("PRAGMA table_info(pengumpulan_tugas)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($tugasCols) && !in_array('link_eksternal', $tugasCols, true)) {
                $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN link_eksternal TEXT DEFAULT NULL");
            }

            // Buat tabel log_aktivitas untuk audit trail kegiatan seluruh pengguna & login-as
            $pdo->exec("
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
                CREATE INDEX IF NOT EXISTS idx_log_created ON log_aktivitas(created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_log_user ON log_aktivitas(id_user);
                CREATE INDEX IF NOT EXISTS idx_log_tipe ON log_aktivitas(tipe_aktivitas);

                -- ========================================================
                -- TABEL BANK SOAL & KUIS INTERAKTIF (v3.6)
                -- ========================================================
                CREATE TABLE IF NOT EXISTS bank_soal (
                    id_soal TEXT PRIMARY KEY,
                    id_mk TEXT DEFAULT NULL,
                    id_dosen TEXT NOT NULL,
                    tipe_soal TEXT NOT NULL, -- 'pg', 'benar_salah', 'isian', 'essay'
                    pertanyaan TEXT NOT NULL,
                    kunci_jawaban_teks TEXT DEFAULT NULL,
                    bobot_default NUMERIC DEFAULT 10.00,
                    pembahasan TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE SET NULL,
                    FOREIGN KEY (id_dosen) REFERENCES users(id_user) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_bank_soal_mk ON bank_soal(id_mk);
                CREATE INDEX IF NOT EXISTS idx_bank_soal_dosen ON bank_soal(id_dosen);
                CREATE INDEX IF NOT EXISTS idx_bank_soal_tipe ON bank_soal(tipe_soal);

                CREATE TABLE IF NOT EXISTS bank_soal_opsi (
                    id_opsi TEXT PRIMARY KEY,
                    id_soal TEXT NOT NULL,
                    label_opsi TEXT NOT NULL,
                    teks_opsi TEXT NOT NULL,
                    is_benar INTEGER DEFAULT 0,
                    urutan INTEGER DEFAULT 1,
                    FOREIGN KEY (id_soal) REFERENCES bank_soal(id_soal) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_bank_soal_opsi_soal ON bank_soal_opsi(id_soal);

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
                CREATE INDEX IF NOT EXISTS idx_kuis_soal_konten ON kuis_soal(id_konten);

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
                CREATE INDEX IF NOT EXISTS idx_kuis_sesi_konten ON kuis_sesi(id_konten);
                CREATE INDEX IF NOT EXISTS idx_kuis_sesi_user ON kuis_sesi(id_user);

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
                CREATE INDEX IF NOT EXISTS idx_kuis_jawaban_sesi ON kuis_jawaban(id_sesi);
            ");
        } else {
            // MariaDB / MySQL
            $colUser = $pdo->query("SHOW COLUMNS FROM users LIKE 'foto_profil'")->fetch();
            if (!$colUser) {
                $pdo->exec("ALTER TABLE users ADD COLUMN foto_profil VARCHAR(255) DEFAULT NULL AFTER angkatan");
            }

            // Ubah tipe kolom role agar fleksibel mendukung 'Dosen Admin', 'Dosen', 'Mahasiswa'
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'Mahasiswa'");

            $colMK = $pdo->query("SHOW COLUMNS FROM mata_kuliah LIKE 'id_dosen'")->fetch();
            if (!$colMK) {
                $pdo->exec("ALTER TABLE mata_kuliah ADD COLUMN id_dosen VARCHAR(20) DEFAULT NULL AFTER id_ta");
            }

            // Buat tabel relasi dosen_mata_kuliah untuk mendukung team teaching (2-3 dosen pengampu)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dosen_mata_kuliah (
                    id_mk VARCHAR(20) NOT NULL,
                    id_user VARCHAR(20) NOT NULL,
                    urutan TINYINT UNSIGNED DEFAULT 1,
                    PRIMARY KEY (id_mk, id_user),
                    CONSTRAINT fk_dmk_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE,
                    CONSTRAINT fk_dmk_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
                    INDEX idx_dmk_user (id_user)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Migrasikan id_dosen lama ke tabel relasi jika belum tercatat
            $pdo->exec("
                INSERT IGNORE INTO dosen_mata_kuliah (id_mk, id_user, urutan)
                SELECT id_mk, id_dosen, 1 FROM mata_kuliah WHERE id_dosen IS NOT NULL AND id_dosen != ''
            ");

            // Cocokkan juga berdasarkan nama pengampu jika id_dosen masih kosong
            $pdo->exec("
                INSERT IGNORE INTO dosen_mata_kuliah (id_mk, id_user, urutan)
                SELECT mk.id_mk, u.id_user, 1 
                FROM mata_kuliah mk
                JOIN users u ON (u.role IN ('Dosen Admin', 'Dosen', 'Admin') OR u.username = 'admin') 
                  AND (mk.dosen_pengampu = u.nama OR mk.dosen_pengampu = u.username)
                WHERE mk.id_mk NOT IN (SELECT id_mk FROM dosen_mata_kuliah)
            ");

            // Perbarui id_dosen pada tabel mata_kuliah jika masih kosong
            $pdo->exec("
                UPDATE mata_kuliah 
                SET id_dosen = (SELECT id_user FROM dosen_mata_kuliah WHERE dosen_mata_kuliah.id_mk = mata_kuliah.id_mk ORDER BY urutan ASC LIMIT 1)
                WHERE (id_dosen IS NULL OR id_dosen = '') AND id_mk IN (SELECT id_mk FROM dosen_mata_kuliah)
            ");

            // Cek kolom link_eksternal di tabel pengumpulan_tugas
            $colLink = $pdo->query("SHOW COLUMNS FROM pengumpulan_tugas LIKE 'link_eksternal'")->fetch();
            if (!$colLink) {
                $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN link_eksternal VARCHAR(500) DEFAULT NULL AFTER catatan_mhs");
            }
            // Jadikan file_path dan file_name nullable agar mahasiswa bisa mengumpulkan link eksternal
            $pdo->exec("ALTER TABLE pengumpulan_tugas MODIFY COLUMN file_path VARCHAR(255) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE pengumpulan_tugas MODIFY COLUMN file_name VARCHAR(200) NULL DEFAULT NULL");

            // Buat tabel log_aktivitas untuk audit trail kegiatan seluruh pengguna & login-as
            $pdo->exec("
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

                -- ========================================================
                -- TABEL BANK SOAL & KUIS INTERAKTIF (v3.6)
                -- ========================================================
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

        // Pastikan akun 'admin' memiliki role 'Dosen Admin'
        $pdo->exec("UPDATE users SET role = 'Dosen Admin' WHERE username = 'admin' AND role IN ('Dosen', 'Admin')");
    } catch (Throwable $e) {
        // Jika tabel belum di-create oleh setup.php, abaikan
    }
}

function get_active_db_driver(): string {
    $db = get_db();
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * Generate Sequential ID (misal: USR-00001, MK-00001, KNT-00001)
 */
function generate_id(string $prefix, string $table, string $column = 'id'): string {
    $db = get_db();
    // Cari record terakhir berdasarkan ID
    $stmt = $db->query("SELECT {$column} FROM {$table} WHERE {$column} LIKE '{$prefix}-%' ORDER BY {$column} DESC LIMIT 1");
    $lastId = $stmt->fetchColumn();

    if ($lastId && preg_match('/' . preg_quote($prefix, '/') . '-(\d+)/', $lastId, $matches)) {
        $nextNum = (int)$matches[1] + 1;
    } else {
        $nextNum = 1;
    }

    return sprintf('%s-%05d', $prefix, $nextNum);
}
