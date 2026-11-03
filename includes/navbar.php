<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Global Navigation: Top Header Ringkas & Left Sidebar Responsif (v3.3)
 */

declare(strict_types=1);

$branding = get_branding();
$activeTA = get_active_ta();
$currentUser = current_user();
$currentScript = basename($_SERVER['PHP_SELF']);

function nav_active(string $target, string $current): string {
    $targetClean = trim(str_replace(['.php', '/'], '', $target));
    $currentClean = trim(str_replace(['.php', '/'], '', $current));
    return ($targetClean === $currentClean) 
        ? 'bg-gradient-to-r from-cyan-950/80 to-blue-950/50 text-cyan-300 font-semibold border-l-4 border-cyan-400 shadow-sm' 
        : 'text-slate-300 hover:text-white hover:bg-white/5 border-l-4 border-transparent transition';
}
?>

<!-- 1. TOP HEADER (Ringkas: Menu Utama, Profil & Logout Saja) -->
<header class="sticky top-0 z-40 bg-abyssal-900 border-b border-slate-800 text-white shadow-md">
    <?php if (is_impersonating()): ?>
        <?php $impersonator = impersonator_user(); ?>
        <!-- Bilah Mode Simulasi (Login-As) -->
        <div class="bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 text-slate-950 px-4 py-2 text-xs font-semibold shadow-inner flex flex-wrap items-center justify-between gap-2 border-b border-amber-600/40">
            <div class="flex items-center space-x-2.5 truncate">
                <span class="flex h-2.5 w-2.5 relative shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-slate-950 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-slate-950"></span>
                </span>
                <div class="truncate text-slate-950">
                    <span class="uppercase tracking-wider font-extrabold text-[10px] bg-slate-950 text-amber-300 px-2 py-0.5 rounded-full mr-1.5 shadow-xs">Mode Simulasi</span>
                    <span>Masuk sebagai: <strong><?= htmlspecialchars($currentUser['nama'] ?? '') ?></strong> (<?= htmlspecialchars($currentUser['role'] ?? '') ?>)</span>
                    <span class="hidden sm:inline text-slate-800 ml-1">• Admin Asli: <strong><?= htmlspecialchars($impersonator['nama'] ?? '') ?></strong></span>
                </div>
            </div>
            <form action="/login-as" method="POST" class="inline shrink-0 m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="revert">
                <button type="submit" class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-lg bg-slate-950 hover:bg-slate-900 text-amber-300 hover:text-white font-bold transition shadow-xs">
                    <i data-lucide="log-out" class="w-3.5 h-3.5 text-amber-300"></i>
                    <span>Kembali ke Akun Admin</span>
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="w-full px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            
            <!-- Left: Mobile Sidebar Toggle + Brand Logo -->
            <div class="flex items-center space-x-3">
                <!-- Hamburger Button (Mobile Drawer Toggle) -->
                <button id="mobile-sidebar-toggle" type="button" 
                        class="md:hidden p-2 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 transition"
                        title="Buka Navigasi">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>

                <a href="/dashboard" class="flex items-center space-x-3 group">
                    <img src="<?= htmlspecialchars(get_logo_url()) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="h-9 w-auto drop-shadow">
                    <span class="hidden sm:inline font-bold text-white text-base font-heading group-hover:text-cyan-300 transition">
                        <?= htmlspecialchars($branding['app_name']) ?>
                    </span>
                </a>

                <!-- Active Academic Year Badge -->
                <?php if ($activeTA): ?>
                    <div class="hidden lg:flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-cyan-950/80 border border-cyan-500/30 text-cyan-300">
                        <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
                        <span><?= htmlspecialchars($activeTA['nama_ta']) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Middle / Center: Pintasan Ekosistem Kelautan.ID -->
            <div class="hidden sm:flex items-center space-x-2">
                <a href="<?= htmlspecialchars($branding['url_portal']) ?>" target="_blank" rel="noopener noreferrer" 
                   class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-medium text-cyan-300 bg-cyan-950/50 hover:bg-cyan-900/70 border border-cyan-700/40 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                    <span>Portal Kelautan.ID</span>
                    <i data-lucide="external-link" class="w-3 h-3 text-cyan-400"></i>
                </a>
            </div>

            <!-- Right: Profil Pengguna & Logout Langsung -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                <?php if ($currentUser): ?>
                    <!-- Profil Button & Dropdown -->
                    <div class="relative">
                        <button id="user-menu-button" type="button" 
                                class="flex items-center space-x-2 sm:space-x-3 px-2.5 sm:px-3 py-1.5 rounded-xl bg-slate-800/80 hover:bg-slate-700 border border-slate-700/80 transition focus:outline-none focus:ring-2 focus:ring-cyan-500">
                            <?php $navAvatarUrl = get_avatar_url($currentUser['foto_profil'] ?? null); ?>
                            <?php if ($navAvatarUrl): ?>
                                <img src="<?= htmlspecialchars($navAvatarUrl) ?>" 
                                     alt="Avatar" 
                                     class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg object-cover shadow border border-cyan-400/50"
                                     onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-gradient-to-tr from-maritime-700 to-ocean items-center justify-center font-bold text-xs sm:text-sm text-white shadow" style="display:none;">
                                    <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                                </div>
                            <?php else: ?>
                                <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center font-bold text-xs sm:text-sm text-white shadow">
                                    <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>

                            <div class="hidden md:block text-left text-xs">
                                <div class="font-semibold text-white truncate max-w-[120px]"><?= htmlspecialchars($currentUser['nama']) ?></div>
                                <div class="text-cyan-400 text-[11px] font-medium"><?= htmlspecialchars($currentUser['role']) ?></div>
                            </div>
                            <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div id="user-menu-dropdown" class="hidden absolute right-0 mt-2 w-60 rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl py-2 z-50 text-xs">
                            <div class="px-4 py-2 border-b border-slate-800">
                                <p class="text-[11px] text-slate-400">Masuk sebagai</p>
                                <p class="font-semibold text-white truncate"><?= htmlspecialchars($currentUser['nama']) ?></p>
                                <p class="text-cyan-400 font-mono mt-0.5"><?= htmlspecialchars($currentUser['username']) ?> • <?= htmlspecialchars($currentUser['role']) ?></p>
                            </div>

                            <a href="/profil" class="flex items-center space-x-2.5 px-4 py-2.5 text-slate-300 hover:text-white hover:bg-slate-800 transition">
                                <i data-lucide="user-cog" class="w-4 h-4 text-slate-400"></i>
                                <span>Profil & Keamanan Akun</span>
                            </a>

                            <?php if (is_dosen_admin()): ?>
                                <a href="/pengaturan" class="flex items-center space-x-2.5 px-4 py-2.5 text-slate-300 hover:text-white hover:bg-slate-800 transition">
                                    <i data-lucide="sliders" class="w-4 h-4 text-slate-400"></i>
                                    <span>Pengaturan Platform</span>
                                </a>
                                <a href="/login-as" class="flex items-center space-x-2.5 px-4 py-2.5 text-slate-300 hover:text-white hover:bg-slate-800 transition">
                                    <i data-lucide="user-check" class="w-4 h-4 text-slate-400"></i>
                                    <span>Simulasi Akun (Login-As)</span>
                                </a>
                                <a href="/log-aktivitas" class="flex items-center space-x-2.5 px-4 py-2.5 text-slate-300 hover:text-white hover:bg-slate-800 transition">
                                    <i data-lucide="clipboard-list" class="w-4 h-4 text-slate-400"></i>
                                    <span>Log Aktivitas & Audit</span>
                                </a>
                            <?php endif; ?>

                            <div class="border-t border-slate-800 my-1"></div>

                            <a href="/logout" class="flex items-center space-x-2.5 px-4 py-2.5 text-rose-400 hover:text-rose-300 hover:bg-rose-950/40 transition font-medium">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                <span>Keluar (Logout)</span>
                            </a>
                        </div>
                    </div>

                    <!-- Quick Logout Button in Header -->
                    <a href="/logout" title="Keluar dari Akun" 
                       class="p-2 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-slate-800 border border-transparent hover:border-rose-900/50 transition">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<!-- 2. LAYOUT CONTAINER (Sidebar Kiri + Main Content Column) -->
<div class="flex-1 flex w-full relative min-h-[calc(100vh-4rem)]">

    <!-- DESKTOP SIDEBAR (Sebelah Kiri - Sticky) -->
    <aside id="desktop-sidebar" class="hidden md:flex flex-col w-64 bg-abyssal-900 border-r border-slate-800 shrink-0 sticky top-16 h-[calc(100vh-4rem)] overflow-y-auto z-30 select-none">
        
        <!-- User Badge Mini -->
        <?php if ($currentUser): ?>
            <div class="p-4 mx-3 my-3 rounded-2xl bg-slate-800/60 border border-slate-700/60 flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center font-bold text-sm text-white shrink-0 shadow">
                    <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($currentUser['nama']) ?></p>
                    <span class="inline-block px-2 py-0.5 mt-0.5 rounded text-[10px] font-semibold bg-cyan-950 text-cyan-300 border border-cyan-800/40">
                        <?= htmlspecialchars($currentUser['role']) ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigasi Menu Sidebar -->
        <nav class="flex-1 px-3 space-y-6">
            
            <!-- Grup 1: Menu Utama -->
            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                    Menu Utama
                </p>
                <div class="space-y-1">
                    <a href="/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('dashboard', $currentScript) ?>">
                        <i data-lucide="layout-dashboard" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                        <span>Dashboard</span>
                    </a>

                    <?php if (is_dosen_admin()): ?>
                        <a href="/tahun-akademik" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('tahun-akademik', $currentScript) ?>">
                            <i data-lucide="calendar-range" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Tahun Akademik</span>
                        </a>
                        <a href="/mata-kuliah" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('mata-kuliah', $currentScript) ?>">
                            <i data-lucide="book-open" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Mata Kuliah</span>
                        </a>
                        <a href="/mahasiswa" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('mahasiswa', $currentScript) ?>">
                            <i data-lucide="graduation-cap" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Sivitas Mahasiswa</span>
                        </a>
                        <a href="/pengaturan" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('pengaturan', $currentScript) ?>">
                            <i data-lucide="sliders" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Pengaturan Platform</span>
                        </a>
                        <a href="/login-as" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('login-as', $currentScript) ?>">
                            <i data-lucide="user-check" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Simulasi Akun (Login-As)</span>
                        </a>
                        <a href="/log-aktivitas" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('log-aktivitas', $currentScript) ?>">
                            <i data-lucide="clipboard-list" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                            <span>Log Aktivitas</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grup 2: Akun & Keamanan -->
            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                    Akun & Keamanan
                </p>
                <div class="space-y-1">
                    <a href="/profil" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('profil', $currentScript) ?>">
                        <i data-lucide="user-cog" class="w-4 h-4 shrink-0 text-slate-400"></i>
                        <span>Profil Pengguna</span>
                    </a>
                    <a href="/logout" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs text-rose-400 hover:text-rose-300 hover:bg-rose-950/30 transition">
                        <i data-lucide="log-out" class="w-4 h-4 shrink-0"></i>
                        <span>Keluar (Logout)</span>
                    </a>
                </div>
            </div>

            <!-- Grup 3: Ekosistem Kelautan.ID -->
            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                    Ekosistem Kelautan.ID
                </p>
                <div class="space-y-1 text-xs">
                    <a href="<?= htmlspecialchars($branding['url_portal']) ?>" target="_blank" rel="noopener noreferrer" 
                       class="flex items-center justify-between px-3 py-2 rounded-xl text-slate-300 hover:text-cyan-300 hover:bg-white/5 transition">
                        <span class="flex items-center space-x-2.5">
                            <i data-lucide="globe" class="w-4 h-4 text-cyan-400"></i>
                            <span>Portal Utama</span>
                        </span>
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-500"></i>
                    </a>
                    <a href="<?= htmlspecialchars($branding['url_journal']) ?>" target="_blank" rel="noopener noreferrer" 
                       class="flex items-center justify-between px-3 py-2 rounded-xl text-slate-300 hover:text-cyan-300 hover:bg-white/5 transition">
                        <span class="flex items-center space-x-2.5">
                            <i data-lucide="newspaper" class="w-4 h-4 text-cyan-400"></i>
                            <span>Journal Maritim</span>
                        </span>
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-500"></i>
                    </a>
                    <a href="<?= htmlspecialchars($branding['url_shortener'] ?? 'https://go.kelautan.id') ?>" target="_blank" rel="noopener noreferrer" 
                       class="flex items-center justify-between px-3 py-2 rounded-xl text-slate-300 hover:text-cyan-300 hover:bg-white/5 transition">
                        <span class="flex items-center space-x-2.5">
                            <i data-lucide="link" class="w-4 h-4 text-cyan-400"></i>
                            <span>Go Shortener</span>
                        </span>
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-500"></i>
                    </a>
                </div>
            </div>
        </nav>

        <!-- Sidebar Footer Version -->
        <div class="p-4 border-t border-slate-800 text-[11px] text-slate-500 text-center">
            <span class="font-semibold text-slate-400">LMS v3.3</span> • Kelautan.ID
        </div>
    </aside>

    <!-- MOBILE SIDEBAR DRAWER (Menyesuaikan saat dibuka di Mobile) -->
    <div id="mobile-sidebar-backdrop" class="hidden fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm md:hidden transition-opacity"></div>
    <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-50 w-72 bg-abyssal-900 border-r border-slate-800 shadow-2xl transition-transform duration-300 transform -translate-x-full md:hidden flex flex-col overflow-y-auto">
        
        <!-- Header Drawer -->
        <div class="flex items-center justify-between p-4 border-b border-slate-800">
            <div class="flex items-center space-x-2.5">
                <img src="<?= htmlspecialchars(get_logo_url()) ?>" alt="Logo" class="h-8 w-auto">
                <span class="font-bold text-white text-sm font-heading"><?= htmlspecialchars($branding['app_name']) ?></span>
            </div>
            <button id="mobile-sidebar-close" type="button" class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- User Mini Info in Mobile Drawer -->
        <?php if ($currentUser): ?>
            <div class="p-4 mx-3 my-3 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center font-bold text-sm text-white shrink-0">
                    <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($currentUser['nama']) ?></p>
                    <span class="text-[10px] text-cyan-400 font-semibold"><?= htmlspecialchars($currentUser['role']) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigasi Mobile Drawer -->
        <nav class="flex-1 px-3 space-y-5">
            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Navigasi Utama</p>
                <div class="space-y-1">
                    <a href="/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('dashboard', $currentScript) ?>">
                        <i data-lucide="layout-dashboard" class="w-4 h-4 text-cyan-400"></i>
                        <span>Dashboard</span>
                    </a>
                    <?php if (is_dosen_admin()): ?>
                        <a href="/tahun-akademik" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('tahun-akademik', $currentScript) ?>">
                            <i data-lucide="calendar-range" class="w-4 h-4 text-cyan-400"></i>
                            <span>Tahun Akademik</span>
                        </a>
                        <a href="/mata-kuliah" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('mata-kuliah', $currentScript) ?>">
                            <i data-lucide="book-open" class="w-4 h-4 text-cyan-400"></i>
                            <span>Mata Kuliah</span>
                        </a>
                        <a href="/mahasiswa" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('mahasiswa', $currentScript) ?>">
                            <i data-lucide="graduation-cap" class="w-4 h-4 text-cyan-400"></i>
                            <span>Sivitas Mahasiswa</span>
                        </a>
                        <a href="/pengaturan" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('pengaturan', $currentScript) ?>">
                            <i data-lucide="sliders" class="w-4 h-4 text-cyan-400"></i>
                            <span>Pengaturan Platform</span>
                        </a>
                        <a href="/login-as" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('login-as', $currentScript) ?>">
                            <i data-lucide="user-check" class="w-4 h-4 text-cyan-400"></i>
                            <span>Simulasi Akun (Login-As)</span>
                        </a>
                        <a href="/log-aktivitas" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('log-aktivitas', $currentScript) ?>">
                            <i data-lucide="clipboard-list" class="w-4 h-4 text-cyan-400"></i>
                            <span>Log Aktivitas</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Akun</p>
                <div class="space-y-1">
                    <a href="/profil" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs <?= nav_active('profil', $currentScript) ?>">
                        <i data-lucide="user-cog" class="w-4 h-4 text-slate-400"></i>
                        <span>Profil & Keamanan</span>
                    </a>
                    <a href="/logout" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs text-rose-400 hover:text-rose-300 hover:bg-rose-950/30">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                        <span>Keluar (Logout)</span>
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Ekosistem</p>
                <div class="space-y-1 text-xs">
                    <a href="<?= htmlspecialchars($branding['url_portal']) ?>" target="_blank" class="flex items-center justify-between px-3 py-2 text-slate-300">
                        <span>Portal Kelautan.ID</span>
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-500"></i>
                    </a>
                    <a href="<?= htmlspecialchars($branding['url_journal']) ?>" target="_blank" class="flex items-center justify-between px-3 py-2 text-slate-300">
                        <span>Journal Kelautan.ID</span>
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-slate-500"></i>
                    </a>
                </div>
            </div>
        </nav>

        <div class="p-4 border-t border-slate-800 text-[11px] text-slate-500 text-center">
            <span class="font-semibold text-slate-400">LMS v3.3</span> • Kelautan.ID
        </div>
    </aside>

    <!-- MAIN CONTENT COLUMN (Memuat Konten Halaman & Notifikasi Flash) -->
    <div class="flex-1 flex flex-col min-w-0 bg-slate-50">
        
        <!-- Flash Alert Notification Area (PRG Pattern) -->
        <?php $flash = get_flash(); if ($flash): ?>
            <?php
            $bgClass = match ($flash['type']) {
                'success' => 'bg-emerald-50 border-emerald-300 text-emerald-900',
                'error'   => 'bg-rose-50 border-rose-300 text-rose-900',
                'warning' => 'bg-amber-50 border-amber-300 text-amber-900',
                default   => 'bg-sky-50 border-sky-300 text-sky-900',
            };
            $iconName = match ($flash['type']) {
                'success' => 'check-circle-2',
                'error'   => 'alert-circle',
                'warning' => 'alert-triangle',
                default   => 'info',
            };
            ?>
            <div class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                <div class="flash-alert flex items-start justify-between p-4 rounded-2xl border <?= $bgClass ?> shadow-sm transition-opacity duration-200">
                    <div class="flex items-center space-x-3">
                        <i data-lucide="<?= $iconName ?>" class="w-5 h-5 flex-shrink-0"></i>
                        <div class="text-sm font-semibold"><?= htmlspecialchars($flash['message']) ?></div>
                    </div>
                    <button type="button" class="flash-close-btn p-1 text-slate-500 hover:text-slate-800 rounded">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
