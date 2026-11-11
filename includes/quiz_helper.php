<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Helper Fungsi Bank Soal & Sistem Kuis Interaktif (v3.6)
 */

declare(strict_types=1);

/**
 * Mendapatkan label manusiawi untuk tipe soal
 */
function get_tipe_soal_label(string $tipe): string {
    return match ($tipe) {
        'pg'          => 'Pilihan Ganda',
        'benar_salah' => 'Benar / Salah',
        'isian'       => 'Isian Singkat',
        'essay'       => 'Essay / Uraian',
        default       => ucfirst(str_replace('_', ' ', $tipe)),
    };
}

/**
 * Mendapatkan badge Tailwind styling untuk tipe soal
 */
function get_tipe_soal_badge(string $tipe): string {
    $config = match ($tipe) {
        'pg'          => ['label' => 'Pilihan Ganda', 'bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200'],
        'benar_salah' => ['label' => 'Benar / Salah', 'bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'isian'       => ['label' => 'Isian Singkat', 'bg' => 'bg-amber-50 text-amber-700 border-amber-200'],
        'essay'       => ['label' => 'Essay / Uraian', 'bg' => 'bg-purple-50 text-purple-700 border-purple-200'],
        default       => ['label' => ucfirst($tipe), 'bg' => 'bg-slate-50 text-slate-700 border-slate-200'],
    };

    return sprintf(
        '<span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold border %s">%s</span>',
        htmlspecialchars($config['bg']),
        htmlspecialchars($config['label'])
    );
}

/**
 * Menghitung otomatis nilai per butir soal dan total skor kuis
 * Mengembalikan array: [
 *   'total_skor' => float, 
 *   'max_skor' => float, 
 *   'skor_skala_100' => float, 
 *   'ada_essay' => bool, 
 *   'perlu_koreksi' => bool
 * ]
 */
function hitung_nilai_kuis_sesi(PDO $db, string $idSesi): array {
    // 1. Ambil info sesi
    $stmtSesi = $db->prepare("SELECT * FROM kuis_sesi WHERE id_sesi = ?");
    $stmtSesi->execute([$idSesi]);
    $sesi = $stmtSesi->fetch();
    if (!$sesi) {
        return ['total_skor' => 0, 'max_skor' => 0, 'skor_skala_100' => 0, 'ada_essay' => false, 'perlu_koreksi' => false];
    }

    $idKonten = $sesi['id_konten'];

    // 2. Ambil seluruh butir soal dalam kuis beserta bobotnya
    $stmtSoal = $db->prepare("
        SELECT ks.id_soal, ks.bobot_nilai, bs.tipe_soal, bs.kunci_jawaban_teks
        FROM kuis_soal ks
        JOIN bank_soal bs ON ks.id_soal = bs.id_soal
        WHERE ks.id_konten = ?
        ORDER BY ks.urutan ASC
    ");
    $stmtSoal->execute([$idKonten]);
    $daftarSoal = $stmtSoal->fetchAll();

    // 3. Ambil seluruh jawaban mahasiswa pada sesi ini
    $stmtJawaban = $db->prepare("SELECT * FROM kuis_jawaban WHERE id_sesi = ?");
    $stmtJawaban->execute([$idSesi]);
    $mapJawaban = [];
    foreach ($stmtJawaban->fetchAll() as $jaw) {
        $mapJawaban[$jaw['id_soal']] = $jaw;
    }

    $totalPoinDidapat = 0.0;
    $totalBobotMaksimal = 0.0;
    $adaEssay = false;
    $adaPerluKoreksi = false;

    // Siapkan statement update jawaban
    $stmtUpdJawaban = $db->prepare("
        UPDATE kuis_jawaban 
        SET nilai_butir = ?, status_koreksi = ?
        WHERE id_jawaban = ?
    ");

    foreach ($daftarSoal as $soal) {
        $idSoal = $soal['id_soal'];
        $tipe = $soal['tipe_soal'];
        $bobot = (float)$soal['bobot_nilai'];
        $totalBobotMaksimal += $bobot;

        $jawaban = $mapJawaban[$idSoal] ?? null;
        if (!$jawaban) {
            continue;
        }

        $idJawaban = $jawaban['id_jawaban'];
        $nilaiButir = 0.0;
        $statusKoreksi = 'otomatis';

        if ($tipe === 'pg' || $tipe === 'benar_salah') {
            // Cek apakah opsi yang dipilih berstatus is_benar = 1
            $idOpsi = $jawaban['id_opsi_dipilih'] ?? '';
            if (!empty($idOpsi)) {
                $stmtCek = $db->prepare("SELECT is_benar FROM bank_soal_opsi WHERE id_opsi = ? AND id_soal = ?");
                $stmtCek->execute([$idOpsi, $idSoal]);
                $isBenar = (int)$stmtCek->fetchColumn();
                if ($isBenar === 1) {
                    $nilaiButir = $bobot;
                }
            }
            $statusKoreksi = 'otomatis';
        } elseif ($tipe === 'isian') {
            // Pencocokan string isian singkat (case-insensitive & trim)
            $jawabanTeks = mb_strtolower(trim((string)($jawaban['jawaban_teks'] ?? '')));
            $kunciTeks = trim((string)($soal['kunci_jawaban_teks'] ?? ''));

            if ($jawabanTeks !== '' && $kunciTeks !== '') {
                // Pisahkan alternatif kunci dengan tanda koma atau titik koma jika ada
                $alternatives = array_map(fn($k) => mb_strtolower(trim($k)), preg_split('/[,;]+/', $kunciTeks));
                if (in_array($jawabanTeks, $alternatives, true)) {
                    $nilaiButir = $bobot;
                }
            }
            $statusKoreksi = 'otomatis';
        } elseif ($tipe === 'essay') {
            $adaEssay = true;
            // Jika sudah dinilai secara manual oleh dosen sebelumnya, pertahankan nilainya
            if ($jawaban['status_koreksi'] === 'dinilai') {
                $nilaiButir = (float)$jawaban['nilai_butir'];
                $statusKoreksi = 'dinilai';
            } else {
                $nilaiButir = 0.0;
                $statusKoreksi = 'perlu_koreksi';
                $adaPerluKoreksi = true;
            }
        }

        // Update record butir jawaban
        $stmtUpdJawaban->execute([$nilaiButir, $statusKoreksi, $idJawaban]);
        $totalPoinDidapat += $nilaiButir;
    }

    // Hitung skala 0 - 100
    $skorSkala100 = 0.0;
    if ($totalBobotMaksimal > 0) {
        $skorSkala100 = round(($totalPoinDidapat / $totalBobotMaksimal) * 100, 2);
    }

    return [
        'total_skor'     => $totalPoinDidapat,
        'max_skor'       => $totalBobotMaksimal,
        'skor_skala_100' => $skorSkala100,
        'ada_essay'      => $adaEssay,
        'perlu_koreksi'  => $adaPerluKoreksi,
    ];
}

/**
 * Sinkronisasi hasil nilai kuis ke tabel pengumpulan_tugas
 * agar otomatis muncul di Tab Rekap Nilai, CSV Export, dan Dashboard Mahasiswa
 */
function sync_kuis_ke_pengumpulan_tugas(PDO $db, string $idKonten, string $idUser, float $nilai, string $feedback = ''): void {
    $stmtCek = $db->prepare("SELECT id_submit FROM pengumpulan_tugas WHERE id_konten = ? AND id_user = ?");
    $stmtCek->execute([$idKonten, $idUser]);
    $existing = $stmtCek->fetch();

    if ($existing) {
        $stmtUpd = $db->prepare("
            UPDATE pengumpulan_tugas 
            SET nilai = ?, feedback = ?, waktu_submit = CURRENT_TIMESTAMP
            WHERE id_submit = ?
        ");
        $stmtUpd->execute([$nilai, $feedback, $existing['id_submit']]);
    } else {
        $idSubmit = generate_id('SUB', 'pengumpulan_tugas', 'id_submit');
        $stmtIns = $db->prepare("
            INSERT INTO pengumpulan_tugas (id_submit, id_konten, id_user, file_path, file_name, catatan_mhs, nilai, feedback, waktu_submit)
            VALUES (?, ?, ?, 'kuis_online', 'Kuis Daring Interaktif', 'Mengerjakan Kuis Interaktif LMS Kelautan.ID', ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmtIns->execute([$idSubmit, $idKonten, $idUser, $nilai, $feedback]);
    }
}
