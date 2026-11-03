# Learning Management System (LMS) Polikant × Kelautan.ID
### *Powered by Kelautan.ID — Portal Maritim Indonesia*

Platform pembelajaran daring (*Learning Management System*) konvensional berbasis **PHP 8.4 Multi-Page Application (MPA)** dan **MariaDB InnoDB** yang dikembangkan khusus untuk sivitas akademika **Politeknik Perikanan Negeri Tual (Polikant)** di bawah naungan ekosistem terpadu **[Kelautan.ID](https://kelautan.id)**.

---

## 🌊 Fitur Utama Sistem

1. **Arsitektur Multi-Page Application (MPA) Murni**:
   * Bebas *blank screen* JavaScript pada koneksi kepulauan.
   * Pola **Post/Redirect/Get (PRG)** dengan notifikasi *Flash Session*.
   * Render HTML langsung di sisi server oleh PHP 8.4-FPM.

2. **Identitas Visual Co-Branding (*Oceanic Maritime Gradient*)**:
   * Kombinasi warna khas laut: Deep Abyssal Navy (`#0A192F`), Maritime Royal Blue (`#1E3A8A`), Oceanic Cyan (`#06B6D4`), Coral Amber (`#F59E0B`), dan Seafoam Green (`#10B981`).
   * Tipografi modern: **Outfit** untuk tajuk/brand dan **Plus Jakarta Sans** untuk formulir & tabel.
   * Terintegrasi dengan logo combination mark Kelautan.ID dan lambang Polikant.

3. **Modul Manajemen Kurikulum & 16 Pertemuan (v1.2)**:
   * Akordeon Minggu ke-1 sampai Minggu ke-16 dengan auto-expand pada minggu yang baru diperbarui.
   * **Formulir Dinamis Tambah Konten Pembelajaran (v1.2)**: Kolom isian menyesuaikan secara otomatis sesuai jenis komponen yang dipilih:
     - **Materi Presentasi / Modul**: Pertemuan, Judul, Deskripsi, Unggah File (PPT, PDF, Word, Excel maks. 10 MB), dan Link Eksternal (Opsional).
     - **Tugas (Assignment)**: Pertemuan, Judul, Deskripsi, Unggah File (Soal/Panduan Tugas maks. 10 MB), Link Eksternal (Opsional), dan Tenggat Waktu Pengumpulan (Deadline).
     - **Kuis (Online Quiz / Forms)**: Pertemuan, Judul, Deskripsi, URL Ujian Eksternal (Google Forms / Quizizz / dsb.), dan Tenggat Waktu Pengerjaan (Deadline).
     - **Ujian (UTS / UAS Embed)**: Pertemuan, Judul, Deskripsi, URL Ujian Eksternal (Google Forms / Quizizz / dsb.), dan Tenggat Waktu Pelaksanaan (Deadline).
   * **Pratinjau & Unduh Berkas Akordeon (v1.2)**: Tersedia tombol terpisah *Preview Dokumen* (modal inline untuk PDF) dan *Unduh Berkas* untuk seluruh format berkas (PDF, PPT, DOCX, XLSX). Serta tombol *Preview Link* dan *Buka Link Eksternal* untuk tautan daring.

4. **Modul Peserta Kelas, Multi-Role Dosen & Team Teaching (v1.3)**:
   * **Dosen Admin (Super Admin)**: Memiliki hak penuh atas konfigurasi platform, manajemen pengguna, penugasan dosen ke mata kuliah, tahun akademik, backup, dan pengaturan footer.
   * **Dosen Biasa**: Hanya dapat mengakses dan mengelola mata kuliah yang di-assign oleh Dosen Admin (menambah materi 16 pertemuan, tugas, kuis, dan penilaian). Tidak memiliki akses ke menu Pengaturan atau pembuatan mata kuliah baru.
   * **Team Teaching (2-3 Dosen Pengampu)**: Setiap mata kuliah dapat diampu bersama oleh 1 hingga 3 orang dosen (Koordinator Utama dan Anggota Tim Pengajar). Seluruh dosen yang terdaftar memiliki hak untuk mengajar dan memeriksa serta menilai tugas mahasiswa.
   * **Mahasiswa**: Mengakses materi, mengunduh modul/RPS, dan mengumpulkan tugas.
   * **Foto Profil (v1.1)**: Seluruh pengguna dapat mengunggah dan memperbarui foto profil mereka sendiri secara aman.

5. **Pengumpulan & Penilaian Tugas Mahasiswa**:
   * Validasi MIME asli (`finfo_file()`) khusus PDF dengan ukuran maks. 10 MB.
   * Penamaan otomatis berkas: `[NIM]_[Nama_Tugas_Sanitized].pdf`.
   * Halaman pemeriksaan berkas, input nilai numerik (0–100), dan feedback kualitatif oleh dosen.

6. **Impor Massal & Manajemen Sivitas (v1.1)**:
   * Salin-tempel ratusan baris data dari Excel/Google Sheets ke textarea.
   * Parsing cerdas pemisah kolom TAB, Koma, Titik Koma, atau Pipe.
   * Pembuatan akun individual dengan pilihan peran: *Mahasiswa*, *Dosen Biasa*, atau *Dosen Admin*.

7. **Pengaturan Branding Dinamis & Footer Terpadu (v1.1)**:
   * Kustomisasi penuh teks footer, deskripsi platform, tautan cepat, dan informasi kontak/bantuan langsung dari antarmuka Pengaturan.
   * Unduh cadangan database `.sql`.
   * Reset database terproteksi kata sandi.

8. **Pembersihan Log Konsol & Optimasi Keamanan (v1.2)**:
   * Filter supresi peringatan Tailwind CDN development di browser console.
   * Penjelasan status integrasi Cloudflare Web Analytics beacon.

9. **URL Cantik & Estetik RESTful (v1.3)**:
   * **Bebas Ekstensi `.php`**: Seluruh tautan menggunakan routing bersih seperti `/login`, `/dashboard`, `/mata-kuliah`, `/tahun-akademik`, `/mahasiswa`, `/profil`, `/pengaturan`, dan `/logout`.
   * **Rute Kelas RESTful**:
     - Ruang Kelas Utama: `/kelas/{id_mk}` (misal: `/kelas/MK-00001`)
     - Tab Pertemuan 1–16: `/kelas/{id_mk}/pertemuan`
     - Tab Dokumen Kurikulum & RPS: `/kelas/{id_mk}/rps`
     - Tab Peserta Kelas: `/kelas/{id_mk}/peserta`
   * **Rute Penilaian Tugas**: `/nilai/{id_konten}` (misal: `/nilai/KNT-00002`)
   * **Dukungan Apache & Router Lokal**: Dilengkapi konfigurasi Apache `public_html/.htaccess` (`mod_rewrite`) dengan proteksi 301 redirect untuk request `.php` lama serta router dev bawaan `router.php`.

---

## 🚀 Kredensial Pengujian Bawaan (v1.1)

| Peran | Username / NIM | Password Default | Akses |
|---|---|---|---|
| **Dosen Admin** | `admin` | `dosen123` | Super Admin: Seluruh kelas, tahun akademik, sivitas, dan menu Pengaturan. |
| **Dosen Biasa** | `dosen1` | `dosen123` | Dosen Biasa: Khusus kelas yang ditugaskan (*Meteorologi & Dinamika Oseanografi*). Tidak dapat mengakses Pengaturan. |
| **Mahasiswa 1** | `2024001` | `mhs123` | Mahasiswa Angkatan 2024 (Terdaftar di MK Oseanografi Geologi). |
| **Mahasiswa 2** | `2024002` | `mhs123` | Mahasiswa Angkatan 2024 (Terdaftar di MK Oseanografi Geologi). |
| **Mahasiswa 3** | `2024003` | `mhs123` | Mahasiswa Angkatan 2024. |

---

## 🛠️ Panduan Instalasi & Menjalankan Aplikasi

### 1. Inisialisasi Database & Storage
Jalankan perintah berikut di terminal:
```bash
php cli/setup.php
```
*Skrip ini akan otomatis membuat 7 tabel relasional, menyiapkan struktur direktori `storage/`, dan mengisi data awal pengujian.*

### 2. Menjalankan Server Lokal (Built-in PHP Server dengan Clean URL Router)
```bash
php -S localhost:8000 router.php
```
Buka peramban dan akses:
**`http://localhost:8000/login`** atau **`http://localhost:8000/dashboard`**

### 3. Konfigurasi MariaDB / MySQL Produksi & Deployment VPS
Panduan langkah demi langkah lengkap untuk menyebarkan aplikasi ke Ubuntu Server 22.04 LTS (Apache2, PHP 8.4-FPM, MariaDB, Let's Encrypt SSL) tersedia di dokumen:
👉 **[PANDUAN_DEPLOY_VPS.md](file:///Users/budhiagungp/Documents/web/lms-kelautan-php/PANDUAN_DEPLOY_VPS.md)**

Salin template konfigurasi lokal:
```bash
cp config/env.example.php config/env.php
```
Sesuaikan parameter `DB_HOST`, `DB_NAME`, `DB_USER`, dan `DB_PASS` pada `config/env.php`.
*(Catatan: Sistem dilengkapi dengan fallback otomatis ke SQLite lokal jika MariaDB tidak aktif).*

---

## 📂 Struktur Direktori Proyek

```text
lms-kelautan-php/
├── config/
│   ├── database.php          # Koneksi PDO MariaDB & resilient SQLite fallback
│   ├── branding.php          # Helper identitas Kelautan.ID dari tabel pengaturan_sistem
│   └── env.example.php       # Template konfigurasi database lingkungan
├── includes/
│   ├── auth_check.php        # Role guard, CSRF protection, & notifikasi flash
│   ├── storage_helper.php    # Validasi finfo_file, sanitasi, & penamaan otomatis file
│   ├── header.php            # Head, Tailwind CSS, Google Fonts, & Lucide Icons
│   ├── navbar.php            # Bilah navigasi atas, lencana TA aktif, & menu profil
│   └── footer.php            # Footer resmi, modal previewer PDF, & script UI
├── storage/                  # Penyimpanan berkas privat di luar DocumentRoot
│   ├── 01_Materi_Perkuliahan/
│   ├── 02_RPS_dan_Silabus/
│   └── 03_Pengumpulan_Tugas/
├── cli/
│   └── setup.php             # CLI setup, migrasi tabel, & seeder data default
└── public_html/              # DocumentRoot Apache2 / Web Root
    ├── index.php             # Router/redirector halaman utama
    ├── login.php             # Halaman & pemroses otentikasi login
    ├── logout.php            # Penghancur sesi & pembersih cookie
    ├── dashboard.php         # Ringkasan statistik & kartu kelas perkuliahan
    ├── tahun-akademik.php    # CRUD siklus tahun akademik (khusus Dosen)
    ├── mata-kuliah.php       # CRUD mata kuliah + upload RPS & silabus
    ├── mahasiswa.php         # CRUD mahasiswa & form impor massal Excel
    ├── ruang-kelas.php       # Halaman kelas 3-tab (?id=MK-XXXXX&tab=pertemuan|rps|peserta)
    ├── tugas-kumpul.php      # Pemroses upload PDF jawaban tugas mahasiswa
    ├── tugas-nilai.php       # Evaluasi, penilaian (0-100), & feedback dosen
    ├── profil.php            # Ganti kata sandi mandiri pengguna
    ├── pengaturan.php        # Branding dinamis, unduh backup SQL, & reset DB
    ├── unduh.php             # Streamer berkas aman (inline preview & download)
    └── assets/
        ├── css/style.css     # Stylesheet kustom Oceanic Gradient
        ├── js/ui-helper.js   # Script modal preview, akordeon, & select-all
        └── img/              # Logo SVG Kelautan.ID, Polikant, & Favicon
```

---
&copy; 2026 Kelautan.ID - Portal Maritim Indonesia × Politeknik Perikanan Negeri Tual.  
*Dikembangkan untuk Kemajuan Riset & Pendidikan Vokasi Kelautan Indonesia.*
