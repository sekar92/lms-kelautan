<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Global Footer Component, Task Upload Animation Modal & Global PDF Previewer
 */

declare(strict_types=1);

$branding = get_branding();
?>

    <!-- Global Footer -->
    <footer class="mt-auto bg-abyssal-950 text-slate-400 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                
                <!-- Col 1: Identity & Ecosystem -->
                <div class="md:col-span-2 space-y-3">
                    <div class="flex items-center space-x-3">
                        <img src="<?= htmlspecialchars(get_logo_url()) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="h-8 w-auto">
                    </div>
                    <p class="text-sm text-slate-400 max-w-md leading-relaxed">
                        <?= htmlspecialchars($branding['footer_desc'] ?: ($branding['app_tagline'] . '. Platform pembelajaran daring resmi ' . $branding['study_program'] . ', ' . $branding['institution_name'] . '.')) ?>
                    </p>
                </div>

                <!-- Col 2: Ekosistem & Tautan -->
                <div>
                    <h4 class="text-sm font-semibold text-white tracking-wider uppercase mb-3 font-heading">
                        <?= htmlspecialchars($branding['footer_links_title'] ?: 'Tautan Ekosistem') ?>
                    </h4>
                    <ul class="space-y-2 text-xs">
                        <?php if (!empty($branding['footer_link1_label']) && !empty($branding['footer_link1_url'])): ?>
                            <li>
                                <a href="<?= htmlspecialchars($branding['footer_link1_url']) ?>" target="_blank" rel="noopener noreferrer" 
                                   class="hover:text-cyan-400 flex items-center space-x-1 transition">
                                    <span><?= htmlspecialchars($branding['footer_link1_label']) ?></span>
                                    <i data-lucide="arrow-up-right" class="w-3 h-3 text-slate-500"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($branding['footer_link2_label']) && !empty($branding['footer_link2_url'])): ?>
                            <li>
                                <a href="<?= htmlspecialchars($branding['footer_link2_url']) ?>" target="_blank" rel="noopener noreferrer" 
                                   class="hover:text-cyan-400 flex items-center space-x-1 transition">
                                    <span><?= htmlspecialchars($branding['footer_link2_label']) ?></span>
                                    <i data-lucide="arrow-up-right" class="w-3 h-3 text-slate-500"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($branding['footer_link3_label']) && !empty($branding['footer_link3_url'])): ?>
                            <li>
                                <a href="<?= htmlspecialchars($branding['footer_link3_url']) ?>" target="_blank" rel="noopener noreferrer" 
                                   class="hover:text-cyan-400 flex items-center space-x-1 transition">
                                    <span><?= htmlspecialchars($branding['footer_link3_label']) ?></span>
                                    <i data-lucide="arrow-up-right" class="w-3 h-3 text-slate-500"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Col 3: Bantuan & Kontak -->
                <div>
                    <h4 class="text-sm font-semibold text-white tracking-wider uppercase mb-3 font-heading">
                        <?= htmlspecialchars($branding['contact_title'] ?: 'Bantuan & Kontak') ?>
                    </h4>
                    <ul class="space-y-2 text-xs">
                        <?php $mail = $branding['contact_email'] ?: $branding['support_email']; if (!empty($mail)): ?>
                            <li class="flex items-center space-x-2">
                                <i data-lucide="mail" class="w-3.5 h-3.5 text-cyan-400 shrink-0"></i>
                                <a href="mailto:<?= htmlspecialchars($mail) ?>" class="hover:text-cyan-400 break-all">
                                    <?= htmlspecialchars($mail) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($branding['contact_address'])): ?>
                            <li class="flex items-center space-x-2">
                                <i data-lucide="map-pin" class="w-3.5 h-3.5 text-cyan-400 shrink-0"></i>
                                <span><?= htmlspecialchars($branding['contact_address']) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($branding['contact_person'])): ?>
                            <li class="pt-2 text-[11px] text-slate-400 border-t border-slate-800/60">
                                <?= htmlspecialchars($branding['contact_person']) ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Copyright Line (Sesuai Permintaan v3.3: Hanya Nama Brand & Ekosistem) -->
            <div class="mt-8 pt-6 border-t border-slate-800/80 flex flex-col md:flex-row items-center justify-between text-xs text-slate-500">
                <p class="w-full text-center md:text-left"><?= htmlspecialchars($branding['footer_text']) ?></p>
            </div>
        </div>
    </footer>

    </div> <!-- Close Main Content Column dari navbar.php -->
</div> <!-- Close Layout Container dari navbar.php -->

<!-- Global PDF Modal Previewer -->
<div id="pdf-preview-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-sm transition-opacity">
    <div class="flex items-center justify-center min-h-screen p-3 md:p-6">
        <div class="relative w-full max-w-5xl h-[88vh] bg-slate-900 rounded-2xl shadow-2xl flex flex-col border border-slate-700 overflow-hidden" onclick="event.stopPropagation()">
            
            <!-- Modal Header -->
            <div class="flex items-center justify-between px-5 py-3.5 bg-slate-900 border-b border-slate-800">
                <div class="flex items-center space-x-3 truncate">
                    <div class="p-1.5 rounded-lg bg-red-500/10 text-red-400">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </div>
                    <h3 id="pdf-preview-title" class="text-sm font-semibold text-white truncate font-heading">
                        Pratinjau Dokumen PDF
                    </h3>
                </div>
                <div class="flex items-center space-x-2">
                    <a id="pdf-preview-download" href="#" class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-cyan-300 bg-cyan-950 hover:bg-cyan-900 border border-cyan-800 transition">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                        <span>Unduh</span>
                    </a>
                    <button id="pdf-preview-close" type="button" class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>

            <!-- Modal Iframe Body -->
            <div class="flex-1 bg-slate-950 relative">
                <iframe id="pdf-preview-iframe" src="about:blank" class="w-full h-full border-0"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Modal Animasi Loading Pengumpulan Tugas Mahasiswa (Sisi Mahasiswa 1) -->
<div id="tugas-upload-overlay" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4 transition-opacity duration-300">
    <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-sky-100 text-center space-y-5 animate-in fade-in zoom-in-95 duration-200">
        <!-- Oceanic Animated Spinner / Radar -->
        <div class="relative w-20 h-20 mx-auto">
            <div class="absolute inset-0 rounded-full border-4 border-sky-100"></div>
            <div class="absolute inset-0 rounded-full border-4 border-cyan-500 border-t-transparent animate-spin"></div>
            <div class="absolute inset-3 rounded-full bg-gradient-to-tr from-maritime-700 to-ocean flex items-center justify-center text-white shadow-lg shadow-sky-500/30">
                <i data-lucide="upload-cloud" class="w-7 h-7 animate-bounce"></i>
            </div>
        </div>

        <div class="space-y-1.5">
            <h3 class="text-lg font-bold text-slate-900 font-heading">
                Sedang Mengunggah Jawaban Tugas...
            </h3>
            <p class="text-xs text-slate-500 leading-relaxed">
                Mohon tunggu beberapa saat. Berkas Anda sedang ditransfer dan divalidasi ke server penyimpanan <strong class="text-cyan-700">Kelautan.ID</strong>.
            </p>
        </div>

        <!-- Progress Bar Pulse -->
        <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 via-sky-500 to-cyan-400 h-full w-full rounded-full animate-pulse"></div>
        </div>

        <p class="text-[11px] text-amber-800 bg-amber-50 rounded-2xl p-3 border border-amber-200 flex items-center justify-center space-x-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 text-amber-600"></i>
            <span class="font-medium">Jangan menutup atau merefresh halaman selama proses unggah berlangsung.</span>
        </p>
    </div>
</div>

<!-- UI Scripts -->
<script src="/assets/js/ui-helper.js?v=<?= @filemtime(__DIR__ . '/../public_html/assets/js/ui-helper.js') ?: time() ?>"></script>
</body>
</html>
