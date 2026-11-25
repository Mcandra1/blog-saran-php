
<?php
session_start();
require_once 'config.php';

// ======================================
// CEK LOGIN
// ======================================

if (
    !isset($_SESSION['loggedin']) ||
    $_SESSION['loggedin'] !== true
) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// ======================================
// HANYA SUPER ADMIN YANG BOLEH MENGHAPUS
// ======================================

if ($role !== 'superadmin') {
    http_response_code(403);
    die("Akses ditolak! Hanya Super Admin yang dapat menghapus pesan.");
}

// ======================================
// CEK REQUEST
// ======================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin.php");
    exit;
}

$saran_id = (int) ($_POST['saran_id'] ?? 0);
$current_page = max(1, (int) ($_POST['current_page'] ?? 1));

$search = trim($_POST['search'] ?? '');
$kategori_filter = trim($_POST['kategori'] ?? '');

if ($saran_id <= 0) {
    die("ID pesan tidak valid.");
}

// ======================================
// FUNGSI PENGECEKAN QUERY
// ======================================

function prepareQuery($conn, $sql)
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception(
            "Query prepare gagal: " . $conn->error
        );
    }

    return $stmt;
}

// ======================================
// PROSES PENGHAPUSAN
// ======================================

try {

    // Mulai transaksi
    if (!$conn->begin_transaction()) {
        throw new Exception(
            "Gagal memulai transaksi: " . $conn->error
        );
    }

    // ======================================
    // AMBIL DATA PESAN
    // ======================================

    $stmt = prepareQuery($conn, "
        SELECT id, kategori
        FROM saran
        WHERE id = ?
          AND is_deleted = 0
        FOR UPDATE
    ");

    $stmt->bind_param("i", $saran_id);

    if (!$stmt->execute()) {
        throw new Exception(
            "Gagal mengambil data pesan: " . $stmt->error
        );
    }

    $result = $stmt->get_result();
    $saran = $result->fetch_assoc();

    $stmt->close();

    if (!$saran) {
        throw new Exception(
            "Pesan tidak ditemukan atau sudah dihapus."
        );
    }

    $kategori_pesan = $saran['kategori'];

    // ======================================
    // IDENTITAS SUPER ADMIN
    // ======================================

    $deleted_role = 'superadmin';
    $deleted_kategori = 'Admin Utama';

    // ======================================
    // SOFT DELETE PESAN
    // ======================================

    $stmt = prepareQuery($conn, "
        UPDATE saran
        SET
            is_deleted = 1,
            deleted_by = ?,
            deleted_role = ?,
            deleted_kategori = ?,
            deleted_at = NOW()
        WHERE id = ?
    ");

    $stmt->bind_param(
        "sssi",
        $username,
        $deleted_role,
        $deleted_kategori,
        $saran_id
    );

    if (!$stmt->execute()) {
        throw new Exception(
            "Gagal menghapus pesan: " . $stmt->error
        );
    }

    $stmt->close();

    // ======================================
    // CARI ADMIN TERKAIT
    // ======================================

    $stmt_admin = prepareQuery($conn, "
        SELECT id
        FROM users
        WHERE role = 'superadmin'
           OR (
                role = 'admin'
                AND kategori_penulis = ?
           )
    ");

    $stmt_admin->bind_param("s", $kategori_pesan);

    if (!$stmt_admin->execute()) {
        throw new Exception(
            "Gagal mencari admin: " . $stmt_admin->error
        );
    }

    $result_admin = $stmt_admin->get_result();

    // ======================================
    // SIAPKAN NOTIFIKASI
    // ======================================

    $nama_penghapus = "Super Admin ($username)";

    $isi_notifikasi =
        "Pesan kategori penulis $kategori_pesan dihapus oleh $nama_penghapus.";

    $stmt_notif = prepareQuery($conn, "
        INSERT INTO notifikasi_admin
            (user_id, saran_id, pesan_notifikasi)
        VALUES (?, ?, ?)
    ");

    // ======================================
    // KIRIM NOTIFIKASI KE ADMIN
    // ======================================

    while ($admin = $result_admin->fetch_assoc()) {

        $user_id = (int) $admin['id'];

        $stmt_notif->bind_param(
            "iis",
            $user_id,
            $saran_id,
            $isi_notifikasi
        );

        if (!$stmt_notif->execute()) {
            throw new Exception(
                "Gagal menyimpan notifikasi: " . $stmt_notif->error
            );
        }
    }

    $stmt_notif->close();
    $stmt_admin->close();

    // ======================================
    // SIMPAN PERUBAHAN
    // ======================================

    if (!$conn->commit()) {
        throw new Exception(
            "Gagal menyimpan transaksi: " . $conn->error
        );
    }

    // ======================================
    // KEMBALI KE ADMIN.PHP
    // ======================================

    header(
        "Location: admin.php?page=" . $current_page .
        "&search=" . urlencode($search) .
        "&kategori=" . urlencode($kategori_filter) .
        "&notif=deleted"
    );

    exit;

} catch (Throwable $e) {

    // Batalkan transaksi jika masih aktif
    $conn->rollback();

    die(
        "Gagal menghapus pesan: " .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}
