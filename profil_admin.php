<?php
session_start();
require_once 'config.php';

$is_admin = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

$admin_id = (int) ($_GET['id'] ?? 0);
if ($admin_id <= 0) {
    http_response_code(404);
    exit('Profil penulis tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT id, username, kategori_penulis, asal, no_hp, foto_profil
    FROM users
    WHERE id = ? AND role = 'admin'
    LIMIT 1
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$penulis = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$penulis) {
    http_response_code(404);
    exit('Profil penulis tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT id, judul, kategori, isi, created_at
    FROM artikel
    WHERE user_id = ? AND status = 'published'
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$tulisan = $stmt->get_result();
$total_tulisan = $tulisan->num_rows;

$kategori_penulis_label = $penulis['kategori_penulis'] ?: 'Penulis';
$url_saran_kategori = 'index.php?kategori=' . urlencode($kategori_penulis_label) . '#form-saran';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil <?php echo htmlspecialchars($penulis['username']); ?> | Ruang Penulis</title>
    <link rel="stylesheet" href="./assets/style.css?v=3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        html {
            scroll-behavior: smooth;
        }

        .profile-editorial-shell {
            background-color: #58a7f1;
            max-width: 1020px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .profile-top-bar {
            
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .profile-top-bar .back-link {
            color: white;
            margin-bottom: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .profile-top-bar:hover .back-link {
            color: yellow;
            font-size: 17px;
        }

        .profile-hero-card {
            background: #89bafe;
            border: 1px solid #e3ecf4;
            border-radius: 22px;
            padding: 36px 40px;
            box-shadow: 0 14px 36px rgba(23, 62, 93, 0.06);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
        }

        .profile-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 13px;
            border-radius: 999px;
            background: #aa00ff;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .profile-avatar-frame {
            width: 112px;
            height: 112px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #e9f3fb;
            box-shadow: 0 10px 24px rgba(23, 62, 93, 0.12);
            background: #a5dcfe;
            color: #176ba5;
            display: grid;
            place-items: center;
            font-size: 38px;
            margin-top: 4px;
        }

        .profile-avatar-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
        }

        .profile-hero-card h1 {
            margin: 4px 0 2px;
            font-size: clamp(26px, 3.5vw, 34px);
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #163b58;
        }

        .profile-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 999px;
            background: #eef6fc;
            color: #176ba5;
            font-size: 12.5px;
            font-weight: 700;
        }

        .profile-meta-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px 20px;
            margin-top: 8px;
            font-size: 14px;
            color: #556d82;
        }

        .profile-meta-item {
            color: black;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .profile-meta-item i {
            color: #000000;
        }

        .profile-stats-strip {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-top: 12px;
            padding-top: 18px;
            border-top: 1px solid #ecf2f7;
            width: 100%;
        }

        .profile-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 12px;
            background: #f6fafe;
            border: 1px solid #e1edf7;
            font-size: 13.5px;
            font-weight: 700;
            color: #000000;
        }

        .profile-saran-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            background: linear-gradient(135deg, #247fbd, #155e91);
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, filter 0.18s ease;
        }

        .profile-saran-btn:hover {
            transform: translateY(-1px);
            filter: brightness(0.96);
            color: #ffffff;
        }

        .works-editorial-card {
            background: #89bafe;
            border: 1px solid #e3ecf4;
            border-radius: 22px;
            padding: 34px 40px 38px;
            box-shadow: 0 14px 36px rgba(23, 62, 93, 0.05);
        }

        .works-editorial-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid #ecf2f7;
        }

        .works-editorial-header h2 {
            margin: 0 0 4px;
            font-size: clamp(21px, 2.8vw, 27px);
            font-weight: 800;
            letter-spacing: -0.015em;
            color: #163b58;
        }

        .works-editorial-header p {
            margin: 0;
            font-size: 14px;
            color: #000000;
        }

        .works-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .work-item-card {
            display: flex;
            flex-direction: column;
            padding: 22px;
            border-radius: 18px;
            border: 1px solid #e4edf5;
            background: #b479fb;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .work-item-card:hover {
            transform: translateY(-4px);
            border-color: #9ecbeb;
            box-shadow: 0 14px 28px rgba(23, 62, 93, 0.09);
        }

        .work-item-cat {
            align-self: flex-start;
            padding: 4px 11px;
            border-radius: 999px;
            background: #eef6fc;
            color: #176ba5;
            font-size: 11.5px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .work-item-card h3 {
            margin: 0 0 10px;
            font-size: 17px;
            font-weight: 750;
            line-height: 1.38;
            color: #173e5d;
        }

        .work-item-excerpt {
            margin: 0 0 18px;
            font-size: 13.5px;
            line-height: 1.65;
            color: #000000;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .work-item-footer {
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #edf3f8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 12.5px;
            color: #000000;
        }

        .work-read-more {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            color: #176ba5;
        }

        .work-item-card:hover .work-read-more {
            color: #d9ff03;
        }

        .work-item-card:hover .work-read-more i {
            color: yellow;
            transform: translateX(3px);
            transition: transform 0.18s ease;
        }

        .empty-works-box {
            text-align: center;
            padding: 42px 24px;
            border-radius: 16px;
            background: #f8fbfe;
            border: 1px dashed #d5e4f0;
            color: #62778a;
            font-size: 14.5px;
        }

        @media (max-width: 700px) {
            .profile-hero-card,
            .works-editorial-card {
                padding: 24px 20px;
                border-radius: 16px;
            }

            .profile-stats-strip {
                flex-direction: column;
                align-items: stretch;
            }

            .profile-stat-pill,
            .profile-saran-btn {
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
                Kotak Saran
            </a>

            <div>
                <a href="cek_balasan.php" class="nav-link">Cek Balasan</a>
                <a href="index.php#daftar-penulis" class="nav-link">Daftar Penulis</a>
                <a href="index.php#credit" class="nav-link">Credit By</a>

                <?php if ($is_admin): ?>
                    <a href="admin.php" class="nav-link">
                        <b><?php echo htmlspecialchars($_SESSION['username']); ?></b>
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
        <div class="content-wrapper profile-editorial-shell">
            <div class="profile-top-bar">
                <a class="back-link" href="javascript:history.length > 1 ? history.back() : location.href='index.php'">
                     <i class="fa-solid fa-arrow-left"></i> Kembali
                </a>
            </div>

            <section class="profile-hero-card" aria-label="Profil Penulis">
                <span class="profile-kicker"><i class="fa-solid fa-feather-pointed"></i> Profil Penulis</span>

                <div class="profile-avatar-frame">
                    <?php if (!empty($penulis['foto_profil'])): ?>
                        <img src="<?php echo htmlspecialchars($penulis['foto_profil']); ?>"
                             alt="Foto <?php echo htmlspecialchars($penulis['username']); ?>">
                    <?php else: ?>
                        <i class="fa-solid fa-user-pen"></i>
                    <?php endif; ?>
                </div>

                <h1><?php echo htmlspecialchars($penulis['username']); ?></h1>
                <span class="profile-role-badge">
                    <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($kategori_penulis_label); ?>
                </span>

                <?php if (!empty($penulis['asal']) || !empty($penulis['no_hp'])): ?>
                    <div class="profile-meta-list">
                        <?php if (!empty($penulis['asal'])): ?>
                            <span class="profile-meta-item">
                                <i class="fa-solid fa-location-dot"></i> Asal: <strong><?php echo htmlspecialchars($penulis['asal']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($penulis['no_hp'])): ?>
                            <span class="profile-meta-item">
                                <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($penulis['no_hp']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="profile-stats-strip">
                    <span class="profile-stat-pill">
                        <i class="fa-regular fa-newspaper text-primary"></i>
                        <?php echo (int) $total_tulisan; ?> tulisan dipublikasikan
                    </span>

                    <?php if (!$is_admin): ?>
                        <a class="profile-saran-btn" href="<?php echo htmlspecialchars($url_saran_kategori); ?>">
                            <i class="fa-regular fa-paper-plane"></i> Kirim Saran Kategori <?php echo htmlspecialchars($kategori_penulis_label); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="works-editorial-card" aria-label="Karya Tulisan Penulis">
                <div class="works-editorial-header">
                    <div>
                        <h2>Karya Tulisan</h2>
                        <p>Koleksi tulisan yang telah dipublikasikan oleh <?php echo htmlspecialchars($penulis['username']); ?>.</p>
                    </div>
                </div>

                <?php if ($total_tulisan > 0): ?>
                    <div class="works-grid">
                        <?php while ($row = $tulisan->fetch_assoc()): ?>
                            <?php
                            $ringkasan = trim(html_entity_decode(strip_tags($row['isi'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            $ringkasan = preg_replace('/\s+/u', ' ', $ringkasan);
                            if (mb_strlen($ringkasan, 'UTF-8') > 170) {
                                $ringkasan = mb_substr($ringkasan, 0, 170, 'UTF-8') . '...';
                            }
                            ?>
                            <a class="work-item-card" href="detail_tulisan.php?id=<?php echo (int) $row['id']; ?>">
                                <span class="work-item-cat"><?php echo htmlspecialchars($row['kategori']); ?></span>
                                <h3><?php echo htmlspecialchars($row['judul']); ?></h3>
                                <p class="work-item-excerpt"><?php echo htmlspecialchars($ringkasan); ?></p>
                                <div class="work-item-footer">
                                    <span><i class="fa-regular fa-calendar"></i> <?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                                    <span class="work-read-more">Baca selengkapnya <i class="fa-solid fa-arrow-right"></i></span>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-works-box">
                        <p class="empty-authors" style="margin:0;">Penulis ini belum mempublikasikan tulisan.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer id="credit" class="site-footer">
        <p>&copy; <?php echo date('Y'); ?> <span class="footer-name">Muhammad Candra Wijaya</span></p>
        <span class="footer-caption">Ruang Penulis</span>
    </footer>
</body>
</html>