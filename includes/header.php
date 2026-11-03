<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Global Header Component
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/auth_check.php';

$pageTitle = $pageTitle ?? 'Portal Pembelajaran';
$branding = get_branding();
$activeTA = get_active_ta();
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($branding['app_name']) ?></title>
    
    <!-- Meta Identity & SEO -->
    <meta name="description" content="<?= htmlspecialchars($branding['app_tagline']) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(get_favicon_url()) ?>">

    <!-- Google Fonts: Outfit & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">

    <!-- Suppress Tailwind Play CDN warning in browser console -->
    <script>
        (function() {
            const _warn = console.warn;
            console.warn = function(...args) {
                if (args[0] && typeof args[0] === 'string' && args[0].includes('cdn.tailwindcss.com')) return;
                _warn.apply(console, args);
            };
        })();
    </script>
    <!-- Tailwind CSS (CDN with Oceanic Custom Config) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        abyssal: {
                            950: '#060D18',
                            900: '#0A192F',
                            800: '#0F172A',
                            700: '#1E293B',
                        },
                        maritime: {
                            900: '#1E3A8A',
                            800: '#1E40AF',
                            700: '#1D4ED8',
                            600: '#2563EB',
                            500: '#0284C7',
                        },
                        ocean: {
                            400: '#38BDF8',
                            500: '#0EA5E9',
                            DEFAULT: '#06B6D4',
                            cyan: '#22D3EE',
                        },
                        coral: '#F59E0B',
                        seafoam: '#10B981',
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        heading: ['"Outfit"', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Custom Oceanic Stylesheet -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex flex-col min-h-screen bg-slate-50 text-slate-800 antialiased">
