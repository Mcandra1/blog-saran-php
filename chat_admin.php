<?php
session_start();
require_once 'config.php';

$notif_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

// ========================================
// CEK LOGIN DAN HAK AKSES
// ========================================
if (
    !isset($_SESSION['loggedin']) ||
    $_SESSION['loggedin'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role'])
) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

if (!in_array($role, ['admin', 'superadmin'], true)) {
    header("Location: index.php");
    exit;
}

function hitungPesanBaruChat(mysqli $conn, int $user_id, string $role): int
{
    if ($user_id <= 0 || !in_array($role, ['admin', 'superadmin'], true)) {
        return 0;
    }

    if ($role === 'superadmin') {
        $sql = "
            SELECT COUNT(*) AS total_baru
            FROM percakapan_admin c
            JOIN pesan_admin p
                ON p.id = (
                    SELECT p2.id
                    FROM pesan_admin p2
                    WHERE p2.percakapan_id = c.id
                    ORDER BY p2.waktu_dibuat DESC, p2.id DESC
                    LIMIT 1
                )
            WHERE c.superadmin_id = ?
              AND p.pengirim_id = c.admin_id
        ";
    } else {
        $sql = "
            SELECT COUNT(*) AS total_baru
            FROM percakapan_admin c
            JOIN pesan_admin p
                ON p.id = (
                    SELECT p2.id
                    FROM pesan_admin p2
                    WHERE p2.percakapan_id = c.id
                    ORDER BY p2.waktu_dibuat DESC, p2.id DESC
                    LIMIT 1
                )
            WHERE c.admin_id = ?
              AND p.pengirim_id = c.superadmin_id
        ";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total_baru'] ?? 0);
}

$jumlah_chat_baru = hitungPesanBaruChat($conn, $user_id, $role);

$error = '';
$conversation_id = 0;
$admin_id = 0;
$nama_lawan_chat = '';
$foto_lawan_chat = null;
$daftar_admin = null;

// ========================================
// FUNGSI AVATAR
// ========================================
function avatarHtml($foto, $nama, $class = 'avatar')
{
    $nama = trim((string) $nama);
    $inisial = mb_strtoupper(mb_substr($nama, 0, 1, 'UTF-8'), 'UTF-8');

    if ($inisial === '') {
        $inisial = '?';
    }

    if (!empty($foto)) {
        $path = htmlspecialchars($foto, ENT_QUOTES, 'UTF-8');

        return '
            <div class="' . $class . '">
                <img src="' . $path . '"
                     alt="Foto ' . htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') . '"
                     onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';">
                <span class="avatar-fallback" style="display:none;">'
                    . htmlspecialchars($inisial, ENT_QUOTES, 'UTF-8') .
                '</span>
            </div>
        ';
    }

    return '
        <div class="' . $class . '">
            <span class="avatar-fallback">'
                . htmlspecialchars($inisial, ENT_QUOTES, 'UTF-8') .
            '</span>
        </div>
    ';
}

// ========================================
// TENTUKAN LAWAN CHAT
// ========================================
if ($role === 'admin') {

    // Admin hanya dapat membuka percakapannya sendiri
    $admin_id = $user_id;

    // Ambil data Super Admin
    $stmt_super = $conn->prepare("
        SELECT id, username, foto_profil
        FROM users
        WHERE role = 'superadmin'
        ORDER BY id ASC
        LIMIT 1
    ");

    $stmt_super->execute();
    $superadmin = $stmt_super->get_result()->fetch_assoc();
    $stmt_super->close();

    if (!$superadmin) {
        die("Super Admin belum tersedia.");
    }

    $superadmin_id = (int) $superadmin['id'];
    $nama_lawan_chat = $superadmin['username'];
    $foto_lawan_chat = $superadmin['foto_profil'];

} else {

    // Super Admin memilih Admin
    $admin_id = (int) ($_GET['admin_id'] ?? $_POST['admin_id'] ?? 0);

    if ($admin_id <= 0) {

        // Daftar semua Admin: chat masuk terbaru di paling atas
        $daftar_admin = $conn->query("
            SELECT
                u.id,
                u.username,
                u.kategori_penulis,
                u.foto_profil,

                (
                    SELECT p.isi_pesan
                    FROM pesan_admin p
                    JOIN percakapan_admin c
                        ON c.id = p.percakapan_id
                    WHERE c.admin_id = u.id
                    ORDER BY p.waktu_dibuat DESC, p.id DESC
                    LIMIT 1
                ) AS pesan_terakhir,

                (
                    SELECT p.waktu_dibuat
                    FROM pesan_admin p
                    JOIN percakapan_admin c
                        ON c.id = p.percakapan_id
                    WHERE c.admin_id = u.id
                    ORDER BY p.waktu_dibuat DESC, p.id DESC
                    LIMIT 1
                ) AS waktu_terakhir,

                (
                    SELECT p.pengirim_id
                    FROM pesan_admin p
                    JOIN percakapan_admin c
                        ON c.id = p.percakapan_id
                    WHERE c.admin_id = u.id
                    ORDER BY p.waktu_dibuat DESC, p.id DESC
                    LIMIT 1
                ) AS pengirim_terakhir_id,

                (
                    SELECT p.id
                    FROM pesan_admin p
                    JOIN percakapan_admin c
                        ON c.id = p.percakapan_id
                    WHERE c.admin_id = u.id
                    ORDER BY p.waktu_dibuat DESC, p.id DESC
                    LIMIT 1
                ) AS pesan_terakhir_id

            FROM users u
            WHERE u.role = 'admin'
            ORDER BY
                (waktu_terakhir IS NULL) ASC,
                waktu_terakhir DESC,
                pesan_terakhir_id DESC,
                u.username ASC
        ");

        if (!$daftar_admin) {
            die("Gagal mengambil daftar Admin.");
        }

    } else {

        // Validasi Admin yang dipilih
        $stmt_admin = $conn->prepare("
            SELECT id, username, foto_profil
            FROM users
            WHERE id = ? AND role = 'admin'
        ");

        $stmt_admin->bind_param("i", $admin_id);
        $stmt_admin->execute();

        $admin = $stmt_admin->get_result()->fetch_assoc();
        $stmt_admin->close();

        if (!$admin) {
            die("Admin tidak ditemukan.");
        }

        $superadmin_id = $user_id;
        $nama_lawan_chat = $admin['username'];
        $foto_lawan_chat = $admin['foto_profil'];
    }
}

// ========================================
// CARI / BUAT PERCAKAPAN
// ========================================
if ($admin_id > 0) {

    $stmt_chat = $conn->prepare("
        SELECT id, superadmin_id
        FROM percakapan_admin
        WHERE admin_id = ?
        LIMIT 1
    ");

    $stmt_chat->bind_param("i", $admin_id);
    $stmt_chat->execute();

    $conversation = $stmt_chat->get_result()->fetch_assoc();
    $stmt_chat->close();

    if ($conversation) {

        if ((int) $conversation['superadmin_id'] !== $superadmin_id) {
            die("Anda tidak memiliki akses ke percakapan ini.");
        }

        $conversation_id = (int) $conversation['id'];

    } else {

        $stmt_create = $conn->prepare("
            INSERT INTO percakapan_admin
            (superadmin_id, admin_id)
            VALUES (?, ?)
        ");

        $stmt_create->bind_param(
            "ii",
            $superadmin_id,
            $admin_id
        );

        if ($stmt_create->execute()) {
            $conversation_id = $conn->insert_id;
        } else {
            $error = "Gagal membuat percakapan.";
        }

        $stmt_create->close();
    }
}

// ========================================
// PROSES KIRIM PESAN
// ========================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $conversation_id > 0
) {

    $isi_pesan = trim($_POST['isi_pesan'] ?? '');

    if ($isi_pesan === '') {

        $error = "Pesan tidak boleh kosong.";

    } elseif (mb_strlen($isi_pesan, 'UTF-8') > 10000) {

        $error = "Pesan terlalu panjang.";

    } else {

        $stmt_kirim = $conn->prepare("
            INSERT INTO pesan_admin
            (percakapan_id, pengirim_id, isi_pesan)
            VALUES (?, ?, ?)
        ");

        $stmt_kirim->bind_param(
            "iis",
            $conversation_id,
            $user_id,
            $isi_pesan
        );

        if ($stmt_kirim->execute()) {

            $stmt_kirim->close();

            if ($role === 'superadmin') {
                header("Location: chat_admin.php?admin_id=" . $admin_id);
            } else {
                header("Location: chat_admin.php");
            }

            exit;

        } else {

            $error = "Pesan gagal dikirim.";
            $stmt_kirim->close();
        }
    }
}

// ========================================
// AMBIL RIWAYAT PESAN
// ========================================
$pesan_result = null;
$stmt_pesan = null;

if ($conversation_id > 0) {

    $stmt_pesan = $conn->prepare("
        SELECT
            p.id,
            p.pengirim_id,
            p.isi_pesan,
            p.waktu_dibuat,
            u.username,
            u.foto_profil

        FROM pesan_admin p

        JOIN users u
            ON u.id = p.pengirim_id

        WHERE p.percakapan_id = ?

        ORDER BY p.waktu_dibuat ASC, p.id ASC
    ");

    $stmt_pesan->bind_param("i", $conversation_id);
    $stmt_pesan->execute();

    $pesan_result = $stmt_pesan->get_result();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Chat Admin | Blog Saran</title>

    <link rel="stylesheet" href="./assets/style.css?v=4">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .navbar-menu {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .navbar-menu .nav-link {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .navbar-menu .nav-link.active {
            color: var(--primary, #168bd0);
            font-weight: 700;
        }

        .nav-chat-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #16a34a;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            box-shadow: 0 2px 6px rgba(22, 163, 74, 0.35);
        }

        .navbar-menu .unipi {
            object-fit: contain;
            margin-left: 8px;
        }

        .chat-page {
            max-width: 760px;
            margin: 35px auto;
            padding: 0 18px 35px;
            box-sizing: border-box;
        }

        .chat-container {
            width: 700px;
            background: #85aaf9;
            border: 1px solid #e8edf3;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(23, 50, 77, 0.07);
        }

        .chat-topbar {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 20px 25px;
            background: #85aaf9;
            border-bottom: 1px solid #edf0f4;
        }

        .chat-topbar h2 {
            margin: 0;
            font-size: 19px;
            color: #17324d;
        }

        .chat-topbar p {
            margin: 5px 0 0;
            color: #000000;
            font-size: 15px;
        }

        .chat-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
            font-size: 11px;
            color: #ffffff;
            font-weight: 600;
        }

        .chat-status i {
            font-size: 8px;
        }

        .avatar {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 50%;
            overflow: hidden;
            background: #e0f2fe;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #38a9e8, #168bd0);
            color: #ffffff;
            font-size: 19px;
            font-weight: 700;
        }

        .message-avatar {
            width: 34px;
            height: 34px;
            min-width: 34px;
            border-radius: 50%;
            overflow: hidden;
            background: #e0f2fe;
            align-self: flex-end;
        }

        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message-avatar .avatar-fallback {
            font-size: 14px;
        }

        .chat-box {
            height: 480px;
            overflow-y: auto;
            padding: 25px;
            background: #f6f9fc;
        }

        .message-row {
            display: flex;
            align-items: flex-end;
            gap: 9px;
            margin-bottom: 18px;
        }

        .message-row.mine {
            flex-direction: row-reverse;
        }

        .message-content {
            max-width: 72%;
        }

        .message-sender {
            display: block;
            margin: 0 0 5px 4px;
            color: #718096;
            font-size: 11px;
        }

        .message-row.mine .message-sender {
            text-align: right;
            margin-right: 4px;
        }

        .chat-message {
            padding: 12px 15px;
            border-radius: 15px;
            background: #ffffff;
            color: #334155;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            box-shadow: 0 2px 7px rgba(0, 0, 0, 0.035);
        }

        .message-row.other .chat-message {
            border-bottom-left-radius: 4px;
        }

        .message-row.mine .chat-message {
            background: #168bd0;
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .message-time {
            display: block;
            margin-top: 7px;
            color: #9aa8b7;
            font-size: 10px;
            text-align: right;
        }

        .message-row.mine .message-time {
            color: rgba(255,255,255,.75);
        }

        .empty-chat {
            text-align: center;
            padding: 90px 15px;
            color: #94a3b8;
        }

        .empty-chat i {
            display: block;
            margin-bottom: 15px;
            font-size: 42px;
            color: #cbd5e1;
        }

        .empty-chat h3 {
            margin: 0 0 7px;
            color: #64748b;
            font-size: 16px;
        }

        .empty-chat p {
            margin: 0;
            font-size: 13px;
        }

        .chat-footer {
            padding: 18px 22px;
            background: #ffffff;
            border-top: 1px solid #edf0f4;
        }

        .chat-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        .chat-form textarea {
            width: 100%;
            min-height: 90px;
            padding: 15px 18px;
            box-sizing: border-box;
            resize: vertical;
        }

        .chat-form button {
            width: 100%;
            min-height: 55px;
            border: none;
            border-radius: 12px;
            background: #168bd0;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .chat-form button:hover {
            background: #0878bd;
        }

        .chat-form button i {
            margin-left: 6px;
        }

        .chat-info {
            margin: 10px 0 0;
            color: #94a3b8;
            font-size: 11px;
        }

        .admin-list-header {
            background: #85aaf9;
            width: 700px;
            box-sizing: border-box;
            padding: 25px;
            border-bottom: 1px solid #edf0f4;
        }

        .admin-list-header h2 {
            margin: 0;
            color: #17324d;
            font-size: 20px;
        }

        .admin-list-header p {
            margin: 7px 0 0;
            color: #000000;
            font-size: 15px;
        }

        .admin-chat-list {
            background: #85aaf9;
            padding: 15px;
            display: grid;
            gap: 10px;
        }

        .admin-chat-item {
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 15px;
            border: 1px solid #edf0f4;
            border-radius: 13px;
            text-decoration: none;
            color: #17324d;
            transition: .2s;
        }

        .admin-chat-item:hover {
            border-color: #9bd7f5;
            background: #d4ecfc;
            transform: translateY(-3px);
        }

        .admin-chat-item.has-incoming-admin {
            background: #e8f8ee;
            border-color: #9cd9b4;
        }

        .admin-chat-item.has-incoming-admin:hover {
            background: #d7f2e2;
            border-color: #7cc99c;
        }

        .admin-badge-new {
            display: inline-block;
            margin-left: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #1ea653;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            vertical-align: middle;
        }

        .admin-info {
            flex: 1;
            min-width: 0;
        }

        .admin-info strong {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .admin-info small {
            display: block;
            color: #8492a6;
            font-size: 12px;
        }

        .admin-last-message {
            margin-top: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #64748b;
            font-size: 12px;
        }

        .admin-arrow {
            color: #94a3b8;
            font-size: 14px;
        }

        .notif-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.55;
            box-shadow: 0 6px 18px rgba(22, 59, 88, 0.06);
        }

        .notif-banner.is-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .notif-banner.is-error i {
            color: #dc2626;
            margin-top: 2px;
        }

        .chat-alert {
            margin: 15px 22px 0;
            padding: 12px 15px;
            border-radius: 9px;
            background: #fff1f2;
            color: #be123c;
            font-size: 13px;
        }

        .chat-footer-top {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 12px;
        }

        .btn-kembali {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: #e8f3fc;
            color: #168bd0;
            border: none;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-kembali:hover {
            background: #d5eafa;
            color: #0878bd;
        }

        @media (max-width: 768px) {

            .navbar-inner {
                align-items: flex-start;
                flex-direction: column;
                padding: 12px 15px;
            }

            .navbar-menu {
                width: 100%;
                gap: 3px;
            }

            .navbar-menu .nav-link {
                padding: 8px;
                font-size: 12px;
            }

            .navbar-menu .unipi {
                width: 42px;
                height: 42px;
            }

            .chat-page {
                margin: 20px auto;
                padding: 0 12px 25px;
            }

            .chat-topbar {
                width: 700px;
                box-sizing: border-box;
                padding: 18px 16px;
            }

            .chat-box {
                height: 420px;
                width: 100%;
                box-sizing: border-box;
                padding: 16px 12px;
            }

            .message-content {
                max-width: 82%;
            }

            .chat-footer {
                padding: 13px;
            }

            .chat-form {
                gap: 8px;
            }

            .chat-form button {
                padding: 0 15px;
            }

            .admin-chat-list {
                padding: 10px;
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

        <div class="navbar-menu">

            <a href="admin.php" class="nav-link">
                Daftar Saran
            </a>

            <?php if ($role === 'admin'): ?>

                <a href="kelola_tulisan.php" class="nav-link">
                    Kelola Tulisan
                </a>

                <a href="chat_admin.php" class="nav-link active">
                    Chat Super Admin
                    <?php if ($jumlah_chat_baru > 0): ?>
                        <span class="nav-chat-badge"><?= $jumlah_chat_baru ?></span>
                    <?php endif; ?>
                </a>

            <?php endif; ?>

            <?php if ($role === 'superadmin'): ?>

                <a href="manage_admins.php" class="nav-link">
                    Manajemen Admin
                </a>

                <a href="chat_admin.php" class="nav-link active">
                    Chat Admin
                    <?php if ($jumlah_chat_baru > 0): ?>
                        <span class="nav-chat-badge"><?= $jumlah_chat_baru ?></span>
                    <?php endif; ?>
                </a>

            <?php endif; ?>

            <a href="profil_saya.php" class="nav-link">
                Profil
            </a>

            <a href="logout.php"
               class="nav-link"
               onclick="return confirm('Apakah Anda yakin ingin logout?');">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </a>

            <img class="unipi"
                 src="./assets/unipi.png"
                 alt="Logo UNIPI"
                 width="60"
                 height="60">

        </div>

    </div>

</nav>

<main class="chat-page">

    <?php if (!empty($notif_error)): ?>
        <div class="notif-banner is-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($notif_error, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <div class="chat-footer-top">
        <button type="button" class="btn-kembali"
            onclick="if (history.length > 1) { history.back(); } else { window.location.href='admin.php'; }">
            <i class="fa-solid fa-arrow-left"></i>
            Kembali
        </button>
    </div>

    <div class="chat-container">

        <?php if ($error): ?>
            <div class="chat-alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>


        <?php if ($role === 'superadmin' && $admin_id === 0): ?>

            <div class="admin-list-header">
                <h2>
                    <i class="fa-regular fa-comments"></i>
                    Pesan Admin
                </h2>

                <p>
                    Pilih Admin untuk melihat dan membalas percakapan.
                </p>
            </div>

            <div class="admin-chat-list">

                <?php if ($daftar_admin && $daftar_admin->num_rows > 0): ?>

                    <?php while ($row = $daftar_admin->fetch_assoc()): ?>
                        <?php
                        $ada_chat_masuk = !empty($row['pesan_terakhir'])
                            && (int) ($row['pengirim_terakhir_id'] ?? 0) === (int) $row['id'];
                        ?>

                        <a
                            class="admin-chat-item <?= $ada_chat_masuk ? 'has-incoming-admin' : '' ?>"
                            href="chat_admin.php?admin_id=<?= (int) $row['id'] ?>"
                        >

                            <?= avatarHtml(
                                $row['foto_profil'],
                                $row['username']
                            ) ?>

                            <div class="admin-info">

                                <strong>
                                    <?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($ada_chat_masuk): ?>
                                        <span class="admin-badge-new">Pesan masuk</span>
                                    <?php endif; ?>
                                </strong>

                                <small>
                                    <?= !empty($row['kategori_penulis'])
                                        ? htmlspecialchars($row['kategori_penulis'], ENT_QUOTES, 'UTF-8')
                                        : 'Belum memiliki kategori'
                                    ?>
                                </small>

                                <div class="admin-last-message">
                                    <?php if (!empty($row['pesan_terakhir'])): ?>
                                        <?= htmlspecialchars($row['pesan_terakhir'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        Belum ada pesan. Mulai percakapan.
                                    <?php endif; ?>
                                </div>

                            </div>

                            <div class="admin-arrow">
                                <i class="fa-solid fa-chevron-right"></i>
                            </div>

                        </a>

                    <?php endwhile; ?>

                <?php else: ?>

                    <div class="empty-chat">
                        <i class="fa-solid fa-users"></i>
                        <h3>Belum Ada Admin</h3>
                        <p>Admin yang terdaftar akan muncul di sini.</p>
                    </div>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="chat-topbar">

                <?= avatarHtml(
                    $foto_lawan_chat,
                    $nama_lawan_chat
                ) ?>

                <div>
                    <h2>
                        <?= htmlspecialchars($nama_lawan_chat, ENT_QUOTES, 'UTF-8') ?>
                    </h2>

                    <p>
                        <?= $role === 'admin'
                            ? 'Super Admin'
                            : 'Admin'
                        ?>
                    </p>

                    <div class="chat-status">
                        <i class="fa-solid fa-circle"></i>
                        Percakapan pribadi
                    </div>
                </div>

            </div>

            <div class="chat-box" id="chatBox">

                <?php if ($pesan_result && $pesan_result->num_rows > 0): ?>

                    <?php while ($pesan = $pesan_result->fetch_assoc()): ?>

                        <?php
                        $pesan_saya = (int) $pesan['pengirim_id'] === $user_id;
                        ?>

                        <div class="message-row <?= $pesan_saya ? 'mine' : 'other' ?>">

                            <?= avatarHtml(
                                $pesan['foto_profil'],
                                $pesan['username'],
                                'message-avatar'
                            ) ?>

                            <div class="message-content">

                                <span class="message-sender">
                                    <?= htmlspecialchars($pesan['username'], ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <div class="chat-message">

                                    <?= nl2br(htmlspecialchars($pesan['isi_pesan'], ENT_QUOTES, 'UTF-8')) ?>

                                    <span class="message-time">
                                        <?= date(
                                            'd M Y H:i',
                                            strtotime($pesan['waktu_dibuat'])
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                        </div>

                    <?php endwhile; ?>

                <?php else: ?>

                    <div class="empty-chat">

                        <i class="fa-regular fa-comments"></i>

                        <h3>Belum Ada Percakapan</h3>

                        <p>
                            Kirim pesan pertama untuk memulai percakapan.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

            <div class="chat-footer">

                <form method="POST" class="chat-form">

                    <?php if ($role === 'superadmin'): ?>
                        <input
                            type="hidden"
                            name="admin_id"
                            value="<?= (int) $admin_id ?>"
                        >
                    <?php endif; ?>

                    <textarea
                        name="isi_pesan"
                        id="isiPesan"
                        placeholder="Tulis pesan di sini..."
                        maxlength="10000"
                        required
                    ></textarea>

                    <button type="submit">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span>Kirim</span>
                    </button>

                </form>

                <p class="chat-info">
                    <i class="fa-solid fa-lock"></i>
                    Pesan ini hanya dapat dilihat oleh Admin dan Super Admin.
                </p>

            </div>

        <?php endif; ?>

    </div>

</main>

<script>
    const chatBox = document.getElementById('chatBox');

    if (chatBox) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    const isiPesan = document.getElementById('isiPesan');

    if (isiPesan) {
        isiPesan.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();

                if (this.value.trim() !== '') {
                    this.closest('form').requestSubmit();
                }
            }
        });
    }
</script>

</body>
</html>

<?php
if (isset($stmt_pesan) && $stmt_pesan instanceof mysqli_stmt) {
    $stmt_pesan->close();
}

$conn->close();
?>