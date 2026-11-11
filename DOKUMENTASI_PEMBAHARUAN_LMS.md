# DOKUMENTASI LENGKAP PENGEMBANGAN & PEMBAHARUAN SISTEM
# LMS POLIKANT × KELAUTAN.ID
### *Powered by Kelautan.ID — Portal Maritim Indonesia*

* **Platform**: LMS Polikant — Kelautan.ID Maritime Learning Platform
* **Infrastruktur**: Ubuntu Server 22.04 LTS, Apache2 (Event MPM), PHP 8.4-FPM, MariaDB InnoDB (VPS Dedicated)
* **Arsitektur**: Server-Side Rendered (SSR) / Multi-Page Application (MPA) dengan Pola Post/Redirect/Get (PRG), Native PHP Sessions, Tailwind CSS, Lucide Icons, dan Vanilla JS
* **Inisiator & Pengembang**: Budhi Agung Prasetyo, S.Pi., M.Si.
* **Versi Terkini**: **v3.6.2 (Bank Soal, Kuis Interaktif, Timer & External Quiz Handling - Production Ready)**

---

## DAFTAR ISI RIWAYAT VERSI
1. [Ringkasan Riwayat Versi (Milestone)](#1-ringkasan-riwayat-versi-milestone)
2. [Detail Fitur Versi Terbaru: Versi 3.6](#2-detail-fitur-versi-terbaru-versi-36)
   - [2.1 Bank Soal Perkuliahan (PG, Benar/Salah, Isian Singkat, Essay)](#21-bank-soal-perkuliahan-pg-benarsalah-isian-singkat-essay)
   - [2.2 Kelola Rangkaian Soal Kuis Mingguan](#22-kelola-rangkaian-soal-kuis-mingguan)
   - [2.3 Pengerjaan Kuis Interaktif Mahasiswa dengan Timer Hitung Mundur](#23-pengerjaan-kuis-interaktif-mahasiswa-dengan-timer-hitung-mundur)
   - [2.4 Mesin Auto-Grading & Penilaian Manual Essay Dosen](#24-mesin-auto-grading--penilaian-manual-essay-dosen)
   - [2.5 Integrasi Otomatis ke Rekap Nilai & Ekspor CSV/Excel](#25-integrasi-otomatis-ke-rekap-nilai--ekspor-csvexcel)
   - [2.6 Pengaturan Durasi Timer Kuis Interaktif](#26-pengaturan-durasi-timer-kuis-interaktif)
   - [2.7 Penanganan Cerdas Kuis Tautan Eksternal vs Bank Soal Internal](#27-penanganan-cerdas-kuis-tautan-eksternal-vs-bank-soal-internal)
   - [2.8 Kompatibilitas Multi-Database (MariaDB VPS & SQLite Lokal)](#28-kompatibilitas-multi-database-mariadb-vps--sqlite-lokal)
   - [2.9 Konfigurasi Apache Routing & Canonical Clean URLs](#29-konfigurasi-apache-routing--canonical-clean-urls)
3. [Riwayat Fitur Versi 3.5](#3-riwayat-fitur-versi-35)
   - [3.1 Sisi Pengajar: Rekapitulasi Nilai Tugas Perkuliahan & Ekspor CSV/Excel](#31-sisi-pengajar-rekapitulasi-nilai-tugas-perkuliahan--ekspor-csvexcel)
4. [Riwayat Fitur Versi 3.4](#4-riwayat-fitur-versi-34)
   - [3.1 Sisi Pengajar: Fitur Edit Modul & Komponen Pembelajaran 16 Minggu](#31-sisi-pengajar-fitur-edit-modul--komponen-pembelajaran-16-minggu)
4. [Riwayat Fitur Versi 3.3](#4-riwayat-fitur-versi-33)
   - [4.1 Sisi Mahasiswa & Pengalaman Pengguna](#41-sisi-mahasiswa--pengalaman-pengguna)
   - [4.2 Sisi Tampilan, Antarmuka & Reorganisasi Navigasi](#42-sisi-tampilan-antarmuka--reorganisasi-navigasi)
   - [4.3 Sisi Keamanan & Manajemen Sesi](#43-sisi-keamanan--manajemen-sesi)
5. [Riwayat Versi Sebelumnya (v1.0 s/d v3.2)](#5-riwayat-versi-sebelumnya-v10-sd-v32)
   - [Versi 3.2: Multi-Dosen, Batas Upload 50MB, Tautan Eksternal & Sortir Angkatan](#versi-32)
   - [Versi 3.1: Co-Branding Kelautan.ID & Resilient SQLite Local Fallback](#versi-31)
   - [Versi 3.0: Transformasi Arsitektur PHP 8.4 MPA & MariaDB VPS](#versi-30)
   - [Versi 1.0 – 2.5: Prototipe Google Apps Script & Google Sheets](#versi-10--25)
6. [Tabel Matriks Perbandingan Fitur Antar Versi](#6-tabel-matriks-perbandingan-fitur-antar-versi)

---

## 1. RINGKASAN RIWAYAT VERSI (MILESTONE)

```
[ v1.0 - v2.5 ]  Google Apps Script (GAS) + Google Sheets (Limitasi 6 menit, Base64 storage)
       │
       ▼
[   v3.0   ]  Migrasi Total ke PHP 8.4 MPA + MariaDB di Server VPS Ubuntu 22.04 kelautan.id
       │
       ▼
[   v3.1   ]  Integrasi Co-Branding Kelautan.ID & Auto-Fallback SQLite Lokal
       │
       ▼
[   v3.2   ]  Team Teaching Multi-Dosen, Tenggat Waktu Kuis/Ujian, Slider Upload 50MB,
              Tautan Google Drive Mahasiswa, Sortir Angkatan Kelas, Standar CSS W3C
       │
       ▼
[   v3.3   ]  Animasi Submit Tugas Mahasiswa, Pengingat Tenggat Waktu (Deadlines),
              Halaman Login Cerah Beraksen Maritim, Upload Custom Logo & Favicon,
              Left Sidebar Responsif + Top Header Ringkas, Nama Prodi Dinamis Global,
              Pagination 20 Baris Mahasiswa, dan Fitur "Ingat Saya" (Remember Me)
       │
       ▼
[   v3.4   ]  Fitur Edit Modul & Komponen Pembelajaran Mingguan (Perubahan Pertemuan, Tipe,
              Judul, Deskripsi, Lampiran Berkas, URL Embed Eksternal, dan Tenggat Waktu)
       │
       ▼
[   v3.5   ]  Pusat Rekap Nilai Perkuliahan (/rekap-nilai), Tab Rekap Nilai Ruang Kelas
              (/kelas/{id}/rekap), Matriks Nilai Mahasiswa 16 Minggu, Rata-rata Otomatis,
              dan Ekspor Berkas CSV/Excel Kompatibel UTF-8 BOM & Semicolon Directive.
       │
       ▼
[   v3.6   ]  Sistem Bank Soal Terpadu (/bank-soal) dengan 4 Tipe Soal (Pilihan Ganda,
(TERKINI)     Benar/Salah, Isian Singkat, Essay), Rangkaian Soal Kuis Mingguan (/kuis/kelola/{id}),
              Pengerjaan Kuis Interaktif Mahasiswa (/kuis/kerjakan/{id}) Berbasis Timer Countdown,
              Mesin Auto-Grading Instan, Lembar Koreksi Essay Dosen (/kuis/nilai/{id}),
              serta Sinkronisasi Otomatis ke Transkrip Rekap Nilai & Ekspor CSV/Excel.
```

---

## 2. DETAIL FITUR VERSI TERBARU: VERSI 3.6

### 2.1 Bank Soal Perkuliahan (`/bank-soal`)
1. **Master Repositori Butir Soal Terpusat**:
   - Dosen dapat mengelola bank butir pertanyaan yang dapat dikaitkan dengan mata kuliah tertentu atau bersifat umum (*reusable* lintas kelas).
   - Mendukung 4 tipe butir soal komprehensif:
     - **Pilihan Ganda (PG)**: Teks pertanyaan, opsi jawaban (A s/d E), dan penentuan kunci jawaban benar dengan radio button satu klik.
     - **Benar / Salah (True / False)**: Pernyataan evaluasi dengan kunci status Benar atau Salah.
     - **Isian Singkat (Short Answer)**: Pertanyaan langsung dengan kunci jawaban teks (mendukung beberapa variasi kata kunci alternatif yang dipisahkan koma).
     - **Essay / Uraian**: Pertanyaan analitis atau studi kasus mendalam disertai panduan rubrik penilaian untuk dosen pengampu.
   - Dilengkapi filter per mata kuliah, filter per tipe soal, pencarian teks, bobot default, dan pembahasan rujukan materi.

### 2.2 Kelola Rangkaian Soal Kuis Mingguan (`/kuis/kelola/{id_konten}`)
1. **Penyusunan Paket Kuis Fleksibel**:
   - Dosen dapat menghubungkan butir soal dari Bank Soal ke dalam Kuis/Ujian pada minggu perkuliahan tertentu.
   - Fitur modal pemilih (*Question Picker*) yang memungkinkan pemilihan banyak soal secara massal (*bulk select*).
   - Penyesuaian nomor urut pengerjaan soal dan penyesuaian bobot poin per butir soal untuk kuis terkait.
   - Perhitungan otomatis total bobot kuis dan komposisi tipe soal secara *real-time*.

### 2.3 Pengerjaan Kuis Interaktif Mahasiswa (`/kuis/kerjakan/{id_konten}`)
1. **Antarmuka Pengerjaan yang Aman & Modern**:
   - *Sticky Header Countdown Timer*: Menampilkan sisa waktu pengerjaan kuis yang berjalan secara *server-side timestamp* dan aman dari manipulasi jam lokal perangkat.
   - Peringatan visual berdenyut (*pulse warning*) saat waktu tersisa di bawah 5 menit, dan mekanisme *auto-submit* otomatis saat waktu habis.
   - Bilah navigasi nomor soal yang otomatis berubah warna hijau ketika butir soal telah dijawab.
   - Input ramah pengguna untuk setiap tipe soal (kartu opsi PG, radio Benar/Salah, text input isian singkat, dan textarea essay).

### 2.4 Mesin Auto-Grading & Penilaian Manual Essay Dosen (`/kuis/nilai/{id_konten}`)
1. **Koreksi Otomatis 100% (Instant Grading)**:
   - Begitu kuis diserahkan, sistem langsung menilai soal Pilihan Ganda, Benar/Salah, dan Isian Singkat (dengan normalisasi *case-insensitive*).
2. **Lembar Koreksi Essay Dosen**:
   - Jika kuis memuat butir essay, status kuis ditandai *"Perlu Koreksi Essay"*.
   - Dosen membuka lembar jawaban mahasiswa, melihat perolehan butir objektif, dan memberikan nilai pada butir essay (0 s/d bobot maksimal) beserta catatan evaluasi spesifik.
   - Menghitung ulang skor skala 0 - 100 secara instan dan menyimpan feedback umum ke riwayat mahasiswa.

### 2.5 Integrasi Otomatis Rekap Nilai Kuis LMS ke Transkrip & Ekspor CSV/Excel
1. **Penyatuan Evaluasi Tugas & Kuis LMS**:
   - Tab Rekap Nilai di Ruang Kelas (`/kelas/{kode_mk}/rekap`) dan Pusat Rekap Nilai (`/rekap-nilai`) kini secara otomatis menggabungkan seluruh komponen **Tugas** dan **Kuis Bank Soal LMS** ke dalam matriks transkrip perkuliahan 16 minggu.
2. **Eksklusi Tepat Sasaran Kuis Eksternal**:
   - Kuis atau ujian yang menggunakan tautan eksternal (`!empty($url_embed)` seperti Google Forms atau Quizizz) **dikecualikan** dari rekapitulasi nilai tugas & kuis, karena nilai dan pengerjaannya berada di luar basis data internal LMS.
3. **Penyajian Matriks yang Informatif**:
   - Kolom kuis ditandai dengan badge ungu (*Kuis M13*) lengkap dengan tautan langsung ke lembar nilai & koreksi kuis (`/kuis/nilai/{id}`).
   - Kolom tugas ditandai dengan badge amber (*Tugas M3*) dengan tautan ke lembar penilaian tugas (`/nilai/{id}`).
   - Status pengerjaan kuis menampilkan indikator: *Mengerjakan*, *Koreksi Essay*, atau nilai angka berskala 0 - 100 dengan gradien warna.
   - Ekspor berkas CSV/Excel secara rapi membedakan label kolom evaluasi: `[Kuis] M13: Judul Kuis` dan `[Tugas] M3: Judul Tugas`.

### 2.6 Pengaturan Durasi Timer Kuis Interaktif
1. **Fleksibilitas Durasi Timer**:
   - Dosen dapat mengatur batas waktu durasi pengerjaan timer kuis (dalam satuan menit) melalui:
     - **Modal "Atur Durasi & Deadline" di Halaman Kelola Kuis (`/kuis/kelola/{id}`)**: Dilengkapi tombol preset cepat (15m, 30m, 45m, 60m, 90m, dan Tanpa Timer) serta pengaturan *deadline* tanggal & jam.
     - **Modal Tambah & Edit Komponen di Ruang Kelas (`ruang-kelas.php`)**: Muncul otomatis saat tipe komponen dipilih *Kuis* atau *Ujian*.
   - Jika durasi disetel, saat mahasiswa mulai mengerjakan kuis, *countdown timer* akan berjalan di bagian atas halaman dan otomatis mengirimkan (*auto-submit*) jawaban jika waktu habis.
   - Jika dikosongkan (tanpa timer), mahasiswa dapat mengerjakan secara bebas hingga batas tanggal *deadline*.

### 2.7 Penanganan Cerdas Kuis Tautan Eksternal vs Bank Soal Internal
1. **Kondisional Tampilan Ruang Kelas**:
   - **Kuis Internal (Bank Soal LMS)**: Mahasiswa melihat kotak *Status Pengerjaan Kuis Daring*, ringkasan butir soal, status nilai, dan tombol *"Mulai Kerjakan Kuis"* / *"Lanjutkan Kuis"*. Dosen melihat tombol *"Kelola Soal"* dan *"Nilai"*.
   - **Kuis Eksternal (Google Forms / Quizizz dsb.)**: Jika komponen kuis memiliki `url_embed` eksternal, sistem secara cerdas menyembunyikan kotak status pengerjaan kuis LMS dan hanya menampilkan tombol **"Buka Kuis Daring"** yang langsung membuka URL kuis eksternal di tab baru. Tombol *Preview Link* modal dinonaktifkan khusus kuis/ujian untuk menghindari pemblokiran *iframe* (*X-Frame-Options*).
2. **Pengalihan Otomatis (*Fallback Redirect*)**:
   - Jika mahasiswa langsung mengunjungi URL `/kuis/kerjakan/{id}` pada kuis bertautan eksternal, sistem langsung me-redirect browser mahasiswa ke URL eksternal tersebut.

### 2.8 Kompatibilitas Multi-Database (MariaDB VPS & SQLite Lokal)
1. **Resiliensi Sintaks SQL Lintas Driver**:
   - Mengatasi perbedaan sintaks *bulk insert* ignore antara SQLite (`INSERT OR IGNORE`) dan MariaDB / MySQL (`INSERT IGNORE`).
   - Helper `get_active_db_driver()` mendeteksi driver database secara dinamis sehingga query berjalan mulus tanpa error baik di server produksi VPS (MariaDB) maupun lingkungan lokal (SQLite).

### 2.9 Konfigurasi Apache Routing & Canonical Clean URLs
1. **Routing Apache `.htaccess` di Server VPS**:
   - Penambahan aturan `RewriteRule` untuk rute RESTful modul kuis:
     - `RewriteRule ^kuis/kelola/([^/]+)/?$ kuis-kelola.php?id_konten=$1 [QSA,L]`
     - `RewriteRule ^kuis/kerjakan/([^/]+)/?$ kuis-kerjakan.php?id_konten=$1 [QSA,L]`
     - `RewriteRule ^kuis/nilai/([^/]+)/?$ kuis-nilai.php?id_konten=$1 [QSA,L]`
   - Mencegah error 404 pada tautan bersih kuis di server web Apache produksi.

---

## 3. RIWAYAT FITUR VERSI 3.5

### 3.1 Sisi Pengajar: Rekapitulasi Nilai Tugas Perkuliahan & Ekspor CSV/Excel
1. **Pusat Rekap Nilai Global (`/rekap-nilai`)**:
   - Dosen dapat mengakses menu baru **"Rekap Nilai"** di Sidebar kiri desktop maupun drawer ponsel.
   - Menampilkan seluruh mata kuliah yang diampu, jumlah mahasiswa terdaftar, dan jumlah tugas aktif dengan tombol akses cepat ke matriks nilai dan ekspor.
2. **Tab Rekap Nilai di Ruang Kelas (`/kelas/{kode_mk}/rekap`)**:
   - Tab ke-4 baru berdampingan dengan *16 Minggu Perkuliahan*, *Deskripsi & RPS*, dan *Peserta Kelas*.
   - Menghitung statistik kelas: Total Mahasiswa, Total Tugas, Jumlah Tugas Terkumpul, dan Nilai Rata-rata Kelas.
3. **Matriks Transkrip Tugas Mahasiswa**:
   - Tabel responsif dengan kolom tetap (NIM, Nama Mahasiswa, Angkatan) dan kolom dinamis untuk setiap pertemuan yang memiliki tugas (M1 s/d M16).
   - Menampilkan skor angka bergradien warna (Hijau $\ge 80$, Biru $65 - 79$, Kuning $< 65$), status kumpul belum dinilai, serta kalkulasi rata-rata nilai akhir secara *real-time*.
4. **Ekspor CSV / Excel Fleksibel**:
   - **Ekspor Lengkap 1 Semester**: Menghasilkan berkas CSV berisi seluruh nilai tugas mingguan mahasiswa beserta rata-ratanya.
   - **Ekspor Khusus Per Pertemuan**: Menghasilkan berkas CSV untuk tugas tertentu lengkap dengan nama file jawaban, link Google Drive, dan waktu submit mahasiswa.
   - Dilengkapi **UTF-8 BOM** (*Byte Order Mark*) agar langsung rapi dibuka di Microsoft Excel tanpa masalah encoding karakter atau pemisahan kolom.

---

## 3. RIWAYAT FITUR VERSI 3.4

### 2.1 Sisi Pengajar: Fitur Edit Modul & Komponen Pembelajaran 16 Minggu
1. **Tombol Edit pada Setiap Komponen Pembelajaran**:
   * Setiap item komponen pembelajaran (Materi, Tugas, Kuis, Ujian) kini dilengkapi tombol **Edit** dengan ikon pensil (`edit-3`) di sebelah tombol hapus dan periksa nilai.
   * Hanya tampil untuk Dosen Pengampu dan Dosen Admin.
2. **Modal Interaktif Edit Komponen**:
   * Membuka modal dengan seluruh data komponen yang terisi otomatis (*pre-filled*): Pertemuan Minggu Ke- (1–16), Tipe Komponen, Judul, Deskripsi, Tautan Eksternal, dan Tenggat Waktu (*Deadline*).
   * **Fleksibilitas Pindah Pertemuan**: Dosen dapat memindahkan materi/tugas dari satu minggu ke minggu lainnya tanpa harus menghapus dan membuat ulang.
3. **Manajemen Berkas Lampiran yang Aman**:
   * Menampilkan nama berkas lampiran yang sedang aktif tersimpan di server.
   * Dosen dapat:
     * **Mempertahankan berkas lama** (cukup edit teks/link tanpa menyentuh file).
     * **Mengunggah berkas pengganti** (berkas lama otomatis dibersihkan dari direktori server dan digantikan berkas baru).
     * **Menghapus berkas lampiran** melalui opsi centang *"Hapus Berkas Ini"*.
4. **Validasi & Audit Trail Lengkap**:
   * Dilengkapi proteksi CSRF token dan validasi format berkas.
   * Setiap pengeditan tercatat rapi ke tabel `log_aktivitas` dengan tipe `UPDATE_KONTEN` lengkap dengan nama dosen, judul komponen, alokasi minggu, dan kelas terkait.
5. **Animasi Progress Penyimpanan**:
   * Tombol simpan dan overlay loading menampilkan status animasi saat proses unggah dan update berkas berlangsung.

---

## 3. RIWAYAT FITUR VERSI 3.3

### 2.1 Sisi Mahasiswa & Pengalaman Pengguna
1. **Animasi Visual saat Mengumpulkan Tugas**:
   * **Masalah Sebelumnya**: Mahasiswa tidak melihat indikator apa pun saat menekan tombol kirim tugas (terutama pada file ukuran 5–10 MB), sehingga sering memicu kebingungan dan klik berulang (*double submission*).
   * **Solusi v3.3**:
     * Ditambahkan modal overlay animasi interaktif (`#tugas-upload-overlay`) yang otomatis muncul saat form pengumpulan tugas disubmit.
     * Dilengkapi ikon radar/awan maritim beranimasi berputar (*spin & bounce*), progress bar berpulsasi, serta pesan penenang: *"Mohon tunggu beberapa saat. Berkas Anda sedang ditransfer dan divalidasi ke server penyimpanan Kelautan.ID."*
     * Tombol submit otomatis dinonaktifkan (*disabled*) dan teksnya berubah menjadi indikator status untuk mencegah klik ganda.
2. **Fitur Pengingat Tenggat Waktu (*Upcoming Deadlines Alert*)**:
   * **Latar Belakang**: Mahasiswa membutuhkan visibilitas cepat terkait tugas, kuis, atau ujian yang mendekati batas waktu agar tidak terlambat mengumpulkan.
   * **Implementasi v3.3**:
     * **Banner Notifikasi Mendesak**: Jika terdapat tugas yang memiliki sisa waktu $\le 3$ hari, pill peringatan beranimasi kedip (*pulsing badge*) muncul langsung di banner selamat datang dengan tautan lompat (*quick scroll*) ke rincian tugas.
     * **Seksi Khusus Pengingat di Dashboard**: Ditampilkan tepat di bawah statistik mahasiswa sebelum daftar mata kuliah.
     * **Filter Otomatis Berdasarkan Status Pengumpulan**: Hanya menampilkan penugasan yang **belum dikumpulkan** (`id_submit IS NULL`) oleh mahasiswa bersangkutan. Tugas yang sudah selesai otomatis hilang dari daftar pengingat.
     * **Badge Hitung Mundur Adaptif & Berwarna**:
       - `< 1 Jam`: Merah muda berdenyut (*"Sisa X mnt lagi! (Mendesak)"*).
       - `< 24 Jam`: Oranye amber tegas (*"Sisa X jam lagi! (Hari Ini)"*).
       - $\le 3$ Hari: Kuning peringatan (*"Sisa X hari lagi (Mendekati Batas)"*).
       - $> 3$ Hari: Biru laut santai (*"Sisa X hari lagi"*).
       - Melebihi Batas: Merah peringatan (*"Tenggat Waktu Berakhir"*).
     * **Tombol Akses Cepat**: Tiap kartu memiliki tombol *"Buka Kelas"* yang langsung mengarahkan mahasiswa ke tab pertemuan mata kuliah terkait.
     * **State Kosong (*Empty State*) Estetik**: Menampilkan kartu apresiasi hijau zamrud (*"Luar Biasa! Tidak Ada Tenggat Waktu Tertunda"*) jika seluruh penugasan telah dikumpulkan.

### 2.2 Sisi Tampilan, Antarmuka & Reorganisasi Navigasi
1. **Banner Selamat Datang Dinamis**:
   * Teks pada banner selamat datang di dashboard sebelumnya memuat hardcoded *"Program Studi Teknologi Kelautan"*.
   * Pada v3.3, teks banner disinkronkan secara *real-time* dengan nilai **Program Studi** di menu Pengaturan (`get_branding('study_program')`).
   * Badge nama platform di atas ucapan selamat datang yang sebelumnya *hardcoded* (`Polikant × Kelautan.ID Platform`) kini sepenuhnya dinamis mengambil variabel **Nama Aplikasi LMS** (`$branding['app_name']`).
2. **Sinkronisasi Global Nama Program Studi**:
   * Seluruh teks nama prodi yang muncul di platform (halaman manajemen mata kuliah, deskripsi kurikulum, modul ruang kelas, dan login) kini terpusat dan dinamis mengikuti perubahan pada panel Pengaturan.
3. **Nama Aplikasi LMS Dinamis di Halaman Login**:
   * Judul aplikasi di halaman login kini otomatis mengambil data **Nama Aplikasi LMS** (`get_branding('app_name')`) dari Pengaturan, tidak lagi terkunci statis.
4. **Opsi Unggah & Kustomisasi Logo Platform**:
   * Menu Pengaturan kini menyediakan fitur upload logo kustom (`custom_logo`) berformat SVG, PNG, JPG, atau WEBP (hingga 4 MB).
   * Logo kustom langsung diterapkan di halaman login, top header platform, mobile drawer, dan footer.
   * Disediakan opsi *Reset ke Logo Standar Kelautan.ID* sewaktu-waktu.
5. **Opsi Unggah & Kustomisasi Favicon**:
   * Menu Pengaturan kini menyediakan fitur upload favicon kustom (`custom_favicon`) berformat ICO, PNG, atau SVG (hingga 2 MB).
   * Otomatis terpasang di tag `<link rel="icon">` di seluruh halaman aplikasi.
6. **Pembaruan Teks Hak Cipta Footer**:
   * Teks hak cipta disederhanakan dan dibersihkan menjadi:
     > **© 2026 Kelautan.ID - Portal Maritim Indonesia.**
   * Menghilangkan imbuhan nama institusi pada baris hak cipta agar selaras dengan identitas payung ekosistem maritim nasional.
7. **Halaman Login Bertema Cerah (*Light & Fresh Oceanic*)**:
   * Halaman login bertransformasi dari tema biru gelap pekat (`abyssal-950`) menjadi tema **cerah, bersih, dan modern** dengan gradien halus (*slate-50 to sky-50*), aksen biru maritim yang segar, kartu login putih bersih berkontras tinggi, dan bayangan lembut.
8. **Reorganisasi Navigasi: Top Header Ringkas & Left Sidebar Responsif**:
   * **Top Header**: Disederhanakan hanya menampilkan Toggle Mobile, Logo Brand, Badge Tahun Akademik Aktif, Profil Pengguna, dan Tombol Cepat Logout.
   * **Left Sidebar (Sebelah Kiri)**:
     * Menampung seluruh navigasi utama: Dashboard, Tahun Akademik, Mata Kuliah, Sivitas Mahasiswa, Pengaturan Platform, Profil & Keamanan, serta Tautan Ekosistem (Portal, Journal, Go Shortener).
     * **Desktop**: Tampil sebagai sidebar permanen (*sticky*) yang elegan di samping kiri konten.
     * **Mobile**: Otomatis menyesuaikan menjadi *slide-out drawer* dengan animasi transisi mulus dan latar *backdrop blur* saat tombol hamburger ditekan.
9. **Penyesuaian Teks Deskripsi & RPS**:
   * Teks hardcoded pada tab RPS (*"Pedoman kurikulum dan capaian pembelajaran mata kuliah Program Studi Teknologi Kelautan Polikant"*) diganti dengan kombinasi variabel dinamis dari Pengaturan (`$branding['study_program']` dan `$branding['institution_name']`).
10. **Pagination Data Sivitas Mahasiswa**:
    * Halaman `mahasiswa.php` kini dilengkapi sistem **Pagination 20 baris per halaman**.
    * Dilengkapi navigasi nomor halaman, tombol Sebelumnya / Berikutnya, serta indikator rentang data aktif (contoh: *Menampilkan 1 - 20 dari 150 sivitas*), dengan parameter pencarian dan filter angkatan yang tetap terjaga saat berpindah halaman.

### 2.3 Sisi Keamanan & Manajemen Sesi
1. **Fitur "Ingat Saya" (*Remember Me*) pada Form Login**:
   * Checkbox *"Ingat saya di perangkat ini"* tersedia di form masuk.
   * **Jika dicentang**: Cookie sesi diperpanjang hingga **30 hari**, memungkinkan dosen dan mahasiswa tetap masuk tanpa harus berulang kali mengetik password di perangkat pribadi.
   * **Jika tidak dicentang**: Sesi browser dibatasi dengan **Idle Session Timeout 30 menit**. Jika tidak ada aktivitas klik/halaman selama 30 menit, sesi otomatis berakhir dan dialihkan ke login demi keamanan di komputer umum/laboratorium kampus.
2. **Fitur Simulasi Akun / Login-As (*User Impersonation*)**:
   * **Latar Belakang**: Dosen Admin kerap membutuhkan kemampuan untuk memverifikasi kendala teknis yang dilaporkan mahasiswa (misal: kendala upload tugas, status deadline, absensi, tampilan materi) atau memeriksa sudut pandang dosen pengampu lain tanpa perlu meminta atau mengetahui kata sandi mereka.
   * **Implementasi v3.3**:
     * **Pusat Simulasi Akun (`/login-as`)**: Halaman khusus Dosen Admin untuk mencari, memfilter per peran (Dosen / Mahasiswa) maupun Angkatan, serta masuk ke akun target dengan 1 klik.
     * **Tombol Cepat di Tabel Sivitas (`/mahasiswa`)**: Ikon masuk (*log-in*) langsung tersedia di setiap baris sivitas mahasiswa maupun dosen pengampu.
     * **Isolasi Sesi & Snapshot Admin**: Data sesi admin asli disimpan di `$_SESSION['impersonator']` saat beralih, dan peran aktif ditransformasikan secara penuh ke peran target (*Mahasiswa* atau *Dosen*).
     * **Bilah Mode Simulasi Melayang (*Sticky Alert Bar*)**: Bilah kuning cerah (*Amber Bar*) selalu menempel di bagian paling atas seluruh halaman selama mode simulasi berlangsung, menampilkan nama akun target dan nama akun admin asli.
     * **Tombol "Kembali ke Akun Admin" 1-Klik**: Dosen Admin dapat kembali ke akun admin aslinya kapan saja tanpa perlu login ulang. Dilengkapi pembersihan sesi menyeluruh (unset impersonator) dan auto-heal safeguard untuk menjamin admin tidak terperangkap dalam mode simulasi.
3. **Fitur Log Aktivitas & Audit Trail Sistem (`log_aktivitas`)**:
   * **Latar Belakang**: Memastikan akuntabilitas, transparansi, dan pembuktian autentik mengenai aktivitas seluruh pengguna di sistem, terutama pembuktian apakah mahasiswa benar-benar telah mengunggah tugas atau belum.
   * **Pencatatan Komprehensif Real-Time**:
     * **Log Login & Logout**: Mencatat setiap keberhasilan masuk, kegagalan login (salah kata sandi), dan waktu logout beserta IP address serta jenis browser (*User Agent*).
     * **Log Pengumpulan Tugas Mahasiswa**: Setiap kali mahasiswa mengunggah atau memperbarui berkas tugas, sistem mencatat nama mahasiswa, NIM, nama tugas, kelas/mata kuliah, nama berkas fisik, tautan Google Drive, catatan, dan timestamp hingga satuan detik.
     * **Log Penilaian Tugas**: Mencatat saat dosen memberikan skor nilai dan evaluasi kualitatif kepada mahasiswa.
     * **Log Simulasi (Login-As)**: Mencatat kapan Dosen Admin memulai dan mengakhiri sesi simulasi, serta menandai (*flag*) seluruh aktivitas yang dilakukan di bawah mode simulasi dengan label khusus: `[Via Simulasi oleh Admin]`.
     * **Log Perubahan Modul & Akun**: Mencatat penambahan/penghapusan konten mingguan, pembaruan peserta kelas, serta pergantian kata sandi pengguna.
   * **Panel Khusus Dosen Admin (`/log-aktivitas`)**:
     * Menampilkan statistik ringkas: Total Log, Aktivitas Hari Ini, Upload Tugas, dan Sesi Simulasi.
     * Filter multi-kriteria: Pencarian kata kunci, filter kategori aktivitas, filter peran (Mahasiswa / Dosen), rentang waktu, dan opsi saring *"Hanya Login-As"*.
     * **Fitur Ekspor CSV**: Memungkinkan pengunduhan seluruh log terfilter ke format CSV (UTF-8 BOM) untuk pelaporan akreditasi dan arsip akademik.

---

## 3. RIWAYAT VERSI SEBELUMNYA (v1.0 s/d v3.2)

### Versi 3.2
* **Team Teaching Multi-Dosen**: Mendukung penugasan hingga 3 orang dosen pengampu dalam satu mata kuliah melalui tabel relasi `dosen_mata_kuliah`. Dosen slot 1 ditetapkan sebagai Dosen Koordinator, sedangkan slot 2–3 sebagai Dosen Pengampu Anggota.
* **Tenggat Waktu Kuis & Ujian**: Penambahan kolom `tenggat_waktu` pada seluruh tipe penugasan perkuliahan (Tugas, Kuis, Ujian).
* **Slider Pengaturan Upload 50 MB**: Pengaturan dinamis batas ukuran file upload materi kuliah (5–50 MB) dan tugas (2–50 MB) dengan slider interaktif di panel admin.
* **Tautan Eksternal Tugas (Google Drive)**: Mahasiswa dapat mengumpulkan tugas berupa file fisik (PDF, PPT, Word, Excel) atau menyertakan link Google Drive dengan peringatan izin berbagi (*Viewer*).
* **Sortir & Filter Enrollment Angkatan**: Fitur filter instan berdasarkan Angkatan dan status pendaftaran saat mengelola peserta kelas di `ruang-kelas.php`.
* **Standarisasi CSS W3C**: Pembaruan gradien teks dan scrollbar W3C standar di `style.css` untuk kompatibilitas lintas peramban.

### Versi 3.1
* **Co-Branding Kelautan.ID**: Integrasi identitas visual maritim (*Oceanic Maritime Gradient* & *Abyssal Navy*).
* **Dual-Driver Resilient Database**: Fitur otomatis beralih (*auto-fallback*) ke SQLite lokal saat MariaDB lokal tidak aktif, memastikan pengembangan dan simulasi di localhost berjalan tanpa hambatan.
* **Runtime Schema Migration**: Skrip otomatis `ensure_schema_up_to_date()` yang memperbarui kolom database tanpa merusak data lama.

### Versi 3.0
* **Migrasi Penuh Arsitektur MPA**: Menggantikan Single Page Application (SPA) berbasis Google Apps Script dengan Multi-Page Application murni di atas PHP 8.4-FPM dan MariaDB InnoDB di VPS Ubuntu 22.04 LTS `kelautan.id`.
* **Private Local Storage**: Berkas materi, RPS, dan tugas dipindahkan ke direktori aman `/var/www/kelautan.id/lms/storage/` dengan skrip streaming `unduh.php` untuk mencegah akses file ilegal.
* **Keamanan Server**: Implementasi proteksi CSRF token di seluruh form POST, password hash Bcrypt/Argon2id, dan konfigurasi cookie sesi aman (`HttpOnly`, `SameSite=Lax`).

### Versi 1.0 – 2.5
* Prototipe awal aplikasi berbasis Google Apps Script (GAS) dan Google Sheets.
* Menggunakan modal preview Google Drive dan encoding berkas Base64.
* Terkendala batas waktu eksekusi Google 6 menit dan antrean `LockService`.

---

## 6. TABEL MATRIKS PERBANDINGAN FITUR ANTAR VERSI

| Modul / Fitur | Versi 2.5 (GAS) | Versi 3.0 (PHP MPA) | Versi 3.3 | Versi 3.4 | Versi 3.5 | Versi 3.6 (Terkini) |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **Basis Arsitektur** | SPA (Google Script) | MPA (PHP 8.4 SSR) | MPA (PHP 8.4 SSR) | MPA (PHP 8.4 SSR) | MPA (PHP 8.4 SSR) | **MPA (PHP 8.4 SSR)** |
| **Database** | Google Sheets | MariaDB InnoDB | MariaDB + SQLite | MariaDB + SQLite | MariaDB + SQLite | **MariaDB + SQLite Multi-Driver** |
| **Navigasi Menu** | Header Menu Bar | Header Bar Penuh | Left Sidebar + Header | Left Sidebar + Header | Left Sidebar + Header | **Left Sidebar + Header Ringkas** |
| **Tema Halaman Login** | Putih Dasar | Abyssal Biru Gelap | Cerah & Fresh Oceanic | Cerah & Fresh Oceanic | Cerah & Fresh Oceanic | **Cerah & Fresh Oceanic** |
| **Kelola Komponen Mingguan** | Tambah/Hapus | Tambah/Hapus | Tambah/Hapus | Tambah, Hapus, & Edit | Tambah, Hapus, & Edit | **Tambah, Hapus, Edit & Timer** |
| **Rekapitulasi Nilai & Ekspor** | Tidak Ada | Tidak Ada | Tidak Ada | Tidak Ada | Matriks 16M & CSV/Excel | **Matriks 16M + Kuis & CSV/Excel** |
| **Bank Soal Terpadu** | Tidak Ada | Tidak Ada | Tidak Ada | Tidak Ada | Tidak Ada | **4 Tipe: PG, B/S, Isian, Essay** |
| **Kuis Interaktif Mahasiswa** | Link Eksternal | Link Eksternal | Link Eksternal | Link Eksternal | Link Eksternal | **LMS Native Quiz + Countdown Timer** |
| **Auto-Grading & Koreksi Essay** | Tidak Ada | Tidak Ada | Tidak Ada | Tidak Ada | Tidak Ada | **Instant Grading + Lembar Koreksi Dosen** |
| **Penanganan Kuis Eksternal** | Buka Link | Buka Link | Buka Link | Buka Link | Buka Link | **Cerdas: Buka Link Langsung Tanpa Status LMS** |
| **Animasi Upload Tugas** | Tidak Ada | Tidak Ada | Overlay Radar Maritim | Overlay Radar Maritim | Overlay Radar Maritim | **Overlay Radar Maritim** |
| **Format Tugas Mahasiswa** | PDF Saja | PDF Saja | PDF, PPT, DOC, XLS + Link | PDF, PPT, DOC, XLS + Link | PDF, PPT, DOC, XLS + Link | **PDF, PPT, DOC, XLS + Link** |
| **Fitur Login-As (Simulasi)** | Tidak Ada | Tidak Ada | Dosen & Mhs + Bilah Revert | Dosen & Mhs + Bilah Revert | Dosen & Mhs + Bilah Revert | **Dosen & Mhs + Bilah Revert** |
| **Log Aktivitas & Audit Trail** | Tidak Ada | Tidak Ada | Audit Trail & Ekspor CSV | Audit Trail & Ekspor CSV | Audit Trail & Ekspor CSV | **Audit Trail Lengkap & Ekspor CSV** |
| **Pengingat Deadline** | Tidak Ada | Tidak Ada | Countdown Badge & Alert | Countdown Badge & Alert | Countdown Badge & Alert | **Countdown Badge, Alert & Timer Kuis** |
