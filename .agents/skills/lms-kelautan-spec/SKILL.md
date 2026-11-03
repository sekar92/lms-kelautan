---
name: lms-kelautan-spec
description: Panduan resmi spesifikasi branding, identitas visual, layout antarmuka, palet warna, tipografi, dan standar arsitektur platform LMS Polikant × Kelautan.ID.
---

# Spesifikasi Desain, Layout & Branding LMS Polikant × Kelautan.ID

Dokumen ini merupakan panduan acuan (*Single Source of Truth*) untuk seluruh perancangan antarmuka, penulisan kode frontend/backend, penyesuaian gaya visual, serta standar pengalaman pengguna (*User Experience*) pada aplikasi **LMS Polikant × Kelautan.ID** (Edisi PHP 8.x MPA).

---

## 1. Identitas & Branding Ekosistem

### 1.1 Entitas & Nomenklatur Resmi
* **Nama Platform Induk**: `Kelautan.ID - Portal Maritim Indonesia`
* **Nama Aplikasi LMS**: `LMS Polikant × Kelautan.ID`
* **Tagline Aplikasi**: `Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia`
* **Institusi Afiliasi**: `Politeknik Perikanan Negeri Tual (Polikant)`
* **Program Studi**: `Program Studi Teknologi Kelautan` *(Bersifat dinamis dari konfigurasi database)*
* **Jurusan**: `Teknik Kelautan dan Perikanan`
* **Inisiator & Pengembang**: `Budhi Agung Prasetyo, S.Pi., M.Si.`
* **Teks Hak Cipta Footer Resmi**:
  > `© 2026 Kelautan.ID - Portal Maritim Indonesia.`

### 1.2 Ekosistem Tautan Digital
* **Portal Utama**: `https://kelautan.id`
* **LMS Perkuliahan**: `https://lms.kelautan.id`
* **Jurnal Ilmiah Maritim**: `https://journal.kelautan.id`
* **Pemendek Tautan**: `https://go.kelautan.id`
* **Email Dukungan**: `teknologikelautanid@gmail.com`

### 1.3 Aset Visual: Logo & Favicon
* **Mekanisme Dinamis**: Seluruh logo dan favicon dimuat melalui helper `get_logo_url()` dan `get_favicon_url()` di `config/branding.php`.
* **Logo Standar (Default Vector)**:
  * Berupa kombinasi simbol gelombang samudra dan jangkar maritim bergradien *Ocean Cyan* (`#06B6D4`) ke *Maritime Blue* (`#1D4ED8`).
* **Logo Kustom (Custom Logo Upload)**:
  * Lokasi Simpan: `storage/branding/` dan `public_html/assets/uploads/branding/`
  * Format Didukung: `.svg`, `.png`, `.jpg`, `.jpeg`, `.webp`
  * Batas Maksimal: 4 MB
* **Favicon Kustom (Custom Favicon Upload)**:
  * Lokasi Simpan: `storage/branding/` dan `public_html/assets/uploads/branding/`
  * Format Didukung: `.ico`, `.png`, `.svg`
  * Batas Maksimal: 2 MB
  * Terpasang otomatis pada `<link rel="icon">` di seluruh header dokumen HTML.

---

## 2. Palet Warna & Token Desain (Design Tokens)

Sistem warna memadukan nuansa laut dalam (*Abyssal Navy*), kedalaman biru maritim profesional (*Maritime Blue*), dan aksen samudra tropis yang segar (*Oceanic Cyan*).

### 2.1 Palet Warna Inti (Core Palette)

| Kategori Token | Nama Token | Kode Warna (HEX) | Penggunaan Utama |
| :--- | :--- | :--- | :--- |
| **Abyssal Navy** | `abyssal-950` | `#060D18` | Latar belakang paling gelap, border kontras tinggi |
| | `abyssal-900` | `#0A192F` | Top Header, Sidebar navigasi utama, kartu gelap kontras |
| | `abyssal-800` | `#0F172A` | Border pembatas navigasi, hover state gelap |
| | `abyssal-700` | `#1E293B` | Elemen struktural sekunder, teks headings gelap |
| **Maritime Blue** | `maritime-900` | `#1E3A8A` | Latar gradien biru maritim |
| | `maritime-800` | `#1E40AF` | Hover state tombol primer, header modal |
| | `maritime-700` | `#1D4ED8` | **Warna Brand Primer**, tombol utama, fokus input |
| | `maritime-600` | `#2563EB` | Aksen biru interaktif |
| | `maritime-500` | `#0284C7` | Transisi gradien & link aktif |
| **Oceanic Cyan** | `ocean` | `#06B6D4` | **Aksen Samudra**, badge aktif, garis pembatas aktif |
| | `ocean-400` | `#38BDF8` | Teks gradien, highlight ikon |
| | `ocean-500` | `#0EA5E9` | Border interaktif, radio & checkbox |
| | `ocean-cyan` | `#22D3EE` | Aksen neon cyan, indikator ping aktif |
| **Netral / Slate** | `slate-50` | `#F8FAFC` | **Latar Belakang Kanvas Utama Platform** |
| | `slate-100` | `#F1F5F9` | Latar card ringan, track scrollbar |
| | `slate-200` | `#E2E8F0` | Border standar kartu dan tabel |
| | `slate-500` | `#64748B` | Teks keterangan sekunder, subtitle |
| | `slate-900` | `#0F172A` | Teks utama konten (*Body text*) |

### 2.2 Warna Semantik & Fungsional (Status Feedback)

| Status | Palet Tailwind | Kode Warna | Arti / Penggunaan |
| :--- | :--- | :--- | :--- |
| **Sukses / Selesai** | `emerald-500` / `emerald-600` | `#10B981` / `#059669` | Tugas terkumpul, nilai tuntas, flash message sukses |
| **Peringatan / Urgent** | `amber-400` / `amber-500` | `#FBBF24` / `#F59E0B` | Bilah Mode Simulasi Login-As, deadline $\le 3$ hari |
| **Bahaya / Jatuh Tempo** | `rose-500` / `rose-600` | `#F43F5E` / `#E11D48` | Deadline $\le 24$ jam / berakhir, tombol hapus, pesan error |
| **Informasi** | `sky-500` / `blue-600` | `#0284C7` / `#2563EB` | Panduan, badge status umum, informasi akademik |

### 2.3 Utilitas Gradien & Efek Kaca (Glassmorphism)
Didefinisikan dalam `public_html/assets/css/style.css`:
* `.bg-oceanic-gradient`: `linear-gradient(135deg, #0A192F 0%, #1E3A8A 50%, #0284C7 100%)`
* `.bg-oceanic-card`: `linear-gradient(135deg, rgba(10, 25, 47, 0.95) 0%, rgba(30, 58, 138, 0.95) 100%)`
* `.bg-maritime-header`: `linear-gradient(90deg, #0A192F 0%, #0F172A 40%, #1E3A8A 100%)`
* `.text-gradient-ocean`: Gradien teks biru langit ke toska cerah (`#38BDF8` ke `#22D3EE`).
* `.glass-panel`: `background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px);`
* `.glass-panel-dark`: `background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(12px);`

---

## 3. Tipografi & Hirarki Teks

Menggunakan Google Fonts modern dengan pemisahan peran yang tegas antara judul dan keterbacaan teks konten:

1. **Heading & Brand Display Font**:
   * Font Family: `'Outfit', sans-serif`
   * Karakter: Geometris, kokoh, berwibawa, modern dengan `letter-spacing: -0.02em;`
   * Ketebalan (Weight): SemiBold (600), Bold (700), ExtraBold (800).
   * Penerapan: Semua tag `<h1>` hingga `<h4>`, nama brand, judul kartu stat, judul halaman.

2. **Body & User Interface Font**:
   * Font Family: `'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`
   * Karakter: Sangat jernih pada resolusi layar tinggi maupun rendah, netral, nyaman untuk teks panjang.
   * Ketebalan (Weight): Regular (400), Medium (500), SemiBold (600).
   * Penerapan: Paragraf deskripsi, tabel data, formulir input, teks tombol, badge status.

3. **Monospace & Code Font**:
   * Font Family: `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace`
   * Penerapan: Kode mata kuliah (`TKL-2101`), NIM, ID log, timestamp audit trail.

---

## 4. Arsitektur Layout & Pola Navigasi

Aplikasi mengadopsi struktur tata letak modern **2-Tier Navigation** yang responsif dan efisien ruang:

```
┌────────────────────────────────────────────────────────────────────────┐
│ [STICKY ALERT BAR] Mode Simulasi Login-As (Hanya tampil saat simulasi)  │
├────────────────────────────────────────────────────────────────────────┤
│ [TOP HEADER (h-16, bg-abyssal-900)]                                    │
│ [=] Logo Brand  [Badge T.A. Aktif]            [Profil & Dropdown] [x]  │
├───────────────┬────────────────────────────────────────────────────────┤
│ [LEFT SIDEBAR]│ [MAIN CONTENT CANVAS (max-w-7xl, bg-slate-50)]         │
│ (w-64 desktop,│                                                        │
│ slide-drawer  │  • Breadcrumb / Header Halaman                         │
│ pada mobile)  │  • Kartu Ringkasan & Statistik / Alert Deadline        │
│               │  • Konten Utama (Tabel / Modul 16 Minggu / Form)       │
│ • Dashboard   │                                                        │
│ • Ruang Kelas │                                                        │
│ • Sivitas Mhs │                                                        │
│ • T.A.        │                                                        │
│ • Simulasi*   │                                                        │
│ • Audit Log*  │                                                        │
│ • Pengaturan* │                                                        │
│ • Ekosistem   ├────────────────────────────────────────────────────────┤
│               │ [FOOTER] © 2026 Kelautan.ID - Portal Maritim Indonesia.│
└───────────────┴────────────────────────────────────────────────────────┘
* Khusus Dosen Admin
```

### 4.1 Top Header (`includes/navbar.php`)
* **Karakteristik**: Ketinggian tetap `h-16`, tema gelap `bg-abyssal-900`, `border-b border-slate-800`, posisi menempel di atas (`sticky top-0 z-40`).
* **Komponen**:
  1. **Bilah Mode Simulasi (Login-As Alert Bar)**:
     * Muncul di paling atas jika `is_impersonating()` bernilai `true`.
     * Berwarna Amber Cerah (`bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500`) dengan animasi *ping pulse*.
     * Menampilkan: Nama user yang sedang disimulasikan, perannya, nama admin asli, dan tombol 1-klik **"Kembali ke Akun Admin"**.
  2. **Hamburger Toggle**: Tombol pembuka drawer navigasi samping untuk layar ponsel (`md:hidden`).
  3. **Identitas Brand**: Logo SVG / Kustom + Teks brand yang dapat diklik menuju `/dashboard`.
  4. **Badge Tahun Akademik Aktif**: Menampilkan semester aktif (misal: `2025/2026 Ganjil`) dengan status *Active Pulse*.
  5. **User Profile Dropdown**:
     * Menampilkan foto profil melingkar (`w-9 h-9 rounded-full object-cover`) dengan fallback inisial nama.
     * Nama pengguna, peran aktif, menu ke Profil, Pengaturan Akun, Log Aktivitas (jika Admin), dan tombol Logout cepat.

### 4.2 Left Sidebar Responsif
* **Desktop**:
  * Lebar tetap `w-64`, menempel di sisi kiri layar secara permanen (`sticky top-16 h-[calc(100vh-4rem)]`).
  * Desain gelap profesional (`bg-abyssal-900 border-r border-slate-800`).
* **Mobile Drawer**:
  * Muncul sebagai *Slide-out Drawer* dari kiri dengan animasi transisi mulus dan lapisan latar belakang kabur (*backdrop blur*).
* **Indikator Menu Aktif**:
  * Garis vertikal cyan di sebelah kiri (`border-l-4 border-cyan-400`).
  * Gradien latar halus (`bg-gradient-to-r from-cyan-950/80 to-blue-950/50 text-cyan-300 font-semibold`).

### 4.3 Kanvas Konten Utama (*Main Content Canvas*)
* Latar belakang: `#F8FAFC` (`bg-slate-50`).
* Batas lebar: `max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8`.
* Pembungkus kartu utama: `bg-white rounded-2xl border border-slate-200 shadow-sm p-6`.

---

## 5. Spesifikasi Komponen & Modul Utama

### 5.1 Halaman Masuk (*Login Page* - `/login`)
* **Tema Visual**: Cerah, segar, bernuansa samudra modern (*Light & Fresh Oceanic*).
* **Latar**: Gradien halus `bg-gradient-to-br from-slate-50 via-sky-50 to-blue-100`.
* **Kartu Form**: Putih bersih berkontras tinggi dengan sudut membulat lebar (`rounded-3xl shadow-xl border border-slate-200/80`).
* **Fitur Manajemen Sesi**:
  * **Checkbox "Ingat Saya di Perangkat Ini"**:
    * Jika dicentang: Cookie sesi bertahan selama **30 Hari** (`30 * 86400 detik`).
    * Jika tidak dicentang: Diberlakukan **Idle Timeout 30 Menit** demi keamanan di komputer publik/laboratorium.
  * Autentikasi mencatat log ke `log_aktivitas` (sukses `LOGIN` atau gagal `LOGIN_GAGAL` dengan IP dan User-Agent).

### 5.2 Kartu Hero & Banner Selamat Datang Dashboard
* **Visual**: `bg-oceanic-gradient` dengan dekorasi gelombang samudra SVG transparan.
* **Badge Nama Aplikasi**: Menggunakan variabel dinamis **Nama Aplikasi LMS** (`<?= htmlspecialchars($branding['app_name']) ?>`), tidak di-*hardcoded*.
* **Teks Deskripsi**: Dinamis menyelaraskan nama Program Studi (`$branding['study_program']`) dari menu Pengaturan.

### 5.3 Kartu Pengingat Tenggat Waktu (*Deadline Reminder Cards*)
Khusus pada dashboard mahasiswa untuk memastikan tidak ada tugas atau kuis yang terlewat:
* **Urgensi Tinggi ($\le 24$ Jam / Hari Ini)**:
  * Border dan badge merah rose (`border-rose-300 bg-rose-50/70 text-rose-800`).
  * Ikon *clock alert* merah menyala.
* **Urgensi Sedang ($\le 3$ Hari)**:
  * Border dan badge kuning jingga (`border-amber-300 bg-amber-50/70 text-amber-800`).
* **Urgensi Santai ($> 3$ Hari)**:
  * Border dan badge biru laut (`border-blue-200 bg-blue-50/60 text-maritime-700`).
* **Kondisi Tuntas (*Empty State*)**:
  * Jika seluruh tugas telah dikumpulkan, tampil kartu apresiasi hijau zamrud (*"Luar Biasa! Tidak Ada Tenggat Waktu Tertunda"*).

### 5.3 Modal & Animasi Pengumpulan Tugas Mahasiswa
* **Animasi Verifikasi Upload**:
  * Saat mahasiswa mengunggah tugas di modal kumpul tugas, tombol submit memicu animasi checklist status progresif:
    1. *Memvalidasi format & ukuran berkas...*
    2. *Mengompresi dan memverifikasi integritas...*
    3. *Mengunggah berkas ke server penyimpanan...*
    4. *Menyimpan tautan eksternal & konfirmasi...*
* **Dukungan Berkas**: Berkas fisik (PDF, Word, PPT, Excel, ZIP) dan opsi tautan Google Drive / Cloud Storage eksternal.

### 5.4 Manajemen Komponen Pembelajaran 16 Minggu (Tambah & Edit Modul)
* **Akses & Otorisasi**: Khusus Dosen Pengampu dan Dosen Admin.
* **Fitur Tambah & Edit Komprehensif**:
  * Dosen dapat menambah dan mengedit komponen perkuliahan (Materi, Tugas, Kuis, Ujian) di setiap minggu pertemuan (1–16).
  * Modal edit terisi otomatis (*pre-filled*): Pertemuan Minggu Ke-, Tipe Komponen, Judul, Deskripsi, Tautan Eksternal, dan Tenggat Waktu (*Deadline*).
  * **Manajemen Lampiran Berkas**: Menampilkan nama berkas lampiran yang ada, opsi ganti berkas baru (berkas lama dibersihkan dari server), atau opsi hapus berkas lampiran.
  * Dilengkapi animasi proses penyimpanan dan pencatatan audit trail ke `log_aktivitas` (`TAMBAH_KONTEN`, `UPDATE_KONTEN`, `HAPUS_KONTEN`).

### 5.5 Pusat Simulasi Akun / Login-As (`/login-as`)
* **Otorisasi**: Khusus Dosen Admin (`require_dosen_admin()`).
* **Isolasi Snapshot**: Sesi admin asli diarsipkan di `$_SESSION['impersonator']` dengan seluruh hak akses beralih penuh ke identitas target.
* **Auto-Heal Safeguard**: Jika `user_id` aktif sama dengan `impersonator['user_id']`, sistem otomatis menghapus `$_SESSION['impersonator']` untuk mencegah admin terperangkap dalam status simulasi.

### 5.5 Log Aktivitas & Audit Trail Sistem (`/log-aktivitas`)
* **Tabel Database**: `log_aktivitas` (SQLite & MariaDB/MySQL auto-migration).
* **Data Tercatat**:
  * `id_user`, `username`, `nama`, `role`
  * `tipe_aktivitas` (`LOGIN`, `LOGIN_GAGAL`, `LOGOUT`, `UPLOAD_TUGAS`, `UPDATE_TUGAS`, `NILAI_TUGAS`, `LOGIN_AS`, `REVERT_ADMIN`, `TAMBAH_KONTEN`, `HAPUS_KONTEN`, `GANTI_PASSWORD`)
  * `deskripsi` (berisi detail mata kuliah, nama tugas, nama file, tautan eksternal, nilai angka)
  * `ip_address` (deteksi Cloudflare, Forwarded, Direct IP)
  * `user_agent` (perangkat & browser)
  * `is_impersonated` & `impersonator_id` (penanda aksi lewat simulasi)
  * `created_at` (timestamp presisi detik)
* **Ekspor CSV**: Menyediakan fitur ekspor data terfilter langsung ke file `.csv` dengan header UTF-8 BOM untuk kompatibilitas sempurna dengan Microsoft Excel.

### 5.6 Panel Pengaturan Sistem (`/pengaturan`)
* Mengontrol konfigurasi dinamis platform tanpa perlu mengubah kode sumber:
  * Nama Platform, Nama Aplikasi LMS, Tagline, dan Deskripsi Footer.
  * Nama Program Studi dan Nama Institusi.
  * Upload Logo Kustom & Favicon Kustom.
  * Batas Maksimal Upload Materi (MB) dan Upload Tugas (MB).
  * Tautan Ekosistem (Portal, Journal, Pemendek Link) & Kontak Bantuan.

---

## 6. Standar Teknis & Konvensi Kode

### 6.1 Backend & Arsitektur Sistem
* **Bahasa**: PHP 8.x dengan deklarasi ketat (`declare(strict_types=1);` di baris pertama setiap skrip).
* **Database Abstraction**: PDO dengan dukungan Dual-Engine:
  * **Lokal / Fallback**: SQLite (`storage/db_lms_kelautan.sqlite`).
  * **Produksi**: MariaDB / MySQL (terkonfigurasi di `config/env.php`).
* **Keamanan Sesi & CSRF**:
  * Validasi token CSRF wajib pada seluruh request `POST` via `validate_csrf_or_abort()`.
  * Sanitasi output HTML selalu menggunakan `htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8')`.
* **Pola Alur Form**: Pola **Post/Redirect/Get (PRG)** menggunakan helper `set_flash('success'|'error'|'warning'|'info', $message)`.

### 6.2 Frontend & Aset
* **CSS Framework**: Tailwind CSS via CDN dengan injeksi palet kustom `abyssal`, `maritime`, dan `ocean`.
* **Ikonografi**: [Lucide Icons](https://lucide.dev/) via CDN (`<i data-lucide="nama-ikon"></i>`), selalu diinisialisasi melalui `lucide.createIcons();` di akhir halaman.
* **Routing**: Menggunakan URL bersih (*Pretty URLs*) melalui Apache rewrite di `.htaccess` atau fallback `router.php`.
* **Lokasi Penyimpanan Berkas**:
  * Materi Perkuliahan: `storage/01_Materi_Perkuliahan/`
  * RPS & Silabus: `storage/02_RPS_dan_Silabus/`
  * Pengumpulan Tugas: `storage/03_Pengumpulan_Tugas/`
  * Avatar Pengguna: `public_html/assets/uploads/avatars/`
  * Logo & Favicon: `public_html/assets/uploads/branding/`
