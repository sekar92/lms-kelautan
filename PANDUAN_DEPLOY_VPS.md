# PANDUAN LENGKAP DEPLOYMENT VPS
# LMS POLIKANT × KELAUTAN.ID (EDISI PHP 8.4 & MARIADB INNODB)
### *Target Lingkungan: Ubuntu Server 22.04 LTS — Dedicated VPS Kelautan.ID*

Panduan ini berisi langkah-langkah terstruktur dari awal (*bare-metal / clean VPS*) hingga aplikasi aktif dan dapat diakses dengan aman di **`https://lms.kelautan.id`**.

---

## DAFTAR ISI
1. [Spesifikasi Server & Arsitektur Direktori](#1-spesifikasi-server--arsitektur-direktori)
2. [Persiapan Awal Server Ubuntu 22.04 LTS](#2-persiapan-awal-server-ubuntu-2204-lts)
3. [Instalasi Web Server & Runtime (Apache2, PHP 8.4-FPM, MariaDB)](#3-instalasi-web-server--runtime-apache2-php-84-fpm-mariadb)
4. [Konfigurasi Basis Data MariaDB (InnoDB)](#4-konfigurasi-basis-data-mariadb-innodb)
5. [Sinkronisasi File Aplikasi ke VPS via Rsync](#5-sinkronisasi-file-aplikasi-ke-vps-via-rsync)
6. [Pengaturan Izin Berkas & Kepemilikan Linux (Permissions)](#6-pengaturan-izin-berkas--kepemilikan-linux-permissions)
7. [Inisialisasi Database & Storage via CLI](#7-inisialisasi-database--storage-via-cli)
8. [Konfigurasi Apache VirtualHost & Let's Encrypt SSL](#8-konfigurasi-apache-virtualhost--lets-encrypt-ssl)
9. [Optimasi Performa & Tuning Server (RAM 4GB + SWAP 2GB)](#9-optimasi-performa--tuning-server-ram-4gb--swap-2gb)
10. [Pengujian Pasca-Deploy & Checklist Operasional](#10-pengujian-pasca-deploy--checklist-operasional)

---

## 1. SPESIFIKASI SERVER & ARSITEKTUR DIREKTORI

* **Sistem Operasi**: Ubuntu Server 22.04 LTS (Jammy Jellyfish)
* **Kapasitas RAM**: Minimal 4 GB (dengan SWAP 2 GB)
* **Lokasi Root Proyek**: `/var/www/kelautan.id/lms/`
* **Apache DocumentRoot**: `/var/www/kelautan.id/lms/public_html`
* **Penyimpanan Berkas Privat**: `/var/www/kelautan.id/lms/storage/` *(Di luar jangkauan DocumentRoot publik)*
* **Domain / Subdomain**: `lms.kelautan.id`

---

## 2. PERSIAPAN AWAL SERVER UBUNTU 22.04 LTS

Masuk ke VPS melalui SSH:
```bash
ssh root@IP_VPS_ANDA
```

Perbarui paket sistem dan pasang utilitas penting:
```bash
apt update && apt upgrade -y
apt install -y curl wget git unzip zip rsync ufw software-properties-common ca-certificates lsb-release apt-transport-https
```

### Konfigurasi Firewall (UFW)
Buka port SSH, HTTP, dan HTTPS:
```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status
```

---

## 3. INSTALASI WEB SERVER & RUNTIME (APACHE2, PHP 8.4-FPM, MARIADB)

### A. Pasang Apache2 & Aktifkan Modul
```bash
apt install -y apache2
a2enmod rewrite ssl headers proxy proxy_fcgi setenvif expires
systemctl enable apache2
systemctl restart apache2
```

### B. Pasang PHP 8.4 & Ekstensi yang Dibutuhkan
Gunakan repositori resmi Ondřej Surý:
```bash
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-fileinfo php8.4-opcache php8.4-gd php8.4-cli
systemctl enable php8.4-fpm
systemctl start php8.4-fpm
```

### C. Pasang MariaDB Server 10.6+
```bash
apt install -y mariadb-server mariadb-client
systemctl enable mariadb
systemctl start mariadb
```

Jalankan pengamanan dasar MariaDB:
```bash
mariadb-secure-installation
```
*(Ikuti petunjuk di layar: set password root jika diminta, hapus anonymous users, nonaktifkan remote root login, dan hapus test database).*

---

## 4. KONFIGURASI BASIS DATA MARIADB (INNODB)

Masuk ke console MariaDB sebagai root:
```bash
mariadb -u root -p
```

Eksekusi perintah SQL berikut untuk membuat database `db_lms_kelautan` dan user khusus:
```sql
CREATE DATABASE IF NOT EXISTS db_lms_kelautan
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Ganti 'KATA_SANDI_DB_AMAN' dengan kata sandi acak yang kuat
CREATE USER IF NOT EXISTS 'lms_user'@'localhost' IDENTIFIED BY 'KATA_SANDI_DB_AMAN';

GRANT ALL PRIVILEGES ON db_lms_kelautan.* TO 'lms_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 5. SINKRONISASI FILE APLIKASI KE VPS VIA RSYNC

Di server VPS, siapkan struktur direktori induk:
```bash
mkdir -p /var/www/kelautan.id/lms
chown -R www-data:www-data /var/www/kelautan.id/lms
```

### Eksekusi dari Komputer Lokal Anda (Mac / Linux)
Buka terminal baru di komputer lokal Anda pada folder proyek `lms-kelautan-php`, lalu jalankan `rsync`:
```bash
rsync -avz --progress \
  --exclude '.git' \
  --exclude 'storage/db_lms_kelautan.sqlite' \
  --exclude '.DS_Store' \
  /Users/budhiagungp/Documents/web/lms-kelautan-php/ \
  root@IP_VPS_ANDA:/var/www/kelautan.id/lms/
```

---

## 6. PENGATURAN IZIN BERKAS & KEPEMILIKAN LINUX (PERMISSIONS)

Di server VPS, terapkan kepemilikan dan hak akses yang aman:

```bash
# Tetapkan kepemilikan kepada www-data
chown -R www-data:www-data /var/www/kelautan.id/lms

# Set direktori permission 2775 (setgid agar file baru tetap dimiliki grup www-data)
find /var/www/kelautan.id/lms -type d -exec chmod 2775 {} \;

# Set berkas permission 0664
find /var/www/kelautan.id/lms -type f -exec chmod 0664 {} \;

# Berikan izin eksekusi pada skrip CLI
chmod +x /var/www/kelautan.id/lms/cli/*.php
```

Tambahkan akun deploy pengguna Anda ke dalam grup `www-data` (opsional jika menggunakan non-root user):
```bash
usermod -a -G www-data $USER
```

---

## 7. INISIALISASI DATABASE & STORAGE VIA CLI

### A. Buat File Konfigurasi Lingkungan Produksi (`config/env.php`)
Di server VPS, buat file `config/env.php`:
```bash
nano /var/www/kelautan.id/lms/config/env.php
```

Isi dengan konfigurasi MariaDB Anda:
```php
<?php
// Driver Database Produksi
putenv('DB_DRIVER=mysql');

// Kredensial MariaDB Lokal
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=db_lms_kelautan');
putenv('DB_USER=lms_user');
putenv('DB_PASS=KATA_SANDI_DB_AMAN'); // Samakan dengan password di Langkah 4
```
Simpan dengan menekan `Ctrl + O`, lalu `Enter`, dan keluar dengan `Ctrl + X`.

Kunci izin berkas kredensial ini:
```bash
chmod 640 /var/www/kelautan.id/lms/config/env.php
chown www-data:www-data /var/www/kelautan.id/lms/config/env.php
```

### B. Jalankan Skrip CLI Inisialisasi & Migrasi Database
Jalankan setup CLI untuk membuat/memigrasikan tabel InnoDB, direktori penyimpanan, serta akun awal (otomatis memigrasikan skema ke **v1.1**):
```bash
# Pastikan folder upload foto profil publik ada dan writable
mkdir -p /var/www/kelautan.id/lms/public_html/assets/uploads/avatars
chown -R www-data:www-data /var/www/kelautan.id/lms/public_html/assets/uploads/avatars
chmod -R 2775 /var/www/kelautan.id/lms/public_html/assets/uploads/avatars

# Eksekusi setup (aman dijalankan berulang kali, otomatis idempotent & migrasi v1.1)
php /var/www/kelautan.id/lms/cli/setup.php
```

Output yang diharapkan:
```text
=========================================================
 LMS POLIKANT × KELAUTAN.ID - SETUP & INISIALISASI DB
=========================================================
[INFO] Menggunakan Driver Database: MYSQL
[OK] Struktur 7 tabel berhasil diverifikasi/dibuat.
[OK] Verifikasi migrasi schema v1.1 selesai.
[OK] Data identitas branding Kelautan.ID tersimpan.
[OK] Pengguna dibuat: admin (Dosen Admin) - Kata Sandi: dosen123
[OK] Pengguna dibuat: dosen1 (Dosen) - Kata Sandi: dosen123
[OK] Pengguna dibuat: 2024001 (Mahasiswa) - Kata Sandi: mhs123
[OK] Pengguna dibuat: 2024002 (Mahasiswa) - Kata Sandi: mhs123
[OK] Pengguna dibuat: 2024003 (Mahasiswa) - Kata Sandi: mhs123
[OK] Tahun akademik default dibuat (2026/2027 Ganjil: Aktif).
[OK] Mata kuliah default 'Oseanografi Geologi' (TKL-2101) & 'Meteorologi & Dinamika Oseanografi' (TKL-2102) dibuat.
[OK] Pendaftaran peserta (Mahasiswa 2024001 & 2024002) pada MK-00001 berhasil.
[OK] Konten pembelajaran 16 pertemuan berhasil diisi.
=========================================================
 INISIALISASI SELESAI!
=========================================================
```

Jalankan test suite untuk memastikan seluruh 28 pengujian valid di server:
```bash
php /var/www/kelautan.id/lms/cli/test_suite.php
```

---

## 8. KONFIGURASI APACHE VIRTUALHOST & LET'S ENCRYPT SSL

### A. Buat Konfigurasi VirtualHost Apache2
```bash
nano /etc/apache2/sites-available/lms.kelautan.id.conf
```

Tempel konfigurasi VirtualHost berikut:
```apache
<VirtualHost *:80>
    ServerName lms.kelautan.id
    DocumentRoot /var/www/kelautan.id/lms/public_html

    <Directory /var/www/kelautan.id/lms/public_html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Forward eksekusi PHP ke PHP 8.4-FPM Socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/lms_kelautan_error.log
    CustomLog ${APACHE_LOG_DIR}/lms_kelautan_access.log combined
</VirtualHost>
```

Aktifkan VirtualHost dan restart Apache:
```bash
a2ensite lms.kelautan.id.conf
apache2ctl configtest
systemctl reload apache2
```

### B. Pasang Sertifikat SSL (Let's Encrypt / Certbot)
Pasang Certbot:
```bash
apt install -y certbot python3-certbot-apache
```

Dapatkan dan pasang sertifikat SSL untuk `lms.kelautan.id`:
```bash
certbot --apache -d lms.kelautan.id
```
*(Certbot otomatis mengalihkan HTTP ke HTTPS dan membuat blok VirtualHost *:443).*

### C. Tambahkan Pengaturan Iframe & Header Keamanan (PRD Section 6.4)
Buka file VirtualHost SSL yang telah dihasilkan oleh Certbot:
```bash
nano /etc/apache2/sites-available/lms.kelautan.id-le-ssl.conf
```

Pastikan blok berikut terkonfigurasi di dalam `<VirtualHost *:443>`:
```apache
<VirtualHost *:443>
    ServerName lms.kelautan.id
    DocumentRoot /var/www/kelautan.id/lms/public_html

    <Directory /var/www/kelautan.id/lms/public_html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Teruskan eksekusi ke PHP 8.4-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Izinkan embedding iframe di ekosistem Kelautan.ID dan Polikant
    Header always unset X-Frame-Options
    Header always set Content-Security-Policy "frame-ancestors 'self' https://kelautan.id https://*.kelautan.id https://*.polikant.ac.id;"
    Header set X-Content-Type-Options "nosniff"
    Header set X-XSS-Protection "1; mode=block"

    # Sertifikat SSL Let's Encrypt
    SSLEngine on
    Include /etc/letsencrypt/options-ssl-apache.conf
    SSLCertificateFile /etc/letsencrypt/live/lms.kelautan.id/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/lms.kelautan.id/privkey.pem
</VirtualHost>
```

Uji dan reload Apache:
```bash
apache2ctl configtest
systemctl reload apache2
```

---

## 9. OPTIMASI PERFORMA & TUNING SERVER (RAM 4GB + SWAP 2GB)

### A. Tuning Batas Upload & Performa PHP 8.4-FPM
Default instalasi PHP pada Ubuntu membatasi upload berkas hanya **2 MB** (`upload_max_filesize = 2M`). Untuk mengunggah modul presentasi PPT/PDF hingga 10–20 MB, batas ini wajib dinaikkan.

**Cara Cepat (Perintah 1 Baris di VPS)**:
```bash
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 25M/' /etc/php/8.4/fpm/php.ini
sudo sed -i 's/^post_max_size = .*/post_max_size = 30M/' /etc/php/8.4/fpm/php.ini
sudo systemctl restart php8.4-fpm
```

*Atau edit manual melalui `nano /etc/php/8.4/fpm/php.ini`:*
```ini
memory_limit = 256M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 120
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = "Lax"

; OPcache Tuning
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
```

Buka konfigurasi worker pool `/etc/php/8.4/fpm/pool.d/www.conf`:
```bash
nano /etc/php/8.4/fpm/pool.d/www.conf
```
Sesuaikan worker pool untuk spesifikasi RAM 4GB:
```ini
pm = dynamic
pm.max_children = 35
pm.start_servers = 8
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 500
```
Restart PHP-FPM dan Apache:
```bash
systemctl restart php8.4-fpm apache2
```

### B. Tuning MariaDB InnoDB
Buka `/etc/mysql/mariadb.conf.d/50-server.cnf`:
```bash
nano /etc/mysql/mariadb.conf.d/50-server.cnf
```
Tambahkan di bawah blok `[mysqld]`:
```ini
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
max_connections = 100
```
Restart MariaDB:
```bash
systemctl restart mariadb
```

---

## 10. PENGUJIAN PASCA-DEPLOY & CHECKLIST OPERASIONAL

1. Buka browser dan akses **`https://lms.kelautan.id`**.
2. Pastikan halaman otomatis mengarah ke `login.php` dengan koneksi gembok hijau HTTPS SSL valid.
3. Login sebagai **Dosen / Admin**:
   * **Username**: `admin`
   * **Password**: `dosen123`
4. Uji alur kerja dosen:
   * Buka menu **Tahun Akademik** & periksa status aktif.
   * Buka **Mata Kuliah** & uji preview dokumen RPS PDF.
   * Buka **Ruang Kelas** & uji tab **Peserta Kelas (Enrollment)**.
   * Buka **Pengaturan** & uji tombol **Unduh Backup SQL (`.sql`)**.
5. Buka tab samaran (*Incognito*) dan login sebagai **Mahasiswa**:
   * **Username**: `2024001`
   * **Password**: `mhs123`
6. Uji alur kerja mahasiswa:
   * Masuk ke ruang kelas *Oseanografi Geologi*.
   * Buka tab **Peserta Kelas**: pastikan tampilan bersifat **Strictly Read-Only** tanpa checkbox dan terdapat penanda `[Anda]`.
   * Pada tab **16 Minggu Perkuliahan**, coba kumpulkan berkas jawaban tugas (PDF < 10 MB) dan periksa perubahan status pengumpulan.
7. Ganti kata sandi default `admin` dan mahasiswa melalui menu pojok kanan atas **Profil & Keamanan** ([`profil.php`](file:///Users/budhiagungp/Documents/web/lms-kelautan-php/public_html/profil.php)).

---
*LMS Polikant × Kelautan.ID — Portal Maritim Indonesia siap digunakan dalam kegiatan pembelajaran daring.*
