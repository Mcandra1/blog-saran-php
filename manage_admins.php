<?php
session_start();
require_once 'config.php';

// ========================================
// CEK HAK AKSES SUPER ADMIN
// ========================================

if (
    !isset($_SESSION['loggedin']) ||
    $_SESSION['loggedin'] !== true ||
    ($_SESSION['role'] ?? '') !== 'superadmin'
) {
    header("Location: admin.php");
    exit;
}

$message = '';
$error = '';

// ========================================
// FUNGSI CEK KATEGORI
// ========================================

function kategoriAda($conn, $nama)
{
    $stmt = $conn->prepare(
        "SELECT id FROM kategori WHERE nama_kategori = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $nama);
    $stmt->execute();

    $result = $stmt->get_result();
    $ada = $result->num_rows > 0;

    $stmt->close();

    return $ada;
}

// ========================================
// PROSES FORM ADMIN
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // ====================================
    // TAMBAH ADMIN
    // ====================================

    if ($action === 'add') {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $kategori_penulis = trim($_POST['kategori_penulis'] ?? '');

        if (
            $username === '' ||
            $password === '' ||
            $kategori_penulis === ''
        ) {
            $error = "Semua data wajib diisi.";
        } elseif (strlen($password) < 6) {
            $error = "Password minimal 6 karakter.";
        } elseif (!kategoriAda($conn, $kategori_penulis)) {
            $error = "Kategori tidak ditemukan.";
        } else {

            $stmt = $conn->prepare(
                "SELECT id FROM users WHERE username = ?"
            );

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $error = "Username sudah digunakan.";

            } else {

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt_insert = $conn->prepare(
                    "INSERT INTO users
                     (username, password, role, kategori_penulis)
                     VALUES (?, ?, 'admin', ?)"
                );

                $stmt_insert->bind_param(
                    "sss",
                    $username,
                    $password_hash,
                    $kategori_penulis
                );

                if ($stmt_insert->execute()) {
                    $message = "Admin berhasil ditambahkan.";
                } else {
                    $error = "Gagal menambahkan Admin.";
                }

                $stmt_insert->close();
            }

            $stmt->close();
        }
    }

    // ====================================
    // EDIT / TUGASKAN KATEGORI ADMIN
    // ====================================

    elseif ($action === 'edit') {

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $kategori_penulis = trim($_POST['kategori_penulis'] ?? '');

        if ($user_id <= 0) {

            $error = "Data Admin tidak valid.";

        } elseif (
            $kategori_penulis !== '' &&
            !kategoriAda($conn, $kategori_penulis)
        ) {

            $error = "Kategori yang dipilih tidak tersedia.";

        } else {

            $stmt = $conn->prepare(
                "UPDATE users
                 SET kategori_penulis = NULLIF(?, '')
                 WHERE id = ? AND role = 'admin'"
            );

            $stmt->bind_param("si", $kategori_penulis, $user_id);

            if ($stmt->execute() && $stmt->affected_rows >= 0) {
                $message = "Kategori Admin berhasil diperbarui.";
            } else {
                $error = "Gagal memperbarui kategori Admin.";
            }

            $stmt->close();
        }
    }

    // ====================================
    // HAPUS ADMIN
    // ====================================

    elseif ($action === 'delete') {

        $user_id = (int) ($_POST['user_id'] ?? 0);

        if (
            $user_id <= 0 ||
            $user_id == ($_SESSION['user_id'] ?? 0)
        ) {
            $error = "Tidak dapat menghapus akun ini.";
        } else {

            $stmt = $conn->prepare(
                "DELETE FROM users
                 WHERE id = ? AND role = 'admin'"
            );

            $stmt->bind_param("i", $user_id);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Admin berhasil dihapus.";
            } else {
                $error = "Admin tidak ditemukan atau gagal dihapus.";
            }

            $stmt->close();
        }
    }
}

// ========================================
// AMBIL DATA KATEGORI
// ========================================

$kategori_result = $conn->query(
    "SELECT id, nama_kategori
     FROM kategori
     ORDER BY nama_kategori ASC"
);

if (!$kategori_result) {
    die("Gagal mengambil data kategori.");
}

$kategori_list = $kategori_result->fetch_all(MYSQLI_ASSOC);

// ========================================
// AMBIL DATA ADMIN
// ========================================

$users_result = $conn->query(
    "SELECT id, username, role, kategori_penulis, created_at
     FROM users
     ORDER BY created_at DESC"
);

if (!$users_result) {
    die("Gagal mengambil data Admin.");
}

$users = $users_result->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manajemen Admin</title>

    <link rel="stylesheet" href="./assets/style.css?v=4">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        .label-row-kategori {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 7px;
        }

        .label-row-kategori label {
            margin-bottom: 0;
        }

        .btn-manajemen-kategori {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 7px;
            background: #eef6fc;
            color: #176ba5;
            border: 1px solid #c6e0f5;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color 0.18s ease, color 0.18s ease;
        }

        .btn-manajemen-kategori:hover {
            background: #176ba5;
            color: #ffffff;
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
            <a href="admin.php" class="nav-link">Daftar Saran</a>
            <a href="manage_admins.php" class="nav-link active">Manajemen Admin</a>
            <a href="chat_admin.php" class="nav-link">Chat Admin</a>
            <a href="profil_saya.php" class="nav-link">Profil</a>
            <a href="logout.php" class="nav-link">Logout</a>

            <img class="unipi"
                src="./assets/unipi.png"
                alt="UNIPI"
                width="60"
                height="60">
        </div>

    </div>
</nav>

<main class="main-container">
<div class="content-wrapper" style="max-width: 1000px;">

    <div class="content-header">
        <h1>Manajemen Admin</h1>
        <p style="color:black;">
            Kelola akun Admin berdasarkan kategori penulis.
        </p>
    </div>

    <?php if ($message): ?>
        <p class="success" style="text-align:center; margin-bottom:1.5rem;">
            <?= htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="error" style="text-align:center; margin-bottom:1.5rem;">
            <?= htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>


    <!-- ================================= -->
    <!-- TAMBAH ADMIN -->
    <!-- ================================= -->

    <h2>Tambah Admin Baru</h2>

    <form action="manage_admins.php" method="POST" class="admin-form">

        <input type="hidden" name="action" value="add">

        <div class="form-group">
            <label for="new_username">Username</label>
            <input type="text" id="new_username" name="username" required>
        </div>

        <div class="form-group">
            <label for="new_password">Password</label>
            <input
                type="password"
                id="new_password"
                name="password"
                minlength="6"
                required>
        </div>

        <div class="form-group">
            <div class="label-row-kategori">
                <label for="kategori_penulis">Kategori Penulis</label>
                <a href="kelola_kategori.php" class="btn-manajemen-kategori">
                    <i class="fa-solid fa-folder-plus"></i> Manajemen Kategori
                </a>
            </div>

            <select name="kategori_penulis" id="kategori_penulis" required>

                <option value="">Pilih Kategori Penulis</option>

                <?php foreach ($kategori_list as $kategori): ?>

                    <option value="<?= htmlspecialchars($kategori['nama_kategori']); ?>">
                        <?= htmlspecialchars($kategori['nama_kategori']); ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>

        <button type="submit">
            Tambah Admin
        </button>

    </form>


    <!-- ================================= -->
    <!-- DAFTAR ADMIN -->
    <!-- ================================= -->

    <h2 style="margin-top:3rem;">Daftar Admin</h2>

    <?php if (count($users) > 0): ?>

        <table class="admin-table">

            <thead>
                <tr>
                    <th>No</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Kategori Penulis</th>
                    <th>Dibuat Pada</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>

            <?php $no = 1; ?>

            <?php foreach ($users as $user): ?>

                <tr>

                    <td><?= $no++; ?></td>

                    <td>
                        <?= htmlspecialchars($user['username']); ?>
                    </td>

                    <td>
                        <?php if ($user['role'] === 'superadmin'): ?>
                            <strong>Super Admin</strong>
                        <?php else: ?>
                            Admin
                        <?php endif; ?>
                    </td>

                   <td>
                        <?php if ($user['role'] === 'superadmin'): ?>

                            -

                        <?php elseif (!empty($user['kategori_penulis'])): ?>

                            <?= htmlspecialchars($user['kategori_penulis']); ?>

                        <?php else: ?>

                            <span style="color:#d97706;">
                                Belum memiliki kategori
                            </span>

                        <?php endif; ?>
                    </td>

                    <td>
                        <?= date(
                            'd M Y H:i',
                            strtotime($user['created_at'])
                        ); ?>
                    </td>

                    <td>

                        <?php if ($user['role'] === 'admin'): ?>

                            <button
                                type="button"
                                class="btn-edit"
                                onclick='showEditForm(
                                    <?= (int) $user["id"]; ?>,
                                    <?= json_encode($user["username"]); ?>,
                                    <?= json_encode($user["kategori_penulis"]); ?>
                                )'>

                                <?= empty($user['kategori_penulis'])
                                    ? 'Tugaskan Kategori'
                                    : 'Edit Kategori'; ?>

                            </button>

                            <form
                                action="manage_admins.php"
                                method="POST"
                                style="display:inline-block; margin-left:5px;"
                                onsubmit="return confirm('Yakin ingin menghapus Admin ini?');">

                                <input type="hidden" name="action" value="delete">

                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?= (int) $user['id']; ?>">

                                <button type="submit" class="btn-delete">
                                    Hapus
                                </button>

                            </form>

                        <?php else: ?>

                            <span>Admin Utama</span>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    <?php else: ?>

        <p style="text-align:center;">
            Belum ada Admin yang terdaftar.
        </p>

    <?php endif; ?>


    <!-- ================================= -->
    <!-- MODAL EDIT ADMIN -->
    <!-- ================================= -->

    <div id="editAdminModal" class="modal" style="display:none;">

        <div class="modal-content">

            <span class="close-button" onclick="hideEditForm()">
                &times;
            </span>

            <h2>Edit Kategori Admin</h2>

            <form action="manage_admins.php" method="POST" class="admin-form">

                <input type="hidden" name="action" value="edit">

                <input type="hidden" id="edit_user_id" name="user_id">

                <div class="form-group">
                    <label for="edit_username">Username</label>

                    <input type="text" id="edit_username" readonly>
                </div>

                <div class="form-group">

                    <label for="edit_kategori_penulis">
                        Kategori Penulis
                    </label>

                    <select
                        name="kategori_penulis"
                        id="edit_kategori_penulis"
                        required>

                        <option value="">
                            Belum memiliki kategori
                        </option>

                        <?php foreach ($kategori_list as $kategori): ?>

                            <option value="<?= htmlspecialchars($kategori['nama_kategori']); ?>">
                                <?= htmlspecialchars($kategori['nama_kategori']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <button type="submit">
                    Simpan Kategori
                </button>

            </form>

        </div>

    </div>

</div>
</main>


<script>

function showEditForm(id, username, kategoriPenulis) {

    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;

    document.getElementById('edit_kategori_penulis').value =
        kategoriPenulis || '';

    document.getElementById('editAdminModal').style.display = 'block';
}

function hideEditForm() {
    document.getElementById('editAdminModal').style.display = 'none';
}

window.onclick = function(event) {

    const modalAdmin = document.getElementById('editAdminModal');

    if (event.target === modalAdmin) {
        hideEditForm();
    }
};

</script>


<footer class="site-footer">

    <p>
        &copy; <?= date('Y'); ?>
        <span class="footer-name">Muhammad Candra Wijaya</span>
    </p>

    <span class="footer-caption">
        Kotak Saran, Kritik dan Aspirasi
    </span>

</footer>

</body>
</html>