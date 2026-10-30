<?php
require_once __DIR__ . '/app/bootstrap.php';

// Parse Search, Category & Sorting Input
$q    = trim((string)($_GET['q'] ?? ''));
$cat  = trim((string)($_GET['category'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'latest'));

// Dynamic Query Building with Normalized Product Cover Image JOIN
$sql = 'SELECT 
            p.*, 
            c.name category_name, 
            c.slug category_slug, 
            COALESCE(pi.image_url, p.image_url) AS cover_image 
        FROM products p 
        LEFT JOIN categories c ON c.id = p.category_id 
        LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1 
        WHERE p.active = 1';
$args = [];

if ($q !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)';
    $searchTerm = '%' . $q . '%';
    $args[] = $searchTerm;
    $args[] = $searchTerm;
    $args[] = $searchTerm;
}

if ($cat !== '' && $cat !== 'new-arrivals') {
    $sql .= ' AND c.slug = ?';
    $args[] = $cat;
}

if ($cat === 'new-arrivals') {
    $sql .= ' AND p.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)';
}

// Sorting Order Switcher
switch ($sort) {
    case 'price_low':
        $sql .= ' ORDER BY p.price ASC';
        break;
    case 'price_high':
        $sql .= ' ORDER BY p.price DESC';
        break;
    case 'popular':
        $sql .= ' ORDER BY p.stock DESC, p.id DESC';
        break;
    case 'latest':
    default:
        $sql .= ' ORDER BY p.created_at DESC';
        break;
}

$sql .= ' LIMIT 24';
$st = db()->prepare($sql);
$st->execute($args);
$products = $st->fetchAll();

// Multi-Variant Optimization: Batch load variants for all visible products
$productIds = array_column($products, 'id');
$variantDataByProduct = [];

if (!empty($productIds)) {
    $inMarks = implode(',', array_fill(0, count($productIds), '?'));
    $vStmt = db()->prepare("
        SELECT product_id, color, size, stock, additional_price 
        FROM product_variants 
        WHERE product_id IN ($inMarks)
        ORDER BY id ASC
    ");
    $vStmt->execute($productIds);
    $allVariants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allVariants as $v) {
        $pId = (int)$v['product_id'];
        if (!isset($variantDataByProduct[$pId])) {
            $variantDataByProduct[$pId] = [
                'colors'      => [],
                'sizes'       => [],
                'max_price'   => 0.0,
                'min_price'   => 0.0,
                'has_delta'   => false,
                'total_stock' => 0
            ];
        }

        $c = trim((string)($v['color'] ?? ''));
        $sz = trim((string)($v['size'] ?? ''));
        $stk = (int)$v['stock'];
        $addPrice = (float)($v['additional_price'] ?? 0);

        if ($c !== '' && !in_array($c, $variantDataByProduct[$pId]['colors'], true)) {
            $variantDataByProduct[$pId]['colors'][] = $c;
        }
        if ($sz !== '' && !in_array($sz, $variantDataByProduct[$pId]['sizes'], true)) {
            $variantDataByProduct[$pId]['sizes'][] = $sz;
        }

        if ($addPrice > 0) {
            $variantDataByProduct[$pId]['has_delta'] = true;
            if ($addPrice > $variantDataByProduct[$pId]['max_price']) {
                $variantDataByProduct[$pId]['max_price'] = $addPrice;
            }
        }
        $variantDataByProduct[$pId]['total_stock'] += $stk;
    }
}

// Dynamic Hero Banner Slides & Testimonials
$slides = db()->query('SELECT * FROM hero_slides WHERE active=1 ORDER BY sort_order ASC, id DESC')->fetchAll();
$testimonials = db()->query('SELECT * FROM testimonials WHERE active=1 ORDER BY sort_order ASC, id DESC')->fetchAll();

// Fetch Active Social Media Feed Cards
$socialFeed = db()->query('SELECT * FROM social_links WHERE active=1 ORDER BY sort_order ASC, id DESC LIMIT 8')->fetchAll();

// Categories with Active Product Count
$categories = db()->query('
    SELECT c.*, COUNT(p.id) AS product_count 
    FROM categories c 
    LEFT JOIN products p ON p.category_id = c.id AND p.active = 1 
    GROUP BY c.id 
    ORDER BY c.name
')->fetchAll();

// Store Guarantee Badges
$b1Title = site_setting('trust_badge_1_title', 'Nationwide Shipping');
$b1Sub   = site_setting('trust_badge_1_sub', 'Fast delivery across Bangladesh');
$b2Title = site_setting('trust_badge_2_title', 'Cash on Delivery');
$b2Sub   = site_setting('trust_badge_2_sub', 'Pay safely at your doorstep');
$b3Title = site_setting('trust_badge_3_title', 'Premium Fabric');
$b3Sub   = site_setting('trust_badge_3_sub', 'Crafted for long-lasting comfort');
$b4Title = site_setting('trust_badge_4_title', 'Hassle-Free Exchange');
$b4Sub   = site_setting('trust_badge_4_sub', 'Easy returns & order support');

// Dynamic DB Logo Size Setting
$dbLogoHeight = (int)site_setting('logo_height', '58');
if ($dbLogoHeight <= 0) $dbLogoHeight = 58;

// Dynamic SEO Variables
$pageTitle       = site_setting('seo_meta_title', 'Shop Modern Clothing & Wardrobe Essentials | DiverseBD');
$metaDescription = site_setting('seo_meta_description', 'Discover modern clothing, premium wardrobe staples, and new season drops with nationwide Cash on Delivery in Bangladesh.');
$metaKeywords    = site_setting('seo_meta_keywords', 'clothing, fashion, online shop, bangladesh, modern fit, wardrobe');
$ogImage         = site_setting('seo_og_image', site_setting('logo_url', ''));

$cartQty = function_exists('cart_count') ? cart_count() : 0;

require_once __DIR__ . '/app/header.php';
?>

<!-- ================= MOBILE APP-BAR (Sin Clothing Exact Proportions) ================= -->
<div class="mobile-nav-bar">
    <div class="mobile-bar-col mobile-bar-left">
        <button type="button" class="mobile-hamburger-btn" onclick="toggleMobileDrawer()" aria-label="Open Navigation Menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>

    <div class="mobile-bar-col mobile-bar-center">
        <a href="<?= e(url('index.php')) ?>" class="mobile-brand-link">
            <?php
            $logoUrl = site_setting('logo_url', '');
            if (!empty($logoUrl)):
            ?>
                <img src="<?= e(url($logoUrl)) ?>" alt="<?= e(store_name()) ?>" class="mobile-brand-img">
            <?php else: ?>
                <span class="mobile-brand-text"><?= e(store_name()) ?></span>
            <?php endif; ?>
        </a>
    </div>

    <div class="mobile-bar-col mobile-bar-right">
        <a href="<?= e(url('cart.php')) ?>" class="mobile-cart-btn" aria-label="View Shopping Bag">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <path d="M16 10a4 4 0 0 1-8 0"></path>
            </svg>
            <span class="mobile-cart-badge"><?= $cartQty ?></span>
        </a>
    </div>
</div>

<!-- Slide-out Drawer Menu -->
<div class="mobile-drawer-backdrop" id="mobileDrawerBackdrop" onclick="toggleMobileDrawer()"></div>
<aside class="mobile-drawer-panel" id="mobileDrawerPanel">
    <div class="mobile-drawer-header">
        <div class="mobile-drawer-title"><?= e(store_name()) ?></div>
        <button type="button" class="mobile-drawer-close" onclick="toggleMobileDrawer()">&times;</button>
    </div>
    <nav class="mobile-drawer-nav">
        <a href="<?= e(url('index.php')) ?>">🏠 Home</a>
        <a href="<?= e(url('index.php#shop')) ?>">👕 Shop All Collection</a>
        <a href="<?= e(url('index.php?category=new-arrivals#shop')) ?>">🔥 New Arrivals</a>
        <a href="<?= e(url('track-order.php')) ?>">📦 Track Order</a>
        <a href="<?= e(url('contact.php')) ?>">💬 Contact Us</a>
        <hr style="border:0; border-top:1px solid #f1f5f9; margin: 10px 0;">
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="<?= e(url('user_profile.php')) ?>">👤 My Account</a>
            <a href="<?= e(url('user_logout.php')) ?>">🚪 Sign Out</a>
        <?php else: ?>
            <a href="<?= e(url('user_login.php')) ?>">🔐 Customer Sign In / Register</a>
        <?php endif; ?>
    </nav>
</aside>

<script>
    function toggleMobileDrawer() {
        const backdrop = document.getElementById('mobileDrawerBackdrop');
        const panel = document.getElementById('mobileDrawerPanel');
        if (panel.classList.contains('open')) {
            panel.classList.remove('open');
            backdrop.classList.remove('open');
            document.body.style.overflow = '';
        } else {
            panel.classList.add('open');
            backdrop.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }
</script>

<style>
    :root {
        --primary-color: #25483d;
        --accent-hover: #1b352c;
        --card-radius: 12px;
        --transition-smooth: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Desktop Logo */
    header .logo img,
    nav .brand img,
    .brand-logo img,
    .site-logo,
    header img[alt*="logo" i],
    header img[src*="logo" i] {
        height: <?= $dbLogoHeight ?>px !important;
        max-height: <?= $dbLogoHeight ?>px !important;
        width: auto !important;
        object-fit: contain !important;
        display: block !important;
    }

    .mobile-nav-bar,
    .mobile-drawer-backdrop,
    .mobile-drawer-panel {
        display: none;
    }

    /* Hero Slider */
    .hero-slider-container {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        background: #f1f5f9;
    }

    .hero-slides-wrapper {
        display: flex;
        transition: transform 0.6s cubic-bezier(0.25, 1, 0.5, 1);
    }

    .hero-slide-item {
        min-width: 100%;
        padding: 70px 40px;
        background-size: cover;
        background-position: center;
        min-height: 420px;
        display: flex;
        align-items: center;
        box-sizing: border-box;
    }

    .hero-glass-card {
        max-width: 540px;
        background: rgba(255, 255, 255, 0.94);
        padding: 32px 28px;
        border-radius: 14px;
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    /* Guarantees Bar */
    .guarantees-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 35px;
    }

    .guarantee-card {
        background: #fff;
        padding: 16px;
        border-radius: 12px;
        border: 1px solid #eee;
        text-align: center;
    }

    /* Filters & Products */
    .filter-toolbar {
        background: #ffffff;
        padding: 16px 20px;
        border-radius: 14px;
        border: 1px solid var(--line, #e0e0e0);
        margin-bottom: 25px;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .search-box-wrap {
        display: flex;
        gap: 8px;
        flex: 1;
        max-width: 480px;
    }

    .search-input {
        flex: 1;
        padding: 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        transition: var(--transition-smooth);
    }

    .search-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 72, 61, 0.1);
    }

    .sort-select {
        padding: 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: #fff;
        cursor: pointer;
        outline: none;
    }

    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 22px;
    }

    .product-card {
        background: #ffffff;
        border-radius: var(--card-radius);
        border: 1px solid var(--line, #eaeaea);
        overflow: hidden;
        transition: var(--transition-smooth);
        display: flex;
        flex-direction: column;
    }

    .product-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.08);
        border-color: #d0d0d0;
    }

    .product-image-wrap {
        position: relative;
        aspect-ratio: 3/4;
        overflow: hidden;
        background: #f7f5ef;
    }

    .product-image-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.4s ease;
    }

    .product-card:hover .product-image-wrap img {
        transform: scale(1.05);
    }

    .badge-discount {
        position: absolute;
        top: 8px;
        left: 8px;
        background: #e74c3c;
        color: #fff;
        font-weight: 700;
        font-size: 10px;
        padding: 3px 7px;
        border-radius: 4px;
    }

    .badge-soldout {
        position: absolute;
        top: 8px;
        right: 8px;
        background: rgba(0, 0, 0, 0.75);
        color: #fff;
        font-size: 10px;
        padding: 3px 6px;
        border-radius: 4px;
    }

    .product-info {
        padding: 14px;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }

    .product-info h3 {
        font-size: 14px;
        margin: 0 0 6px 0;
        font-weight: 600;
        line-height: 1.35;
    }

    .product-info h3 a {
        text-decoration: none;
        color: #111;
        transition: color 0.2s;
    }

    .product-info h3 a:hover {
        color: var(--primary-color);
    }

    .variant-swatches-row {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
        margin-bottom: 6px;
    }

    .color-swatch-badge {
        font-size: 10px;
        font-weight: 600;
        color: #3730a3;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        padding: 1px 5px;
        border-radius: 3px;
    }

    .size-tags-row {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        margin-bottom: 6px;
    }

    .size-tag-badge {
        font-size: 10px;
        font-weight: 700;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        padding: 1px 5px;
        border-radius: 3px;
    }

    .price-group {
        display: flex;
        align-items: baseline;
        gap: 6px;
        margin-top: auto;
        padding-top: 8px;
    }

    .price-main {
        font-size: 15px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .price-old {
        font-size: 12px;
        text-decoration: line-through;
        color: #999;
    }

    .action-btn {
        display: block;
        width: 100%;
        text-align: center;
        margin-top: 10px;
        padding: 9px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        border: none;
        background: var(--primary-color);
        color: #ffffff;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition-smooth);
        box-sizing: border-box;
    }

    .action-btn:hover {
        background: var(--accent-hover);
    }

    .social-feed-container {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 16px;
        margin: 0 auto;
        max-width: 1150px;
    }

    .social-feed-card {
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        width: 220px;
        max-width: 100%;
        aspect-ratio: 1 / 1;
        background: #f7f7f7;
        border: 1px solid #eee;
        display: block;
        text-decoration: none;
        color: #fff;
    }

    .social-feed-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .social-feed-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(0, 0, 0, 0.1) 0%, rgba(0, 0, 0, 0.7) 100%);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 12px;
        box-sizing: border-box;
    }

    .social-badge {
        align-self: flex-start;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        color: #fff;
    }

    /* WhatsApp Review Marquee */
    .reviews-section-wrap {
        background: #eef2ed;
        margin: 50px 0 35px 0;
        padding: 50px 20px;
        border-radius: 20px;
        border: 1px solid #dce4da;
        overflow: hidden;
    }

    .reviews-header-block {
        text-align: center;
        margin-bottom: 35px;
    }

    .reviews-main-title {
        font-size: 32px;
        font-weight: 800;
        color: #17382f;
        margin: 0 0 16px 0;
        letter-spacing: -0.5px;
    }

    .reviews-badges-row {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .review-pill-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        padding: 8px 18px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 700;
        color: #1a4336;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .pill-green-dot {
        width: 8px;
        height: 8px;
        background: #10b981;
        border-radius: 50%;
    }

    .reviews-marquee-outer {
        position: relative;
        width: 100%;
        overflow: hidden;
        mask-image: linear-gradient(to right, transparent, black 8%, black 92%, transparent);
        -webkit-mask-image: linear-gradient(to right, transparent, black 8%, black 92%, transparent);
    }

    .reviews-marquee-inner {
        display: flex;
        gap: 22px;
        width: max-content;
        animation: smoothScroll 38s linear infinite;
    }

    .reviews-marquee-outer:hover .reviews-marquee-inner {
        animation-play-state: paused;
    }

    @keyframes smoothScroll {
        0% {
            transform: translate3d(0, 0, 0);
        }

        100% {
            transform: translate3d(-50%, 0, 0);
        }
    }

    .whatsapp-review-card {
        width: 320px;
        flex-shrink: 0;
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #d2ded0;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .review-card-topbar {
        background: #173f32;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #ffffff;
    }

    .review-avatar-circle {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #10b981;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 15px;
        flex-shrink: 0;
    }

    .review-user-meta strong {
        display: block;
        font-size: 14px;
        color: #ffffff;
        line-height: 1.2;
    }

    .review-user-meta span {
        font-size: 11px;
        color: #a7f3d0;
    }

    .review-card-body {
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: #fbfdfa;
        flex-grow: 1;
    }

    .review-attached-img {
        width: 100%;
        height: 220px;
        object-fit: cover;
        border-radius: 10px;
        display: block;
    }

    .chat-bubble-customer {
        background: #ffffff;
        padding: 12px 14px;
        border-radius: 0 12px 12px 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
        position: relative;
    }

    .chat-bubble-customer p {
        margin: 0 0 6px 0;
        font-size: 13px;
        line-height: 1.45;
        color: #1e293b;
    }

    .chat-timestamp {
        display: block;
        text-align: right;
        font-size: 10px;
        color: #94a3b8;
    }

    .chat-bubble-reply {
        background: #d9fdd3;
        padding: 10px 14px;
        border-radius: 12px 0 12px 12px;
        border: 1px solid #c1efb9;
        align-self: flex-end;
        max-width: 88%;
    }

    .chat-bubble-reply p {
        margin: 0;
        font-size: 12px;
        color: #166534;
        line-height: 1.4;
    }

    .review-card-footer {
        padding: 10px 16px;
        background: #f4f8f3;
        border-top: 1px solid #e7efe5;
        font-size: 11px;
        font-weight: 700;
        color: #15803d;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* ==========================================================================
       MOBILE-VIEW ADAPTATIONS (<= 768px) - SIN CLOTHING ALIGNMENT
       ========================================================================== */
    @media (max-width: 768px) {

        /* 1. HIDE DESKTOP HEADER DUPLICATE ON MOBILE */
        header,
        body>header,
        nav.navbar,
        .site-header {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
        }

        /* 2. DEDICATED MOBILE APP-BAR */
        .mobile-nav-bar {
            display: grid !important;
            grid-template-columns: 50px 1fr 50px !important;
            align-items: center !important;
            padding: 0 16px !important;
            background: #ffffff !important;
            border-bottom: 1px solid #e2e8f0 !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1000 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
            height: 56px !important;
            box-sizing: border-box !important;
        }

        .mobile-bar-col {
            display: flex !important;
            align-items: center !important;
            height: 100% !important;
        }

        .mobile-bar-left {
            justify-content: flex-start !important;
        }

        .mobile-bar-center {
            justify-content: center !important;
            overflow: visible !important;
        }

        .mobile-bar-right {
            justify-content: flex-end !important;
        }

        /* Scaled, Sleek Hamburger */
        .mobile-hamburger-btn {
            background: none !important;
            border: none !important;
            cursor: pointer !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            gap: 4px !important;
            width: 22px !important;
            height: 20px !important;
            padding: 0 !important;
        }

        .mobile-hamburger-btn span {
            display: block !important;
            height: 2px !important;
            width: 100% !important;
            background: #1e293b !important;
            border-radius: 1px !important;
        }

        /* PROMINENT, CRISP MOBILE LOGO */
        .mobile-brand-link {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-decoration: none !important;
            height: 100% !important;
            width: 100% !important;
        }

        .mobile-brand-img {
            /* Optical scaling compensates for transparent margins in PNGs */
            height: 48px !important;
            max-height: 48px !important;
            width: auto !important;
            max-width: 200px !important;
            object-fit: contain !important;
            display: block !important;
            transform: scale(1.65) !important;
            transform-origin: center center !important;
        }

        .mobile-brand-text {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            letter-spacing: 0.5px !important;
            white-space: nowrap !important;
        }

        /* Cart Icon & Badge */
        .mobile-cart-btn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            color: #1e293b !important;
            text-decoration: none !important;
            width: 24px !important;
            height: 24px !important;
        }

        .mobile-cart-btn svg {
            width: 21px !important;
            height: 21px !important;
        }

        .mobile-cart-badge {
            position: absolute !important;
            top: -5px !important;
            right: -8px !important;
            background: #000000 !important;
            color: #ffffff !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            min-width: 16px !important;
            height: 16px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 2px !important;
            border: 2px solid #ffffff !important;
        }

        /* Drawer Overlay */
        .mobile-drawer-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(3px);
            z-index: 9998;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .mobile-drawer-backdrop.open {
            opacity: 1;
            visibility: visible;
        }

        .mobile-drawer-panel {
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            max-width: 80%;
            background: #ffffff;
            z-index: 9999;
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .mobile-drawer-panel.open {
            transform: translateX(0);
        }

        .mobile-drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
        }

        .mobile-drawer-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }

        .mobile-drawer-close {
            background: none;
            border: none;
            font-size: 26px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            padding: 0;
        }

        .mobile-drawer-nav {
            padding: 15px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .mobile-drawer-nav a {
            padding: 12px 14px;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.15s ease;
        }

        .mobile-drawer-nav a:hover {
            background: #f8fafc;
            color: var(--primary-color);
        }

        /* 2-Column Product Grid on Mobile */
        .product-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
        }

        .product-card {
            border-radius: 10px;
        }

        .product-info {
            padding: 10px;
        }

        .product-info h3 {
            font-size: 13px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .price-main {
            font-size: 14px;
        }

        .price-old {
            font-size: 11px;
        }

        .action-btn {
            padding: 7px 4px;
            font-size: 11px;
            margin-top: 8px;
        }

        .variant-swatches-row,
        .size-tags-row {
            display: none !important;
        }

        /* Hero Banner on Mobile */
        .hero-slider-container {
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .hero-slide-item {
            min-height: 240px !important;
            padding: 20px 14px !important;
            align-items: flex-end !important;
        }

        .hero-glass-card {
            max-width: 100% !important;
            padding: 16px 14px !important;
            border-radius: 10px !important;
            backdrop-filter: blur(8px) !important;
        }

        .hero-glass-card h1 {
            font-size: 20px !important;
            margin-bottom: 6px !important;
        }

        .hero-glass-card p {
            font-size: 12px !important;
            margin-bottom: 12px !important;
        }

        .hero-glass-card a.button {
            padding: 8px 16px !important;
            font-size: 12px !important;
        }

        /* Guarantees Bar on Mobile */
        .guarantees-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 8px !important;
            margin-bottom: 25px !important;
        }

        .guarantee-card {
            padding: 12px 8px !important;
        }

        .guarantee-card span {
            font-size: 18px !important;
        }

        .guarantee-card strong {
            font-size: 12px !important;
        }

        .guarantee-card span:last-child {
            font-size: 10px !important;
        }

        .filter-toolbar {
            padding: 12px;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search-box-wrap {
            max-width: 100% !important;
        }

        .sort-select {
            width: 100% !important;
        }

        .social-feed-card {
            width: calc(50% - 8px) !important;
        }

        .reviews-section-wrap {
            padding: 35px 12px;
            border-radius: 14px;
            margin: 35px 0 20px 0;
        }

        .reviews-main-title {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .reviews-badges-row {
            flex-direction: column;
            gap: 8px;
        }

        .review-pill-badge {
            width: 90%;
            justify-content: center;
            font-size: 12px;
            padding: 6px 14px;
        }

        .reviews-marquee-outer {
            mask-image: none;
            -webkit-mask-image: none;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 10px;
        }

        .reviews-marquee-inner {
            animation: none !important;
            gap: 14px;
        }

        .whatsapp-review-card {
            width: 275px;
        }

        .review-attached-img {
            height: 180px;
        }
    }
</style>

<!-- Hero Slider -->
<?php if ($slides && $q === '' && $cat === ''): ?>
    <section class="hero-slider-container">
        <div class="hero-slides-wrapper">
            <?php foreach ($slides as $idx => $sl):
                $bgImage     = !empty($sl['image_url']) ? 'url(' . e(url($sl['image_url'])) . ')' : 'none';
                $hasEyebrow  = !empty(trim((string)($sl['eyebrow'] ?? '')));
                $hasSubtitle = !empty(trim((string)($sl['subtitle'] ?? '')));
                $hasButton   = !empty(trim((string)($sl['button_text'] ?? '')));
                $hasTitle    = !empty(trim((string)($sl['title'] ?? '')));
            ?>
                <div class="hero-slide-item" style="background-image: <?= $bgImage ?>;">
                    <?php if ($hasTitle || $hasEyebrow || $hasSubtitle || $hasButton): ?>
                        <div class="hero-glass-card">
                            <?php if ($hasEyebrow): ?>
                                <div style="text-transform:uppercase; letter-spacing:1px; font-weight:700; font-size:11px; color:var(--primary-color); margin-bottom:4px;">
                                    <?= e($sl['eyebrow']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasTitle): ?>
                                <h1 style="font-size:32px; margin:0 0 10px 0; line-height:1.15; font-weight:700;">
                                    <?= e($sl['title']) ?>
                                </h1>
                            <?php endif; ?>

                            <?php if ($hasSubtitle): ?>
                                <p style="font-size:14px; margin-bottom:18px; color:#444; line-height:1.45;">
                                    <?= e($sl['subtitle']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($hasButton): ?>
                                <a class="button" href="<?= e(url(!empty($sl['button_url']) ? $sl['button_url'] : 'index.php')) ?>" style="padding:10px 22px; border-radius:6px; font-weight:600;">
                                    <?= e($sl['button_text']) ?> →
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
            <button onclick="prevSlide()" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.85); border:none; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:14px;">❮</button>
            <button onclick="nextSlide()" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.85); border:none; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:14px;">❯</button>
        <?php endif; ?>
    </section>

    <script>
        let currentSlideIndex = 0;
        const slidesWrapper = document.querySelector('.hero-slides-wrapper');
        const totalSlides = <?= count($slides) ?>;

        function showSlide(index) {
            if (index >= totalSlides) currentSlideIndex = 0;
            else if (index < 0) currentSlideIndex = totalSlides - 1;
            else currentSlideIndex = index;
            slidesWrapper.style.transform = `translateX(-${currentSlideIndex * 100}%)`;
        }

        function nextSlide() {
            showSlide(currentSlideIndex + 1);
        }

        function prevSlide() {
            showSlide(currentSlideIndex - 1);
        }
        if (totalSlides > 1) {
            setInterval(nextSlide, 6000);
        }
    </script>
<?php endif; ?>

<!-- Guarantees Bar (2 cols on mobile, 4 on desktop) -->
<div class="guarantees-grid">
    <div class="guarantee-card">
        <span style="font-size:22px; display:block; margin-bottom:4px;">🚀</span>
        <strong style="display:block; font-size:13px;"><?= e($b1Title) ?></strong>
        <span style="color:#666; font-size:11px;"><?= e($b1Sub) ?></span>
    </div>
    <div class="guarantee-card">
        <span style="font-size:22px; display:block; margin-bottom:4px;">💵</span>
        <strong style="display:block; font-size:13px;"><?= e($b2Title) ?></strong>
        <span style="color:#666; font-size:11px;"><?= e($b2Sub) ?></span>
    </div>
    <div class="guarantee-card">
        <span style="font-size:22px; display:block; margin-bottom:4px;">✨</span>
        <strong style="display:block; font-size:13px;"><?= e($b3Title) ?></strong>
        <span style="color:#666; font-size:11px;"><?= e($b3Sub) ?></span>
    </div>
    <div class="guarantee-card">
        <span style="font-size:22px; display:block; margin-bottom:4px;">🔄</span>
        <strong style="display:block; font-size:13px;"><?= e($b4Title) ?></strong>
        <span style="color:#666; font-size:11px;"><?= e($b4Sub) ?></span>
    </div>
</div>

<!-- Search, Filter & Price Sorting Toolbar -->
<div id="shop" class="filter-toolbar">
    <div>
        <h2 style="margin:0; font-size:18px; font-weight:700;">
            <?= $cat ? 'Category: ' . e(ucfirst(str_replace('-', ' ', $cat))) : ($q ? 'Search: "' . e($q) . '"' : 'All Collection') ?>
        </h2>
        <span style="font-size:12px; color:#777;"><?= count($products) ?> items available</span>
    </div>

    <form class="search-box-wrap" method="get" action="index.php#shop">
        <?php if ($cat): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif; ?>
        <?php if ($sort): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <input type="text" name="q" class="search-input" placeholder="Search items..." value="<?= e($q) ?>">
        <button type="submit" class="button" style="padding:10px 16px; border-radius:8px;">Search</button>
        <?php if ($q !== '' || $cat !== ''): ?>
            <a href="index.php#shop" class="button secondary" style="padding:10px 12px; border-radius:8px; text-decoration:none;">Clear</a>
        <?php endif; ?>
    </form>

    <form method="get" action="index.php#shop" style="margin:0;">
        <?php if ($cat): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif; ?>
        <?php if ($q): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select name="sort" class="sort-select" onchange="this.form.submit()">
            <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Sort: Newest</option>
            <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High 📈</option>
            <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low 📉</option>
            <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Popular</option>
        </select>
    </form>
</div>

<!-- Category Scrollable Pills -->
<div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:10px; margin-bottom:25px; -webkit-overflow-scrolling:touch;">
    <a class="button <?= ($cat === '' && $q === '') ? '' : 'secondary' ?>" href="<?= e(url('index.php#shop')) ?>" style="border-radius:20px; padding:6px 14px; font-size:12px; text-decoration:none; white-space:nowrap;">All Items</a>
    <a class="button <?= ($cat === 'new-arrivals') ? '' : 'secondary' ?>" href="<?= e(url('index.php?category=new-arrivals#shop')) ?>" style="border-radius:20px; padding:6px 14px; font-size:12px; text-decoration:none; white-space:nowrap;">🔥 New Arrivals</a>
    <?php foreach ($categories as $c): ?>
        <a class="button <?= ($cat === $c['slug']) ? '' : 'secondary' ?>" href="<?= e(url('index.php?category=' . urlencode($c['slug']) . '#shop')) ?>" style="border-radius:20px; padding:6px 14px; font-size:12px; text-decoration:none; white-space:nowrap;">
            <?= e($c['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Product Grid (2 columns on mobile, 4 columns on desktop) -->
<?php if (!$products): ?>
    <div style="text-align: center; padding: 50px 20px; background: #fff; border-radius: 12px; border: 1px solid #eee;">
        <h3 style="font-size: 18px; margin-bottom:8px;">No matching products found</h3>
        <p style="color:#666; margin-bottom:16px; font-size:13px;">Try clearing your search query or filters.</p>
        <a class="button" href="<?= e(url('index.php#shop')) ?>" style="border-radius:6px; text-decoration:none;">Reset Filters</a>
    </div>
<?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $p):
            $pId = (int)$p['id'];
            $cover = $p['cover_image'] ?? $p['image_url'];
            $imgSrc = !empty($cover) ? (str_starts_with($cover, 'http') ? $cover : url($cover)) : 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=800';

            $discountPct = 0;
            if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']) {
                $discountPct = round((($p['compare_price'] - $p['price']) / $p['compare_price']) * 100);
            }

            $vMeta = $variantDataByProduct[$pId] ?? null;

            $cardColors = [];
            if ($vMeta && !empty($vMeta['colors'])) {
                $cardColors = $vMeta['colors'];
            } elseif (!empty($p['colors'])) {
                $cardColors = array_filter(array_map('trim', explode(',', (string)$p['colors'])));
            }

            $cardSizes = [];
            if ($vMeta && !empty($vMeta['sizes'])) {
                $cardSizes = $vMeta['sizes'];
            } elseif (!empty($p['sizes'])) {
                $cardSizes = array_filter(array_map('trim', explode(',', (string)$p['sizes'])));
            }

            $basePrice = (float)$p['price'];
            $hasPriceDelta = $vMeta && $vMeta['has_delta'];

            $isOutOfStock = ($p['stock'] < 1);
            if ($vMeta && $vMeta['total_stock'] !== null && count($vMeta['sizes']) > 0) {
                $isOutOfStock = ($vMeta['total_stock'] < 1);
            }
        ?>
            <article class="product-card">
                <a class="product-image-wrap" href="<?= e(url('product.php?slug=' . urlencode($p['slug']))) ?>">
                    <img loading="lazy" src="<?= e($imgSrc) ?>" alt="<?= e($p['name']) ?>">
                    <?php if ($discountPct > 0): ?>
                        <span class="badge-discount">-<?= $discountPct ?>%</span>
                    <?php endif; ?>
                    <?php if ($isOutOfStock): ?>
                        <span class="badge-soldout">Sold Out</span>
                    <?php endif; ?>
                </a>

                <div class="product-info">
                    <h3>
                        <a href="<?= e(url('product.php?slug=' . urlencode($p['slug']))) ?>"><?= e($p['name']) ?></a>
                    </h3>

                    <?php if (!empty($cardColors)): ?>
                        <div class="variant-swatches-row">
                            <?php
                            $shownColors = array_slice($cardColors, 0, 2);
                            $moreColorsCount = count($cardColors) - count($shownColors);
                            foreach ($shownColors as $col): ?>
                                <span class="color-swatch-badge"><?= e($col) ?></span>
                            <?php endforeach; ?>
                            <?php if ($moreColorsCount > 0): ?>
                                <span class="muted small" style="font-size:9px;">+<?= $moreColorsCount ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($cardSizes)): ?>
                        <div class="size-tags-row">
                            <?php foreach (array_slice($cardSizes, 0, 4) as $sPill): ?>
                                <span class="size-tag-badge"><?= e($sPill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="price-group">
                        <span class="price-main">
                            <?= $hasPriceDelta ? 'From ' : '' ?><?= money($basePrice) ?>
                        </span>
                        <?php if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']): ?>
                            <span class="price-old"><?= money($p['compare_price']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isOutOfStock): ?>
                        <a class="action-btn" href="<?= e(url('product.php?slug=' . urlencode($p['slug']))) ?>">
                            Order Now
                        </a>
                    <?php else: ?>
                        <button class="action-btn" disabled style="background:#cbd5e1; color:#64748b; cursor:not-allowed;">Sold Out</button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Social Feed Section -->
<?php if (!empty($socialFeed) && $q === '' && $cat === ''): ?>
    <section style="margin: 50px 0 30px 0;">
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="text-transform: uppercase; font-size: 11px; letter-spacing: 1.5px; color: #888;">Follow Our Journey</div>
            <h2 style="font-size: 24px; margin: 4px 0 0 0; color: #111;">On the 'Gram & Beyond</h2>
            <p class="muted small" style="margin-top: 4px;">Tag us in your fits to get featured</p>
        </div>

        <div class="social-feed-container">
            <?php foreach ($socialFeed as $sf):
                $sfImg = !empty($sf['image_url']) ? (str_starts_with($sf['image_url'], 'http') ? $sf['image_url'] : url($sf['image_url'])) : 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?w=600';
            ?>
                <a class="social-feed-card" href="<?= e($sf['post_url']) ?>" target="_blank" rel="noopener">
                    <img loading="lazy" src="<?= e($sfImg) ?>" alt="<?= e($sf['title'] ?: $sf['platform']) ?>">
                    <div class="social-feed-overlay">
                        <span class="social-badge"><?= e($sf['platform']) ?></span>
                        <?php if (!empty($sf['title'])): ?>
                            <span style="font-size: 12px; font-weight: 600; line-height: 1.3; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);"><?= e($sf['title']) ?> ↗</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- ================= WHATSAPP-STYLE CUSTOMER REVIEWS (Sin Style) ================= -->
<?php if ($testimonials): ?>
    <section class="reviews-section-wrap">
        <div class="reviews-header-block">
            <h2 class="reviews-main-title">Our customer reviews</h2>
            <div class="reviews-badges-row">
                <div class="review-pill-badge">
                    <span class="pill-green-dot"></span>
                    <span><b>4.8/5</b> average rating</span>
                </div>
                <div class="review-pill-badge">
                    <span class="pill-green-dot"></span>
                    <span><b>100K+</b> orders delivered</span>
                </div>
                <div class="review-pill-badge">
                    <span class="pill-green-dot"></span>
                    <span><b>Free</b> size exchange (Read our policy)</span>
                </div>
            </div>
        </div>

        <div class="reviews-marquee-outer">
            <div class="reviews-marquee-inner">
                <?php for ($loop = 0; $loop < 2; $loop++): ?>
                    <?php foreach ($testimonials as $t):
                        $firstLetter = mb_substr(trim($t['customer_name']), 0, 1, 'UTF-8');
                        $tImg = !empty($t['image_url']) ? (str_starts_with($t['image_url'], 'http') ? $t['image_url'] : url($t['image_url'])) : '';
                    ?>
                        <div class="whatsapp-review-card">
                            <div class="review-card-topbar">
                                <div class="review-avatar-circle"><?= strtoupper(e($firstLetter)) ?></div>
                                <div class="review-user-meta">
                                    <strong><?= e($t['customer_name']) ?></strong>
                                    <span>Verified Customer · Delivered</span>
                                </div>
                            </div>

                            <div class="review-card-body">
                                <?php if (!empty($tImg)): ?>
                                    <img loading="lazy" class="review-attached-img" src="<?= e($tImg) ?>" alt="Customer Photo">
                                <?php endif; ?>

                                <div class="chat-bubble-customer">
                                    <p><?= nl2br(e($t['review_text'])) ?></p>
                                    <span class="chat-timestamp">6:41 PM</span>
                                </div>

                                <div class="chat-bubble-reply">
                                    <p>That means a lot to our team! Enjoy the fit.</p>
                                </div>
                            </div>

                            <div class="review-card-footer">
                                <span>✓ Verified Buyer</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/app/footer.php'; ?>