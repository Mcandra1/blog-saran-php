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
$chat_admins = [];

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
// PROSES FORM KATEGORI
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // ====================================
    // TAMBAH KATEGORI
    // ====================================

    if ($action === 'add_category') {

        $nama = trim($_POST['nama_kategori'] ?? '');

        if ($nama === '') {
            $error = "Nama kategori wajib diisi.";
        } elseif (mb_strlen($nama, 'UTF-8') > 50) {
            $error = "Nama kategori maksimal 50 karakter.";
        } elseif (kategoriAda($conn, $nama)) {
            $error = "Kategori tersebut sudah tersedia.";
        } else {

            $stmt = $conn->prepare(
                "INSERT INTO kategori (nama_kategori) VALUES (?)"
            );

            $stmt->bind_param("s", $nama);

            if ($stmt->execute()) {
                $message = "Kategori berhasil ditambahkan.";
            } else {
                $error = "Gagal menambahkan kategori.";
            }

            $stmt->close();
        }
    }

    // ====================================
    // EDIT NAMA KATEGORI
    // ====================================

    elseif ($action === 'edit_category') {

        $kategori_id = (int) ($_POST['kategori_id'] ?? 0);
        $nama_baru = trim($_POST['nama_kategori'] ?? '');

        if ($kategori_id <= 0 || $nama_baru === '') {
            $error = "Data kategori tidak valid.";
        } elseif (mb_strlen($nama_baru, 'UTF-8') > 50) {
            $error = "Nama kategori maksimal 50 karakter.";
        } else {

            $stmt = $conn->prepare(
                "SELECT nama_kategori FROM kategori WHERE id = ?"
            );

            $stmt->bind_param("i", $kategori_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $kategori_lama = $result->fetch_assoc();
            $stmt->close();

            if (!$kategori_lama) {
                $error = "Kategori tidak ditemukan.";
            } elseif (
                $nama_baru !== $kategori_lama['nama_kategori'] &&
                kategoriAda($conn, $nama_baru)
            ) {
                $error = "Nama kategori sudah digunakan.";
            } else {

                $nama_lama = $kategori_lama['nama_kategori'];

                try {
                    $conn->begin_transaction();

                    $stmt = $conn->prepare(
                        "UPDATE kategori
                         SET nama_kategori = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param("si", $nama_baru, $kategori_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "UPDATE artikel SET kategori = ? WHERE kategori = ?"
                    );

                    $stmt->bind_param("ss", $nama_baru, $nama_lama);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "UPDATE users
                         SET kategori_penulis = ?
                         WHERE kategori_penulis = ? AND role = 'admin'"
                    );

                    $stmt->bind_param("ss", $nama_baru, $nama_lama);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $message = "Nama kategori dan data terkait berhasil diperbarui.";

                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Gagal memperbarui kategori. Periksa kembali database.";
                }
            }
        }
    }

    // ====================================
    // HAPUS KATEGORI
    // ====================================

    elseif ($action === 'delete_category') {

        $kategori_id = (int) ($_POST['kategori_id'] ?? 0);

        $stmt = $conn->prepare(
            "SELECT nama_kategori FROM kategori WHERE id = ?"
        );

        $stmt->bind_param("i", $kategori_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $data_kategori = $result->fetch_assoc();
        $stmt->close();

        if (!$data_kategori) {
            $error = "Kategori tidak ditemukan.";
        } else {

            $nama_kategori = $data_kategori['nama_kategori'];

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM artikel
                 WHERE kategori = ?"
            );

            $stmt->bind_param("s", $nama_kategori);
            $stmt->execute();

            $result = $stmt->get_result();
            $jumlah_artikel = (int) $result->fetch_assoc()['total'];
            $stmt->close();

            if ($jumlah_artikel > 0) {

                $error = "Kategori " . $nama_kategori .
                    " masih memiliki " . $jumlah_artikel .
                    " artikel. Kategori tidak dapat dihapus. Silakan hubungi Admin kategori terkait.";

                $stmt = $conn->prepare(
                    "SELECT id, username
                     FROM users
                     WHERE role = 'admin'
                     AND kategori_penulis = ?
                     ORDER BY username ASC"
                );

                $stmt->bind_param("s", $nama_kategori);
                $stmt->execute();

                $result = $stmt->get_result();

                while ($admin = $result->fetch_assoc()) {
                    $chat_admins[] = $admin;
                }

                $stmt->close();

            } else {

                try {
                    $conn->begin_transaction();

                    $stmt = $conn->prepare(
                        "UPDATE users
                         SET kategori_penulis = NULL
                         WHERE role = 'admin'
                         AND kategori_penulis = ?"
                    );

                    $stmt->bind_param("s", $nama_kategori);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "DELETE FROM kategori WHERE id = ?"
                    );

                    $stmt->bind_param("i", $kategori_id);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        $message = "Kategori berhasil dihapus. Akun Admin tetap tersimpan, tetapi sekarang tidak memiliki kategori.";
                    } else {
                        throw new Exception("Kategori gagal dihapus.");
                    }

                    $stmt->close();

                    $conn->commit();

                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Penghapusan kategori gagal. Silakan periksa kembali database.";
                }
            }
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manajemen Kategori</title>

    <link rel="stylesheet" href="./assets/style.css?v=4">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<style>
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 14px;
        background-color: #176ba5;
        color: #fff;
        text-decoration: none;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 600;
        transition: background-color 0.2s ease, transform 0.2s ease;
    }

    .back-link:hover {
        background-color: #125686;
        transform: translateY(-1px);
    }

    .notif-banner {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 18px;
        border-radius: 14px;
        margin: 0 auto 1.4rem;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.55;
        box-shadow: 0 6px 18px rgba(22, 59, 88, 0.05);
    }

    .notif-banner i {
        font-size: 17px;
        margin-top: 2px;
        flex-shrink: 0;
    }

    .notif-banner.is-success {
        background-color: #ecfdf3;
        border: 1px solid #a7f3d0;
        color: #166534;
    }

    .notif-banner.is-success i {
        color: #15803d;
    }

    .notif-banner.is-error {
        background-color: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    .notif-banner.is-error i {
        color: #dc2626;
    }

    .admin-alert-box {
        background-color: #f3f9fe;
        border: 1px solid #d4e8f7;
        border-radius: 16px;
        padding: 18px 20px;
        text-align: center;
        margin-bottom: 1.8rem;
    }

    .admin-alert-box h3 {
        margin: 0 0 12px;
        font-size: 15px;
        font-weight: 700;
        color: #163b58;
    }

    .admin-chat-chip {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        margin: 4px;
        padding: 8px 15px;
        border-radius: 10px;
        background-color: #176ba5;
        color: #ffffff;
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color 0.2s ease, transform 0.2s ease;
    }

    .admin-chat-chip:hover {
        background-color: #125686;
        color: #ffffff;
        transform: translateY(-1px);
    }
</style>

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
            <a href="manage_admins.php" class="nav-link">Manajemen Admin</a>
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
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
        <a href="manage_admins.php" class="back-link" style="margin-bottom: 0; white-space: nowrap;">
            <i class="fa-solid fa-arrow-left"></i> Kembali
        </a>
        <h1 style="margin: 0; text-align: center; flex: 1;">Manajemen Kategori</h1>
        <span style="width: 84px; flex-shrink: 0;" aria-hidden="true"></span>
    </div>
    <div class="content-header" style="margin-top: 4px;">
        <p style="color:black;">
            Tambah, ubah, atau hapus kategori tulisan dan kategori penulis.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="notif-banner is-success" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <span><?= htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notif-banner is-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($chat_admins)): ?>

        <div class="admin-alert-box">
            <h3>Admin yang mengelola kategori ini</h3>

            <?php foreach ($chat_admins as $admin): ?>

                <a href="chat_admin.php?admin_id=<?= (int) $admin['id']; ?>"
                   class="admin-chat-chip">

                    <i class="fa-solid fa-comments"></i>
                    Chat <?= htmlspecialchars($admin['username']); ?>

                </a>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <h2 style="margin-top: 0px;">Tambah Kategori Baru</h2>

    <form action="kelola_kategori.php" method="POST" class="admin-form">

        <input type="hidden" name="action" value="add_category">

        <div class="form-group">
            <label for="nama_kategori">Nama Kategori Baru</label>

            <input
                type="text"
                id="nama_kategori"
                name="nama_kategori"
                maxlength="50"
                placeholder="Masukkan nama kategori"
                required>
        </div>

        <button type="submit">
            <i class="fa-solid fa-plus"></i>
            Tambah Kategori
        </button>

    </form>

    <h2 style="margin-top:2.5rem;">Daftar Kategori</h2>

    <table class="admin-table" style="margin-top:1rem;">

        <thead>
            <tr>
                <th>No</th>
                <th>Nama Kategori</th>
                <th>Aksi</th>
            </tr>
        </thead>

        <tbody>

        <?php if (count($kategori_list) > 0): ?>

            <?php $no = 1; ?>

            <?php foreach ($kategori_list as $kategori): ?>

                <tr>
                    <td><?= $no++; ?></td>

                    <td>
                        <?= htmlspecialchars($kategori['nama_kategori']); ?>
                    </td>

                    <td>

                        <button
                            type="button"
                            class="btn-edit"
                            onclick='showEditKategori(
                                <?= (int) $kategori["id"]; ?>,
                                <?= json_encode($kategori["nama_kategori"]); ?>
                            )'>

                            Edit
                        </button>

                        <form
                            action="kelola_kategori.php"
                            method="POST"
                            style="display:inline-block; margin-left:5px;"
                            onsubmit="return confirm('Yakin ingin menghapus kategori ini?');">

                            <input type="hidden" name="action" value="delete_category">

                            <input
                                type="hidden"
                                name="kategori_id"
                                value="<?= (int) $kategori['id']; ?>">

                            <button type="submit" class="btn-delete">
                                Hapus
                            </button>

                        </form>

                    </td>
                </tr>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="3" style="text-align:center;">
                    Belum ada kategori.
                </td>
            </tr>

        <?php endif; ?>

        </tbody>

    </table>

    <!-- MODAL EDIT NAMA KATEGORI -->

    <div id="editKategoriModal" class="modal" style="display:none;">

        <div class="modal-content">

            <span class="close-button" onclick="hideEditKategori()">
                &times;
            </span>

            <h2>Edit Nama Kategori</h2>

            <form action="kelola_kategori.php" method="POST" class="admin-form">

                <input type="hidden" name="action" value="edit_category">

                <input type="hidden" id="edit_kategori_id" name="kategori_id">

                <div class="form-group">

                    <label for="edit_nama_kategori">
                        Nama Kategori
                    </label>

                    <input
                        type="text"
                        id="edit_nama_kategori"
                        name="nama_kategori"
                        maxlength="50"
                        required>

                </div>

                <button type="submit">
                    Simpan Perubahan
                </button>

            </form>

        </div>

    </div>

</div>
</main>

<script>

function showEditKategori(id, nama) {

    document.getElementById('edit_kategori_id').value = id;
    document.getElementById('edit_nama_kategori').value = nama;

    document.getElementById('editKategoriModal').style.display = 'block';
}

function hideEditKategori() {
    document.getElementById('editKategoriModal').style.display = 'none';
}

window.onclick = function(event) {

    const modalKategori = document.getElementById('editKategoriModal');

    if (event.target === modalKategori) {
        hideEditKategori();
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