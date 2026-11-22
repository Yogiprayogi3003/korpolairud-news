<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../app/config/database.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Website sedang mengalami gangguan koneksi database.');
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeArticleHtml(?string $html): string {
    $html = (string)$html;
    if (trim($html) === '') return '';

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');

    $dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="article-root">' .
        $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $root = null;
    foreach ($dom->getElementsByTagName('div') as $div) {
        if ($div->getAttribute('id') === 'article-root') {
            $root = $div;
            break;
        }
    }

    if (!$root) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return nl2br(e($html));
    }

    $allowed = [
        'p','br','strong','b','em','i','u',
        'ul','ol','li','blockquote','h2','h3','h4','a'
    ];

    $remove = [
        'script','style','iframe','object','embed',
        'form','input','button','textarea','svg','template'
    ];

    $clean = function($node) use (&$clean, $allowed, $remove) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $tag = strtolower($child->nodeName);

            if (in_array($tag, $remove, true)) {
                $node->removeChild($child);
                continue;
            }

            $clean($child);

            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = '';
            $title = '';

            if ($tag === 'a') {
                $candidate = trim($child->getAttribute('href'));
                if (preg_match('~^(https?://|mailto:|/|#|\?)~i', $candidate)) {
                    $href = $candidate;
                }
                $title = $child->getAttribute('title');
            }

            while ($child->attributes->length > 0) {
                $child->removeAttributeNode($child->attributes->item(0));
            }

            if ($tag === 'a' && $href !== '') {
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'a' && $title !== '') {
                $child->setAttribute('title', $title);
            }
        }
    };

    $clean($root);

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $dom->saveHTML($child);
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $result;
}

function setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    return (string)($stmt->fetchColumn() ?: $default);
}

function asset(string $path): string {
    if (!$path) return 'https://assets.korpolairud.com/images_default.png';
    if (preg_match('~^https?://~i', $path)) return $path;

    return 'https://assets.korpolairud.com/' . ltrim($path, '/');
}

function dateID(?string $date): string {
    if (!$date) return '';
    return date('d/m/Y', strtotime($date));
}

function excerpt(string $text, int $length = 145): string {
    $text = trim(strip_tags($text));
    return mb_strimwidth($text, 0, $length, '...', 'UTF-8');
}

$page = $_GET['page'] ?? 'home';
$search = trim($_GET['q'] ?? '');
$slug = trim($_GET['slug'] ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$siteName = setting($pdo, 'site_name', 'Korpolairud');
$siteDescription = setting($pdo, 'site_description', 'Korps Kepolisian Perairan dan Udara');

$categories = $pdo->query(
    "SELECT id,name,slug FROM categories WHERE status='active' ORDER BY name"
)->fetchAll();

$featured = $pdo->query(
    "SELECT n.*, c.name category_name
     FROM news n LEFT JOIN categories c ON c.id=n.category_id
     WHERE n.status='publish'
     ORDER BY n.created_at DESC LIMIT 1"
)->fetch();

$latest = [];
$popular = [];
$article = null;
$bulletins = [];
$currentCategory = null;
$liveStreams = [];
$liveCurrent = null;

$videos = [];
$video = null;
$contentPage = null;
$gallery = [];


try {
    if ($page === 'detail') {
        if ($slug !== '') {
            $stmt = $pdo->prepare(
                "SELECT n.*, c.name category_name
                 FROM news n LEFT JOIN categories c ON c.id=n.category_id
                 WHERE n.slug=? AND n.status='publish' LIMIT 1"
            );
            $stmt->execute([$slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT n.*, c.name category_name
                 FROM news n LEFT JOIN categories c ON c.id=n.category_id
                 WHERE n.id=? AND n.status='publish' LIMIT 1"
            );
            $stmt->execute([$id ?: 0]);
        }

        $article = $stmt->fetch();

        if ($article) {
            $pdo->prepare("UPDATE news SET views=views+1 WHERE id=?")
                ->execute([$article['id']]);

            $images = $pdo->prepare(
                "SELECT * FROM news_images WHERE news_id=? ORDER BY id"
            );
            $images->execute([$article['id']]);
            $article['gallery'] = $images->fetchAll();
        } else {
            http_response_code(404);
        }
    }

    if ($page === 'category') {
        $categoryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id=? AND status='active'");
        $stmt->execute([$categoryId]);
        $currentCategory = $stmt->fetch();
    }

    if ($page === 'search' && $search !== '') {
        $stmt = $pdo->prepare(
            "SELECT n.*, c.name category_name FROM news n
             LEFT JOIN categories c ON c.id=n.category_id
             WHERE n.status='publish' AND (n.title LIKE ? OR n.content LIKE ?)
             ORDER BY n.created_at DESC LIMIT 30"
        );
        $term = "%$search%";
        $stmt->execute([$term, $term]);
        $latest = $stmt->fetchAll();
    } elseif ($page === 'category' && $currentCategory) {
        $stmt = $pdo->prepare(
            "SELECT n.*, c.name category_name FROM news n
             LEFT JOIN categories c ON c.id=n.category_id
             WHERE n.status='publish' AND n.category_id=?
             ORDER BY n.created_at DESC LIMIT 30"
        );
        $stmt->execute([$currentCategory['id']]);
        $latest = $stmt->fetchAll();
    } elseif ($page === 'news' || $page === 'home') {
        $latest = $pdo->query(
            "SELECT n.*, c.name category_name FROM news n
             LEFT JOIN categories c ON c.id=n.category_id
             WHERE n.status='publish' ORDER BY n.created_at DESC LIMIT 12"
        )->fetchAll();
    }

    $popular = $pdo->query(
        "SELECT id,title,slug,image,views FROM news
         WHERE status='publish' ORDER BY views DESC LIMIT 5"
    )->fetchAll();

    if ($page === 'buletin') {
        $bulletins = $pdo->query(
            "SELECT b.*, c.name category_name
             FROM buletin b LEFT JOIN categories c ON c.id=b.category_id
             WHERE b.status='published'
             ORDER BY b.created_at DESC LIMIT 30"
        )->fetchAll();
    }


    // VIDEO LIST
    if ($page === 'video') {
        $videos = $pdo->query(
            "SELECT * FROM videos
             WHERE status='published'
             ORDER BY sort_order ASC, published_at DESC, id DESC
             LIMIT 60"
        )->fetchAll();
    }

    // VIDEO DETAIL
    if ($page === 'video-detail') {
        $stmt = $pdo->prepare(
            "SELECT * FROM videos
             WHERE slug=? AND status='published' LIMIT 1"
        );
        $stmt->execute([$slug]);
        $video = $stmt->fetch();

        if ($video) {
            $pdo->prepare("UPDATE videos SET views=views+1 WHERE id=?")
                ->execute([$video['id']]);
        } else {
            http_response_code(404);
        }
    }

    // DYNAMIC INSTITUTIONAL PAGES
    $pageSlugs = [
        'profil' => 'profil',
        'informasi-publik' => 'informasi-publik',
        'kontak' => 'kontak'
    ];

    if (isset($pageSlugs[$page])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM pages
             WHERE slug=? AND status='published' LIMIT 1"
        );
        $stmt->execute([$pageSlugs[$page]]);
        $contentPage = $stmt->fetch();

        if (!$contentPage) {
            http_response_code(404);
        }
    }

    // PHOTO GALLERY WITH DATABASE PAGINATION
    if ($page === 'gallery') {
        $galleryPerPage = 12;
        $galleryPage = max(1, (int)($_GET['gpage'] ?? 1));

        $galleryTotal = (int)$pdo->query(
            "SELECT COUNT(*)
             FROM news_images ni
             INNER JOIN news n ON n.id=ni.news_id
             WHERE n.status='publish'"
        )->fetchColumn();

        $galleryTotalPages = max(1, (int)ceil($galleryTotal / $galleryPerPage));
        $galleryPage = min($galleryPage, $galleryTotalPages);
        $galleryOffset = ($galleryPage - 1) * $galleryPerPage;

        $stmt = $pdo->prepare(
            "SELECT ni.id, ni.image, ni.caption, ni.news_id,
                    n.title, n.slug
             FROM news_images ni
             INNER JOIN news n ON n.id=ni.news_id
             WHERE n.status='publish'
             ORDER BY ni.created_at DESC
             LIMIT :limit OFFSET :offset"
        );

        $stmt->bindValue(':limit', $galleryPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $galleryOffset, PDO::PARAM_INT);
        $stmt->execute();

        $gallery = $stmt->fetchAll();
    }


    // LIVE STREAM QUERY
    if ($page === 'live') {
        $liveCurrent = $pdo->query(
            "SELECT * FROM live_streams
             WHERE status='live'
             ORDER BY started_at DESC, id DESC LIMIT 1"
        )->fetch();

        $liveStreams = $pdo->query(
            "SELECT * FROM live_streams
             WHERE status IN ('scheduled','ended')
             ORDER BY
               CASE WHEN status='scheduled' THEN 0 ELSE 1 END,
               scheduled_at DESC, id DESC
             LIMIT 20"
        )->fetchAll();

        if ($liveCurrent) {
            $pdo->prepare(
                "UPDATE live_streams SET views=views+1 WHERE id=?"
            )->execute([$liveCurrent['id']]);
        }
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    $latest = [];
}

$title = match ($page) {
    'news' => 'Berita Terbaru',
    'live' => 'Korpolairud Live',
    'buletin' => 'Buletin',
    'struktur' => 'Struktur Organisasi Korpolairud',
    'video' => 'Video Korpolairud',
    'video-detail' => $video['title'] ?? 'Video Tidak Ditemukan',
    'profil' => $contentPage['title'] ?? 'Profil Korpolairud',
    'gallery' => 'Galeri Foto Korpolairud',
    'informasi-publik' => $contentPage['title'] ?? 'Informasi Publik',
    'kontak' => $contentPage['title'] ?? 'Kontak Korpolairud',

    'search' => 'Hasil Pencarian',
    'category' => $currentCategory['name'] ?? 'Kategori',
    'detail' => $article['title'] ?? 'Berita Tidak Ditemukan',
    default => $siteName
};

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?=e($siteDescription)?>">
<meta name="theme-color" content="#092344">
<title><?=e($title)?> | <?=e($siteName)?></title>
<link rel="stylesheet" href="/assets/css/style.css?v=bmkg-floating-20261111-2">
</head>
<body>

<div class="topbar">
    <div class="container top-inner">
        <span>KEPOLISIAN NEGARA REPUBLIK INDONESIA</span>
        <span>Korps Kepolisian Perairan dan Udara</span>
    </div>
</div>

<header class="header">
    <div class="container nav">
        <a class="brand" href="/">
            <img src="https://assets.korpolairud.com/assets/logo.png"
                 alt="Logo Korpolairud"
                 onerror="this.style.display='none'">
            <span>
                <strong>KORPOLAIRUD</strong>
                <small>BAHARKAM POLRI</small>
            </span>
        </a>
        <button class="menu-toggle" onclick="document.querySelector('.nav-links').classList.toggle('show')">☰</button>
        <nav class="nav-links">
            <a href="/">Beranda</a>
            <a href="/?page=news">Berita</a>
            <a href="/?page=buletin">Buletin</a>
            <a href="/?page=struktur">Struktur</a>
            
            <a href="/?page=video">Video</a>
            
            <a href="/?page=gallery">Galeri</a>
            <a href="/?page=profil">Profil</a>
        </nav>
    </div>
</header>

<main>
<?php if ($page === 'home'): ?>

<section class="hero" style="background-image:linear-gradient(90deg,rgba(4,22,42,.93),rgba(7,34,61,.48)),url('<?=e(asset($featured['image'] ?? ''))?>')">
    <div class="container hero-content">
        <div class="hero-label">PORTAL RESMI INFORMASI</div>
        <h1>Korpolairud<br>Baharkam Polri</h1>
        <p>Melayani, melindungi dan menjaga keamanan wilayah perairan serta udara Indonesia.</p>
        <a href="/?page=news" class="btn-primary">Jelajahi Berita <span>→</span></a>
    </div>
</section>

<div class="container">
    <?php if ($featured): ?>
    <section class="featured">
        <div class="section-heading">
            <span>BERITA UTAMA</span>
            <h2>Informasi Pilihan</h2>
        </div>
        <a class="featured-card" href="/?page=detail&id=<?=(int)$featured['id']?>">
            <img src="<?=e(asset($featured['image']))?>" alt="<?=e($featured['title'])?>">
            <div class="featured-info">
                <span class="category-tag"><?=e($featured['category_name'] ?? 'Berita')?></span>
                <h2><?=e($featured['title'])?></h2>
                <p><?=e(excerpt($featured['excerpt'] ?: $featured['content'], 220))?></p>
                <small><?=e(dateID($featured['created_at']))?> · Baca selengkapnya →</small>
            </div>
        </a>
    </section>
    <?php endif; ?>

    <section class="section">
        <div class="section-heading heading-row">
            <div><span>UPDATE INFORMASI</span><h2>Berita Terbaru</h2></div>
            <a href="/?page=news" class="more-link">Semua berita →</a>
        </div>
        <?php include __DIR__ . '/partials/news-grid.php'; ?>
    </section>

    <section class="section" id="kategori">
        <div class="section-heading"><span>JELAJAHI INFORMASI</span><h2>Kategori Berita</h2></div>
        <div class="category-grid">
            <?php foreach ($categories as $cat): ?>
            <a class="category-box" href="/?page=category&id=<?=(int)$cat['id']?>">
                <span>◈</span><strong><?=e($cat['name'])?></strong><small>Lihat informasi →</small>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php elseif ($page === 'detail'): ?>

<div class="container content-layout">
    <article class="article">
        <?php if ($article): ?>
            <div class="breadcrumbs"><a href="/">Beranda</a> / <a href="/?page=news">Berita</a> / Detail</div>
            <span class="category-tag"><?=e($article['category_name'] ?? 'Berita')?></span>
            <h1><?=e($article['title'])?></h1>
            <div class="article-meta"><?=e(dateID($article['created_at']))?> · <?=number_format((int)$article['views']+1)?> kali dibaca</div>
            <img class="article-cover" src="<?=e(asset($article['image']))?>" alt="<?=e($article['title'])?>">
            <div class="article-content"><?=safeArticleHtml($article['content'])?></div>
            <?php if (!empty($article['gallery'])): ?>
            <h3>Galeri Kegiatan</h3>
            <div class="gallery">
                <?php foreach ($article['gallery'] as $photo): ?>
                <img src="<?=e(asset($photo['image']))?>" alt="<?=e($photo['caption'] ?? '')?>">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <h1>Berita tidak ditemukan</h1>
            <a href="/">Kembali ke beranda</a>
        <?php endif; ?>
    </article>
    <aside class="sidebar">
        <h3>Berita Populer</h3>
        <?php foreach ($popular as $item): ?>
        <a class="popular-item" href="/?page=detail&id=<?=(int)$item['id']?>">
            <img src="<?=e(asset($item['image']))?>" alt="">
            <span><?=e($item['title'])?><small><?=number_format((int)$item['views'])?> dibaca</small></span>
        </a>
        <?php endforeach; ?>
    </aside>
</div>

<?php elseif ($page === 'buletin'): ?>

<div class="container section">
    <div class="section-heading"><span>PUBLIKASI RESMI</span><h1>Buletin Korpolairud</h1></div>
    <div class="bulletin-grid">
    <?php foreach ($bulletins as $b): ?>
        <article class="bulletin-card">
            <div class="bulletin-cover">
                <?php if (!empty($b['thumbnail'])): ?>
                    <img src="<?=e(asset($b['thumbnail']))?>" alt="<?=e($b['title'])?>" loading="lazy">
                    <span class="pdf-label">PDF</span>
                <?php else: ?>
                    <span class="pdf-fallback">PDF</span>
                <?php endif; ?>
            </div>
            <div class="bulletin-info">
                <small><?=e(dateID($b['created_at']))?></small>
                <h3><?=e($b['title'])?></h3>
                <p><?=e(excerpt($b['excerpt'] ?? '', 120))?></p>
                <?php if ($b['pdf']): ?>
                <a class="btn-primary" href="<?=e(asset($b['pdf']))?>" target="_blank" rel="noopener">Buka Buletin →</a>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if (!$bulletins): ?><p>Belum ada buletin yang dipublikasikan.</p><?php endif; ?>
</div>



<?php elseif ($page === 'live'): ?>

<div class="live-page">
    <section class="live-hero">
        <div class="container">
            <div class="section-heading">
                <span>MEDIA RESMI KORPOLAIRUD</span>
                <h1>Korpolairud Live</h1>
                <p>Informasi dan siaran langsung kegiatan Korpolairud Baharkam Polri.</p>
            </div>

            <?php if ($liveCurrent): ?>
            <?php
                $liveId = $liveCurrent['youtube_id'] ?? '';
                if (!$liveId && !empty($liveCurrent['stream_url']) &&
                    preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|live/|embed/))([A-Za-z0-9_-]{11})~',
                    $liveCurrent['stream_url'], $m)) {
                    $liveId = $m[1];
                }
            ?>
            <div class="live-player-card">
                <div class="live-player">
                    <?php if ($liveId): ?>
                    <iframe
                        src="https://www.youtube-nocookie.com/embed/<?=e($liveId)?>?autoplay=0"
                        title="<?=e($liveCurrent['title'])?>"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen></iframe>
                    <?php else: ?>
                    <div class="live-placeholder">
                        <span>SIARAN LANGSUNG</span>
                        <p>URL streaming belum tersedia.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="live-player-info">
                    <span class="live-status"><i></i> LIVE NOW</span>
                    <h2><?=e($liveCurrent['title'])?></h2>
                    <p><?=e($liveCurrent['description'] ?? '')?></p>
                    <small><?=number_format((int)$liveCurrent['views'] + 1)?> tayangan</small>
                </div>
            </div>
            <?php else: ?>
            <div class="live-offline">
                <span class="offline-icon">◉</span>
                <h2>Belum Ada Siaran Langsung</h2>
                <p>Belum ada siaran yang sedang berlangsung. Silakan periksa jadwal siaran berikutnya.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="container section">
        <div class="section-heading">
            <span>INFORMASI SIARAN</span>
            <h2>Jadwal dan Arsip Live</h2>
        </div>

        <?php if ($liveStreams): ?>
        <div class="live-grid">
            <?php foreach ($liveStreams as $ls): ?>
            <article class="live-card">
                <div class="live-card-image">
                    <?php if (!empty($ls['thumbnail'])): ?>
                    <img src="<?=e(asset($ls['thumbnail']))?>" alt="<?=e($ls['title'])?>" loading="lazy">
                    <?php else: ?>
                    <div class="live-card-fallback">KORPOLAIRUD LIVE</div>
                    <?php endif; ?>
                    <span class="live-card-status <?=e($ls['status'])?>">
                        <?= $ls['status'] === 'scheduled' ? 'AKAN DATANG' : 'ARSIP LIVE' ?>
                    </span>
                </div>
                <div class="live-card-info">
                    <h3><?=e($ls['title'])?></h3>
                    <p><?=e(excerpt($ls['description'] ?? '', 130))?></p>
                    <small>
                    <?php if ($ls['status'] === 'scheduled' && $ls['scheduled_at']): ?>
                        Jadwal: <?=e(dateID($ls['scheduled_at']))?>
                        <?=e(date('H:i', strtotime($ls['scheduled_at'])))?> WIB
                    <?php else: ?>
                        <?=number_format((int)$ls['views'])?> tayangan
                    <?php endif; ?>
                    </small>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-feature">
            <h2>Belum Ada Jadwal atau Arsip</h2>
            <p>Informasi siaran akan ditampilkan di sini setelah dipublikasikan.</p>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($page === 'struktur'): ?>

<section class="container section org-page">
    <div class="section-heading">
        <span>PROFIL ORGANISASI</span>
        <h1>Struktur Organisasi Korpolairud</h1>
        <p>Korps Kepolisian Perairan dan Udara Baharkam Polri</p>
    </div>

    <div class="org-notice">
        <strong>Rujukan struktur organisasi</strong>
        <p>
            Bagan ini disajikan berdasarkan Lampiran I Peraturan Kepala
            Korpolairud Nomor 1 Tahun 2018 tentang Pertelaan Tugas di
            Lingkungan Korpolairud Baharkam Polri. Bagan ini merupakan
            rujukan historis tahun 2018 dan bukan pernyataan bahwa seluruh
            nomenklatur masih berlaku pada tahun 2026.
        </p>
    </div>

    <div class="org-chart">
        <div class="org-node org-chief">
            <small>UNSUR PIMPINAN</small>
            <strong>KAKORPOLAIRUD</strong>
            <span>Kepala Korps Kepolisian Perairan dan Udara</span>
        </div>

        <div class="org-connector"></div>
        <h2 class="org-level-label">UNSUR PEMBANTU PIMPINAN DAN PELAKSANA STAF</h2>

        <div class="org-grid">
            <div class="org-node"><strong>SIKEU</strong><span>Seksi Keuangan</span></div>
            <div class="org-node"><strong>TAUD</strong><span>Tata Usaha</span></div>
            <div class="org-node"><strong>DENMA</strong><span>Detasemen Markas</span></div>
            <div class="org-node"><strong>SIPROPAM</strong><span>Seksi Profesi dan Pengamanan</span></div>
            <div class="org-node"><strong>SIKESJAS</strong><span>Seksi Kesehatan Jasmani</span></div>
            <div class="org-node"><strong>BAGOPSNAL &amp; TIK</strong><span>Bagian Operasional dan Teknologi Informasi Komunikasi</span></div>
            <div class="org-node"><strong>BAKERMA</strong><span>Bagian Kerja Sama</span></div>
            <div class="org-node"><strong>BAGLOG</strong><span>Bagian Logistik</span></div>
            <div class="org-node"><strong>BAGRENMIN</strong><span>Bagian Perencanaan dan Administrasi</span></div>
            <div class="org-node"><strong>BAGSELBANGYAR</strong><span>Bagian Penelitian dan Pengembangan</span></div>
            <div class="org-node"><strong>BAGKATPROF</strong><span>Bagian Peningkatan Profesional</span></div>
        </div>

        <div class="org-connector"></div>
        <h2 class="org-level-label">UNSUR PELAKSANA UTAMA</h2>

        <div class="org-grid org-main-units">
            <div class="org-node org-directorate">
                <strong>DITPOLAIR</strong>
                <span>Direktorat Kepolisian Perairan</span>
                <small>Subdit Patroli Air, Intelair, Gakkum, Binmasair, dan Fasharkan</small>
            </div>
            <div class="org-node org-directorate">
                <strong>SATLAN</strong>
                <span>Satuan Pangkalan</span>
                <small>Unsur pangkalan dalam struktur rujukan</small>
            </div>
            <div class="org-node org-directorate">
                <strong>DITPOLDARA</strong>
                <span>Direktorat Kepolisian Udara</span>
                <small>Subdit Patroli Udara, Pamtersklog, Potdirga, dan Fasharkan</small>
            </div>
        </div>
    </div>

    <div class="org-source">
        <h2>Dokumen dan dasar rujukan</h2>
        <ul>
            <li>Peraturan Kepala Korpolairud Nomor 1 Tahun 2018, Lampiran I.</li>
            <li>Perpol Nomor 4 Tahun 2025, perubahan keenam SOTK Mabes Polri.</li>
        </ul>
        <p>
            Nomenklatur dan susunan organisasi setelah perubahan regulasi
            perlu diverifikasi melalui dokumen organisasi terbaru dari Polri.
            Nama pejabat tidak ditampilkan karena belum diverifikasi.
        </p>
        <p>
            Referensi bagan:
            <a href="https://elibrary.lemdiklat.polri.go.id/index.php?bid=78&amp;fid=129&amp;p=fstream-pdf"
               target="_blank" rel="noopener">Dokumen Lemdiklat Polri</a>.
        </p>
    </div>
</section>

<?php elseif ($page === 'video'): ?>

<div class="container section">
    <div class="section-heading">
        <span>MULTIMEDIA RESMI</span>
        <h1>Video Korpolairud</h1>
        <p>Dokumentasi kegiatan dan informasi visual Korpolairud Baharkam Polri.</p>
    </div>

    <?php if ($videos): ?>
    <div class="video-grid">
        <?php foreach ($videos as $v):
            $youtubeId = trim((string)($v['youtube_id'] ?? ''));

            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId)) {
                $youtubeId = '';
                $videoUrl = trim((string)($v['video_url'] ?? ''));

                if (preg_match(
                    '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*?&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})~i',
                    $videoUrl,
                    $match
                )) {
                    $youtubeId = $match[1];
                }
            }

            $customThumb = trim((string)($v['thumbnail'] ?? ''));
            $isYoutubePage = preg_match(
                '~^https?://(?:www\.)?(?:youtube\.com|youtu\.be)/~i',
                $customThumb
            );

            if ($customThumb !== '' && !$isYoutubePage) {
                $thumb = asset($customThumb);
            } elseif ($youtubeId !== '') {
                $thumb = 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg';
            } else {
                $thumb = '/assets/images/video-thumbnail-fallback.svg';
            }
        ?>
        <article class="video-card">
            <a href="/?page=video-detail&slug=<?=urlencode($v['slug'])?>">
                <div class="video-thumb">
                    <?php if ($thumb): ?>
                    <img src="<?=e($thumb)?>"
                         alt="<?=e($v['title'])?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.src='/assets/images/video-thumbnail-fallback.svg';">
                    <?php else: ?>
                    <div class="video-placeholder">KORPOLAIRUD</div>
                    <?php endif; ?>
                    <span class="play-icon">▶</span>
                </div>
                <div class="video-info">
                    <small><?=e($v['category'] ?: 'Dokumentasi')?></small>
                    <h3><?=e($v['title'])?></h3>
                    <p><?=e(excerpt($v['description'] ?? '', 120))?></p>
                    <small><?=number_format((int)$v['views'])?> tayangan</small>
                </div>
            </a>
        </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-feature">
        <span>▶</span>
        <h2>Video sedang dipersiapkan</h2>
        <p>Belum ada video yang dipublikasikan. Silakan kunjungi kembali halaman ini.</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($page === 'video-detail'): ?>

<div class="container section">
    <?php if ($video): ?>
    <?php
        $youtubeId = $video['youtube_id'] ?? '';
        if (!$youtubeId && preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~', $video['video_url'], $match)) {
            $youtubeId = $match[1];
        }
    ?>
    <div class="section-heading">
        <span>MULTIMEDIA KORPOLAIRUD</span>
        <h1><?=e($video['title'])?></h1>
    </div>
    <?php if ($youtubeId): ?>
    <div class="video-player">
        <iframe src="https://www.youtube-nocookie.com/embed/<?=e($youtubeId)?>"
            title="<?=e($video['title'])?>"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin"
            allowfullscreen></iframe>
    </div>
    <?php else: ?>
    <p>Video ini belum memiliki ID YouTube yang valid.</p>
    <?php endif; ?>
    <div class="video-description">
        <span class="category-tag"><?=e($video['category'] ?: 'Dokumentasi')?></span>
        <p><?=nl2br(e($video['description'] ?? ''))?></p>
        <small><?=number_format((int)$video['views']+1)?> tayangan</small>
    </div>
    <?php else: ?>
    <div class="empty-feature"><h2>Video tidak ditemukan</h2><a href="/?page=video">Kembali ke video</a></div>
    <?php endif; ?>
</div>

<?php elseif ($page === 'gallery'): ?>

<div class="container section gallery-page">
    <div class="section-heading">
        <span>DOKUMENTASI KEGIATAN</span>
        <h1>Galeri Korpolairud</h1>
        <p>Dokumentasi foto kegiatan dan operasi Korpolairud Baharkam Polri.</p>
    </div>

    <?php if ($gallery): ?>
    <div class="gallery-summary">
        Menampilkan <?=count($gallery)?> dari <?=number_format($galleryTotal, 0, ',', '.')?>
        dokumentasi foto
        <span>Halaman <?= (int)$galleryPage ?> dari <?= (int)$galleryTotalPages ?></span>
    </div>

    <div class="photo-grid">
        <?php foreach ($gallery as $photo): ?>
        <a class="photo-card" href="/?page=detail&id=<?=(int)$photo['news_id']?>">
            <img
                src="<?=e(asset($photo['image']))?>"
                alt="<?=e($photo['caption'] ?: $photo['title'])?>"
                loading="lazy">
            <span><?=e($photo['caption'] ?: $photo['title'])?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($galleryTotalPages > 1): ?>
    <nav class="gallery-pagination" aria-label="Navigasi halaman galeri">

        <?php if ($galleryPage > 1): ?>
        <a class="gallery-page-link gallery-prev"
           href="/?page=gallery&gpage=<?=$galleryPage - 1?>">
            &larr; Sebelumnya
        </a>
        <?php else: ?>
        <span class="gallery-page-link gallery-disabled">&larr; Sebelumnya</span>
        <?php endif; ?>

        <div class="gallery-page-numbers">
            <?php
                $firstPage = max(1, $galleryPage - 2);
                $lastPage = min($galleryTotalPages, $galleryPage + 2);
            ?>

            <?php if ($firstPage > 1): ?>
                <a href="/?page=gallery&gpage=1">1</a>
                <?php if ($firstPage > 2): ?><span>…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $firstPage; $i <= $lastPage; $i++): ?>
                <?php if ($i === $galleryPage): ?>
                    <span class="active" aria-current="page"><?=$i?></span>
                <?php else: ?>
                    <a href="/?page=gallery&gpage=<?=$i?>"><?=$i?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($lastPage < $galleryTotalPages): ?>
                <?php if ($lastPage < $galleryTotalPages - 1): ?><span>…</span><?php endif; ?>
                <a href="/?page=gallery&gpage=<?=$galleryTotalPages?>"><?=$galleryTotalPages?></a>
            <?php endif; ?>
        </div>

        <?php if ($galleryPage < $galleryTotalPages): ?>
        <a class="gallery-page-link gallery-next"
           href="/?page=gallery&gpage=<?=$galleryPage + 1?>">
            Berikutnya &rarr;
        </a>
        <?php else: ?>
        <span class="gallery-page-link gallery-disabled">Berikutnya &rarr;</span>
        <?php endif; ?>

    </nav>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-feature">
        <h2>Galeri sedang dipersiapkan</h2>
        <p>Belum ada dokumentasi foto yang tersedia.</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif (in_array($page, ['profil','informasi-publik','kontak'], true)): ?>

<div class="container section institutional-page">
    <div class="section-heading">
        <span>INFORMASI RESMI KORPOLAIRUD</span>
        <h1><?=e($contentPage['title'] ?? $title)?></h1>
    </div>
    <?php if ($contentPage): ?>
        <?php if (!empty($contentPage['featured_image'])): ?>
        <img class="institutional-cover" src="<?=e(asset($contentPage['featured_image']))?>" alt="<?=e($contentPage['title'])?>">
        <?php endif; ?>
        <?php if (!empty($contentPage['excerpt'])): ?>
        <p class="institutional-excerpt"><?=e($contentPage['excerpt'])?></p>
        <?php endif; ?>
        <div class="article-content"><?=nl2br(e($contentPage['content'] ?? ''))?></div>
    <?php else: ?>
        <div class="empty-feature">
            <h2>Informasi sedang dipersiapkan</h2>
            <p>Halaman ini belum memiliki konten publikasi resmi.</p>
        </div>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="container section">
    <div class="section-heading">
        <span>INFORMASI KORPOLAIRUD</span>
        <h1><?=e($title)?></h1>
    </div>
    <?php if ($page === 'search'): ?>
    <p>Hasil pencarian: <?=e($search)?></p>
    <?php endif; ?>
    <?php include __DIR__ . '/partials/news-grid.php'; ?>
</div>

<?php endif; ?>
</main>

<footer class="footer">
    <div class="container footer-grid">

        <div class="footer-brand">
            <div class="footer-brand-heading">
                <div class="footer-emblem">K</div>
                <div>
                    <h2>KORPOLAIRUD</h2>
                    <span>BAHARKAM POLRI</span>
                </div>
            </div>

            <p class="footer-description">
                Korps Kepolisian Perairan dan Udara
                Baharkam Kepolisian Negara Republik Indonesia.
            </p>

            <div class="footer-motto">
                <span></span>
                Profesional · Modern · Terpercaya
            </div>
        </div>

        <div class="footer-column">
            <h3>Navigasi Utama</h3>
            <a href="/">Beranda</a>
            <a href="/?page=news">Berita</a>
            <a href="/?page=buletin">Buletin</a>
            <a href="/?page=video">Video</a>
            <a href="/?page=gallery">Galeri</a>
        </div>

        <div class="footer-column">
            <h3>Informasi Institusi</h3>
            <a href="/?page=profil">Profil Korpolairud</a>
            <a href="/?page=struktur">Struktur Organisasi</a>
            <a href="/?page=informasi-publik">Informasi Publik</a>
            <a href="/?page=kontak">Kontak</a>
            <a href="/?page=live">Live Streaming</a>
        </div>

        <div class="footer-column footer-about">
            <h3>Tentang Kami</h3>
            <p><?=e($siteDescription)?></p>
            <p class="footer-institution">
                Korpolairud Baharkam Polri<br>
                Kepolisian Negara Republik Indonesia
            </p>
        </div>

    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>
                © <?=date('Y')?> <strong>Korpolairud Baharkam Polri</strong>.
                Seluruh hak cipta dilindungi.
            </p>
            <span>Website Resmi Korpolairud</span>
        </div>
    </div>
</footer>


<div class="bmkg-float" id="bmkgFloat" role="region" aria-label="Informasi gempa BMKG">
    <div class="bmkg-card">
        <div class="bmkg-head">
            <div class="bmkg-brand">
                <span class="bmkg-dot"></span>
                INFORMASI GEMPA TERKINI · BMKG
            </div>
            <button class="bmkg-close" id="bmkgClose" type="button" aria-label="Tutup">×</button>
        </div>
        <div class="bmkg-body">
            <div class="bmkg-label">MAGNITUDO</div>
            <div class="bmkg-magnitude" id="bmkgMagnitude">Memuat...</div>
            <div class="bmkg-region" id="bmkgRegion">Mengambil informasi resmi BMKG</div>
            <div class="bmkg-meta">
                <span id="bmkgDepth">Kedalaman -</span>
                <span id="bmkgTime">Waktu -</span>
            </div>
            <div class="bmkg-foot" id="bmkgPotential"></div>
        </div>
        <div class="bmkg-status" id="bmkgUpdated">Menghubungkan ke data BMKG...</div>
    </div>
</div>
<button class="bmkg-toggle" id="bmkgToggle" type="button">Info Gempa BMKG</button>
<script src="/assets/js/bmkg-floating.js" defer></script>
<script src="/assets/js/bmkg-drag.js?v=1" defer></script>

<script src="/assets/js/app.js"></script>
</body>
</html>
