<?php
session_start();
require_once 'config.php';
require_once 'sanitasi_artikel.php';

$is_admin_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

$article_id = (int) ($_GET['id'] ?? 0);
if ($article_id <= 0) {
    http_response_code(404);
    exit('Tulisan tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT a.id, a.judul, a.kategori, a.isi, a.created_at,
           u.id AS author_id, u.username, u.kategori_penulis, u.foto_profil, u.asal
    FROM artikel a
    INNER JOIN users u ON u.id = a.user_id
    WHERE a.id = ? AND a.status = 'published' AND u.role = 'admin'
    LIMIT 1
");
$stmt->bind_param("i", $article_id);
$stmt->execute();
$artikel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$artikel) {
    http_response_code(404);
    exit('Tulisan tidak ditemukan atau belum dipublikasikan.');
}

// Membuat CSRF token untuk proses edit
if (
    ($_SESSION['role'] ?? '') === 'admin' &&
    empty($_SESSION['csrf_artikel'])
) {
    $_SESSION['csrf_artikel'] = bin2hex(random_bytes(32));
}

$boleh_edit = (
    isset($_SESSION['loggedin']) &&
    $_SESSION['loggedin'] === true &&
    ($_SESSION['role'] ?? '') === 'admin' &&
    strtolower(trim($_SESSION['kategori'] ?? '')) ===
    strtolower(trim($artikel['kategori']))
);

$isi_bersih = bersihkan_html_tulisan($artikel['isi']);
$teks_polos = trim(html_entity_decode(strip_tags($isi_bersih), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$jumlah_kata = preg_match_all('/\p{L}+/u', $teks_polos, $matches);
$waktu_baca = max(1, (int) ceil(($jumlah_kata ?: 1) / 200));

$url_kotak_saran = 'index.php?kategori=' . urlencode($artikel['kategori']) . '#form-saran';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($artikel['judul']); ?> | Ruang Penulis</title>
    <link rel="stylesheet" href="./assets/style.css?v=3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
.article-detail {
    max-width: 860px !important;
    margin: 0 auto;
    padding: 40px 44px 48px !important;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(23, 62, 93, 0.06);
}
.article-top-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e6eef5;
}
.article-top-nav .back-link {
    margin-bottom: 0;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.back-link:hover {
    font-size: 18px;
    color: #ffff00;
}
.article-category {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 13px;
    border-radius: 999px;
    background: #eaf5fc;
    color: #166ba5;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2px;
}
.article-detail h1 {
    font-size: clamp(28px, 4vw, 40px);
    line-height: 1.24;
    color: #173e5d;
    margin: 14px 0 18px;
    font-weight: 800;
    letter-spacing: -0.015em;
}
.article-meta-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 18px;
    margin-bottom: 28px;
    background: #f6fafe;
    border: 1px solid #e3eef7;
    border-radius: 14px;
}
.article-author-box {
    display: flex;
    align-items: center;
    gap: 12px;
}
.article-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
    background: #e1effa;
    color: #176ba5;
    display: grid;
    place-items: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}
.article-byline {
    font-size: 14px;
    color: #30485d;
    line-height: 1.45;
}
.article-byline a {
    color: #168bd0;
    font-weight: 700;
    text-decoration: none;
}
.article-byline a:hover {
    text-decoration: underline;
}
.article-submeta {
    font-size: 12px;
    color: #6b8093;
    margin-top: 2px;
}
.article-reading-info {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: #556d82;
}
.article-reading-info span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.article-body {
    font-size: 17.5px;
    line-height: 1.9;
    color: #263d51;
    overflow-wrap: anywhere;
}
.article-body > p:first-child {
    margin-top: 0;
}
.article-body p {
    margin: 0 0 18px;
}
.article-body img {
    max-width: 100% !important;
    height: auto !important;
    border-radius: 12px;
    margin: 12px 0;
    vertical-align: middle;
    box-shadow: 0 6px 20px rgba(23, 62, 93, 0.08);
}
.article-body img[style*="width: 100%"],
.article-body img[style*="width:100%"],
.article-body img[width="100%"] {
    display: block;
    margin: 18px auto;
}
.article-body blockquote {
    border-left: 4px solid #38a9e8;
    padding: 14px 20px;
    background: #f1f8fd;
    border-radius: 0 12px 12px 0;
    margin: 22px 0;
    color: #2b4c66;
    font-style: italic;
}
.article-body h2,
.article-body h3 {
    color: #174e73;
    line-height: 1.35;
    margin: 28px 0 12px;
}
.article-body h2 {
    font-size: 24px;
}
.article-body h3 {
    font-size: 20px;
}
.article-body ul,
.article-body ol {
    padding-left: 24px;
    margin: 0 0 18px;
}
.article-body li {
    margin-bottom: 8px;
}
.article-body hr {
    border: 0;
    border-top: 1px solid #e3eef7;
    margin: 26px 0;
}
.article-body a {
    color: #168bd0;
    text-underline-offset: 3px;
}
.article-feedback-cta {
    margin-top: 32px;
    padding: 20px 22px;
    border-radius: 16px;
    background: #f6fafe;
    border: 1px solid #dcebf7;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.article-feedback-cta h2 {
    margin: 0 0 4px;
    font-size: 16px;
    color: #173e5d;
    font-weight: 750;
}
.article-feedback-cta p {
    margin: 0;
    font-size: 13.5px;
    color: #5b7286;
}
.feedback-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 18px;
    border-radius: 11px;
    background: linear-gradient(135deg, #278ac7, #176ba5);
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition: transform 0.18s ease, filter 0.18s ease;
}
.feedback-btn:hover {
    transform: translateY(-1px);
    filter: brightness(0.96);
    color: #ffffff;
}
.article-footer-box {
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid #e6eef5;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 14px;
    color: #556d82;
}
.article-author-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 15px;
    border-radius: 10px;
    background: #f1f8fd;
    color: #166ba5;
    font-weight: 700;
    text-decoration: none;
}
.article-author-link:hover {
    background: #e2f2fc;
}
@media (max-width: 700px) {
    .article-detail {
        padding: 24px 20px 30px !important;
        border-radius: 14px;
    }
    .article-top-nav {
        flex-direction: column;
        align-items: flex-start;
    }
    .article-body {
        font-size: 16px;
        line-height: 1.82;
    }
    .feedback-btn {
        width: 100%;
        justify-content: center;
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
                <?php if ($is_admin_logged_in): ?>
                    <a href="admin.php" class="nav-link">
                        Daftar Saran
                    </a>

                    <a href="kelola_tulisan.php" class="nav-link">
                        Kelola Tulisan
                    </a>

                    <a href="profil_saya.php" class="nav-link">
                        Profil
                    </a>

                    <a href="logout.php" class="nav-link" onclick="return confirm('Apakah Anda yakin ingin logout?');">
                        Logout
                    </a>
                <?php else: ?>
                    <a href="index.php" class="nav-link">
                        Beranda
                    </a>

                    <a href="cek_balasan.php" class="nav-link">
                        Cek Balasan
                    </a>

                    <a href="login.php" class="nav-link">
                        Admin Login
                    </a>
                <?php endif; ?>

                <img class="unipi" src="./assets/unipi.png" alt="" srcset="" width="60px" height="60px">
            </div>
        </div>
    </nav>
        <main class="main-container">
            <article class="content-wrapper article-detail">
                <div class="article-top-nav">
                   <a class="back-link" href="javascript:history.length > 1 ? history.back() : location.href='index.php'">
                        <i class="fa-solid fa-arrow-left"></i> Kembali
                    </a>
                    <span class="article-category"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($artikel['kategori']); ?></span>
                </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap;">

            <h1 style="margin:14px 0 18px;">
                <?php echo htmlspecialchars($artikel['judul']); ?>
            </h1>

            <?php if ($boleh_edit): ?>
            <form method="POST" action="kelola_tulisan.php">
                <input type="hidden" name="aksi" value="edit">

                <input type="hidden" name="id"
                    value="<?php echo (int) $artikel['id']; ?>">

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_artikel']); ?>">

                <button type="submit" class="feedback-btn"
                        style="border:none; cursor:pointer;">
                    <i class="fa-solid fa-pen-to-square"></i>
                    Edit Tulisan
                </button>
            </form>
        <?php endif; ?>

        </div>

        <div class="article-meta-bar">
            <div class="article-author-box">
                <?php if (!empty($artikel['foto_profil'])): ?>
                    <img class="article-avatar" src="<?php echo htmlspecialchars($artikel['foto_profil']); ?>" alt="<?php echo htmlspecialchars($artikel['username']); ?>">
                <?php else: ?>
                    <div class="article-avatar"><?php echo strtoupper(mb_substr($artikel['username'], 0, 1, 'UTF-8')); ?></div>
                <?php endif; ?>
                <div>
                    <div class="article-byline">
                        Ditulis oleh <a href="profil_admin.php?id=<?php echo (int) $artikel['author_id']; ?>"><?php echo htmlspecialchars($artikel['username']); ?></a>
                    </div>
                    <div class="article-submeta">
                        <?php echo htmlspecialchars($artikel['kategori_penulis'] ?: $artikel['kategori']); ?>
                        <?php if (!empty($artikel['asal'])): ?>
                            · <i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($artikel['asal']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="article-reading-info">
                <span><i class="fa-regular fa-calendar"></i> <?php echo date('d F Y', strtotime($artikel['created_at'])); ?></span>
                <span>·</span>
                <span><i class="fa-regular fa-clock"></i> <?php echo $waktu_baca; ?> menit baca</span>
            </div>
        </div>

        <div class="article-body"><?php echo $isi_bersih; ?></div>

        <?php if (!$is_admin_logged_in): ?>
            <div class="article-feedback-cta">
                <div>
                    <h2>Punya tanggapan untuk tulisan ini?</h2>
                    <p>Kirimkan pujian atau saran secara anonim pada kategori <strong><?php echo htmlspecialchars($artikel['kategori']); ?></strong>.</p>
                </div>
                <a class="feedback-btn" href="<?php echo htmlspecialchars($url_kotak_saran); ?>">
                    <i class="fa-regular fa-paper-plane"></i> Beri Pujian atau Saran
                </a>
            </div>
        <?php endif; ?>

        <div class="article-footer-box">
            <span>Dipublikasikan pada <?php echo date('d F Y · H:i', strtotime($artikel['created_at'])); ?> WIB</span>
            <a class="article-author-link" href="profil_admin.php?id=<?php echo (int) $artikel['author_id']; ?>">
                <i class="fa-regular fa-user"></i> Lihat tulisan lain dari <?php echo htmlspecialchars($artikel['username']); ?>
            </a>
        </div>
    </article>
</main>
<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> <span class="footer-name">Muhammad Candra Wijaya</span></p>
    <span class="footer-caption">Ruang Penulis</span>
</footer>

<script>
document.querySelectorAll('.article-body img').forEach(function (img) {
    const size = img.getAttribute('data-width') || img.style.width || img.getAttribute('width');
    if (size && /^(\d+(\.\d+)?)(px|%)$/i.test(String(size).trim())) {
        const cleanSize = String(size).trim();
        img.style.setProperty('width', cleanSize, 'important');
        img.style.setProperty('height', 'auto', 'important');
        img.style.setProperty('max-width', '100%', 'important');
    }
});
</script>
</body>
</html>