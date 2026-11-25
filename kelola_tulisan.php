<?php
session_start();
require_once 'config.php';
require_once 'sanitasi_artikel.php';

if (
    !isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';
$edit_artikel = null;

if (empty($_SESSION['csrf_artikel'])) {
    $_SESSION['csrf_artikel'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_artikel'];

/* Ambil kategori dari akun admin, bukan dari input form. */
$stmt = $conn->prepare('SELECT username, kategori_penulis FROM users WHERE id = ? AND role = "admin" LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || empty($admin['kategori_penulis'])) {
    $_SESSION['flash_error'] = "Akun admin belum memiliki kategori tidak bisa mengakses halaman ini. silahkan Hubungi Super Admin.";
    header("Location: chat_admin.php?role=superadmin");
    exit;
}
$kategori_admin = $admin['kategori_penulis'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM artikel WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $user_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        header('Location: kelola_tulisan.php?deleted=' . ($deleted ? '1' : '0'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'edit') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $edit_id = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare('SELECT id, judul, isi FROM artikel WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $edit_id, $user_id);
        $stmt->execute();
        $edit_artikel = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$edit_artikel) {
            $error = 'Tulisan tidak ditemukan atau bukan milik akun Anda.';
        } else {
            $edit_artikel['isi'] = bersihkan_html_tulisan($edit_artikel['isi']);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_edit') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $judul = trim($_POST['judul'] ?? '');
        $isi_raw = trim($_POST['isi'] ?? '');
        $isi = bersihkan_html_tulisan($isi_raw);
        $plain = trim(html_entity_decode(strip_tags($isi), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($id <= 0) {
            $error = 'ID tulisan tidak valid.';
        } elseif ($judul === '' || mb_strlen($judul) > 200 || $plain === '') {
            $error = 'Judul maksimal 200 karakter dan isi tulisan wajib diisi.';
            $edit_artikel = [
                'id' => $id,
                'judul' => $judul,
                'isi' => $isi,
            ];
        } else {
            $stmt = $conn->prepare('UPDATE artikel SET judul = ?, kategori = ?, isi = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('sssii', $judul, $kategori_admin, $isi, $id, $user_id);
            if ($stmt->execute()) {
                $updated = $stmt->affected_rows;
                $stmt->close();
                header('Location: kelola_tulisan.php?updated=' . ($updated >= 0 ? '1' : '0'));
                exit;
            } else {
                $error = 'Perubahan tulisan gagal disimpan. Periksa struktur tabel artikel.';
                $stmt->close();
                $edit_artikel = [
                    'id' => $id,
                    'judul' => $judul,
                    'isi' => $isi,
                ];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'publikasikan') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $judul = trim($_POST['judul'] ?? '');
        $isi_raw = trim($_POST['isi'] ?? '');
        $isi = bersihkan_html_tulisan($isi_raw);
        $plain = trim(html_entity_decode(strip_tags($isi), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($judul === '' || mb_strlen($judul) > 200 || $plain === '') {
            $error = 'Judul maksimal 200 karakter dan isi tulisan wajib diisi.';
        } else {
            /* Kategori selalu berasal dari database akun admin yang sedang login. */
            $stmt = $conn->prepare("INSERT INTO artikel (user_id, judul, kategori, isi, status) VALUES (?, ?, ?, ?, 'published')");
            $stmt->bind_param('isss', $user_id, $judul, $kategori_admin, $isi);
            if ($stmt->execute()) {
                $stmt->close();
                header('Location: kelola_tulisan.php?published=1');
                exit;
            } else {
                $error = 'Tulisan gagal disimpan. Periksa struktur tabel artikel.';
                $stmt->close();
            }
        }
    }
}

if (isset($_GET['published']))
    $success = 'Tulisan berhasil dipublikasikan.';
if (isset($_GET['updated']) && $_GET['updated'] === '1')
    $success = 'Perubahan tulisan berhasil disimpan.';
if (isset($_GET['updated']) && $_GET['updated'] === '0')
    $error = 'Perubahan tulisan gagal disimpan atau tulisan tidak ditemukan.';
if (isset($_GET['deleted']) && $_GET['deleted'] === '1')
    $success = 'Tulisan berhasil dihapus.';
if (isset($_GET['deleted']) && $_GET['deleted'] === '0')
    $error = 'Tulisan tidak ditemukan atau bukan milik akun Anda.';

$stmt = $conn->prepare('SELECT id, judul, kategori, created_at FROM artikel WHERE user_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$artikel = $stmt->get_result();

$is_editing = !empty($edit_artikel);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Tulisan | Ruang Penulis</title>
    <link rel="stylesheet" href="./assets/style.css?v=6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --ink: #18344b;
            --muted: #718398;
            --line: #e4edf5;
            --blue: #247fbd;
            --blue2: #155e91
        }

        body {
            background: #64afe1;
            color: var(--ink)
        }
        p {
            color: white;
        }

        .navbar {
            background: #3c96d2;
            border-bottom: 1px solid var(--line)
        }

        .nav-brand {
            color: #ffffff
        }

        .nav-brand:hover {
            transition: 0.1s;
            color: #d5e2f3;
        }

        .nav-link {
            color: #ffffff !important
        }

        .nav-link.active,
        .nav-link:hover {
            background: #eaf4fc !important;
            color: #176da7 !important
        }

        .writer-wrap {
            max-width: 1120px;
            margin: 0 auto;
            padding: 34px 22px 70px
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 18px;
            margin-bottom: 24px
        }

        .page-heading h1 {
            font-size: 30px;
            margin: 0 0 7px;
            color: #173e5d
        }

        .page-heading p {
            margin: 0;
            color: var(--muted)
        }

        .writer-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(260px, .8fr);
            gap: 22px;
            align-items: start
        }

        .panel {
            background: #b8dff9;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 8px 28px rgba(32, 73, 105, .055);
            overflow: hidden
        }

        .panel-head {
            padding: 20px 24px;
            border-bottom: 1px solid var(--line)
        }

        .panel-head h2 {
            font-size: 18px;
            margin: 0 0 5px;
            color: #1b4869
        }

        .panel-head p {
            font-size: 13px;
            color: var(--muted);
            margin: 0
        }

        .editor-content {
            padding: 23px 24px
        }

        .field {
            margin-bottom: 19px
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #38556d;
            margin-bottom: 8px
        }

        .title-input {
            width: 100%;
            border: 1px solid #d9e5ef;
            border-radius: 11px;
            padding: 14px 15px;
            font-size: 18px;
            font-weight: 650;
            color: #18344b;
            outline: none
        }

        .title-input:focus {
            border-color: #70b5e2;
            box-shadow: 0 0 0 3px #e8f4fc
        }

        .category-lock {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #d7e9f7;
            background: #f2f8fd;
            border-radius: 11px;
            padding: 12px 14px;
            color: #1c6699;
            font-weight: 700
        }

        .category-lock small {
            display: block;
            color: #718398;
            font-weight: 400;
            font-size: 11px;
            margin-top: 3px
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding: 9px;
            background: #f5f8fb;
            border: 1px solid #dce6ef;
            border-bottom: 0;
            border-radius: 12px 12px 0 0
        }

        .tool-group {
            display: flex;
            gap: 4px;
            padding-right: 7px;
            margin-right: 3px;
            border-right: 1px solid #dbe4ec
        }

        .tool-group:last-child {
            border: 0
        }

        .tool-btn,
        .tool-select {
            height: 34px;
            min-width: 34px;
            border: 1px solid transparent;
            border-radius: 7px;
            background: transparent;
            color: #38556d;
            cursor: pointer;
            padding: 0 9px
        }

        .tool-btn:hover,
        .tool-select:hover {
            background: #e5f1fa;
            color: #176da7
        }

        .tool-select {
            border-color: #dce6ef;
            background: #fff;
            font-size: 12px
        }

        .editor-box {
            position: relative
        }

        .editor-area {
            background: #e6f1ff;
            min-height: 340px;
            padding: 18px 20px;
            border: 1px solid #dce6ef;
            border-radius: 0 0 12px 12px;
            outline: none;
            line-height: 1.8;
            font-size: 15px;
            color: #090909;
            overflow-wrap: anywhere
        }

        .editor-area:focus {
            border-color: #70b5e2;
            box-shadow: 0 0 0 3px #e8f4fc
        }

        .editor-area:empty:before {
            content: attr(data-placeholder);
            color: #9aaaba;
            pointer-events: none
        }

        .editor-area img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            cursor: pointer;
            transition: box-shadow .15s ease
        }

        .editor-area img.selected-img {
            outline: 3px solid #247fbd;
            box-shadow: 0 6px 18px rgba(36, 127, 189, .22)
        }

        .img-resizer-bar {
            display: none;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            position: absolute;
            z-index: 20;
            background: #173e5d;
            color: #fff;
            padding: 7px 10px;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 39, 59, .24);
            font-size: 12px
        }

        .img-resizer-bar.active {
            display: flex
        }

        .img-resizer-bar button {
            border: 1px solid rgba(255, 255, 255, .22);
            background: rgba(255, 255, 255, .1);
            color: #fff;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer
        }

        .img-resizer-bar button:hover {
            background: #247fbd;
            border-color: #247fbd
        }

        .img-resizer-bar button.danger-btn:hover {
            background: #c34b4b;
            border-color: #c34b4b
        }

        .editor-area blockquote {
            border-left: 4px solid #76b7df;
            margin: 14px 0;
            padding: 8px 15px;
            background: #f4f9fd;
            color: #526b80
        }

        .editor-area h2,
        .editor-area h3 {
            color: #17466b
        }

        .editor-hint {
            font-size: 14px;
            color: #000000;
            margin: 8px 0 0
        }

        .action-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            align-items: center;
            margin-top: 20px
        }

        .cancel-edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #d5e2ed;
            border-radius: 10px;
            background: #f7fafc;
            color: #486278;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer
        }

        .cancel-edit-btn:hover {
            background: #eaf1f7
        }

        .publish-btn {
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, #278ac7, #176ba5);
            color: #fff;
            padding: 12px 19px;
            font-weight: 700;
            cursor: pointer
        }

        .publish-btn:hover {
            filter: brightness(.96);
            transform: translateY(-1px)
        }

        .category-card {
            padding: 20px
        }

        .category-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            background: #eaf5fc;
            color: #237eb8;
            border-radius: 12px;
            font-size: 18px;
            margin-bottom: 13px
        }

        .category-card h3 {
            margin: 0 0 6px;
            font-size: 16px
        }

        .category-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6
        }

        .tips {
            margin-top: 15px;
            padding: 17px 19px;
            background: #b8dff9;
            border: 1px solid var(--line);
            border-radius: 13px
        }

        .tips h3 {
            font-size: 13px;
            margin: 0 0 10px
        }

        .tips ul {
            margin: 0;
            padding-left: 18px;
            color: #718398;
            font-size: 12px;
            line-height: 1.9
        }

        .notice {
            padding: 12px 15px;
            border-radius: 10px;
            margin: 0 0 18px;
            font-size: 14px
        }

        .notice.success {
            background: #eaf8f0;
            color: #187344
        }

        .notice.error {
            background: #fff0ef;
            color: #b43a32
        }

        .works-panel {
            margin-top: 23px
        }

        .works-list {
            background: #e6f1ff;
            padding: 5px 22px 18px
        }

        .work-row {
            background: #e6f1ff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 16px 2px;
            border: 1px solid #dce6ef;
            border-radius: 10px;
        }

        .work-row:last-child {
            border-bottom: 0
        }

        .work-row h3 {
            font-size: 15px;
            margin: 0 0 6px;
            color: #254963
        }

        .work-meta {
            font-size: 12px;
            color: #8796a5
        }

        .work-tag {
            display: inline-block;
            background: #edf6fc;
            color: #2879a9;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-right: 7px
        }

        .work-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            white-space: nowrap
        }

        .work-actions a,
        .work-actions .edit-link-btn {
            background: none;
            border: 0;
            padding: 0;
            font-size: 12px;
            text-decoration: none;
            color: #247fbd;
            font-weight: 700;
            cursor: pointer
        }

        .work-actions a:hover,
        .work-actions .edit-link-btn:hover {
            background: none;
            text-decoration: underline
        }

        .work-actions button.delete-btn {
            background: none;
            border: 0;
            padding: 0;
            color: #c34b4b;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer
        }

        .work-actions button.delete-btn:hover {
            text-decoration: underline
        }

        .empty-state {
            text-align: center;
            color: #8393a2;
            padding: 30px 10px
        }

        @media(max-width:850px) {
            .writer-grid {
                grid-template-columns: 1fr
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column
            }
        }

        @media(max-width:560px) {
            .writer-wrap {
                padding: 23px 13px 45px
            }

            .editor-content {
                padding: 17px 14px
            }

            .panel-head {
                padding: 17px
            }

            .work-row {
                align-items: flex-start;
                flex-direction: column
            }

            .work-actions {
                align-self: flex-end
            }

            .toolbar {
                gap: 3px
            }

            .tool-group {
                padding-right: 4px;
                margin-right: 1px
            }
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-inner">
            <a href="index.php" class="nav-brand"><span class="nav-icon"><i
                        class="fa-regular fa-comments"></i></span>Kotak Saran</a>
            <div><a href="admin.php" class="nav-link">Daftar Saran</a><a href="kelola_tulisan.php"
                    class="nav-link active">Kelola Tulisan</a>
                    <a href="chat_admin.php" class="nav-link">
                        Chat Super Admin
                    </a>
                    <a href="profil_saya.php" class="nav-link">Profil</a><a
                    href="logout.php" class="nav-link">Logout</a></div>

                <img class="unipi" src="./assets/unipi.png" alt="" srcset="" width="60px" height="60px">

        </div>
    </nav>
    <main class="writer-wrap">
        <header class="page-heading">
            <div>
                <h1 style="color: black;">Kelola Tulisan</h1>
                <p style="color: white;">Tuangkan ide dan bagikan cerita terbaikmu kepada pembaca.</p>
            </div>
        </header>
        <?php if ($success): ?>
            <div class="notice success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?>
            </div><?php endif; ?>
        <?php if ($error): ?>
            <div class="notice error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div><?php endif; ?>

        <div class="writer-grid">
            <section class="panel" id="editorPanel">
                <div class="panel-head">
                    <h2>
                        <i class="fa-solid <?php echo $is_editing ? 'fa-pen-to-square' : 'fa-feather-pointed'; ?>"></i>
                        <?php echo $is_editing ? 'Edit tulisan' : 'Tulis artikel baru'; ?>
                    </h2>
                    <p style="color: #000000; font-size: 15px;">
                        <?php echo $is_editing
                            ? 'Perbarui judul atau isi tulisan, lalu simpan perubahan.'
                            : 'Isi judul, susun tulisan, lalu publikasikan.'; ?>
                    </p>
                </div>
                <div class="editor-content">
                    <form method="POST" id="articleForm">
                        <input type="hidden" name="aksi"
                            value="<?php echo $is_editing ? 'simpan_edit' : 'publikasikan'; ?>">
                        <input type="hidden" name="id" id="editId"
                            value="<?php echo $is_editing ? (int) $edit_artikel['id'] : 0; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="isi" id="isiHidden">
                        <div class="field">
                            <label for="judul">Judul tulisan</label>
                            <input class="title-input" id="judul" name="judul" maxlength="200" required
                                value="<?php echo $is_editing ? htmlspecialchars($edit_artikel['judul']) : ''; ?>"
                                placeholder="Tuliskan judul yang menarik...">
                        </div>
                        <div class="field"><label>Kategori penulis</label>
                            <div class="category-lock"><i class="fa-solid fa-lock"></i>
                                <div><?php echo htmlspecialchars($kategori_admin); ?><small>Kategori terkunci mengikuti
                                        kategori akun admin Anda.</small></div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Isi tulisan</label>
                            <div class="toolbar" role="toolbar" aria-label="Format tulisan">
                                <div class="tool-group">
                                    <button class="tool-btn" type="button" title="Tebal" onclick="format('bold')"><i
                                            class="fa-solid fa-bold"></i></button>
                                    <button class="tool-btn" type="button" title="Miring" onclick="format('italic')"><i
                                            class="fa-solid fa-italic"></i></button>
                                    <button class="tool-btn" type="button" title="Garis bawah"
                                        onclick="format('underline')"><i class="fa-solid fa-underline"></i></button>
                                    <button class="tool-btn" type="button" title="Coret"
                                        onclick="format('strikeThrough')"><i
                                            class="fa-solid fa-strikethrough"></i></button>
                                </div>
                                <div class="tool-group">
                                    <select class="tool-select" title="Jenis teks"
                                        onchange="formatBlock(this.value);this.selectedIndex=0">
                                        <option value="">Teks normal</option>
                                        <option value="h2">Heading besar</option>
                                        <option value="h3">Heading kecil</option>
                                        <option value="blockquote">Kutipan</option>
                                        <option value="p">Paragraf</option>
                                    </select>
                                    <select class="tool-select" title="Ukuran huruf"
                                        onchange="fontSize(this.value);this.selectedIndex=0">
                                        <option value="">Ukuran</option>
                                        <option value="2">Kecil</option>
                                        <option value="3">Normal</option>
                                        <option value="4">Sedang</option>
                                        <option value="5">Besar</option>
                                        <option value="6">Sangat besar</option>
                                    </select>
                                </div>
                                <div class="tool-group">
                                    <button class="tool-btn" type="button" title="Rata kiri"
                                        onclick="format('justifyLeft')"><i class="fa-solid fa-align-left"></i></button>
                                    <button class="tool-btn" type="button" title="Tengah"
                                        onclick="format('justifyCenter')"><i
                                            class="fa-solid fa-align-center"></i></button>
                                    <button class="tool-btn" type="button" title="Rata kanan"
                                        onclick="format('justifyRight')"><i
                                            class="fa-solid fa-align-right"></i></button>
                                    <button class="tool-btn" type="button" title="Rata kiri-kanan"
                                        onclick="format('justifyFull')"><i
                                            class="fa-solid fa-align-justify"></i></button>
                                </div>
                                <div class="tool-group">
                                    <button class="tool-btn" type="button" title="Daftar bullet"
                                        onclick="format('insertUnorderedList')"><i
                                            class="fa-solid fa-list-ul"></i></button>
                                    <button class="tool-btn" type="button" title="Daftar angka"
                                        onclick="format('insertOrderedList')"><i
                                            class="fa-solid fa-list-ol"></i></button>
                                    <button class="tool-btn" type="button" title="Tambah tautan" onclick="addLink()"><i
                                            class="fa-solid fa-link"></i></button>
                                </div>
                                <div class="tool-group">
                                    <button class="tool-btn" type="button" title="Warna teks" onclick="chooseColor()"><i
                                            class="fa-solid fa-palette"></i></button>
                                    <button class="tool-btn" type="button" title="Tambah gambar"
                                        onclick="document.getElementById('imageUpload').click()"><i
                                            class="fa-regular fa-image"></i></button>
                                    <button class="tool-btn" type="button" title="Garis pemisah"
                                        onclick="format('insertHorizontalRule')"><i
                                            class="fa-solid fa-minus"></i></button>
                                    <button class="tool-btn" type="button" title="Hapus format"
                                        onclick="format('removeFormat')"><i class="fa-solid fa-eraser"></i></button>
                                </div>
                            </div>

                            <div class="editor-box">
                                <div id="imgResizerBar" class="img-resizer-bar" role="toolbar"
                                    aria-label="Ukuran gambar">
                                    <span>Ukuran:</span>
                                    <button type="button" onclick="setSelectedImageSize('25%')">25%</button>
                                    <button type="button" onclick="setSelectedImageSize('50%')">50%</button>
                                    <button type="button" onclick="setSelectedImageSize('75%')">75%</button>
                                    <button type="button" onclick="setSelectedImageSize('100%')">100%</button>
                                    <button type="button" onclick="setCustomImageSize()">Kustom</button>
                                    <button type="button" class="danger-btn" onclick="removeSelectedImage()"><i
                                            class="fa-regular fa-trash-can"></i> Hapus</button>
                                </div>
                                <div id="editor" class="editor-area" contenteditable="true"
                                    data-placeholder="Mulai menulis cerita atau artikel Anda di sini...">
                                    <?php echo $is_editing ? $edit_artikel['isi'] : ''; ?>
                                </div>
                            </div>

                            <input type="file" id="imageUpload" accept="image/jpeg,image/png,image/webp,image/gif"
                                hidden>
                            <p class="editor-hint">Kamu bisa menebalkan teks, membuat heading, daftar, tautan, mengatur
                                warna, dan menyisipkan gambar (maks. 5 MB per gambar). Klik gambar untuk mengubah
                                ukurannya.</p>
                        </div>
                        <div class="action-row">
                            <?php if ($is_editing): ?>
                                <a href="kelola_tulisan.php" class="cancel-edit-btn"><i class="fa-solid fa-xmark"></i> Batal
                                    edit</a>
                            <?php endif; ?>
                            <button class="publish-btn" type="submit">
                                <i
                                    class="fa-solid <?php echo $is_editing ? 'fa-floppy-disk' : 'fa-paper-plane'; ?>"></i>
                                <?php echo $is_editing ? 'Simpan perubahan' : 'Publikasikan tulisan'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
            <aside>
                <div class="panel category-card">
                    <div class="category-icon"><i class="fa-solid fa-book-open"></i></div>
                    <h3>Kategori akun: <?php echo htmlspecialchars($kategori_admin); ?></h3>
                    <p style="color: #000000; font-size: 15px;">Kategori ini ditentukan oleh Super Admin. Semua tulisan yang kamu publikasikan akan otomatis
                        masuk ke kategori tersebut.</p>
                </div>
                <div class="tips">
                    <h3><i class="fa-regular fa-lightbulb"></i> Tips menulis</h3>
                    <ul style="color: #000000; font-size: 14px;">
                        <li>Gunakan judul yang jelas dan menarik.</li>
                        <li>Bagi tulisan menjadi beberapa paragraf.</li>
                        <li>Tambahkan gambar yang relevan.</li>
                        <li>Periksa kembali tulisan sebelum dipublikasikan.</li>
                    </ul>
                </div>
            </aside>
        </div>

        <section class="panel works-panel">
            <div class="panel-head">
                <h2><i class="fa-solid fa-book-bookmark"></i> Tulisan saya</h2>
                <p style="color: #000000; font-size: 13px;">Daftar karya yang sudah dibuat oleh akun Anda.</p>
            </div>
            <div class="works-list">
                <?php if ($artikel->num_rows):
                    while ($a = $artikel->fetch_assoc()): ?>
                        <div class="work-row">
                            <div><span class="work-tag"><?php echo htmlspecialchars($a['kategori']); ?></span>
                                <h4><?php echo htmlspecialchars($a['judul']); ?></h4>
                                <div style="color: #000000; font-size: 13px;" class="work-meta"><?php echo date('d M Y · H:i', strtotime($a['created_at'])); ?></div>
                            </div>
                            <div class="work-actions">
                                <a href="detail_tulisan.php?id=<?php echo (int) $a['id']; ?>">Lihat tulisan <i
                                        class="fa-solid fa-arrow-up-right-from-square"></i></a>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="aksi" value="edit">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="edit-link-btn"><i class="fa-regular fa-pen-to-square"></i>
                                        Edit</button>
                                </form>
                                <form method="POST" style="display:inline"
                                    onsubmit="return confirm('Yakin ingin menghapus tulisan ini?')">
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="delete-btn"><i class="fa-regular fa-trash-can"></i>
                                        Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; else: ?>
                    <div class="empty-state"><i class="fa-regular fa-file-lines"
                            style="font-size:30px;display:block;margin-bottom:10px"></i>Belum ada tulisan. Mulai buat
                        artikel pertamamu!</div><?php endif; ?>
            </div>
        </section>
    </main>
    <script>
        const editor = document.getElementById('editor');
        const resizerBar = document.getElementById('imgResizerBar');
        let selectedImg = null;

        function focusEditor() { editor.focus(); }
        function format(command) { focusEditor(); document.execCommand(command, false, null); }
        function formatBlock(tag) { if (tag) { focusEditor(); document.execCommand('formatBlock', false, '<' + tag + '>'); } }
        function fontSize(size) { if (size) { focusEditor(); document.execCommand('fontSize', false, size); } }
        function addLink() { focusEditor(); const url = prompt('Masukkan alamat tautan (https://...)'); if (url && /^https?:\/\//i.test(url)) document.execCommand('createLink', false, url); }
        function chooseColor() { const color = prompt('Masukkan kode warna HEX (contoh #2563eb)', '#2563eb'); if (color && /^#[0-9a-f]{6}$/i.test(color)) { focusEditor(); document.execCommand('foreColor', false, color); } }

        function applySizeToImg(img, size) {
            if (!img) return;
            const cleanSize = String(size).trim();
            img.setAttribute('data-width', cleanSize);
            img.setAttribute('width', cleanSize);
            img.removeAttribute('height');
            img.style.setProperty('width', cleanSize, 'important');
            img.style.setProperty('height', 'auto', 'important');
            img.style.setProperty('max-width', '100%', 'important');
        }

        function positionResizerBar(img) {
            if (!img || !editor.contains(img)) {
                hideResizerBar();
                return;
            }
            const boxRect = editor.parentElement.getBoundingClientRect();
            const imgRect = img.getBoundingClientRect();
            const barWidth = resizerBar.offsetWidth || 260;

            let left = (imgRect.left - boxRect.left) + (imgRect.width / 2) - (barWidth / 2);
            left = Math.max(8, Math.min(left, boxRect.width - barWidth - 8));

            let top = (imgRect.top - boxRect.top) - resizerBar.offsetHeight - 8;
            if (top < 6) {
                top = (imgRect.bottom - boxRect.top) + 8;
            }

            resizerBar.style.left = left + 'px';
            resizerBar.style.top = top + 'px';
        }

        function selectImage(img) {
            if (selectedImg && selectedImg !== img) {
                selectedImg.classList.remove('selected-img');
            }
            selectedImg = img;
            selectedImg.classList.add('selected-img');
            resizerBar.classList.add('active');
            positionResizerBar(selectedImg);
        }

        function hideResizerBar() {
            if (selectedImg) {
                selectedImg.classList.remove('selected-img');
                selectedImg = null;
            }
            resizerBar.classList.remove('active');
        }

        function setSelectedImageSize(size) {
            if (!selectedImg) return;
            applySizeToImg(selectedImg, size);
            requestAnimationFrame(() => positionResizerBar(selectedImg));
        }

        function setCustomImageSize() {
            if (!selectedImg) return;
            const current = selectedImg.getAttribute('data-width') || selectedImg.style.width || '50%';
            const input = prompt('Masukkan lebar gambar (contoh: 300px atau 60%)', current);
            if (!input) return;
            const trimmed = input.trim();
            if (/^\d+(px|%)$/i.test(trimmed)) {
                setSelectedImageSize(trimmed);
            } else if (/^\d+$/.test(trimmed)) {
                setSelectedImageSize(trimmed + 'px');
            } else {
                alert('Format ukuran tidak valid. Gunakan angka diikuti px atau % (contoh: 400px atau 50%).');
            }
        }

        function removeSelectedImage() {
            if (!selectedImg) return;
            selectedImg.remove();
            hideResizerBar();
        }

        editor.addEventListener('click', function (e) {
            const img = e.target.closest('img');
            if (img && editor.contains(img)) {
                selectImage(img);
            } else {
                hideResizerBar();
            }
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#editor') && !e.target.closest('#imgResizerBar')) {
                hideResizerBar();
            }
        });

        window.addEventListener('resize', function () {
            if (selectedImg) positionResizerBar(selectedImg);
        });

        document.getElementById('imageUpload').addEventListener('change', async function () {
            const file = this.files[0]; if (!file) return;
            if (file.size > 5 * 1024 * 1024) { alert('Ukuran gambar maksimal 5 MB.'); this.value = ''; return; }
            const data = new FormData(); data.append('gambar', file); data.append('csrf_token', <?php echo json_encode($csrf); ?>);
            try {
                const response = await fetch('upload_gambar_artikel.php', { method: 'POST', body: data, credentials: 'same-origin' });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Gambar gagal diunggah.');
                focusEditor();
                document.execCommand('insertImage', false, result.url);
                const insertedImgs = editor.querySelectorAll('img');
                if (insertedImgs.length) {
                    const lastImg = insertedImgs[insertedImgs.length - 1];
                    lastImg.onload = function () {
                        if (!lastImg.getAttribute('data-width')) {
                            applySizeToImg(lastImg, '75%');
                        }
                        selectImage(lastImg);
                    };
                    applySizeToImg(lastImg, '75%');
                    selectImage(lastImg);
                }
            } catch (e) { alert(e.message); }
            this.value = '';
        });

        document.getElementById('articleForm').addEventListener('submit', function (e) {
            hideResizerBar();
            editor.querySelectorAll('img').forEach(img => {
                img.classList.remove('selected-img');
                const savedSize = img.getAttribute('data-width') || img.style.width;
                if (savedSize) {
                    applySizeToImg(img, savedSize);
                }
            });
            document.getElementById('isiHidden').value = editor.innerHTML.trim();
            if (!editor.innerText.trim()) { e.preventDefault(); alert('Isi tulisan belum diisi.'); }
        });

        <?php if ($is_editing): ?>
            document.getElementById('editorPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        <?php endif; ?>
    </script>
</body>

</html>