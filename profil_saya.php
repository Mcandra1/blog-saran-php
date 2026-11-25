<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$error = '';
$success = '';

if (empty($_SESSION['csrf_profil'])) {
    $_SESSION['csrf_profil'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_profil'], $_POST['csrf_token'] ?? '')) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $username_baru = trim($_POST['username'] ?? '');
        $password_baru = $_POST['password_baru'] ?? '';
        $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';
        $asal = trim($_POST['asal'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');

        if ($username_baru === '' || mb_strlen($username_baru) < 3 || mb_strlen($username_baru) > 50) {
            $error = 'Username wajib diisi (3 sampai 50 karakter).';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username_baru)) {
            $error = 'Username hanya boleh menggunakan huruf, angka, titik, garis bawah, atau strip.';
        } elseif (mb_strlen($asal) > 120 || mb_strlen($no_hp) > 25) {
            $error = 'Asal maksimal 120 karakter dan nomor HP maksimal 25 karakter.';
        } elseif ($no_hp !== '' && !preg_match('/^[0-9+() .-]{8,25}$/', $no_hp)) {
            $error = 'Format nomor HP tidak valid.';
        } elseif ($password_baru !== '' && mb_strlen($password_baru) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } elseif ($password_baru !== '' && $password_baru !== $konfirmasi_password) {
            $error = 'Konfirmasi password baru tidak cocok.';
        } else {
            // Pastikan username tidak digunakan user lain
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
            $stmt->bind_param('si', $username_baru, $user_id);
            $stmt->execute();
            $duplikat_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplikat_user) {
                $error = 'Username sudah digunakan oleh akun lain.';
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare('SELECT foto_profil FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $foto = $current['foto_profil'] ?? null;

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Foto gagal diunggah.';
                } elseif ($_FILES['foto']['size'] > 2 * 1024 * 1024) {
                    $error = 'Ukuran foto maksimal 2 MB.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['foto']['tmp_name']);
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime]) || @getimagesize($_FILES['foto']['tmp_name']) === false) {
                        $error = 'Format foto harus JPG, PNG, atau WEBP.';
                    } else {
                        $upload_dir = __DIR__ . '/uploads/profil';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . '/' . $filename)) {
                            if ($foto && strpos($foto, 'uploads/profil/') === 0) {
                                $old_path = __DIR__ . '/' . $foto;
                                if (is_file($old_path)) {
                                    unlink($old_path);
                                }
                            }
                            $foto = 'uploads/profil/' . $filename;
                        } else {
                            $error = 'Foto tidak dapat disimpan. Periksa izin folder uploads.';
                        }
                    }
                }
            }

            if ($error === '') {
                if ($password_baru !== '') {
                    $password_hash = password_hash($password_baru, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE users SET username = ?, password = ?, asal = ?, no_hp = ?, foto_profil = ? WHERE id = ?');
                    $stmt->bind_param('sssssi', $username_baru, $password_hash, $asal, $no_hp, $foto, $user_id);
                } else {
                    $stmt = $conn->prepare('UPDATE users SET username = ?, asal = ?, no_hp = ?, foto_profil = ? WHERE id = ?');
                    $stmt->bind_param('ssssi', $username_baru, $asal, $no_hp, $foto, $user_id);
                }

                if ($stmt->execute()) {
                    $_SESSION['username'] = $username_baru;
                    $success = $password_baru !== ''
                        ? 'Profil, username, dan password berhasil diperbarui.'
                        : 'Profil berhasil diperbarui.';
                } else {
                    $error = 'Profil gagal diperbarui.';
                }
                $stmt->close();
            }
        }
    }
}

$stmt = $conn->prepare('SELECT id, username, role, kategori_penulis, asal, no_hp, foto_profil FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$profil = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$profil) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$tulisan = null;
if ($profil['role'] === 'admin') {
    $stmt = $conn->prepare("SELECT id, judul, kategori, created_at FROM artikel WHERE user_id = ? AND status = 'published' ORDER BY created_at DESC");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $tulisan = $stmt->get_result();
}
$role = $_SESSION['role'] ?? '';
$kategori_admin = $_SESSION['kategori'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya</title>
    <link rel="stylesheet" href="./assets/style.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .profile-edit-grid {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 28px;
            align-items: start;
        }

        .profile-photo-box {
            text-align: center;
            padding: 22px;
            background: #f8fafc;
            border: 1px solid #e5eaf1;
            border-radius: 14px;
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            background: #eaf2ff;
            display: block;
            margin: 0 auto 15px;
            border: 4px solid white;
            box-shadow: 0 2px 12px #00000012;
        }

        .profile-photo-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 56px;
            color: #2563eb;
        }

        .profile-form {
            background: #fff;
            padding: 24px;
            border: 1px solid #e5eaf1;
            border-radius: 14px;
        }

        .profile-form input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        .profile-form .form-group {
            margin-bottom: 17px;
        }

        .profile-form label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .profile-divider {
            border: 0;
            border-top: 1px solid #e5eaf1;
            margin: 20px 0;
        }

        .profile-section-label {
            font-size: 13px;
            font-weight: 700;
            color: #1e3a5f;
            margin-bottom: 12px;
            display: block;
        }

        .profile-info {
            color: #000000;
            font-size: 15px;
        }

        .profile-works {
            margin-top: 32px;
        }

        @media (max-width: 650px) {
            .profile-edit-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-inner">

            <a href="index.php" class="nav-brand">
                <span class="nav-icon">
                    <i class="fa-regular fa-comments"></i>
                </span>
                Blog Saran
            </a>

            <div>
                <a href="admin.php" class="nav-link">
                    Daftar Saran
                </a>

                <?php if ($role === 'admin'): ?>
                    <a href="kelola_tulisan.php" class="nav-link">
                        Kelola Tulisan
                    </a>

                    <a href="chat_admin.php" class="nav-link">
                        Chat Super Admin
                    </a>
                <?php endif; ?>

                <?php if ($role === 'superadmin'): ?>
                    <a href="manage_admins.php" class="nav-link">
                        Manajemen Admin
                    </a>
                    <a href="chat_admin.php" class="nav-link">
                        Chat Admin
                    </a>
                <?php endif; ?>

                <a href="profil_saya.php" class="nav-link active">Profil</a>

                <a href="logout.php" class="nav-link" onclick="return confirm('Apakah Anda yakin ingin logout?');">
                    Logout
                </a>

                <img class="unipi" src="./assets/unipi.png" alt="" srcset="" width="60px" height="60px">

            </div>

        </div>
    </nav>
    <main class="main-container">
        <div class="content-wrapper" style="max-width:950px">
            <div class="content-header">
                <h1>Profil Saya</h1>
                <p style="color: black; font-size: 18px;">Kelola informasi, username, dan password akun Anda.</p>
            </div>
            <?php if ($success): ?>
                <p class="success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <div class="profile-edit-grid">
                <div class="profile-photo-box">
                    <?php if (!empty($profil['foto_profil'])): ?>
                        <img class="profile-photo" src="<?php echo htmlspecialchars($profil['foto_profil']); ?>" alt="Foto profil">
                    <?php else: ?>
                        <div class="profile-photo profile-photo-placeholder"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <strong><?php echo htmlspecialchars($profil['username']); ?></strong>
                    <p class="profile-info">
                        <?php echo htmlspecialchars($profil['kategori_penulis'] ?: ucfirst($profil['role'])); ?>
                    </p>
                </div>
                <form class="profile-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_profil']); ?>">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" maxlength="50" required
                            value="<?php echo htmlspecialchars($profil['username']); ?>"
                            placeholder="Masukkan username">
                    </div>

                    <div class="form-group">
                        <label for="foto">Foto Admin</label>
                        <input type="file" id="foto" name="foto"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <small class="profile-info">Format JPG, PNG, WEBP. Maksimal 2 MB.</small>
                    </div>

                    <div class="form-group">
                        <label for="asal">Asal</label>
                        <input type="text" id="asal" name="asal" maxlength="120"
                            value="<?php echo htmlspecialchars($profil['asal'] ?? ''); ?>"
                            placeholder="Contoh: Tangerang">
                    </div>

                    <div class="form-group">
                        <label for="no_hp">Nomor HP</label>
                        <input type="tel" id="no_hp" name="no_hp" maxlength="25"
                            value="<?php echo htmlspecialchars($profil['no_hp'] ?? ''); ?>"
                            placeholder="Contoh: 08xxxxxxxxxx">
                    </div>

                    <hr class="profile-divider">
                    <span class="profile-section-label">Ubah Password (Opsional)</span>

                    <div class="form-group">
                        <label for="password_baru">Password Baru</label>
                        <input type="password" id="password_baru" name="password_baru" minlength="6"
                            autocomplete="new-password"
                            placeholder="Kosongkan jika tidak ingin mengubah password">
                        <small class="profile-info">Minimal 6 karakter.</small>
                    </div>

                    <div class="form-group">
                        <label for="konfirmasi_password">Konfirmasi Password Baru</label>
                        <input type="password" id="konfirmasi_password" name="konfirmasi_password" minlength="6"
                            autocomplete="new-password"
                            placeholder="Ulangi password baru">
                    </div>

                    <button type="submit">Simpan Perubahan</button>
                </form>
            </div>
            <?php if ($profil['role'] === 'admin'): ?>
                <section class="profile-works">
                    <h2>Daftar Tulisan Saya</h2>
                    <?php if ($tulisan && $tulisan->num_rows > 0): ?>
                        <div class="published-list">
                            <?php while ($row = $tulisan->fetch_assoc()): ?>
                                <div class="article-card">
                                    <span class="article-category"><?php echo htmlspecialchars($row['kategori']); ?></span>
                                    <h3><?php echo htmlspecialchars($row['judul']); ?></h3>
                                    <div class="article-meta">
                                        <?php echo date('d M Y', strtotime($row['created_at'])); ?>
                                        <a href="detail_tulisan.php?id=<?php echo (int) $row['id']; ?>">Lihat tulisan</a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-authors">Anda belum memiliki tulisan yang dipublikasikan.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <footer id="credit" class="site-footer">
        <p>
            &copy; <?php echo date('Y'); ?>
            <span class="footer-name">Muhammad Candra Wijaya</span>
        </p>
        <span class="footer-caption">
            Blog Saran, Kritik dan Aspirasi
        </span>
    </footer>
</body>

</html>