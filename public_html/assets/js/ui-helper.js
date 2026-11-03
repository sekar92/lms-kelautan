/**
 * LMS POLIKANT × KELAUTAN.ID
 * Vanilla JavaScript UI Helper (Modal Preview, Akordeon, & Form Helpers)
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Inisialisasi Lucide Icons jika tersedia
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // 2. Dropdown Profil Pengguna
    const profileBtn = document.getElementById('user-menu-button');
    const profileMenu = document.getElementById('user-menu-dropdown');
    if (profileBtn && profileMenu) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileMenu.classList.toggle('hidden');
        });
        document.addEventListener('click', () => {
            if (!profileMenu.classList.contains('hidden')) {
                profileMenu.classList.add('hidden');
            }
        });
    }

    // 3. Mobile Sidebar Drawer Toggle & Backdrop
    const mobileSidebarToggle = document.getElementById('mobile-sidebar-toggle');
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mobileSidebarBackdrop = document.getElementById('mobile-sidebar-backdrop');
    const mobileSidebarClose = document.getElementById('mobile-sidebar-close');

    function openMobileSidebar() {
        if (mobileSidebar && mobileSidebarBackdrop) {
            mobileSidebar.classList.remove('-translate-x-full');
            mobileSidebarBackdrop.classList.remove('hidden');
        }
    }

    function closeMobileSidebar() {
        if (mobileSidebar && mobileSidebarBackdrop) {
            mobileSidebar.classList.add('-translate-x-full');
            mobileSidebarBackdrop.classList.add('hidden');
        }
    }

    if (mobileSidebarToggle) {
        mobileSidebarToggle.addEventListener('click', openMobileSidebar);
    }
    if (mobileSidebarClose) {
        mobileSidebarClose.addEventListener('click', closeMobileSidebar);
    }
    if (mobileSidebarBackdrop) {
        mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);
    }

    // 3.1 Animasi Loading saat Pengumpulan Tugas Mahasiswa
    const taskForms = document.querySelectorAll('.form-tugas-kumpul');
    const uploadOverlay = document.getElementById('tugas-upload-overlay');

    taskForms.forEach((form) => {
        form.addEventListener('submit', () => {
            const fileInput = form.querySelector('input[type="file"][name="jawaban_file"]');
            const linkInput = form.querySelector('input[name="link_eksternal"]');
            const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
            const hasLink = linkInput && linkInput.value.trim() !== '';

            if (uploadOverlay) {
                uploadOverlay.classList.remove('hidden');
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
                submitBtn.innerHTML = `
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span>Mengunggah Berkas...</span>
                `;
            }
        });
    });

    // 4. Tutup Flash Alert
    document.querySelectorAll('.flash-close-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const alertBox = btn.closest('.flash-alert');
            if (alertBox) {
                alertBox.style.opacity = '0';
                setTimeout(() => alertBox.remove(), 250);
            }
        });
    });

    // 5. Akordeon Pertemuan 1 - 16
    document.querySelectorAll('.accordion-toggle').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const targetBody = document.getElementById(targetId);
            const chevron = btn.querySelector('.accordion-chevron');

            if (targetBody) {
                targetBody.classList.toggle('hidden');
                if (chevron) {
                    chevron.classList.toggle('rotate-180');
                }
            }
        });
    });

    // 6. Kelola Peserta Kelas (Enrollment): Sortir, Filter Angkatan, Pencarian & Select All
    const btnSelectAll = document.getElementById('btn-select-all');
    const btnDeselectAll = document.getElementById('btn-deselect-all');
    const btnResetFilter = document.getElementById('btn-reset-filter');
    const counterBadge = document.getElementById('selected-counter');
    const visibleCounter = document.getElementById('visible-counter');
    const listContainer = document.getElementById('student-list-container');
    const noMatchedNotice = document.getElementById('no-students-matched');

    const sortSelect = document.getElementById('sort-mahasiswa');
    const filterAngkatanSelect = document.getElementById('filter-angkatan');
    const filterStatusSelect = document.getElementById('filter-status');
    const searchInput = document.getElementById('search-mahasiswa');

    function updateCounter() {
        if (!counterBadge) return;
        const total = document.querySelectorAll('.mhs-checkbox').length;
        const checked = document.querySelectorAll('.mhs-checkbox:checked').length;
        counterBadge.textContent = `${checked} Terdaftar dari ${total} Mahasiswa`;
    }

    function applyStudentFiltersAndSort() {
        if (!listContainer) return;

        const sortVal = sortSelect ? sortSelect.value : 'angkatan_desc';
        const filterAngkatan = filterAngkatanSelect ? filterAngkatanSelect.value : 'all';
        const filterStatus = filterStatusSelect ? filterStatusSelect.value : 'all';
        const searchKeyword = searchInput ? searchInput.value.trim().toLowerCase() : '';

        const rows = Array.from(listContainer.querySelectorAll('.mhs-row'));
        let visibleCount = 0;

        // 1. Filter
        rows.forEach(row => {
            const rowNama = row.getAttribute('data-nama') || '';
            const rowNim = row.getAttribute('data-nim') || '';
            const rowAngkatan = row.getAttribute('data-angkatan') || '';
            const cb = row.querySelector('.mhs-checkbox');
            const isChecked = cb ? cb.checked : false;

            const matchAngkatan = (filterAngkatan === 'all') || (rowAngkatan === filterAngkatan);
            const matchStatus = (filterStatus === 'all') ||
                                (filterStatus === 'enrolled' && isChecked) ||
                                (filterStatus === 'not_enrolled' && !isChecked);
            const matchSearch = (searchKeyword === '') ||
                                (rowNama.includes(searchKeyword)) ||
                                (rowNim.includes(searchKeyword));

            if (matchAngkatan && matchStatus && matchSearch) {
                row.classList.remove('hidden');
                visibleCount++;
            } else {
                row.classList.add('hidden');
            }
        });

        // 2. Sortir
        rows.sort((a, b) => {
            const angkatanA = parseInt(a.getAttribute('data-angkatan') || '0', 10);
            const angkatanB = parseInt(b.getAttribute('data-angkatan') || '0', 10);
            const namaA = a.getAttribute('data-nama') || '';
            const namaB = b.getAttribute('data-nama') || '';
            const nimA = a.getAttribute('data-nim') || '';
            const nimB = b.getAttribute('data-nim') || '';
            const cbA = a.querySelector('.mhs-checkbox');
            const cbB = b.querySelector('.mhs-checkbox');
            const isEnrolledA = cbA && cbA.checked ? 1 : 0;
            const isEnrolledB = cbB && cbB.checked ? 1 : 0;

            switch (sortVal) {
                case 'angkatan_asc':
                    if (angkatanA !== angkatanB) return angkatanA - angkatanB;
                    return namaA.localeCompare(namaB);
                case 'nama_asc':
                    return namaA.localeCompare(namaB);
                case 'nama_desc':
                    return namaB.localeCompare(namaA);
                case 'nim_asc':
                    return nimA.localeCompare(nimB);
                case 'status_enrolled':
                    if (isEnrolledA !== isEnrolledB) return isEnrolledB - isEnrolledA;
                    if (angkatanA !== angkatanB) return angkatanB - angkatanA;
                    return namaA.localeCompare(namaB);
                case 'angkatan_desc':
                default:
                    if (angkatanA !== angkatanB) return angkatanB - angkatanA;
                    return namaA.localeCompare(namaB);
            }
        });

        // Re-append baris dalam urutan baru (state checkbox tetap terjaga)
        rows.forEach(row => listContainer.appendChild(row));

        if (noMatchedNotice) {
            if (visibleCount === 0) {
                noMatchedNotice.classList.remove('hidden');
            } else {
                noMatchedNotice.classList.add('hidden');
            }
        }

        if (visibleCounter) {
            visibleCounter.textContent = `Menampilkan ${visibleCount} dari ${rows.length} mahasiswa`;
        }
    }

    if (listContainer) {
        if (sortSelect) sortSelect.addEventListener('change', applyStudentFiltersAndSort);
        if (filterAngkatanSelect) filterAngkatanSelect.addEventListener('change', applyStudentFiltersAndSort);
        if (filterStatusSelect) filterStatusSelect.addEventListener('change', applyStudentFiltersAndSort);
        if (searchInput) searchInput.addEventListener('input', applyStudentFiltersAndSort);

        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', (e) => {
                e.preventDefault();
                const visibleCheckboxes = listContainer.querySelectorAll('.mhs-row:not(.hidden) .mhs-checkbox');
                visibleCheckboxes.forEach(cb => cb.checked = true);
                updateCounter();
                if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                    applyStudentFiltersAndSort();
                }
            });
        }

        if (btnDeselectAll) {
            btnDeselectAll.addEventListener('click', (e) => {
                e.preventDefault();
                const visibleCheckboxes = listContainer.querySelectorAll('.mhs-row:not(.hidden) .mhs-checkbox');
                visibleCheckboxes.forEach(cb => cb.checked = false);
                updateCounter();
                if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                    applyStudentFiltersAndSort();
                }
            });
        }

        if (btnResetFilter) {
            btnResetFilter.addEventListener('click', (e) => {
                e.preventDefault();
                if (sortSelect) sortSelect.value = 'angkatan_desc';
                if (filterAngkatanSelect) filterAngkatanSelect.value = 'all';
                if (filterStatusSelect) filterStatusSelect.value = 'all';
                if (searchInput) searchInput.value = '';
                applyStudentFiltersAndSort();
            });
        }

        document.querySelectorAll('.mhs-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                updateCounter();
                if (filterStatusSelect && filterStatusSelect.value !== 'all') {
                    applyStudentFiltersAndSort();
                }
            });
        });
    }

    // Filter Angkatan untuk Tampilan Mahasiswa (Rekan Sekelas Terdaftar)
    const mhsFilterAngkatan = document.getElementById('mhs-filter-angkatan');
    const mhsClassmatesGrid = document.getElementById('mhs-classmates-grid');
    if (mhsFilterAngkatan && mhsClassmatesGrid) {
        mhsFilterAngkatan.addEventListener('change', () => {
            const val = mhsFilterAngkatan.value;
            const cards = mhsClassmatesGrid.querySelectorAll('.mhs-card');
            let matched = 0;
            cards.forEach(card => {
                const cardAngkatan = card.getAttribute('data-angkatan') || '';
                if (val === 'all' || cardAngkatan === val) {
                    card.classList.remove('hidden');
                    matched++;
                } else {
                    card.classList.add('hidden');
                }
            });
            const emptyNotice = document.getElementById('mhs-empty-notice');
            if (emptyNotice) {
                if (matched === 0) emptyNotice.classList.remove('hidden');
                else emptyNotice.classList.add('hidden');
            }
        });
    }

    // 7. Modal Previewer PDF Global
    const modal = document.getElementById('pdf-preview-modal');
    const modalIframe = document.getElementById('pdf-preview-iframe');
    const modalTitle = document.getElementById('pdf-preview-title');
    const modalDownloadLink = document.getElementById('pdf-preview-download');
    const modalCloseBtn = document.getElementById('pdf-preview-close');

    window.openPdfModal = function(url, title = 'Pratinjau Dokumen PDF') {
        if (!modal || !modalIframe) return;
        if (modalTitle) modalTitle.textContent = title;
        if (modalDownloadLink) {
            // Ubah mode inline menjadi mode download untuk tombol unduh di header modal
            const downloadUrl = url.replace('mode=inline', 'mode=download');
            modalDownloadLink.href = downloadUrl;
        }
        modalIframe.src = url;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    window.closePdfModal = function() {
        if (!modal || !modalIframe) return;
        modal.classList.add('hidden');
        modalIframe.src = 'about:blank'; // Cegah pemborosan memori peramban
        document.body.style.overflow = '';
    };

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', window.closePdfModal);
    }

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                window.closePdfModal();
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            window.closePdfModal();
        }
    });
});
