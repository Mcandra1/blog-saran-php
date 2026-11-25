<?php
session_start();
require_once 'config.php';

$message = '';
$message_type = '';
$kode_unik_display = '';

$is_admin = (
    isset($_SESSION['loggedin']) &&
    $_SESSION['loggedin'] === true &&
    in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)
);

// Daftar kategori penulis ditampilkan tetap, meskipun belum ada admin yang ditugaskan.
$daftar_kategori_penulis = [
    'Diary Life',
    'Novel',
    'Anak-anak',
    'Teknologi'
];

$kategori_terpilih = trim($_GET['kategori'] ?? '');
if (!in_array($kategori_terpilih, $daftar_kategori_penulis, true)) {
    $kategori_terpilih = '';
}

$artikel_terpilih_id = (int) ($_GET['artikel_id'] ?? 0);

// Ambil daftar admin penulis (Super Admin tidak ditampilkan).
$daftar_admin = [];
$sql_admin = "
    SELECT u.id, u.username, u.kategori_penulis, u.foto_profil,
           COUNT(a.id) AS total_tulisan
    FROM users u
    LEFT JOIN artikel a
        ON a.user_id = u.id AND a.status = 'published'
    WHERE u.role = 'admin'
    GROUP BY u.id, u.username, u.kategori_penulis, u.foto_profil
    ORDER BY u.username ASC
";
$result_admin = $conn->query($sql_admin);
if ($result_admin) {
    while ($admin = $result_admin->fetch_assoc()) {
        $daftar_admin[] = $admin;
    }
}

// Ambil daftar artikel terpublikasi untuk pilihan artikel & highlight tulisan.
$artikel_per_kategori = [];
$highlight_tulisan = [];
$sql_artikel = "
    SELECT a.id, a.judul, a.kategori, a.isi, a.created_at,
           u.username AS penulis
    FROM artikel a
    INNER JOIN users u ON u.id = a.user_id
    WHERE a.status = 'published' AND u.role = 'admin'
    ORDER BY a.created_at DESC, a.id DESC
";
$result_artikel = $conn->query($sql_artikel);
if ($result_artikel) {
    while ($row_art = $result_artikel->fetch_assoc()) {
        $kat = $row_art['kategori'] ?? '';
        if ($kat !== '') {
            $artikel_per_kategori[$kat][] = [
                'id' => (int) $row_art['id'],
                'judul' => $row_art['judul'],
            ];
        }

        $ringkasan_polos = trim(html_entity_decode(strip_tags($row_art['isi'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ringkasan_polos = preg_replace('/\s+/u', ' ', $ringkasan_polos);
        if (mb_strlen($ringkasan_polos, 'UTF-8') > 110) {
            $ringkasan_polos = mb_substr($ringkasan_polos, 0, 110, 'UTF-8') . '...';
        }

        $highlight_tulisan[] = [
            'id' => (int) $row_art['id'],
            'judul' => $row_art['judul'],
            'kategori' => $kat ?: 'Umum',
            'penulis' => $row_art['penulis'] ?? 'Admin',
            'ringkasan' => $ringkasan_polos !== '' ? $ringkasan_polos : 'Baca selengkapnya tulisan terbaru dari penulis.',
            'tanggal' => !empty($row_art['created_at']) ? date('d M Y', strtotime($row_art['created_at'])) : '',
        ];
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Admin dan Super Admin tidak boleh mengirim saran
    if ($is_admin) {
        http_response_code(403);
        exit("Akses ditolak! Akun admin tidak diperbolehkan mengirim saran.");
    }

    $pesan = trim($_POST['pesan'] ?? '');
    $pesan = trim($_POST['pesan'] ?? '');
    $kategori = trim($_POST['kategori'] ?? '');
    $artikel_id_post = (int) ($_POST['artikel_id'] ?? 0);

    $judul_artikel_terpilih = '';
    if ($artikel_id_post > 0 && isset($artikel_per_kategori[$kategori])) {
        foreach ($artikel_per_kategori[$kategori] as $item_art) {
            if ((int) $item_art['id'] === $artikel_id_post) {
                $judul_artikel_terpilih = $item_art['judul'];
                break;
            }
        }
    }

    if (!empty($pesan) && in_array($kategori, $daftar_kategori_penulis, true)) {
        if ($judul_artikel_terpilih !== '' && strpos($pesan, '[Tulisan: ' . $judul_artikel_terpilih . ']') !== 0) {
            $pesan = '[Tulisan: ' . $judul_artikel_terpilih . ']' . "\n\n" . $pesan;
        }

        $kode_unik = uniqid('saran-');
        $status_awal = 'Menunggu Balasan';

        $stmt = $conn->prepare("
        INSERT INTO saran
            (pesan, kategori, kode_unik, status)
        VALUES (?, ?, ?, ?)
    ");

        $stmt->bind_param(
            "ssss",
            $pesan,
            $kategori,
            $kode_unik,
            $status_awal
        );

        if ($stmt->execute()) {
            $message = 'Pesan Anda telah berhasil dikirim. Simpan kode ini untuk melihat balasan:';
            $message_type = 'success';
            $kode_unik_display = $kode_unik;
        } else {
            $message = 'Gagal menyimpan pesan ke database.';
            $message_type = 'error';
        }

        $stmt->close();
    } else {
        $message = empty($pesan) ? 'Pesan tidak boleh kosong.' : 'Silakan pilih kategori penulis yang tersedia.';
        $message_type = 'error';
    }
    $conn->close();
}

if (isset($_SESSION['form_message'])) {
    $message = $_SESSION['form_message'];
    $message_type = $_SESSION['form_message_type'];
    $kode_unik_display = $_SESSION['form_kode_unik'] ?? '';
    unset($_SESSION['form_message']);
    unset($_SESSION['form_message_type']);
    unset($_SESSION['form_kode_unik']);
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kotak Saran, Kritik dan Aspirasi</title>
    <link rel="stylesheet" href="./assets/style.css?v=3">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        html {
            scroll-behavior: smooth;
        }

        #form-saran,
        #daftar-penulis,
        #credit {
            scroll-margin-top: 90px;
        }

        .editorial-shell {
            background-color: #3c96d2;
            max-width: 1020px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 44px;
        }

        /* Bagian Tentang Blog & Highlight Tulisan */
        .blog-about-card {
            background: #b8dcff;
            border: 1px solid #e3ecf4;
            border-radius: 22px;
            padding: 36px 40px;
            box-shadow: 0 14px 36px rgba(23, 62, 93, 0.06);
        }

        .blog-about-hero {
            display: grid;
            grid-template-columns: 1.45fr 0.85fr;
            gap: 28px;
            align-items: center;
            padding-bottom: 28px;
            border-bottom: 1px solid #ecf2f7;
        }

        .blog-about-hero h2 {
            margin: 0 0 12px;
            font-size: clamp(24px, 3.2vw, 32px);
            line-height: 1.24;
            font-weight: 800;
            color: #163b58;
            letter-spacing: -0.02em;
        }

        .blog-about-hero p {
            margin: 0 0 16px;
            font-size: 15px;
            line-height: 1.7;
            color: #000000;
        }

        .blog-about-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .blog-about-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #f3f9fe;
            border: 1px solid #dcebf7;
            color: #176ba5;
            font-size: 12.5px;
            font-weight: 600;
        }

        .blog-about-visual {
            background: #3c96d2;
            border: 1px solid #d8eaf7;
            border-radius: 18px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
        }

       .blog-about-visual img {
            width: 124px;
            height: 124px;
            object-fit: cover;
            object-position: center;
            border-radius: 50%;
            background: #ffffff;
            padding: 0;
            border: 3px solid #ffffff;
            box-shadow: 0 8px 22px rgba(23, 62, 93, 0.14);
        }

        .blog-about-visual strong {
            font-size: 14.5px;
            color: #000000;
        }

        .blog-about-visual span {
            font-size: 12.5px;
            color: #ffffff;
        }

        .highlight-marquee-wrap {
            margin-top: 26px;
        }

        .highlight-marquee-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .highlight-marquee-header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #163b58;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .highlight-marquee-header span {
            font-size: 12.5px;
            color: #6d8194;
        }

        .marquee-viewport {
            overflow: hidden;
            position: relative;
            padding: 4px 0 8px;
        }

        .marquee-track {
            display: flex;
            gap: 18px;
            width: max-content;
            animation: scrollLeftHighlight 32s linear infinite;
        }

        .marquee-viewport:hover .marquee-track {
            animation-play-state: paused;
        }

        @keyframes scrollLeftHighlight {
            0% {
                transform: translateX(0);
            }
            100% {
                transform: translateX(-50%);
            }
        }

    .highlight-slide-card {
            width: 290px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 18px 20px;
            border-radius: 16px;
            border: 3px solid #027ce8;
            background: #85aaf9;
            text-decoration: none;
            color: inherit;
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .highlight-slide-card:hover {
            transform: translateY(-4px);
            background: #ffbc51;
            border-color: #fff200;
            box-shadow: 0 14px 28px rgba(23, 62, 93, 0.09);
        }

        .highlight-slide-cat {
            display: inline-block;
            align-self: flex-start;
            padding: 3px 10px;
            border-radius: 999px;
            background: #eef6fc;
            color: #176ba5;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .highlight-slide-card h4 {
            margin: 0 0 8px;
            font-size: 15.5px;
            font-weight: 750;
            line-height: 1.38;
            color: #000000;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .highlight-slide-card p {
            margin: 0 0 14px;
            font-size: 13px;
            line-height: 1.55;
            color: #000000;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .highlight-slide-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: #176ba5;
            font-weight: 600;
            padding-top: 10px;
            border-top: 1px solid #edf3f8;
        }

        .highlight-slide-meta span:first-child {
            color: #000000;
            font-weight: 500;
        }

        .saran-editorial-card {
            background: #b8dcff;
            border: 1px solid #e3ecf4;
            border-radius: 22px;
            padding: 36px 40px;
            box-shadow: 0 14px 36px rgba(23, 62, 93, 0.06);
        }

        .editorial-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 13px;
            border-radius: 999px;
            background: #eef6fc;
            color: #176ba5;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .saran-editorial-card .content-header {
            text-align: left;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ecf2f7;
        }

        .saran-editorial-card .content-header h1 {
            font-size: clamp(26px, 3.6vw, 36px);
            line-height: 1.22;
            letter-spacing: -0.02em;
            color: #163b58;
            margin: 0 0 10px;
            font-weight: 800;
        }

        .saran-editorial-card .content-header p {
            margin: 0;
            font-size: 15px;
            line-height: 1.65;
            color: #62778a;
        }

        .saran-form-editorial .form-group {
            margin-bottom: 22px;
        }

        .saran-form-editorial label {
            display: block;
            font-size: 13.5px;
            font-weight: 700;
            color: #234561;
            margin-bottom: 8px;
            letter-spacing: 0.01em;
        }

        .saran-form-editorial select,
        .saran-form-editorial textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1.5px solid #d7e4ef;
            border-radius: 14px;
            background: #f9fcff;
            color: #1b3b54;
            font-family: inherit;
            font-size: 15px;
            line-height: 1.7;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .saran-form-editorial select:disabled {
            background: #eef4f9;
            color: #365269;
            cursor: not-allowed;
            opacity: 1;
        }

        .saran-form-editorial select {
            padding: 13px 16px;
            cursor: pointer;
        }

        .saran-form-editorial textarea {
            padding: 15px 18px;
            min-height: 190px;
            resize: vertical;
        }

        .saran-form-editorial select:focus,
        .saran-form-editorial textarea:focus {
            outline: none;
            background: #ffffff;
            border-color: #247fbd;
            box-shadow: 0 0 0 4px rgba(36, 127, 189, 0.12);
        }

        .field-lock-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 7px;
            font-size: 12.5px;
            color: #6d8194;
        }

        .unlock-btn {
            background: none;
            border: 0;
            padding: 0;
            color: #176ba5;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .unlock-btn:hover {
            text-decoration: underline;
        }

        .saran-submit-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .saran-privacy-note {
            font-size: 13px;
            color: #6d8194;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .saran-form-editorial button[type="submit"] {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 13px 26px;
            border: 0;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .saran-form-editorial button[type="submit"]:hover {
            transform: translateY(-1px);
            filter: brightness(0.97);
        }

        .saran-editorial-card .response-message {
            margin-top: 24px;
            padding: 20px 22px;
            border-radius: 15px;
            line-height: 1.65;
        }

        .saran-editorial-card .kode-unik-box {
            margin: 12px 0;
            padding: 12px 16px;
            border-radius: 11px;
            background: #ffffff;
            border: 1px dashed #8bbbe0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 15px;
            letter-spacing: 0.04em;
            display: inline-block;
        }

        .authors-editorial-section {
            background: #b8dcff;
            border: 1px solid #e3ecf4;
            border-radius: 22px;
            padding: 36px 40px 40px;
            box-shadow: 0 14px 36px rgba(23, 62, 93, 0.05);
        }

        .authors-editorial-section .authors-heading {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 1px solid #ecf2f7;
        }

        .authors-editorial-section .authors-heading h2 {
            margin: 0;
            font-size: clamp(22px, 2.8vw, 28px);
            font-weight: 800;
            letter-spacing: -0.015em;
            color: #163b58;
        }

        .authors-editorial-section .authors-heading p {
            margin: 0;
            font-size: 14.5px;
            color: #62778a;
        }



        .authors-editorial-section .authors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(225px, 1fr));
            gap: 20px;
        }

        .authors-editorial-section .author-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 14px;
            padding: 22px;
            border-radius: 18px;
            border: 3px solid #027ce8;
            background: #85aaf9;
            text-decoration: none;
            color: inherit;
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        }

        .authors-editorial-section .author-card:hover {
            transform: translateY(-4px);
            background: #ffbc51;
            border-color: #fff200;
            box-shadow: 0 14px 28px rgba(23, 62, 93, 0.09);
        }

        .authors-editorial-section .author-avatar {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            font-size: 22px;
            background: #eaf5fc;
            color: #176ba5;
            flex-shrink: 0;
        }

        .authors-editorial-section .author-card-content {
            display: flex;
            flex-direction: column;
            width: 100%;
            flex: 1;
        }

        .authors-editorial-section .author-card-content h3 {
            margin: 0 0 7px;
            font-size: 17px;
            font-weight: 750;
            color: #173e5d;
        }

        .authors-editorial-section .author-category {
            align-self: flex-start;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            background: #eef6fc;
            color: #176ba5;
            margin-bottom: 10px;
        }

        .authors-editorial-section .author-card-content p {
            margin: 0 0 14px;
            font-size: 13px;
            color: #000000;
        }

        .authors-editorial-section .author-link {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #176ba5;
        }

        .authors-editorial-section .author-card:hover .author-link i {
            transform: translateX(3px);
            transition: transform 0.18s ease;
        }

        .scroll-top-btn {
            position: fixed;
            right: 26px;
            bottom: 26px;
            width: 46px;
            height: 46px;
            border: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, #247fbd, #155e91);
            color: #ffffff;
            font-size: 17px;
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(21, 94, 145, 0.28);
            opacity: 0;
            visibility: hidden;
            transform: translateY(12px);
            transition: opacity 0.22s ease, transform 0.22s ease, visibility 0.22s ease, filter 0.2s ease;
            z-index: 99;
        }

        .scroll-top-btn.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .scroll-top-btn:hover {
            filter: brightness(0.95);
            transform: translateY(-2px);
        }

        @media (max-width: 780px) {
            .blog-about-hero {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .blog-about-card,
            .saran-editorial-card,
            .authors-editorial-section {
                padding: 24px 20px;
                border-radius: 16px;
            }

            .highlight-slide-card {
                width: 255px;
            }

            .saran-submit-row {
                flex-direction: column;
                align-items: stretch;
            }

            .saran-form-editorial button[type="submit"] {
                width: 100%;
            }

            .scroll-top-btn {
                right: 18px;
                bottom: 18px;
                width: 42px;
                height: 42px;
                font-size: 15px;
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
                <a href="cek_balasan.php" class="nav-link">Cek Balasan</a>
                <a href="#form-saran" class="nav-link">Kotak Saran</a>
                <a href="#daftar-penulis" class="nav-link">Daftar Penulis</a>
                <a href="#credit" class="nav-link">Credit By</a>

                <?php if ($is_admin): ?>

                    <a href="admin.php" class="nav-link">
                        <b>
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </b>
                    </a>

                <?php else: ?>

                    <a href="login.php" class="nav-link">
                        Login
                    </a>

                <?php endif; ?>

                <img class="unipi" src="./assets/unipi.png" alt="" srcset="" width="60px" height="60px">
            </div>
        </div>
    </nav>

    <main class="main-container">
        <div class="content-wrapper editorial-shell">
            <!-- TENTANG BLOG & HIGHLIGHT TULISAN -->
            <section class="blog-about-card" aria-label="Tentang Blog Kotak Saran">
                <div class="blog-about-hero">
                    <div>
                        <span class="editorial-kicker"><i class="fa-solid fa-book-open-reader"></i> Tentang Blog &amp; Project</span>
                        <h2>Ruang Baca &amp; Kotak Saran Karya Muhammad Candra</h2>
                        <p>
                            Blog ini merupakan project <strong>Kotak Saran, Kritik, dan Aspirasi</strong> yang dikelola dan dikembangkan oleh <strong>Muhammad Candra Wijaya</strong> sebagai wadah interaksi terbuka antara penulis dan pembaca. Pembaca dapat menelusuri berbagai tulisan berdasarkan kategori penulis, menyampaikan masukan atau apresiasi secara anonim, serta memantau balasan menggunakan kode unik.
                        </p>
                        <div class="blog-about-pills">
                            <span class="blog-about-pill"><i class="fa-solid fa-user-shield"></i> Anonim &amp; Terarah</span>
                            <span class="blog-about-pill"><i class="fa-solid fa-key"></i> Pelacakan Kode Unik</span>
                            <span class="blog-about-pill"><i class="fa-solid fa-feather"></i> Multi-Kategori Penulis</span>
                        </div>
                    </div>

                    <div class="blog-about-visual">
                        <img src="./assets/saya.jpg" alt="Ilustrasi Blog Kotak Saran Muhammad Candra"
                             onerror="this.style.display='none';">
                        <strong>Project Blog Saran</strong>
                        <span>Dikembangkan oleh Muhammad Candra Wijaya</span>
                    </div>
                </div>

                <?php if (!empty($highlight_tulisan)): ?>
                    <div class="highlight-marquee-wrap">
                        <div class="highlight-marquee-header">
                            <h3><i class="fa-solid fa-fire text-primary"></i> Highlight Tulisan Penulis</h3>
                            <span style="color: black;">Arahkan kursor untuk menjeda · Klik kartu untuk membaca</span>
                        </div>
                        <div class="marquee-viewport">
                            <div class="marquee-track">
                                <?php
                                $items_loop = count($highlight_tulisan) > 1
                                    ? array_merge($highlight_tulisan, $highlight_tulisan)
                                    : $highlight_tulisan;
                                foreach ($items_loop as $item):
                                ?>
                                    <a href="detail_tulisan.php?id=<?php echo (int) $item['id']; ?>" class="highlight-slide-card">
                                        <div>
                                            <span class="highlight-slide-cat"><?php echo htmlspecialchars($item['kategori']); ?></span>
                                            <h4><?php echo htmlspecialchars($item['judul']); ?></h4>
                                            <p><?php echo htmlspecialchars($item['ringkasan']); ?></p>
                                        </div>
                                        <div class="highlight-slide-meta">
                                            <span><i class="fa-regular fa-user"></i> <?php echo htmlspecialchars($item['penulis']); ?></span>
                                            <span>Baca <i class="fa-solid fa-arrow-right"></i></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section id="form-saran" class="saran-editorial-card" aria-label="Formulir Saran, Kritik dan Aspirasi">
                <span class="editorial-kicker"><i class="fa-regular fa-paper-plane"></i> Ruang Aspirasi Pembaca</span>
                <div class="content-header">
                    <h1>Sampaikan Saran, Kritik dan Aspirasi Anda</h1>
                    <p style="color: black;">Semua pesan yang dikirim bersifat anonim.</p>
                </div>

                
                <?php if (!$is_admin): ?>

                    <!-- FORM KHUSUS PENGUNJUNG -->

                    <form action="index.php" method="POST"
                        class="saran-form-editorial"
                        id="formSaranEditorial">

                        <input type="hidden" name="kategori" id="kategoriHidden"
                            value="<?php echo htmlspecialchars($kategori_terpilih); ?>">

                        <input type="hidden" name="artikel_id" id="artikelHidden"
                            value="<?php echo $artikel_terpilih_id > 0 ? (int) $artikel_terpilih_id : ''; ?>">

                        <div class="form-group">
                            <label for="kategori">Kategori Penulis</label>

                            <select id="kategori"
                                <?php echo $kategori_terpilih !== '' ? 'disabled' : 'required'; ?>>

                                <option value="">Pilih kategori penulis</option>

                                <?php foreach ($daftar_kategori_penulis as $kategori_penulis): ?>
                                    <option value="<?php echo htmlspecialchars($kategori_penulis); ?>"
                                        <?php echo ($kategori_terpilih === $kategori_penulis) ? 'selected' : ''; ?>>

                                        <?php echo htmlspecialchars($kategori_penulis); ?>

                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <div class="field-lock-meta" id="kategoriLockMeta"
                                style="<?php echo $kategori_terpilih !== '' ? '' : 'display:none;'; ?>">

                                <span>
                                    <i class="fa-solid fa-lock"></i>
                                    Kategori terkunci otomatis sesuai pilihan.
                                </span>

                                <button type="button" class="unlock-btn" id="unlockKategoriBtn">
                                    <i class="fa-solid fa-rotate-left"></i>
                                    Ubah kategori
                                </button>

                            </div>
                        </div>

                        <div class="form-group" id="artikelGroup"
                            style="<?php echo $kategori_terpilih !== '' ? '' : 'display:none;'; ?>">

                            <label for="artikel_id">Pilih Tulisan / Artikel</label>

                            <select id="artikel_id">
                                <option value="">-- Pilih tulisan pada kategori ini --</option>
                            </select>

                            <div class="field-lock-meta">
                                <span id="artikelHelper">
                                    Pilih tulisan tertentu yang ingin diberi saran atau pujian.
                                </span>

                                <button type="button" class="unlock-btn"
                                    id="unlockArtikelBtn" style="display:none;">

                                    <i class="fa-solid fa-lock-open"></i>
                                    Ubah artikel

                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="pesan">Pesan Anda</label>

                            <textarea id="pesan" name="pesan" rows="8" required
                                placeholder="Tuliskan saran, kritik, atau aspirasi Anda di sini..."></textarea>
                        </div>

                        <div class="saran-submit-row">

                            <span class="saran-privacy-note">
                                <i class="fa-solid fa-shield-halved"></i>
                                Identitas pengirim tidak ditampilkan secara publik
                            </span>

                            <button type="submit">
                                <i class="fa-solid fa-paper-plane"></i>
                                Kirim Pesan
                            </button>

                        </div>

                            </form>

                <?php else: ?>

                    <!-- TAMPILAN KHUSUS ADMIN -->

                    <div class="response-message" style="
                        background: #eef6fc;
                        border: 1px solid #b8dcff;
                        padding: 25px;
                        border-radius: 15px;
                        text-align: center;
                        color: #173e5d;
                        margin-top: 20px;
                    ">

                        <i class="fa-solid fa-user-shield"
                            style="font-size: 35px; margin-bottom: 12px; color: #247fbd;">
                        </i>

                        <h3 style="margin: 8px 0;">
                            Fitur Khusus Pembaca
                        </h3>

                        <p style="margin: 0; line-height: 1.7;">
                            Anda sedang login sebagai Admin.
                            Fitur pengiriman saran, kritik, dan aspirasi
                            hanya tersedia untuk pengunjung.
                        </p>

                        <p style="margin-top: 12px;">
                            Silakan kelola tulisan dan balas pesan pembaca
                            melalui halaman dashboard admin.
                        </p>

                        <a href="admin.php" style="
                            display: inline-block;
                            margin-top: 10px;
                            padding: 11px 22px;
                            background: #247fbd;
                            color: white;
                            text-decoration: none;
                            border-radius: 9px;
                            font-weight: 600;
                        ">
                            <i class="fa-solid fa-gauge-high"></i>
                            Buka Dashboard Admin
                        </a>

                    </div>

                <?php endif; ?>


                <?php if ($message): ?>
                    <div class="response-message <?php echo $message_type; ?>">
                        <p><?php echo $message; ?></p>
                        <?php if ($kode_unik_display): ?>
                            <div class="kode-unik-box"><strong><?php echo $kode_unik_display; ?></strong></div>
                            <p class="info-text">Gunakan kode di atas untuk memeriksa balasan dari admin di halaman Cek Balasan.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- DAFTAR ADMIN PENULIS -->
            <section id="daftar-penulis" class="authors-section authors-editorial-section">
                <div class="authors-heading">
                    <h2>Kenali Para Penulis</h2>
                    <p>Jelajahi profil dan karya tulisan dari para admin kami.</p>
                </div>

                <?php if (!empty($daftar_admin)): ?>
                    <div class="authors-grid">
                        <?php foreach ($daftar_admin as $penulis): ?>
                            <a class="author-card" href="profil_admin.php?id=<?php echo (int) $penulis['id']; ?>">
                                <?php if (!empty($penulis['foto_profil'])): ?>
                                    <img class="author-avatar" style="object-fit:cover"
                                        src="<?php echo htmlspecialchars($penulis['foto_profil']); ?>"
                                        alt="Foto <?php echo htmlspecialchars($penulis['username']); ?>">
                                <?php else: ?>
                                    <div class="author-avatar"><i class="fa-solid fa-user-pen"></i></div>
                                <?php endif; ?>
                                <div class="author-card-content">
                                    <h3><?php echo htmlspecialchars($penulis['username']); ?></h3>
                                    <span class="author-category">
                                        <?php echo htmlspecialchars($penulis['kategori_penulis'] ?: 'Penulis'); ?>
                                    </span>
                                    <p><?php echo (int) $penulis['total_tulisan']; ?> tulisan dipublikasikan</p>
                                    <span class="author-link">Lihat Profil <i class="fa-solid fa-arrow-right"></i></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-authors">Belum ada admin penulis yang terdaftar.</p>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Kembali ke paling atas"
        title="Kembali ke atas">
        <i class="fa-solid fa-arrow-up"></i>
    </button>

    <footer id="credit" class="site-footer">
        <p>
            &copy; <?php echo date('Y'); ?>
            <span class="footer-name">Muhammad Candra Wijaya</span>
        </p>
        <span class="footer-caption">
            Blog Saran, Kritik dan Aspirasi
        </span>
    </footer>

    <script>
        const scrollTopBtn = document.getElementById('scrollTopBtn');

        window.addEventListener('scroll', function () {
            if (window.scrollY > 260) {
                scrollTopBtn.classList.add('visible');
            } else {
                scrollTopBtn.classList.remove('visible');
            }
        });

        scrollTopBtn.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        const artikelPerKategori = <?php echo json_encode($artikel_per_kategori, JSON_UNESCAPED_UNICODE); ?>;
        const initialArtikelId = <?php echo (int) $artikel_terpilih_id; ?>;
        const kategoriSelect = document.getElementById('kategori');
        const kategoriHidden = document.getElementById('kategoriHidden');
        const kategoriLockMeta = document.getElementById('kategoriLockMeta');
        const unlockKategoriBtn = document.getElementById('unlockKategoriBtn');

        const artikelGroup = document.getElementById('artikelGroup');
        const artikelSelect = document.getElementById('artikel_id');
        const artikelHidden = document.getElementById('artikelHidden');
        const artikelHelper = document.getElementById('artikelHelper');
        const unlockArtikelBtn = document.getElementById('unlockArtikelBtn');

        const pesanTextarea = document.getElementById('pesan');
        const formSaran = document.getElementById('formSaranEditorial');

        function lockKategori(locked) {
            kategoriSelect.disabled = locked;
            kategoriSelect.required = !locked;
            kategoriLockMeta.style.display = locked ? 'flex' : 'none';
        }

        function lockArtikel(locked) {
            const hasValue = Boolean(artikelSelect.value);
            const shouldLock = locked && hasValue;
            artikelSelect.disabled = shouldLock;
            artikelHidden.value = artikelSelect.value || '';
            unlockArtikelBtn.style.display = shouldLock ? 'inline-flex' : 'none';
        }

        function renderArtikelOptions(kategori, preselectedId = 0) {
            artikelSelect.innerHTML = '';
            artikelSelect.disabled = false;
            unlockArtikelBtn.style.display = 'none';
            artikelHidden.value = '';

            if (!kategori) {
                artikelGroup.style.display = 'none';
                artikelSelect.required = false;
                return;
            }

            artikelGroup.style.display = 'block';
            const daftarArtikel = artikelPerKategori[kategori] || [];

            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = daftarArtikel.length > 0
                ? 'Pilih tulisan / artikel...'
                : 'Belum ada tulisan pada kategori ini';
            artikelSelect.appendChild(defaultOpt);

            let matchedPreselect = false;
            daftarArtikel.forEach(function (item) {
                const opt = document.createElement('option');
                opt.value = String(item.id);
                opt.textContent = item.judul;
                opt.dataset.judul = item.judul;
                if (preselectedId > 0 && Number(item.id) === Number(preselectedId)) {
                    opt.selected = true;
                    matchedPreselect = true;
                }
                artikelSelect.appendChild(opt);
            });

            artikelSelect.required = daftarArtikel.length > 0;
            artikelHelper.textContent = daftarArtikel.length > 0
                ? 'Menampilkan ' + daftarArtikel.length + ' tulisan pada kategori "' + kategori + '".'
                : 'Tidak ada tulisan terpublikasi pada kategori "' + kategori + '"; saran tetap dapat dikirim.';

            if (matchedPreselect) {
                lockArtikel(true);
            }
        }

        kategoriSelect.addEventListener('change', function () {
            kategoriHidden.value = this.value;
            renderArtikelOptions(this.value, 0);
            if (this.value) {
                lockKategori(true);
            }
        });

        unlockKategoriBtn.addEventListener('click', function () {
            lockKategori(false);
            kategoriSelect.focus();
        });

        artikelSelect.addEventListener('change', function () {
            artikelHidden.value = this.value || '';
            if (this.value) {
                lockArtikel(true);
            } else {
                unlockArtikelBtn.style.display = 'none';
            }
        });

        unlockArtikelBtn.addEventListener('click', function () {
            lockArtikel(false);
            artikelSelect.focus();
        });

        formSaran.addEventListener('submit', function () {
            kategoriHidden.value = kategoriHidden.value || kategoriSelect.value;
            artikelHidden.value = artikelHidden.value || artikelSelect.value;

            const selectedOption = artikelSelect.options[artikelSelect.selectedIndex];
            const judulArtikel = selectedOption && selectedOption.dataset ? selectedOption.dataset.judul : '';
            const isiPesan = pesanTextarea.value.trim();

            if (judulArtikel && isiPesan && !isiPesan.startsWith('[Tulisan: ')) {
                pesanTextarea.value = '[Tulisan: ' + judulArtikel + ']\n\n' + isiPesan;
            }
        });

        const kategoriAwal = kategoriHidden.value || kategoriSelect.value;
        if (kategoriAwal) {
            kategoriSelect.value = kategoriAwal;
            renderArtikelOptions(kategoriAwal, initialArtikelId);
            lockKategori(true);
        }
    </script>
</body>

</html>