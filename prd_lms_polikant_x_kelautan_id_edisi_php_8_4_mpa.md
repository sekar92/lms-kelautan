# PRODUCT REQUIREMENT DOCUMENT (PRD)
# LEARNING MANAGEMENT SYSTEM (LMS) POLIKANT × KELAUTAN.ID
### *Powered by Kelautan.ID — Portal Maritim Indonesia*

* **Nama Produk**: LMS Polikant — Kelautan.ID Maritime Learning Platform
* **Ekosistem Induk**: **Kelautan.ID - Portal Maritim Indonesia** (`https://kelautan.id`)
* **Institusi Pengguna**: Politeknik Perikanan Negeri Tual (Program Studi Teknologi Kelautan)
* **Inisiator & Pengembang Utama**: Budhi Agung Prasetyo, S.Pi., M.Si.
* **Versi Dokumen**: 3.3 (Edisi PHP 8.4 Konvensional / Multi-Page Application & MariaDB — Production Ready)
* **Status Proyek**: Active / Migration to Dedicated VPS
* **Infrastruktur Server**: Ubuntu Server 22.04 LTS, Apache2 (Event MPM + Let's Encrypt Wildcard SSL), PHP 8.4-FPM, MariaDB 10.6+ (InnoDB), RAM 4GB, SWAP 2GB
* **Arsitektur Aplikasi**: Traditional Server-Side Rendered (SSR) / Multi-Page Application (MPA) dengan pola *Post/Redirect/Get (PRG)*, Native PHP Sessions, HTML5, Tailwind CSS, Lucide Icons, dan Vanilla JS ringan (hanya untuk interaksi UI dasar)

---

## DAFTAR ISI
1. [Informasi Dokumen, Gambaran Umum & Ekosistem Kelautan.ID](#1-informasi-dokumen-gambaran-umum--ekosistem-kelautanid)
2. [Latar Belakang, Nilai Manfaat & Identitas Brand Kelautan.ID](#2-latar-belakang-nilai-manfaat--identitas-brand-kelautanid)
   - [2.1 Tantangan Institusi & Alasan Penggunaan Arsitektur PHP Konvensional (MPA)](#21-tantangan-institusi--alasan-penggunaan-arsitektur-php-konvensional-mpa)
   - [2.2 Nilai Manfaat Utama (Value Proposition)](#22-nilai-manfaat-utama-value-proposition)
   - [2.3 Pedoman Identitas Visual & Branding Kelautan.ID (Brand Guidelines)](#23-pedoman-identitas-visual--branding-kelautanid-brand-guidelines)
   - [2.4 Integrasi Ekosistem Layanan Kelautan.ID](#24-integrasi-ekosistem-layanan-kelautanid)
3. [Peran Pengguna & Hak Akses (User Roles)](#3-peran-pengguna--hak-akses-user-roles)
4. [Daftar Fitur Lengkap Sistem](#4-daftar-fitur-lengkap-sistem)
   - [4.1 Modul Otentikasi, Akun & Keamanan Sesi (Native PHP Session)](#41-modul-otentikasi-akun--keamanan-sesi-native-php-session)
   - [4.2 Modul Manajemen Siklus Tahun Akademik](#42-modul-manajemen-siklus-tahun-akademik)
   - [4.3 Modul Manajemen Mata Kuliah, Deskripsi & Dokumen Pendukung (RPS/Silabus)](#43-modul-manajemen-mata-kuliah-deskripsi--dokumen-pendukung-rpssilabus)
   - [4.4 Modul Manajemen Data Mahasiswa & Impor Massal (Bulk Import)](#44-modul-manajemen-data-mahasiswa--impor-massal-bulk-import)
   - [4.5 Modul Ruang Kelas & Manajemen Peserta (Enrollment - Role-Based)](#45-modul-ruang-kelas--manajemen-peserta-enrollment---role-based)
   - [4.6 Modul 16 Minggu Pembelajaran & Integrasi Pustaka Maritim](#46-modul-16-minggu-pembelajaran--integrasi-pustaka-maritim)
   - [4.7 Modul Evaluasi & Penilaian Tugas Mahasiswa](#47-modul-evaluasi--penilaian-tugas-mahasiswa)
   - [4.8 Modul Pengaturan Branding Dinamis Kelautan.ID & Pemeliharaan Database](#48-modul-pengaturan-branding-dinamis-kelautanid--pemeliharaan-database)
5. [Alur Kerja Sistem (Workflows)](#5-alur-kerja-sistem-workflows)
   - [5.1 Alur Deployment & Inisialisasi Sistem Pertama Kali](#51-alur-deployment--inisialisasi-sistem-pertama-kali)
   - [5.2 Alur Kerja Dosen (Lecturer Lifecycle Workflow - MPA)](#52-alur-kerja-dosen-lecturer-lifecycle-workflow---mpa)
   - [5.3 Alur Kerja Mahasiswa (Student Learning Workflow - MPA)](#53-alur-kerja-mahasiswa-student-learning-workflow---mpa)
   - [5.4 Alur Transisi Pergantian Tahun Akademik](#54-alur-transisi-pergantian-tahun-akademik)
   - [5.5 Alur Penanganan Berkas & Keamanan File Storage Linux](#55-alur-penanganan-berkas--keamanan-file-storage-linux)
6. [Spesifikasi Arsitektur Server, Basis Data & Struktur File PHP](#6-spesifikasi-arsitektur-server-basis-data--struktur-file-php)
   - [6.1 Skema Tabel Relasional (MariaDB InnoDB)](#61-skema-tabel-relasional-mariadb-innodb)
   - [6.2 Struktur Direktori & Halaman PHP Konvensional (`/var/www/kelautan.id/lms/`)](#62-struktur-direktori--halaman-php-konvensional-varwwwkelautanidlms)
   - [6.3 Optimasi Konkurensi & Tuning Server (RAM 4GB + SWAP 2GB)](#63-optimasi-konkurensi--tuning-server-ram-4gb--swap-2gb)
   - [6.4 Pengaturan Iframe, Keamanan Header & Embedding Portal Kelautan.ID](#64-pengaturan-iframe-keamanan-header--embedding-portal-kelautanid)
7. [Detail Pembaharuan & Transformasi Arsitektur (Changelog)](#7-detail-pembaharuan--transformasi-arsitektur-changelog)
8. [Panduan Operasional & Kredensial Bawaan](#8-panduan-operasional--kredensial-bawaan)

---

## 1. INFORMASI DOKUMEN, GAMBARAN UMUM & EKOSISTEM KELAUTAN.ID

**LMS Polikant × Kelautan.ID** adalah platform pembelajaran daring (*Learning Management System*) yang dikembangkan khusus untuk sivitas akademika **Politeknik Perikanan Negeri Tual (Polikant)** dan terintegrasi penuh di bawah naungan ekosistem **Kelautan.ID - Portal Maritim Indonesia**.

Sebagai pusat informasi riset, pendidikan vokasi, dan manajemen data kelautan terpadu di Indonesia, **Kelautan.ID** menyediakan infrastruktur digital mandiri yang menyatukan kegiatan perkuliahan, repositori riset pesisir dan laut, publikasi ilmiah, serta layanan utilitas akademik.

Pada versi 3.2 ini, aplikasi dibangun menggunakan **metode PHP konvensional (*Multi-Page Application* / *Server-Side Rendering*)** di atas infrastruktur **Ubuntu Server 22.04 LTS** milik **Kelautan.ID**:
* **Arsitektur Web Konvensional (Non-SPA)**: Setiap halaman (`login.php`, `dashboard.php`, `mata-kuliah.php`, `ruang-kelas.php`, `mahasiswa.php`, dll.) dirender langsung oleh **PHP 8.4-FPM** di sisi server. Pengiriman formulir dan unggah berkas menggunakan mekanisme standar HTML `<form method="POST" enctype="multipart/form-data">` dengan pola **Post/Redirect/Get (PRG)** serta notifikasi *Flash Session*.
* **Web Server & SSL**: Apache2 (`mpm_event`, `proxy_fcgi`, `rewrite`) dengan sertifikat keamanan **Let's Encrypt Wildcard SSL** (`*.kelautan.id`).
* **Database Relasional**: **MariaDB 10.6+** menggunakan *storage engine* **InnoDB** yang mendukung transaksi atomik (`ACID`), *row-level locking*, dan integritas *Foreign Key*.
* **Media Penyimpanan Berkas**: Penyimpanan sistem berkas lokal Linux (`/var/www/kelautan.id/lms/storage/`) di luar direktori publik dengan proteksi skrip streaming PHP (`unduh.php`).
* **Antarmuka & Interaktivitas Ringan**: HTML5 yang disusun secara modular (`includes/header.php`, `includes/navbar.php`, `includes/footer.php`), dipercantik dengan Tailwind CSS bertema *Oceanic Maritime Gradient* khas **Kelautan.ID**, Lucide Icons, Google Fonts (*Outfit* & *Plus Jakarta Sans*), serta sedikit Vanilla JavaScript murni hanya untuk membuka modal pratinjau PDF, akordeon pertemuan, dan tombol centang semua (*Select All*).

Aplikasi ini dapat diakses langsung melalui **`https://lms.kelautan.id`** maupun disematkan (*embedded*) ke dalam portal utama **`https://kelautan.id`** dan website resmi kampus melalui `<iframe>`.

---

## 2. LATAR BELAKANG, NILAI MANFAAT & IDENTITAS BRAND KELAUTAN.ID

### 2.1 Tantangan Institusi & Alasan Penggunaan Arsitektur PHP Konvensional (MPA)
1. **Kemudahan Pemeliharaan & Stabilitas Tinggi**: Arsitektur PHP konvensional (*Multi-Page Application*) jauh lebih mudah dipelihara, di-debug, dan dikembangkan dibanding SPA berbasis JavaScript kompleks. Tidak ada risiko halaman kosong (*blank screen*) akibat kegagalan pemuatan state JavaScript di peramban mahasiswa.
2. **Penghapusan Limitasi Google Apps Script**: Menghilangkan batas waktu eksekusi 6 menit, antrean `LockService` pada Google Sheets, serta overhead konversi berkas ke string Base64 dengan memindahkan seluruh beban kerja ke VPS Ubuntu 22.04 milik **Kelautan.ID**.
3. **Kesesuaian dengan Jaringan di Wilayah Kepulauan**: Dengan merender HTML langsung dari server PHP 8.4-FPM, peramban di ponsel pintar mahasiswa tidak perlu mengunduh dan mengeksekusi bundel JavaScript besar. Setiap perpindahan halaman dan pengiriman formulir bekerja andal meskipun pada koneksi internet yang fluktuatif.

### 2.2 Nilai Manfaat Utama (Value Proposition)
* **Server-Side Reliability**: Seluruh validasi hak akses, sesi pengguna, dan pemrosesan data dilakukan 100% di sisi server oleh PHP 8.4 sebelum halaman dikirim ke peramban.
* **Ekosistem Maritim Terpadu**: Terhubung langsung dengan identitas dan layanan **Kelautan.ID - Portal Maritim Indonesia**, memperkuat citra akademik bidang ilmu dan teknologi kelautan.
* **Pemisahan Hak Akses Presisi**: Dosen mengontrol kelas, kurikulum, peserta, dan konfigurasi portal, sedangkan mahasiswa fokus belajar secara mandiri tanpa risiko merusak konfigurasi kelas.
* **Skalabilitas Data Instan**: Mendukung penambahan ratusan mahasiswa secara massal dari Excel/Sheets melalui pengiriman `<form method="POST">` standar yang diproses dalam hitungan milidetik menggunakan transaksi `PDO` MariaDB.

### 2.3 Pedoman Identitas Visual & Branding Kelautan.ID (Brand Guidelines)
Seluruh halaman PHP dalam LMS wajib menerapkan standar identitas visual **Kelautan.ID - Portal Maritim Indonesia**:

1. **Identitas Logo & Tipografi**:
   * **Logo Utama**: *Combination mark* modern dan minimalis **Kelautan.ID** yang merepresentasikan **gradasi warna laut serta elemen ombak dinamis**, dipadukan secara harmonis (*co-branding*) dengan lambang **Politeknik Perikanan Negeri Tual**.
   * **Tipografi**: Menggunakan pasangan fon *sans-serif* modern — **Outfit** untuk judul halaman, nama brand, dan angka statistik; serta **Plus Jakarta Sans** untuk teks isi, tabel, dan formulir.
2. **Palet Warna Resmi (*Oceanic Maritime Gradient*)**:
   * **Deep Abyssal Navy (`#0A192F` s/d `#0F172A`)**: Warna latar belakang utama untuk bilah navigasi atas (`includes/navbar.php`), kartu header utama, dan latar halaman `login.php`.
   * **Maritime Royal Blue (`#1E3A8A` s/d `#0284C7`)**: Warna primer untuk komponen aktif, header tabel, dan tombol submit utama.
   * **Oceanic Cyan / Wave Turquoise (`#06B6D4` s/d `#22D3EE`)**: Warna aksen khas elemen ombak **Kelautan.ID** untuk sorotan interaktif, lencana (*pill badge*) Tahun Akademik, serta efek gradasi (`bg-gradient-to-r from-slate-900 via-blue-900 to-cyan-600`).
   * **Coral Amber & Seafoam Green (`#F59E0B` & `#10B981`)**: Warna indikator status tugas, lencana `"Terdaftar"`, dan pesan *Flash Session* berhasil.
3. **Penempatan Atribut Brand di Setiap Halaman PHP**:
   * **`login.php`**: Menampilkan *Combination Mark Logo* **Kelautan.ID**, tajuk utama **"LMS POLIKANT"**, sub-tajuk **"Powered by Kelautan.ID — Portal Maritim Indonesia"**, serta latar belakang bergaya ilustrasi gelombang laut minimalis.
   * **`includes/navbar.php`**: Menampilkan identitas ganda (**Polikant × Kelautan.ID**), lencana Tahun Akademik aktif, navigasi antar-halaman, serta tautan pintas menuju beranda portal utama `https://kelautan.id`.
   * **`includes/footer.php`**: Menampilkan kredit resmi di bagian bawah seluruh halaman:  
     `© 2026 Kelautan.ID - Portal Maritim Indonesia × Politeknik Perikanan Negeri Tual. Dikembangkan untuk Kemajuan Riset & Pendidikan Vokasi Kelautan Indonesia.`

### 2.4 Integrasi Ekosistem Layanan Kelautan.ID
Di dalam server Ubuntu 22.04 (`/var/www/kelautan.id/`), LMS ini terintegrasi dengan ekosistem subdomain **Kelautan.ID** yang diamankan dengan sertifikat **Let's Encrypt Wildcard SSL (`*.kelautan.id`)**:

| Subdomain / Layanan | Fungsi dalam Ekosistem LMS Polikant × Kelautan.ID |
|---|---|
| **`kelautan.id`** | Portal utama informasi riset, artikel ilmiah populer, dan pusat data maritim Indonesia. |
| **`lms.kelautan.id`** | Aplikasi utama *Learning Management System* (LMS) perkuliahan daring Polikant. |
| **`journal.kelautan.id`** | Portal publikasi dan jurnal penelitian kelautan (rujukan materi kuliah & literatur mahasiswa). |
| **`go.kelautan.id`** | Layanan pemendek tautan (*URL Shortener*) resmi untuk distribusi link kuis, ujian, dan materi. |
| **`webmail.kelautan.id`** | Layanan surat elektronik resmi bagi pengelola, dosen, dan dukungan teknis (`teknologikelautanid@gmail.com` / `@kelautan.id`). |
| **`router.kelautan.id`** | Manajemen *reverse proxy*, gateway integrasi API, dan orkestrasi layanan cerdas (9Router). |

---

## 3. PERAN PENGGUNA & HAK AKSES (USER ROLES)

Sistem membedakan dua peran pengguna (*roles*) utama yang divalidasi secara langsung oleh middleware PHP (`includes/auth_check.php`) pada setiap pemuatan halaman maupun pengiriman formulir `POST`:

| Fitur / Modul | Halaman PHP Terkait | Dosen / Admin (Kelautan.ID) | Mahasiswa |
|---|---|:---:|:---:|
| Login & Ganti Password Mandiri | `login.php`, `profil.php` | ✅ | ✅ |
| Kelola Pengaturan Branding Kelautan.ID | `pengaturan.php` | ✅ | ❌ |
| Manajemen Siklus Tahun Akademik | `tahun-akademik.php` | ✅ | ❌ (Hanya Lihat Status) |
| Tambah, Edit, Hapus Mata Kuliah | `mata-kuliah.php` | ✅ | ❌ |
| Upload Dokumen RPS & Silabus Mingguan | `mata-kuliah.php`, `ruang-kelas.php` | ✅ | ❌ |
| Preview & Unduh Dokumen RPS & Silabus | `ruang-kelas.php`, `unduh.php` | ✅ | ✅ |
| Tambah Data Mahasiswa Satuan & Massal | `mahasiswa.php` | ✅ | ❌ |
| Kelola Peserta Kelas / Enrollment | `ruang-kelas.php?tab=peserta` | ✅ | ❌ |
| Melihat Daftar Teman Sekelas | `ruang-kelas.php?tab=peserta` | ✅ | ✅ (Read-Only) |
| Kelola Konten 16 Pertemuan | `ruang-kelas.php?tab=pertemuan` | ✅ | ❌ |
| Akses Materi Pembelajaran & Literatur | `ruang-kelas.php`, `unduh.php` | ✅ | ✅ |
| Mengunggah Jawaban Tugas PDF (< 10 MB) | `tugas-kumpul.php` / `ruang-kelas.php` | ❌ | ✅ |
| Preview Soal Tugas PDF | `ruang-kelas.php`, `unduh.php` | ✅ | ✅ |
| Penilaian Tugas & Feedback Kualitatif | `tugas-nilai.php` | ✅ | ❌ |
| Melihat Hasil Nilai & Feedback Tugas | `ruang-kelas.php` | ✅ (Rekap Semua) | ✅ (Milik Sendiri) |
| Akses Kuis Interaktif & Ujian Daring | `ruang-kelas.php` | ✅ (Preview) | ✅ (Mengerjakan) |
| Inisialisasi / Reset & Backup Database | `pengaturan.php` | ✅ (Terproteksi) | ❌ |

---

## 4. DAFTAR FITUR LENGKAP SISTEM

### 4.1 Modul Otentikasi, Akun & Keamanan Sesi (Native PHP Session)
1. **Otentikasi Server-Side Murni (`login.php`)**:
   * Pengguna memasukkan Username/NIM dan Password melalui form HTML standar (`POST` ke `login.php`).
   * Verifikasi kata sandi menggunakan fungsi native PHP 8.4 `password_verify()` dengan algoritma hash **Argon2id** atau **Bcrypt** serta **PDO Prepared Statements**.
   * Jika berhasil, server memanggil `session_regenerate_id(true)`, menyimpan `user_id`, `username`, `nama`, dan `role` ke dalam `$_SESSION`, lalu melakukan redirect `header('Location: dashboard.php')`.
2. **Penghapusan Demo 1-Klik Publik**:
   * Halaman `login.php` mewajibkan input manual Username/NIM dan Password tanpa tombol login instan publik.
3. **Proteksi Halaman & CSRF Token (`includes/auth_check.php`)**:
   * Setiap halaman internal menyertakan `require_once 'includes/auth_check.php';` di baris paling atas. Jika `$_SESSION['user_id']` tidak ditemukan, pengguna otomatis dialihkan ke `login.php`.
   * Setiap form `<form method="POST">` menyertakan `<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">` untuk mencegah serangan *Cross-Site Request Forgery*.
4. **Sistem Notifikasi *Flash Message* (Pola PRG)**:
   * Setiap aksi `POST` (tambah data, ubah data, hapus data, unggah tugas) menyetel pesan notifikasi di `$_SESSION['flash']` kemudian melakukan redirect (`header('Location: ...')`) agar pengguna dapat me-refresh halaman (F5) tanpa mengirim ulang data form (*duplicate form submission*).
5. **Fitur Ganti Password (`profil.php`)**:
   * Halaman khusus penggantian kata sandi untuk Dosen dan Mahasiswa dengan validasi kata sandi lama.
6. **Normalisasi Role Toleran**:
   * Pengecekan peran pengguna menggunakan fungsi `strtolower(trim($_SESSION['role']))` untuk menghindari kendala perbedaan huruf besar/kecil (`dosen`, `Dosen`, `mahasiswa`, `Mahasiswa`).

### 4.2 Modul Manajemen Siklus Tahun Akademik (`tahun-akademik.php`)
1. **Status Tahun Akademik Global**:
   * Diambil langsung oleh PHP dari tabel `tahun_akademik` (`WHERE status = 'Aktif'`) dan dirender pada `includes/navbar.php` serta header `dashboard.php`.
2. **Pembuatan Periode Akademik Baru**:
   * Dosen mengisi form tambah Tahun Akademik (contoh: `2026/2027 Ganjil`, `2026/2027 Genap`) dan mengirimkannya via `POST` ke `tahun-akademik.php`.
3. **Otomatisasi Status Aktif via Transaksi MariaDB**:
   * Ketika dosen menekan tombol **"Aktifkan"** pada salah satu periode atau mencentang *"Jadikan Tahun Akademik Aktif"* saat membuat periode baru, PHP menjalankan transaksi SQL untuk menonaktifkan seluruh periode lama dan mengaktifkan periode terpilih, lalu melakukan redirect kembali ke `tahun-akademik.php` dengan pesan sukses.
4. **Riwayat Semester**:
   * Tabel HTML yang merender seluruh riwayat Tahun Akademik beserta jumlah mata kuliah terkait.

### 4.3 Modul Manajemen Mata Kuliah, Deskripsi & Dokumen Pendukung (`mata-kuliah.php`)
1. **Penyusunan Data Mata Kuliah**:
   * Formulir standar untuk menambah/mengedit Nama Mata Kuliah, Kode Mata Kuliah, Jumlah SKS (1 s/d 6 SKS), Semester (1 s/d 8), dan Dosen Pengampu.
2. **Deskripsi Komprehensif Mata Kuliah**:
   * Area teks deskripsi mengenai capaian pembelajaran mata kuliah (CPMK), prasyarat, metode pembelajaran, serta rujukan ke portal **`kelautan.id`**.
3. **Unggah Langsung Dokumen RPS & Silabus (`multipart/form-data`)**:
   * Input `<input type="file" name="rps_file" accept=".pdf">` dan `<input type="file" name="silabus_file" accept=".pdf,.docx">` yang langsung diproses oleh `$_FILES` di PHP dan disimpan ke `/var/www/kelautan.id/lms/storage/02_RPS_dan_Silabus/`.
4. **Pratinjau & Unduh Dokumen (`unduh.php`)**:
   * Tombol **"Preview RPS"** dan **"Preview Silabus"** membuka modal `<iframe>` yang mengarah ke `unduh.php?jenis=rps&id_mk=MK-00001&mode=inline`.
   * Tersedia pula tombol **"Unduh Berkas"** (`unduh.php?jenis=rps&id_mk=MK-00001&mode=download`) dan tautan untuk membuka dokumen di tab peramban baru.

### 4.4 Modul Manajemen Data Mahasiswa & Impor Massal (`mahasiswa.php`)
1. **Penambahan Mahasiswa Satuan**:
   * Formulir input individual: NIM (sebagai Username), Nama Lengkap, Angkatan, dan Password awal.
2. **Fitur Impor Massal Mahasiswa (Bulk Import dari Excel / Sheets)**:
   * Dosen/Admin menyalin ratusan baris data dari Microsoft Excel atau Google Sheets lalu menempelkannya (*paste*) ke dalam `<textarea name="bulk_data">` pada halaman `mahasiswa.php` dan menekan tombol **Simpan Impor Massal**.
   * **Pemisah Kolom Fleksibel**: Skrip PHP mem-parsing tiap baris dengan `preg_split('/[\t,;|]+/', $baris)` sehingga mendukung pemisah TAB, Koma (`,`), Titik Koma (`;`), maupun Garis Tegak (`|`).
   * **Format Data**: `NIM, Nama Mahasiswa, Angkatan` (kolom angkatan opsional).
3. **Auto-Generated ID_User Unik & Password Default**:
   * PHP menghasilkan ID berurutan `USR-XXXXX` dan menyetel password awal sama dengan **NIM** mahasiswa (di-hash dengan `password_hash()`).
4. **Proteksi Duplikasi & Laporan Impor**:
   * NIM yang sudah terdaftar di MariaDB akan dilewati (*skipped*). Setelah proses selesai, halaman memuat ringkasan laporan jumlah mahasiswa yang berhasil ditambahkan beserta daftar NIM duplikat yang dilewati.

### 4.5 Modul Ruang Kelas & Manajemen Peserta (`ruang-kelas.php`)
1. **Struktur Navigasi Ruang Kelas 3-Tab (`GET` Query Parameter)**:
   * Halaman ruang kelas diakses melalui URL standar:
     - `ruang-kelas.php?id=MK-00001&tab=pertemuan` (**Tab 1: Pertemuan 1 - 16**)
     - `ruang-kelas.php?id=MK-00001&tab=rps` (**Tab 2: Deskripsi & RPS**)
     - `ruang-kelas.php?id=MK-00001&tab=peserta` (**Tab 3: Peserta Kelas**)
2. **Tampilan Tab Peserta Khusus Dosen (Full Control)**:
   * PHP mengecek `if ($_SESSION['role'] === 'Dosen')` dan merender panel berjudul *"Kelola Peserta Kelas (Enrollment)"*.
   * Menampilkan form `<form method="POST" action="ruang-kelas.php?id=MK-00001&tab=peserta">` berisi daftar seluruh mahasiswa di politeknik lengkap dengan `<input type="checkbox" name="mahasiswa_ids[]" value="USR-XXXXX">`.
   * Dilengkapi tombol bantu **"Pilih Semua"** dan **"Batal Semua"** serta tombol submit **"Simpan Perubahan Peserta"**.
   * Menampilkan indikator ringkasan: `X Terdaftar dari Y Mahasiswa`.
3. **Tampilan Tab Peserta Khusus Mahasiswa (Strictly Read-Only)**:
   * Jika yang membuka halaman adalah Mahasiswa (`$_SESSION['role'] === 'Mahasiswa'`), PHP **tidak merender** elemen `<form>`, kotak centang, maupun tombol simpan sama sekali di dalam HTML yang dikirim ke peramban.
   * Hanya merender kartu daftar rekan sekelas yang berstatus **Terdaftar**, lengkap dengan avatar inisial nama bernuansa biru laut **Kelautan.ID**, Nama Lengkap, NIM, Angkatan, lencana hijau `"Terdaftar"`, dan lencana khusus `[Anda]` pada baris mahasiswa yang sedang login.
4. **Proteksi Keamanan Server**:
   * Apabila pengguna berstatus Mahasiswa mencoba mengirimkan request `POST` perubahan peserta secara paksa, blok pemroses PHP di `ruang-kelas.php` langsung menolak eksekusi (`HTTP 403 Forbidden`) dan mengembalikan pesan peringatan hak akses.

### 4.6 Modul 16 Minggu Pembelajaran & Integrasi Pustaka Maritim (`ruang-kelas.php?tab=pertemuan`)
Pada tab Pertemuan, PHP merender akordeon **Minggu ke-1 s/d Minggu ke-16** yang berisi komponen pembelajaran:

1. **Komponen Materi Perkuliahan & Referensi Kelautan.ID**:
   * Dosen menambahkan judul materi, uraian/instruksi, berkas lampiran (PDF, PPTX, DOCX), serta tautan referensi artikel/jurnal dari **`kelautan.id`** atau **`journal.kelautan.id`**.
   * Berkas disimpan di `/var/www/kelautan.id/lms/storage/01_Materi_Perkuliahan/` dan dapat dilihat melalui modal pratinjau atau diunduh via `unduh.php`.
2. **Komponen Tugas Perkuliahan (Assignment)**:
   * Judul tugas, instruksi pengerjaan, batas waktu (*deadline* tanggal & jam), serta lampiran **Berkas Soal Tugas (PDF)**.
   * **Akses Berkas Soal oleh Mahasiswa**: Mahasiswa dapat menekan tombol **"Lihat Soal PDF"** (membuka modal iframe `unduh.php`) atau **"Unduh Soal"** langsung dari kartu tugas.
3. **Komponen Kuis Daring (Online Quiz)**:
   * Menyematkan tautan kuis interaktif (`go.kelautan.id`, Google Forms, Quizizz, dll.) beserta informasi durasi pengerjaan dalam menit.
4. **Komponen Ujian Daring (Exam Embed)**:
   * Menyematkan tautan Ujian Tengah Semester (UTS) atau Ujian Akhir Semester (UAS), lengkap dengan tampilan iframe terintegrasi maupun tombol *"Buka Ujian di Tab Baru"*.

### 4.7 Modul Evaluasi & Penilaian Tugas Mahasiswa (`tugas-kumpul.php` & `tugas-nilai.php`)
1. **Pengumpulan Berkas Jawaban oleh Mahasiswa**:
   * Mahasiswa mengunggah berkas jawaban format PDF (maksimum 10 MB) beserta catatan tambahan melalui form `<form method="POST" action="tugas-kumpul.php" enctype="multipart/form-data">`.
   * PHP memvalidasi ukuran berkas dan *magic bytes* MIME type asli menggunakan `finfo_file()` (`application/pdf`), mengubah nama berkas secara otomatis menjadi `[NIM]_[Nama_Tugas_Sanitized].pdf`, dan menyimpannya ke folder privat `/var/www/kelautan.id/lms/storage/03_Pengumpulan_Tugas/`.
2. **Status Pengerjaan pada Tampilan Mahasiswa**:
   * Dirender langsung oleh PHP berdasarkan data di tabel `pengumpulan_tugas`:
     - `Belum Mengumpulkan` (Lencana Abu-abu).
     - `Sudah Mengumpulkan` (Lencana Biru Laut + Waktu Submit).
     - `Sudah Dinilai` (Lencana Hijau + Skor Nilai & Catatan Feedback Dosen).
3. **Halaman Pemeriksaan & Penilaian Dosen (`tugas-nilai.php?id_konten=KNT-XXXXX`)**:
   * Dosen membuka halaman khusus rekap pengumpulan untuk tugas terkait yang menampilkan tabel seluruh mahasiswa peserta kelas beserta status pengumpulan mereka.
   * Dosen dapat menekan tombol **"Periksa PDF"** untuk melihat jawaban mahasiswa di layar, lalu mengisi form **Nilai Numerik (0 - 100)** dan **Feedback Kualitatif** pada baris mahasiswa tersebut dan menekan **Simpan Nilai**.

### 4.8 Modul Pengaturan Branding Dinamis Kelautan.ID & Pemeliharaan Database (`pengaturan.php`)
1. **Manajemen Identitas & Branding Dinamis Kelautan.ID**:
   * Halaman khusus Dosen/Admin untuk memperbarui atribut identitas portal di tabel `pengaturan_sistem` melalui form standar:
     - Nama Platform (`Kelautan.ID - Portal Maritim Indonesia`)
     - Nama Aplikasi (`LMS Polikant × Kelautan.ID`)
     - Slogan / Tagline (`Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia`)
     - Tautan Ekosistem (`https://kelautan.id`, `https://journal.kelautan.id`, `https://go.kelautan.id`)
     - Email Dukungan Resmi (`teknologikelautanid@gmail.com`)
2. **Relokasi Fitur Inisialisasi/Reset & Backup Database**:
   * Tombol reset/inisialisasi database tidak pernah ditampilkan di halaman `login.php`.
   * Hanya tersedia di dalam halaman terproteksi `pengaturan.php` (serta tautan pintas di menu dropdown profil Dosen), dilengkapi konfirmasi password Admin, serta tombol **Unduh Backup SQL (`.sql`)**.

---

## 5. ALUR KERJA SISTEM (WORKFLOWS)

### 5.1 Alur Deployment & Inisialisasi Sistem Pertama Kali
```
flowchart TD
    A[Siapkan Direktori /var/www/kelautan.id/lms di Ubuntu 22.04] --> B[Set Kepemilikan chown -R www-data:www-data]
    B --> C[Konfigurasi Apache2 VirtualHost & Let's Encrypt Wildcard SSL]
    C --> D[Buat Database MariaDB db_lms_kelautan & User DB]
    D --> E[Jalankan Skrip CLI php cli/setup.php]
    E --> F[Skrip Membuat 7 Tabel InnoDB & Direktori Storage 01, 02, 03]
    F --> G[Skrip Mengisi Branding Kelautan.ID & Akun Default Dosen/Mahasiswa]
    G --> H[Akses https://lms.kelautan.id/login.php - Sistem Siap Digunakan]
```

### 5.2 Alur Kerja Dosen (Lecturer Lifecycle Workflow - MPA)
```
flowchart TD
    D1[Dosen Buka login.php & Submit Kredensial] --> D2[Redirect ke dashboard.php]
    D2 --> D3{Pilih Menu Navigasi}
    
    D3 -->|tahun-akademik.php| TA1[Cek Status TA Aktif]
    TA1 --> TA2[Submit Form POST TA Baru jika Ganti Semester]
    TA2 --> TA3[Transaksi MariaDB Set TA Baru Aktif & Redirect PRG]
    
    D3 -->|mahasiswa.php| MHS1[Salin Daftar Mahasiswa dari Excel]
    MHS1 --> MHS2[Paste di Textarea Impor Massal & Submit Form POST]
    MHS2 --> MHS3[PHP PDO Transaction Generate ID_User & Hash Password NIM]
    
    D3 -->|mata-kuliah.php| MK1[Isi Form Tambah Mata Kuliah Baru]
    MK1 --> MK2[Pilih File RPS & Silabus PDF pada Form Multipart]
    MK2 --> MK3[Submit POST: File Tersimpan di Storage & Data di MariaDB]
    
    MK3 --> CR1[Buka ruang-kelas.php?id=MK-XXXXX]
    CR1 --> CR2[Tab Peserta: Centang Mahasiswa & Klik Simpan Perubahan]
    CR2 --> CR3[Tab Pertemuan: Submit Form Materi / Tugas / Kuis / Ujian]
    
    CR3 --> EVAL1[Buka tugas-nilai.php?id_konten=KNT-XXXXX]
    EVAL1 --> EVAL2[Klik Periksa PDF via unduh.php]
    EVAL2 --> EVAL3[Submit Form Nilai 0-100 & Feedback Dosen]
```

### 5.3 Alur Kerja Mahasiswa (Student Learning Workflow - MPA)
```
flowchart TD
    S1[Mahasiswa Login di login.php dengan NIM & Password] --> S2[Redirect ke dashboard.php]
    S2 --> S3[Lihat Kartu Mata Kuliah yang Diikuti]
    S3 --> S4[Klik Mata Kuliah -> Buka ruang-kelas.php?id=MK-XXXXX]
    
    S4 --> S5{Pilih Tab Halaman}
    S5 -->|?tab=rps| R1[Baca Capaian Pembelajaran & Literatur Kelautan.ID]
    R1 --> R2[Preview / Unduh RPS & Silabus via unduh.php]
    
    S5 -->|?tab=peserta| P1[Server Merender Daftar Teman Sekelas - Read Only]
    P1 --> P2[Lihat Akun Sendiri bertanda Lencana Anda]
    
    S5 -->|?tab=pertemuan| W1[Buka Akordeon Minggu Perkuliahan 1-16]
    W1 --> W2[Unduh / Preview Materi PDF]
    W1 --> W3[Buka Kuis Interaktif / Ujian Daring]
    
    W1 --> T1[Lihat Komponen Tugas]
    T1 --> T2[Klik Lihat Soal PDF / Unduh Petunjuk via unduh.php]
    T2 --> T3[Pilih File Jawaban PDF Maks 10MB & Isi Catatan di Form Tugas]
    T3 --> T4[Submit Form Multipart POST ke tugas-kumpul.php]
    T4 --> T5[Redirect Kembali ke ruang-kelas.php dengan Flash Message Sukses]
    T5 --> T6[Lihat Status Berubah & Pantau Nilai/Feedback Dosen]
```

### 5.4 Alur Transisi Pergantian Tahun Akademik
1. Semester berjalan berakhir.
2. Dosen membuka halaman **`tahun-akademik.php`** dari bilah navigasi.
3. Mengisi formulir **"+ Tambah Tahun Akademik Baru"** (misalnya: `2026/2027 Ganjil`) dan mencentang opsi **"Jadikan Tahun Akademik Aktif"**, lalu menekan tombol **Simpan**.
4. Skrip PHP `tahun-akademik.php` memproses request `POST` di dalam transaksi MariaDB:
   * Mengubah seluruh Tahun Akademik sebelumnya menjadi `Tidak Aktif`.
   * Menyimpan Tahun Akademik baru dengan status `Aktif`.
   * Menyetel pesan `$_SESSION['flash_success']` dan melakukan redirect `header('Location: tahun-akademik.php')`.
5. Saat halaman dimuat kembali, lencana Tahun Akademik di `includes/navbar.php` otomatis menampilkan periode terbaru di seluruh halaman.

### 5.5 Alur Penanganan Berkas & Keamanan File Storage Linux
```
flowchart LR
    Client[Form HTML Multipart] -->|1. Submit POST File + CSRF Token| PHP[Skrip PHP Pemroses]
    PHP -->|2. Cek Session Role, Ukuran < 10MB & MIME finfo_file| Storage{Tipe Dokumen}
    
    Storage -->|Materi / RPS / Soal| DirPublik[/storage/01_Materi & 02_RPS/]
    Storage -->|Jawaban Tugas Mhs| DirPrivat[/storage/03_Pengumpulan_Tugas/]
    
    DirPrivat --> Rename[Rename Otomatis: NIM_NamaTugas.pdf]
    DirPublik --> SaveDB[(Simpan Path ke MariaDB & Redirect PRG)]
    Rename --> SaveDB
```

---

## 6. SPESIFIKASI ARSITEKTUR SERVER, BASIS DATA & STRUKTUR FILE PHP

### 6.1 Skema Tabel Relasional (MariaDB InnoDB)

Database menggunakan nama `db_lms_kelautan` dengan karakter set `utf8mb4_unicode_ci` dan terdiri dari **7 tabel relasional**:

```sql
CREATE DATABASE IF NOT EXISTS db_lms_kelautan
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE db_lms_kelautan;

-- 1. Tabel Pengaturan Branding Dinamis Kelautan.ID
CREATE TABLE pengaturan_sistem (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabel Users (Dosen/Admin & Mahasiswa)
CREATE TABLE users (
    id_user VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nama VARCHAR(150) NOT NULL,
    role ENUM('Dosen', 'Mahasiswa') NOT NULL DEFAULT 'Mahasiswa',
    angkatan CHAR(4) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_angkatan (angkatan)
) ENGINE=InnoDB;

-- 3. Tabel Tahun Akademik
CREATE TABLE tahun_akademik (
    id_ta VARCHAR(20) PRIMARY KEY,
    nama_ta VARCHAR(100) NOT NULL,
    status ENUM('Aktif', 'Tidak Aktif') NOT NULL DEFAULT 'Tidak Aktif',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- 4. Tabel Mata Kuliah
CREATE TABLE mata_kuliah (
    id_mk VARCHAR(20) PRIMARY KEY,
    nama_mk VARCHAR(150) NOT NULL,
    kode_mk VARCHAR(30) NOT NULL,
    sks TINYINT UNSIGNED NOT NULL CHECK (sks BETWEEN 1 AND 6),
    semester TINYINT UNSIGNED NOT NULL CHECK (semester BETWEEN 1 AND 8),
    id_ta VARCHAR(20) NOT NULL,
    dosen_pengampu VARCHAR(150) NOT NULL,
    deskripsi_mk TEXT DEFAULT NULL,
    rps_file_path VARCHAR(255) DEFAULT NULL,
    silabus_file_path VARCHAR(255) DEFAULT NULL,
    dokumen_lain_url VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mk_ta FOREIGN KEY (id_ta) REFERENCES tahun_akademik(id_ta) ON DELETE CASCADE,
    INDEX idx_mk_ta (id_ta),
    INDEX idx_kode_mk (kode_mk)
) ENGINE=InnoDB;

-- 5. Tabel Enrollment (Peserta Kelas)
CREATE TABLE enrollment (
    id_enroll VARCHAR(20) PRIMARY KEY,
    id_user VARCHAR(20) NOT NULL,
    id_mk VARCHAR(20) NOT NULL,
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_mk (id_user, id_mk),
    CONSTRAINT fk_enroll_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_mk FOREIGN KEY (id_mk) REFERENCES mata_kuliah(id_mk) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Tabel Konten Mingguan (Pertemuan 1 - 16)
CREATE TABLE konten_mingguan (
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
) ENGINE=InnoDB;

-- 7. Tabel Pengumpulan Tugas & Evaluasi
CREATE TABLE pengumpulan_tugas (
    id_submit VARCHAR(20) PRIMARY KEY,
    id_konten VARCHAR(20) NOT NULL,
    id_user VARCHAR(20) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(200) NOT NULL,
    catatan_mhs TEXT DEFAULT NULL,
    nilai DECIMAL(5,2) DEFAULT NULL CHECK (nilai IS NULL OR (nilai >= 0 AND nilai <= 100)),
    feedback TEXT DEFAULT NULL,
    waktu_submit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submit_mhs (id_konten, id_user),
    CONSTRAINT fk_submit_konten FOREIGN KEY (id_konten) REFERENCES konten_mingguan(id_konten) ON DELETE CASCADE,
    CONSTRAINT fk_submit_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### 6.2 Struktur Direktori & Halaman PHP Konvensional (`/var/www/kelautan.id/lms/`)

Menggunakan pola struktur halaman PHP tradisional yang bersih, modular, dan mudah dikelola tanpa kerumitan *frontend router* SPA:

```text
/var/www/kelautan.id/lms/
├── config/
│   ├── database.php          # Koneksi PDO MariaDB & helper fungsi ID unik (USR-XXXXX, dll.)
│   └── branding.php          # Pengambil data identitas Kelautan.ID dari tabel pengaturan_sistem
├── includes/
│   ├── auth_check.php        # Pemeriksa $_SESSION login, Role Guard, CSRF Token, & Flash Message
│   ├── storage_helper.php    # Fungsi validasi upload PDF/DOCX/PPTX & penamaan otomatis NIM_Tugas.pdf
│   ├── header.php            # Tag <head>, Tailwind CSS, Font Outfit & Plus Jakarta Sans, Meta Brand
│   ├── navbar.php            # Navigasi atas Kelautan.ID x Polikant, Lencana TA Aktif, & Menu Profil
│   └── footer.php            # Footer resmi Kelautan.ID, Modal Previewer PDF, & skrip JS UI ringan
├── storage/                  # DI LUAR public_html (Owner: www-data:www-data, Mode: 2775)
│   ├── 01_Materi_Perkuliahan/
│   ├── 02_RPS_dan_Silabus/
│   └── 03_Pengumpulan_Tugas/
├── cli/
│   └── setup.php             # Skrip CLI pembuat tabel MariaDB, direktori storage, & data awal
└── public_html/              # DocumentRoot Apache2 (https://lms.kelautan.id)
    ├── index.php             # Redirect otomatis ke dashboard.php (jika login) atau login.php
    ├── login.php             # Halaman & pemroses form Login berdesain maritim Kelautan.ID
    ├── logout.php            # Penghancur sesi PHP & redirect ke login.php
    ├── dashboard.php         # Halaman Utama Dosen & Mahasiswa (Ringkasan & Daftar Mata Kuliah)
    ├── tahun-akademik.php    # Halaman & pemroses CRUD Siklus Tahun Akademik (Khusus Dosen)
    ├── mata-kuliah.php       # Halaman & pemroses Tambah/Edit/Hapus Mata Kuliah + Upload RPS/Silabus
    ├── mahasiswa.php         # Halaman & pemroses Tambah Mahasiswa Satuan & Bulk Import Excel
    ├── ruang-kelas.php       # Halaman Ruang Kelas 3-Tab (?id=MK-XXXXX&tab=pertemuan|rps|peserta)
    ├── tugas-kumpul.php      # Pemroses POST pengumpulan berkas jawaban PDF oleh Mahasiswa
    ├── tugas-nilai.php       # Halaman & pemroses penilaian tugas serta feedback oleh Dosen
    ├── profil.php            # Halaman ganti password mandiri pengguna
    ├── pengaturan.php        # Halaman Branding Dinamis Kelautan.ID, Backup SQL, & Reset Database
    ├── unduh.php             # Streamer berkas PDF/DOCX terproteksi (mode inline preview & download)
    └── assets/
        ├── css/              # Stylesheet kustom Oceanic Gradient Kelautan.ID
        ├── js/               # ui-helper.js (Hanya untuk buka/tutup modal, akordeon, & select-all)
        └── img/              # Logo Combination Mark Kelautan.ID & Politeknik Perikanan Negeri Tual
```

**Standar Izin Berkas & Sinkronisasi (`rsync`)**:
* Seluruh direktori dimiliki oleh `www-data:www-data`.
* Untuk mencegah kegagalan *permission denied* pada `mkstemp` ketika melakukan sinkronisasi berkas via `rsync`, akun pengguna deploy dimasukkan ke dalam grup `www-data` dengan bit `setgid` aktif (`chmod 2775` pada direktori dan `chmod 664` pada berkas).

---

### 6.3 Optimasi Konkurensi & Tuning Server (RAM 4GB + SWAP 2GB)

Karena menggunakan metode PHP konvensional (MPA) tanpa beban framework berat, konsumsi memori per *request* menjadi sangat kecil (~15–25 MB per worker), sehingga spesifikasi **RAM 4GB dan SWAP 2GB** di Ubuntu 22.04 LTS dapat bekerja sangat leluasa:

1. **Alokasi Memori Sistem**:
   * **OS Ubuntu 22.04 Kernel & Systemd**: ~450 MB
   * **MariaDB Server (`innodb_buffer_pool_size`)**: **1.2 GB** (menjamin seluruh tabel, indeks, dan transaksi `ACID` berjalan di memori utama).
   * **PHP 8.4-FPM Workers + OPcache**: **1.5 GB** (`pm = dynamic`, `pm.max_children = 35`, `pm.start_servers = 8`, `pm.min_spare_servers = 5`, `pm.max_spare_servers = 15`).
   * **Apache2 (`mpm_event`) & File Cache**: ~850 MB.
   * **SWAP 2GB (`vm.swappiness = 10`)**: Cadangan memori darurat tanpa membebani I/O disk pada kondisi beban normal.
2. **Konfigurasi `/etc/php/8.4/fpm/php.ini`**:
   ```ini
   memory_limit = 128M
   upload_max_filesize = 12M
   post_max_size = 15M
   max_execution_time = 60
   session.use_strict_mode = 1
   session.cookie_httponly = 1
   session.cookie_secure = 1
   session.cookie_samesite = "Lax"
   opcache.enable = 1
   opcache.memory_consumption = 128
   opcache.interned_strings_buffer = 16
   opcache.max_accelerated_files = 10000
   ```
3. **Konfigurasi `/etc/mysql/mariadb.conf.d/50-server.cnf`**:
   ```ini
   [mysqld]
   innodb_buffer_pool_size = 1G
   innodb_log_file_size = 256M
   innodb_flush_log_at_trx_commit = 2
   max_connections = 100
   ```

---

### 6.4 Pengaturan Iframe, Keamanan Header & Embedding Portal Kelautan.ID

Pada `/etc/apache2/sites-available/lms.kelautan.id.conf`, VirtualHost Apache2 dikonfigurasi agar mendukung sertifikat **Let's Encrypt Wildcard SSL (`*.kelautan.id`)** dan mengizinkan penyematan `<iframe>` di dalam portal utama **Kelautan.ID** maupun website resmi Polikant:

```apache
<VirtualHost *:443>
    ServerName lms.kelautan.id
    DocumentRoot /var/www/kelautan.id/lms/public_html

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/kelautan.id/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/kelautan.id/privkey.pem

    <Directory /var/www/kelautan.id/lms/public_html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Teruskan eksekusi skrip PHP ke PHP 8.4-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Izinkan embedding iframe di ekosistem Kelautan.ID dan Portal Polikant
    Header always unset X-Frame-Options
    Header always set Content-Security-Policy "frame-ancestors 'self' https://kelautan.id https://*.kelautan.id https://*.polikant.ac.id;"
</VirtualHost>
```

---

## 7. DETAIL PEMBAHARUAN & TRANSFORMASI ARSITEKTUR (CHANGELOG)

Berikut adalah rekapitulasi transformasi sistem dari versi Google Apps Script (v2.5) menjadi versi **PHP 8.4 Konvensional (MPA) × Kelautan.ID (v3.2)**:

| No | Komponen / Fitur | Versi 2.5 (Google Apps Script) | Versi 3.2 (PHP 8.4 MPA × Kelautan.ID) | Status |
|:--:|---|---|---|:---:|
| 1 | **Arsitektur Aplikasi** | Single Page Application (SPA) dengan `google.script.run` yang rentan *blank header bug* jika koneksi terputus. | **Multi-Page Application (MPA) / Server-Side Rendered PHP** dengan halaman terpisah (`dashboard.php`, `ruang-kelas.php`, dll.) dan pola *Post/Redirect/Get (PRG)*. | Solved |
| 2 | **Identitas & Co-Branding** | Hanya menampilkan identitas internal Polikant. | Terintegrasi penuh dengan **Kelautan.ID - Portal Maritim Indonesia** (Logo *combination mark* ombak laut, palet *Oceanic Gradient*, footer ekosistem, dan halaman `pengaturan.php`). | Solved |
| 3 | **Mesin Backend & Database** | Google Apps Script + Google Spreadsheet (`LockService`). | **PHP 8.4-FPM** + **MariaDB InnoDB** di Ubuntu 22.04 (`/var/www/kelautan.id/lms/`) dengan transaksi `ACID` dan kecepatan tinggi. | Solved |
| 4 | **Pengiriman Form & Upload** | Konversi Base64 di JavaScript lalu dikirim ke Google Drive API. | Pengiriman standar HTML `<form method="POST" enctype="multipart/form-data">` langsung ke `/var/www/kelautan.id/lms/storage/` dengan validasi `finfo_file()`. | Solved |
| 5 | **Manajemen Sesi & Keamanan** | Token disimpan di `localStorage` peramban + hash SHA-256. | Sesi murni di sisi server (`$_SESSION` PHP), hash **Argon2id/Bcrypt** (`password_hash`), dan proteksi `csrf_token` di setiap form. | Solved |
| 6 | **Hak Akses Peserta Kelas** | Pemisahan tampilan dilakukan oleh JavaScript di sisi klien. | Pemisahan dilakukan **langsung oleh PHP sebelum HTML dikirim**: Mahasiswa hanya menerima HTML Read-Only tanpa elemen form/checkbox sama sekali, ditambah lencana `[Anda]`. | Solved |
| 7 | **Impor Mahasiswa Massal** | Copy-paste dari Excel ke Google Sheet (rawan timeout). | Diproses melalui form `POST` di `mahasiswa.php` menggunakan **PDO Bulk Transaction** di MariaDB (< 1 detik untuk ratusan baris). | Solved |
| 8 | **Pratinjau Soal & Dokumen PDF** | Menggunakan Google Drive Embed Previewer. | Menggunakan skrip streaming lokal `unduh.php?mode=inline` yang menampilkan PDF langsung di modal iframe dari server **Kelautan.ID**. | Solved |

---

## 8. PANDUAN OPERASIONAL & KREDENSIAL BAWAAN

### 8.1 Kredensial Default Pengujian
Setelah menjalankan perintah inisialisasi `php cli/setup.php` di terminal Ubuntu Server (atau melalui fitur Reset Database di halaman `pengaturan.php`), sistem otomatis menyediakan akun awal berikut:

| Peran | Username / NIM | Password Bawaan | Keterangan Akses |
|---|---|---|---|
| **Dosen / Admin Kelautan.ID** | `admin` | `dosen123` | Hak penuh kelola Branding Kelautan.ID, TA, MK, RPS, Mahasiswa, Konten 16 Minggu, Nilai & Backup/Reset DB. |
| **Mahasiswa 1** | `2024001` | `mhs123` | Mahasiswa Angkatan 2024, terdaftar di MK Oseanografi Geologi. |
| **Mahasiswa 2** | `2024002` | `mhs123` | Mahasiswa Angkatan 2024, terdaftar di MK Oseanografi Geologi. |

> **Catatan Pengamanan**: Seluruh pengguna disarankan segera mengganti kata sandi setelah login pertama kali melalui halaman **`profil.php`** di menu pojok kanan atas.

### 8.2 Cara Mengakses & Menguji Fitur pada Server Kelautan.ID
1. **Persiapan Layanan di Ubuntu Server 22.04**:
   * Pastikan layanan `apache2`, `php8.4-fpm`, dan `mariadb` berstatus `active (running)`.
   * Jalankan `php /var/www/kelautan.id/lms/cli/setup.php` untuk membuat tabel MariaDB dan struktur folder `storage/`.
2. **Login sebagai Dosen / Admin (`admin` / `dosen123` di `login.php`)**:
   * Setelah login, Anda langsung diarahkan ke `dashboard.php` yang menampilkan identitas **Kelautan.ID - Portal Maritim Indonesia**.
   * Buka mata kuliah *Oseanografi Geologi* (`ruang-kelas.php?id=MK-00001&tab=peserta`), centang mahasiswa peserta kelas, lalu klik **"Simpan Perubahan Peserta"**.
   * Pindah ke tab **Pertemuan 1-16** (`ruang-kelas.php?id=MK-00001&tab=pertemuan`) untuk menambahkan materi, kuis, atau tugas dengan lampiran soal PDF.
3. **Login sebagai Mahasiswa (`2024001` / `mhs123` di `login.php`)**:
   * Masuk ke ruang kelas *Oseanografi Geologi* dan klik tab **Peserta Kelas**: perhatikan bahwa halaman dirender sepenuhnya dalam mode **Read-Only** (tanpa checkbox dan tanpa tombol simpan) serta menampilkan penanda `[Anda]` pada nama Anda.
   * Pada tab **Pertemuan 1-16**, klik tombol **"Lihat Soal PDF"** untuk membaca soal, lalu unggah berkas jawaban PDF (< 10 MB) melalui form pengumpulan tugas.

---
*Dokumen ini merupakan spesifikasi kebutuhan produk (PRD) resmi untuk LMS Politeknik Perikanan Negeri Tual × Kelautan.ID - Portal Maritim Indonesia (Edisi PHP 8.4 Konvensional / Multi-Page Application & MariaDB pada Ubuntu Server 22.04 LTS).*