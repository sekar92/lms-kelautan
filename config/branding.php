<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Konfigurasi & Helper Branding Dinamis Kelautan.ID
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function get_branding(?string $key = null): mixed {
    static $settings = null;

    if ($settings === null) {
        $defaults = [
            'platform_name'    => 'Kelautan.ID - Portal Maritim Indonesia',
            'app_name'         => 'LMS Polikant × Kelautan.ID',
            'app_tagline'      => 'Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia',
            'institution_name' => 'Politeknik Perikanan Negeri Tual',
            'study_program'    => 'Program Studi Teknologi Kelautan',
            'url_portal'       => 'https://kelautan.id',
            'url_lms'          => 'https://lms.kelautan.id',
            'url_journal'      => 'https://journal.kelautan.id',
            'support_email'    => 'teknologikelautanid@gmail.com',
            'footer_text'      => '© 2026 Kelautan.ID - Portal Maritim Indonesia.',
            'footer_desc'        => 'Portal Informasi, Riset & Pembelajaran Maritim Terpadu Indonesia. Platform pembelajaran daring resmi terpadu.',
            'footer_links_title' => 'Ekosistem Kelautan.ID',
            'footer_link1_label' => 'Portal Utama',
            'footer_link1_url'   => 'https://kelautan.id',
            'footer_link2_label' => 'Journal Kelautan.ID',
            'footer_link2_url'   => 'https://journal.kelautan.id',
            'footer_link3_label' => 'Pemendek Link (Go)',
            'footer_link3_url'   => 'https://go.kelautan.id',
            'contact_title'      => 'Bantuan & Kontak',
            'contact_email'      => 'teknologikelautanid@gmail.com',
            'contact_address'    => 'Polikant, Tual, Maluku',
            'contact_person'     => 'Inisiator: Budhi Agung Prasetyo, S.Pi., M.Si.',
            'max_upload_materi_mb' => 25,
            'max_upload_tugas_mb'  => 10,
            'custom_logo_url'      => '',
            'custom_favicon_url'   => '',
        ];

        try {
            $db = get_db();
            $stmt = $db->query("SELECT setting_key, setting_value FROM pengaturan_sistem");
            $rows = $stmt->fetchAll();
            $fromDb = [];
            foreach ($rows as $row) {
                $fromDb[$row['setting_key']] = $row['setting_value'];
            }
            $settings = array_merge($defaults, $fromDb);
            
            // Otomatis sinkronkan jika footer_text di database masih versi lama
            if (isset($settings['footer_text']) && str_contains($settings['footer_text'], 'Politeknik Perikanan Negeri Tual')) {
                $settings['footer_text'] = '© 2026 Kelautan.ID - Portal Maritim Indonesia.';
                try {
                    $upStmt = $db->prepare("UPDATE pengaturan_sistem SET setting_value = ? WHERE setting_key = 'footer_text'");
                    $upStmt->execute(['© 2026 Kelautan.ID - Portal Maritim Indonesia.']);
                } catch (Throwable) {}
            }
        } catch (Throwable $e) {
            // Jika tabel belum diinisialisasi, gunakan nilai default
            $settings = $defaults;
        }
    }

    if ($key !== null) {
        return $settings[$key] ?? '';
    }

    return $settings;
}

function get_logo_url(): string {
    $custom = trim((string)get_branding('custom_logo_url'));
    if ($custom !== '') {
        $diskPath = dirname(__DIR__) . '/public_html/' . ltrim($custom, '/');
        if (file_exists($diskPath) && is_file($diskPath)) {
            return '/' . ltrim($custom, '/');
        }
    }
    return '/assets/img/logo-combined.svg';
}

function get_favicon_url(): string {
    $custom = trim((string)get_branding('custom_favicon_url'));
    if ($custom !== '') {
        $diskPath = dirname(__DIR__) . '/public_html/' . ltrim($custom, '/');
        if (file_exists($diskPath) && is_file($diskPath)) {
            return '/' . ltrim($custom, '/');
        }
    }
    return '/assets/img/favicon.svg';
}

function get_max_upload_materi_mb(): int {
    $val = (int)get_branding('max_upload_materi_mb');
    return ($val >= 2 && $val <= 100) ? $val : 25;
}

function get_max_upload_tugas_mb(): int {
    $val = (int)get_branding('max_upload_tugas_mb');
    return ($val >= 1 && $val <= 100) ? $val : 10;
}

function get_active_ta(): ?array {
    static $activeTA = null;
    if ($activeTA === null) {
        try {
            $db = get_db();
            $stmt = $db->query("SELECT * FROM tahun_akademik WHERE status = 'Aktif' LIMIT 1");
            $activeTA = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            $activeTA = null;
        }
    }
    return $activeTA;
}
