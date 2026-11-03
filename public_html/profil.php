<?php
/**
 * LMS POLIKANT × KELAUTAN.ID
 * Halaman Profil, Foto Profil & Ganti Kata Sandi Mandiri
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/storage_helper.php';

$pageTitle = 'Profil Pengguna & Keamanan';
$db = get_db();
$currentUser = current_user();

// Tangani Pengiriman Form (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = $_POST['action'] ?? 'change_password';

    // 1. Upload Foto Profil
    if ($action === 'upload_photo') {
        if (!empty($_FILES['foto_profil']['name'])) {
            $uploadRes = handle_avatar_upload($_FILES['foto_profil'], $currentUser['id_user'], $currentUser['foto_profil']);
            if ($uploadRes['success']) {
                $newPhotoPath = $uploadRes['path'];
                $stmtUp = $db->prepare("UPDATE users SET foto_profil = ? WHERE id_user = ?");
                $stmtUp->execute([$newPhotoPath, $currentUser['id_user']]);
                $_SESSION['foto_profil'] = $newPhotoPath;

                log_activity('UPDATE_FOTO', "Pengguna '{$currentUser['nama']}' memperbarui foto profil akun.");
                set_flash('success', 'Foto profil berhasil diperbarui!');
            } else {
                set_flash('error', $uploadRes['error']);
            }
        } else {
            set_flash('warning', 'Pilih berkas foto terlebih dahulu.');
        }
        header('Location: /profil');
        exit;
    }

    // 2. Hapus Foto Profil
    if ($action === 'delete_photo') {
        if (!empty($currentUser['foto_profil'])) {
            $oldFile = __DIR__ . '/' . ltrim($currentUser['foto_profil'], '/');
            if (file_exists($oldFile) && is_file($oldFile)) {
                @unlink($oldFile);
            }
            $stmtDel = $db->prepare("UPDATE users SET foto_profil = NULL WHERE id_user = ?");
            $stmtDel->execute([$currentUser['id_user']]);
            $_SESSION['foto_profil'] = null;

            log_activity('HAPUS_FOTO', "Pengguna '{$currentUser['nama']}' menghapus foto profil akun.");
            set_flash('success', 'Foto profil berhasil dihapus.');
        }
        header('Location: /profil');
        exit;
    }

    // 3. Ganti Kata Sandi
    if ($action === 'change_password') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            set_flash('error', 'Semua kolom kata sandi wajib diisi.');
        } elseif ($newPass !== $confirmPass) {
            set_flash('error', 'Konfirmasi kata sandi baru tidak cocok.');
        } elseif (strlen($newPass) < 6) {
            set_flash('error', 'Kata sandi baru minimal 6 karakter.');
        } else {
            try {
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE id_user = ?");
                $stmt->execute([$currentUser['id_user']]);
                $row = $stmt->fetch();

                if ($row && password_verify($currentPass, $row['password_hash'])) {
                    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                    $stmtUp = $db->prepare("UPDATE users SET password_hash = ? WHERE id_user = ?");
                    $stmtUp->execute([$newHash, $currentUser['id_user']]);

                    log_activity('GANTI_PASSWORD', "Pengguna '{$currentUser['nama']}' berhasil memperbarui kata sandi akun.");
                    set_flash('success', 'Kata sandi Anda berhasil diperbarui. Silakan gunakan kata sandi baru untuk login berikutnya.');
                } else {
                    log_activity('GANTI_PASSWORD_GAGAL', "Percobaan gagal ubah kata sandi oleh '{$currentUser['nama']}': Kata sandi lama tidak cocok.");
                    set_flash('error', 'Kata sandi saat ini yang Anda masukkan salah.');
                }
            } catch (Throwable $e) {
                set_flash('error', 'Gagal memperbarui kata sandi: ' . $e->getMessage());
            }
        }

        header('Location: /profil');
        exit;
    }
}

// Refresh data user terbaru dari DB
$stmtUser = $db->prepare("SELECT * FROM users WHERE id_user = ?");
$stmtUser->execute([$currentUser['id_user']]);
$userDb = $stmtUser->fetch();
if ($userDb) {
    $_SESSION['foto_profil'] = $userDb['foto_profil'];
    $currentUser = current_user();
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Page Header -->
    <div class="pb-4 border-b border-slate-200">
        <div class="flex items-center space-x-2 text-xs font-semibold text-cyan-700 uppercase tracking-wider">
            <i data-lucide="shield" class="w-4 h-4"></i>
            <span>Akun & Keamanan</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-abyssal-900 font-heading mt-1">
            Profil & Pengaturan Akun
        </h1>
        <p class="text-sm text-slate-500 mt-1">
            Kelola foto profil, informasi akun, dan perbarui kata sandi Anda secara mandiri.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Kartu Identitas & Foto Profil (5/12) -->
        <div class="lg:col-span-5 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-6 text-center">
            
            <div class="relative w-28 h-28 mx-auto">
                <?php 
                $profileAvatarUrl = get_avatar_url($currentUser['foto_profil'] ?? null); 
                ?>
                <?php if ($profileAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($profileAvatarUrl) ?>?v=<?= time() ?>" 
                         alt="Foto Profil" 
                         class="w-28 h-28 rounded-3xl object-cover shadow-xl border-2 border-cyan-400"
                         onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                    <div class="w-28 h-28 rounded-3xl bg-gradient-to-tr from-maritime-700 via-maritime-500 to-ocean text-white font-extrabold text-3xl items-center justify-center shadow-xl font-heading" style="display:none;">
                        <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                    </div>
                <?php else: ?>
                    <div class="w-28 h-28 rounded-3xl bg-gradient-to-tr from-maritime-700 via-maritime-500 to-ocean text-white font-extrabold text-3xl flex items-center justify-center shadow-xl font-heading">
                        <?= strtoupper(substr($currentUser['nama'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="text-lg font-bold text-slate-900 font-heading">
                    <?= htmlspecialchars($currentUser['nama']) ?>
                </h2>
                <p class="text-xs font-mono text-cyan-700 mt-0.5">
                    <?= htmlspecialchars($currentUser['username']) ?>
                </p>
                <div class="mt-2 inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= is_dosen_admin() ? 'bg-cyan-100 text-cyan-800 border border-cyan-200' : (is_dosen() ? 'bg-blue-100 text-blue-800 border border-blue-200' : 'bg-slate-100 text-slate-700') ?>">
                    <?= htmlspecialchars($currentUser['role']) ?>
                </div>
            </div>

            <!-- Form Upload Foto Profil -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-left space-y-3">
                <div class="flex items-center space-x-2 text-xs font-bold text-slate-800">
                    <i data-lucide="camera" class="w-4 h-4 text-cyan-600"></i>
                    <span>Perbarui Foto Profil</span>
                </div>
                
                <form action="/profil" method="POST" enctype="multipart/form-data" class="space-y-2.5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="upload_photo">

                    <input type="file" name="foto_profil" accept="image/jpeg,image/png,image/webp" required
                           class="block w-full text-xs text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-800 hover:file:bg-cyan-100 cursor-pointer">
                    
                    <p class="text-[10px] text-slate-400">
                        Format: JPG, PNG, atau WEBP. Maksimum 2 MB.
                    </p>

                    <div class="flex items-center space-x-2 pt-1">
                        <button type="submit" 
                                class="flex-1 inline-flex items-center justify-center space-x-1.5 py-2 px-3 rounded-xl text-xs font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                            <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                            <span>Unggah Foto</span>
                        </button>

                        <?php if (!empty($currentUser['foto_profil'])): ?>
                            <button type="submit" formaction="/profil" 
                                    name="action" value="delete_photo"
                                    onclick="return confirm('Apakah Anda yakin ingin menghapus foto profil ini?');"
                                    class="p-2 rounded-xl text-xs font-semibold text-rose-600 bg-rose-50 hover:bg-rose-100 border border-rose-200 transition" 
                                    title="Hapus Foto">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Detail Akun -->
            <div class="pt-2 border-t border-slate-100 space-y-2 text-xs text-left">
                <div class="flex justify-between">
                    <span class="text-slate-400">Peran Sistem:</span>
                    <span class="font-semibold text-slate-700"><?= htmlspecialchars($currentUser['role']) ?></span>
                </div>
                <?php if ($currentUser['angkatan']): ?>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Angkatan:</span>
                        <span class="font-semibold text-slate-700"><?= htmlspecialchars($currentUser['angkatan']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">ID Pengguna:</span>
                    <span class="font-mono text-slate-500"><?= htmlspecialchars($currentUser['id_user']) ?></span>
                </div>
            </div>
        </div>

        <!-- Form Ganti Kata Sandi (7/12) -->
        <div class="lg:col-span-7 bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
            <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-700 flex items-center justify-center">
                    <i data-lucide="key" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 font-heading">
                        Ganti Kata Sandi
                    </h3>
                    <p class="text-xs text-slate-500">
                        Disarankan memperbarui kata sandi bawaan secara berkala
                    </p>
                </div>
            </div>

            <form action="/profil" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="change_password">

                <div>
                    <label for="current_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                        Kata Sandi Saat Ini *
                    </label>
                    <input id="current_password" name="current_password" type="password" required
                           placeholder="Masukkan kata sandi lama Anda"
                           class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="new_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                            Kata Sandi Baru *
                        </label>
                        <input id="new_password" name="new_password" type="password" required minlength="6"
                               placeholder="Minimal 6 karakter"
                               class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                            Konfirmasi Kata Sandi Baru *
                        </label>
                        <input id="confirm_password" name="confirm_password" type="password" required minlength="6"
                               placeholder="Ulangi kata sandi baru"
                               class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-ocean-cyan focus:bg-white transition">
                    </div>
                </div>

                <div class="pt-3">
                    <button type="submit" 
                            class="inline-flex items-center space-x-2 px-6 py-2.5 rounded-xl text-sm font-semibold text-white bg-maritime-700 hover:bg-maritime-800 shadow transition">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <span>Simpan Kata Sandi Baru</span>
                    </button>
                </div>
            </form>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
