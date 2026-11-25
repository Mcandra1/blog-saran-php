<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
function fail_upload($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] ?? '') !== 'admin')
    fail_upload('Silakan login sebagai admin.', 403);
if (!hash_equals($_SESSION['csrf_artikel'] ?? '', $_POST['csrf_token'] ?? ''))
    fail_upload('Token keamanan tidak valid. Muat ulang halaman.', 403);
if (!isset($_FILES['gambar']) || $_FILES['gambar']['error'] !== UPLOAD_ERR_OK)
    fail_upload('Pilih gambar yang valid untuk diunggah.');
$file = $_FILES['gambar'];
if ($file['size'] > 5 * 1024 * 1024)
    fail_upload('Ukuran gambar maksimal 5 MB.');
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false)
    fail_upload('Format gambar harus JPG, PNG, WEBP, atau GIF.');
$dir = __DIR__ . '/uploads/artikel';
if (!is_dir($dir) && !mkdir($dir, 0755, true))
    fail_upload('Folder upload tidak dapat dibuat.', 500);
$name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name))
    fail_upload('Gambar gagal disimpan. Periksa izin folder uploads.', 500);
echo json_encode(['success' => true, 'url' => 'uploads/artikel/' . $name]);
