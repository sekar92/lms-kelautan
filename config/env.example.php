<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Konfigurasi Lingkungan (Environment Config)
 * Salin file ini menjadi `env.php` untuk kustomisasi koneksi database.
 */

// Driver: 'mysql' (MariaDB / MySQL InnoDB) atau 'sqlite' (Lokal Standalone)
putenv('DB_DRIVER=mysql');

// Konfigurasi MariaDB / MySQL
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=db_lms_kelautan');
putenv('DB_USER=root');
putenv('DB_PASS=');
