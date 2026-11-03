<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Modul Manajemen Siklus Tahun Akademik (Khusus Dosen)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_dosen_admin();

$pageTitle = 'Manajemen Tahun Akademik';
$db = get_db();

// Handle Aksi Form POST (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $namaTA = trim((string)($_POST['nama_ta'] ?? ''));
        $isActive = isset($_POST['is_active']);

        if ($namaTA === '') {
            set_flash('error', 'Nama Tahun Akademik tidak boleh kosong.');
        } else {
            try {
                $idTA = generate_id('TA', 'tahun_akademik', 'id_ta');
                $status = $isActive ? 'Aktif' : 'Tidak Aktif';

                $db->beginTransaction();
                if ($isActive) {
                    $db->exec("UPDATE tahun_akademik SET status = 'Tidak Aktif'");
                }
                $stmt = $db->prepare("INSERT INTO tahun_akademik (id_ta, nama_ta, status) VALUES (?, ?, ?)");
                $stmt->execute([$idTA, $namaTA, $status]);
                $db->commit();

                set_flash('success', "Tahun Akademik '{$namaTA}' berhasil ditambahkan" . ($isActive ? " dan diaktifkan." : "."));
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('error', 'Gagal menambahkan Tahun Akademik: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'activate') {
        $idTA = trim((string)($_POST['id_ta'] ?? ''));
        if ($idTA !== '') {
            try {
                $db->beginTransaction();
                $db->exec("UPDATE tahun_akademik SET status = 'Tidak Aktif'");
                $stmt = $db->prepare("UPDATE tahun_akademik SET status = 'Aktif' WHERE id_ta = ?");
                $stmt->execute([$idTA]);
                $db->commit();

                set_flash('success', 'Status Tahun Akademik aktif berhasil diperbarui.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('error', 'Gagal mengaktifkan Tahun Akademik: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        $idTA = trim((string)($_POST['id_ta'] ?? ''));
        if ($idTA !== '') {
            try {
                $stmtCheck = $db->prepare("SELECT status FROM tahun_akademik WHERE id_ta = ?");
                $stmtCheck->execute([$idTA]);
                $taRow = $stmtCheck->fetch();

                if ($taRow && $taRow['status'] === 'Aktif') {
                    set_flash('error', 'Tahun Akademik yang sedang aktif tidak dapat dihapus. Silakan aktifkan periode lain terlebih dahulu.');
                } else {
                    $stmt = $db->prepare("DELETE FROM tahun_akademik WHERE id_ta = ?");
                    $stmt->execute([$idTA]);
                    set_flash('success', 'Tahun Akademik berhasil dihapus.');
                }
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menghapus Tahun Akademik: ' . $e->getMessage());
            }
        }
    }

    header('Location: /tahun-akademik');
    exit;
}

// Ambil Seluruh Data Tahun Akademik
$stmtList = $db->query("
    SELECT ta.*, 
           (SELECT COUNT(*) FROM mata_kuliah WHERE id_ta = ta.id_ta) AS total_mk
    FROM tahun_akademik ta
    ORDER BY ta.created_at DESC
");
$taList = $stmtList->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
            <div class="flex items-center space-x-2 text-xs font-semibold text-cyan-700 uppercase tracking-wider">
                <i data-lucide="calendar" class="w-4 h-4"></i>
                <span>Konfigurasi Siklus Akademik</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 font-heading mt-1">
                Manajemen Tahun Akademik
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Kelola periode perkuliahan semester aktif untuk sivitas akademika Polikant.
            </p>
        </div>
        <div>
            <a href="/mata-kuliah" class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-sm transition">
                <i data-lucide="book-open" class="w-4 h-4 text-maritime-700"></i>
                <span>Lihat Mata Kuliah</span>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Form Tambah Periode Baru -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm sticky top-24">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 flex items-center justify-center">
                        <i data-lucide="plus-circle" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-slate-900 font-heading">Tambah Periode Baru</h2>
                        <p class="text-xs text-slate-500">Buat siklus semester mendatang</p>
                    </div>
                </div>

                <form action="/tahun-akademik" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create">

                    <div>
                        <label for="nama_ta" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">
                            Nama Tahun Akademik
                        </label>
                        <input id="nama_ta" name="nama_ta" type="text" required
                               placeholder="Contoh: 2026/2027 Ganjil"
                               class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                        <p class="text-[11px] text-slate-400 mt-1">Format lazim: [Tahun]/[Tahun] [Ganjil/Genap/Antara]</p>
                    </div>

                    <div class="pt-1">
                        <label class="flex items-center space-x-2.5 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-maritime-700 rounded border-slate-300 focus:ring-cyan-500">
                            <span class="text-xs font-medium text-slate-700">Jadikan sebagai Tahun Akademik Aktif</span>
                        </label>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full flex items-center justify-center space-x-2 py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                            <i data-lucide="save" class="w-4 h-4"></i>
                            <span>Simpan Tahun Akademik</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Daftar Riwayat Tahun Akademik -->
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-slate-900 font-heading">Daftar Siklus Semester</h2>
                        <p class="text-xs text-slate-500">Riwayat seluruh periode akademik di sistem</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                        <?= count($taList) ?> Periode
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                <th class="py-3.5 px-6">ID & Nama TA</th>
                                <th class="py-3.5 px-4">Status</th>
                                <th class="py-3.5 px-4">Mata Kuliah</th>
                                <th class="py-3.5 px-6 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($taList)): ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-slate-400">
                                        Belum ada data Tahun Akademik.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($taList as $row): ?>
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-4 px-6">
                                            <div class="font-bold text-slate-900 font-heading">
                                                <?= htmlspecialchars($row['nama_ta']) ?>
                                            </div>
                                            <div class="text-xs font-mono text-slate-400 mt-0.5">
                                                <?= htmlspecialchars($row['id_ta']) ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <?php if ($row['status'] === 'Aktif'): ?>
                                                <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                    <span>Aktif</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                                                    Tidak Aktif
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="inline-flex items-center space-x-1 text-xs text-slate-700 font-medium">
                                                <i data-lucide="book-open" class="w-3.5 h-3.5 text-cyan-600"></i>
                                                <span><?= (int)$row['total_mk'] ?> MK</span>
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <div class="flex items-center justify-end space-x-2">
                                                <?php if ($row['status'] !== 'Aktif'): ?>
                                                    <!-- Tombol Aktifkan (POST) -->
                                                    <form action="/tahun-akademik" method="POST" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="activate">
                                                        <input type="hidden" name="id_ta" value="<?= htmlspecialchars($row['id_ta']) ?>">
                                                        <button type="submit" 
                                                                title="Jadikan Tahun Akademik Aktif"
                                                                class="px-2.5 py-1.5 text-xs font-semibold rounded-lg text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 transition">
                                                            Aktifkan
                                                        </button>
                                                    </form>

                                                    <!-- Tombol Hapus (POST) -->
                                                    <form action="/tahun-akademik" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus Tahun Akademik ini?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id_ta" value="<?= htmlspecialchars($row['id_ta']) ?>">
                                                        <button type="submit" 
                                                                title="Hapus Tahun Akademik"
                                                                class="p-1.5 text-xs text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition">
                                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-xs font-medium text-emerald-600 italic">
                                                        Sedang Berjalan
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
