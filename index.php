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
                'colors'     => [],
                'sizes'      => [],
                'max_price'  => 0.0,
                'min_price'  => 0.0,
                'has_delta'  => false,
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

if ($cat) {
    $catName = ucfirst(str_replace('-', ' ', $cat));
    $pageTitle = e($catName) . ' Collection | ' . store_name();
} elseif ($q) {
    $pageTitle = 'Search results for "' . e($q) . '" | ' . store_name();
}

require_once __DIR__ . '/app/header.php';
?>

<!-- Dynamic DB-Driven Logo & Layout Customizations -->
<style>
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

    :root {
        --primary-color: #25483d;
        --accent-hover: #1b352c;
        --card-radius: 14px;
        --transition-smooth: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .hero-slider-container {
        position: relative;
        overflow: hidden;
        border-radius: 18px;
        margin-bottom: 35px;
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.06);
    }

    .hero-slides-wrapper {
        display: flex;
        transition: transform 0.6s cubic-bezier(0.25, 1, 0.5, 1);
    }

    .hero-slide-item {
        min-width: 100%;
        padding: 85px 50px;
        background-size: cover;
        background-position: center;
        min-height: 440px;
        display: flex;
        align-items: center;
    }

    .hero-glass-card {
        max-width: 580px;
        background: rgba(255, 255, 255, 0.92);
        padding: 38px 32px;
        border-radius: 14px;
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.6);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    .filter-toolbar {
        background: #ffffff;
        padding: 18px 22px;
        border-radius: 14px;
        border: 1px solid var(--line, #e0e0e0);
        margin-bottom: 30px;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    }

    .search-box-wrap {
        display: flex;
        gap: 8px;
        flex: 1;
        max-width: 480px;
    }

    .search-input {
        flex: 1;
        padding: 10px 16px;
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
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 24px;
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
        transform: translateY(-6px);
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
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
        transition: transform 0.5s ease;
    }

    .product-card:hover .product-image-wrap img {
        transform: scale(1.07);
    }

    .badge-discount {
        position: absolute;
        top: 12px;
        left: 12px;
        background: #e74c3c;
        color: #fff;
        font-weight: 700;
        font-size: 11px;
        padding: 4px 9px;
        border-radius: 5px;
    }

    .badge-soldout {
        position: absolute;
        top: 12px;
        right: 12px;
        background: rgba(0, 0, 0, 0.75);
        color: #fff;
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 4px;
    }

    .product-info {
        padding: 18px;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }

    .product-info h3 {
        font-size: 15px;
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
        gap: 5px;
        flex-wrap: wrap;
        margin-bottom: 6px;
    }

    .color-swatch-badge {
        font-size: 10px;
        font-weight: 600;
        color: #3730a3;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        padding: 1px 6px;
        border-radius: 4px;
    }

    .size-tags-row {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        margin-bottom: 8px;
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
        gap: 8px;
        margin-top: auto;
        padding-top: 10px;
    }

    .price-main {
        font-size: 17px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .price-old {
        font-size: 13px;
        text-decoration: line-through;
        color: #999;
    }

    .action-btn {
        display: block;
        width: 100%;
        text-align: center;
        margin-top: 14px;
        padding: 11px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 8px;
        border: none;
        background: var(--primary-color);
        color: #ffffff;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition-smooth);
    }

    .action-btn:hover {
        background: var(--accent-hover);
    }

    .social-feed-container {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 18px;
        margin: 0 auto;
        max-width: 1150px;
    }

    .social-feed-card {
        position: relative;
        border-radius: 14px;
        overflow: hidden;
        width: 220px;
        max-width: 100%;
        aspect-ratio: 1 / 1;
        background: #f7f7f7;
        border: 1px solid #eee;
        display: block;
        text-decoration: none;
        color: #fff;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .social-feed-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.14);
    }

    .social-feed-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.4s ease;
    }

    .social-feed-card:hover img {
        transform: scale(1.08);
    }

    .social-feed-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(0, 0, 0, 0.15) 0%, rgba(0, 0, 0, 0.72) 100%);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 14px;
        box-sizing: border-box;
    }

    .social-badge {
        align-self: flex-start;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 5px;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 0.5px;
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
                                <div style="text-transform:uppercase; letter-spacing:1.5px; font-weight:700; font-size:12px; color:var(--primary-color); margin-bottom:8px;">
                                    <?= e($sl['eyebrow']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasTitle): ?>
                                <h1 style="font-size:36px; margin:0 0 12px 0; line-height:1.15; font-weight:700;">
                                    <?= e($sl['title']) ?>
                                </h1>
                            <?php endif; ?>

                            <?php if ($hasSubtitle): ?>
                                <p style="font-size:15px; margin-bottom:22px; color:#444; line-height:1.5;">
                                    <?= e($sl['subtitle']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($hasButton): ?>
                                <a class="button" href="<?= e(url(!empty($sl['button_url']) ? $sl['button_url'] : 'index.php')) ?>" style="padding:12px 26px; border-radius:6px; font-weight:600;">
                                    <?= e($sl['button_text']) ?> →
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
            <button onclick="prevSlide()" style="position:absolute; left:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.9); border:none; width:42px; height:42px; border-radius:50%; cursor:pointer;">❮</button>
            <button onclick="nextSlide()" style="position:absolute; right:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.9); border:none; width:42px; height:42px; border-radius:50%; cursor:pointer;">❯</button>
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

<!-- Guarantees Bar -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 40px;">
    <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; text-align:center;">
        <span style="font-size:24px; display:block; margin-bottom:6px;">🚀</span>
        <strong style="display:block; font-size:14px;"><?= e($b1Title) ?></strong>
        <span style="color:#666; font-size:12px;"><?= e($b1Sub) ?></span>
    </div>
    <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; text-align:center;">
        <span style="font-size:24px; display:block; margin-bottom:6px;">💵</span>
        <strong style="display:block; font-size:14px;"><?= e($b2Title) ?></strong>
        <span style="color:#666; font-size:12px;"><?= e($b2Sub) ?></span>
    </div>
    <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; text-align:center;">
        <span style="font-size:24px; display:block; margin-bottom:6px;">✨</span>
        <strong style="display:block; font-size:14px;"><?= e($b3Title) ?></strong>
        <span style="color:#666; font-size:12px;"><?= e($b3Sub) ?></span>
    </div>
    <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; text-align:center;">
        <span style="font-size:24px; display:block; margin-bottom:6px;">🔄</span>
        <strong style="display:block; font-size:14px;"><?= e($b4Title) ?></strong>
        <span style="color:#666; font-size:12px;"><?= e($b4Sub) ?></span>
    </div>
</div>

<!-- Search, Filter & Price Sorting Toolbar -->
<div id="shop" class="filter-toolbar">
    <div>
        <h2 style="margin:0; font-size:22px; font-weight:700;">
            <?= $cat ? 'Category: ' . e(ucfirst(str_replace('-', ' ', $cat))) : ($q ? 'Search: "' . e($q) . '"' : 'All Collection') ?>
        </h2>
        <span style="font-size:13px; color:#777;"><?= count($products) ?> items available</span>
    </div>

    <!-- Search Form -->
    <form class="search-box-wrap" method="get" action="index.php#shop">
        <?php if ($cat): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif; ?>
        <?php if ($sort): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <input type="text" name="q" class="search-input" placeholder="Search by item name, description..." value="<?= e($q) ?>">
        <button type="submit" class="button" style="padding:10px 18px; border-radius:8px;">Search</button>
        <?php if ($q !== '' || $cat !== ''): ?>
            <a href="index.php#shop" class="button secondary" style="padding:10px 14px; border-radius:8px; text-decoration:none;">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Price & Ordering Dropdown -->
    <form method="get" action="index.php#shop" style="margin:0;">
        <?php if ($cat): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif; ?>
        <?php if ($q): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select name="sort" class="sort-select" onchange="this.form.submit()">
            <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Sort by: Newest Arrivals</option>
            <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High 📈</option>
            <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low 📉</option>
            <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Sort by: Most Popular</option>
        </select>
    </form>
</div>

<!-- Category Pills -->
<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:30px;">
    <a class="button <?= ($cat === '' && $q === '') ? '' : 'secondary' ?>" href="<?= e(url('index.php#shop')) ?>" style="border-radius:20px; padding:8px 18px; font-size:13px; text-decoration:none;">All Items</a>
    <a class="button <?= ($cat === 'new-arrivals') ? '' : 'secondary' ?>" href="<?= e(url('index.php?category=new-arrivals#shop')) ?>" style="border-radius:20px; padding:8px 18px; font-size:13px; text-decoration:none;">🔥 New Arrivals</a>
    <?php foreach ($categories as $c): ?>
        <a class="button <?= ($cat === $c['slug']) ? '' : 'secondary' ?>" href="<?= e(url('index.php?category=' . urlencode($c['slug']) . '#shop')) ?>" style="border-radius:20px; padding:8px 18px; font-size:13px; text-decoration:none;">
            <?= e($c['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Product Grid -->
<?php if (!$products): ?>
    <div style="text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 1px solid #eee;">
        <h3 style="font-size: 20px; margin-bottom:8px;">No matching products found</h3>
        <p style="color:#666; margin-bottom:20px;">Try clearing your filters or search keywords.</p>
        <a class="button" href="<?= e(url('index.php#shop')) ?>" style="border-radius:6px; text-decoration:none;">Reset Search</a>
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

            // Resolve Multi-Variant Colors and Sizes
            $vMeta = $variantDataByProduct[$pId] ?? null;

            // Colors: prioritize matrix, fallback to product table column
            $cardColors = [];
            if ($vMeta && !empty($vMeta['colors'])) {
                $cardColors = $vMeta['colors'];
            } elseif (!empty($p['colors'])) {
                $cardColors = array_filter(array_map('trim', explode(',', (string)$p['colors'])));
            }

            // Sizes: prioritize matrix, fallback to product table column
            $cardSizes = [];
            if ($vMeta && !empty($vMeta['sizes'])) {
                $cardSizes = $vMeta['sizes'];
            } elseif (!empty($p['sizes'])) {
                $cardSizes = array_filter(array_map('trim', explode(',', (string)$p['sizes'])));
            }

            // Dynamic Price Label (e.g. "From ৳..." if variants have additional pricing)
            $basePrice = (float)$p['price'];
            $hasPriceDelta = $vMeta && $vMeta['has_delta'];
            $maxVariantPrice = $hasPriceDelta ? ($basePrice + $vMeta['max_price']) : $basePrice;

            // In-stock evaluation
            $isOutOfStock = ($p['stock'] < 1);
            if ($vMeta && $vMeta['total_stock'] !== null && count($vMeta['sizes']) > 0) {
                $isOutOfStock = ($vMeta['total_stock'] < 1);
            }
        ?>
            <article class="product-card">
                <a class="product-image-wrap" href="<?= e(url('product.php?slug=' . urlencode($p['slug']))) ?>">
                    <img loading="lazy" src="<?= e($imgSrc) ?>" alt="<?= e($p['name']) ?>">
                    <?php if ($discountPct > 0): ?>
                        <span class="badge-discount">-<?= $discountPct ?>% OFF</span>
                    <?php endif; ?>
                    <?php if ($isOutOfStock): ?>
                        <span class="badge-soldout">Out of Stock</span>
                    <?php endif; ?>
                </a>

                <div class="product-info">
                    <h3>
                        <a href="<?= e(url('product.php?slug=' . urlencode($p['slug']))) ?>"><?= e($p['name']) ?></a>
                    </h3>

                    <!-- Available Colors Display -->
                    <?php if (!empty($cardColors)): ?>
                        <div class="variant-swatches-row">
                            <?php
                            $shownColors = array_slice($cardColors, 0, 3);
                            $moreColorsCount = count($cardColors) - count($shownColors);
                            foreach ($shownColors as $col): ?>
                                <span class="color-swatch-badge"><?= e($col) ?></span>
                            <?php endforeach; ?>
                            <?php if ($moreColorsCount > 0): ?>
                                <span class="muted small" style="font-size:10px;">+<?= $moreColorsCount ?> more</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Available Sizes Display -->
                    <?php if (!empty($cardSizes)): ?>
                        <div class="size-tags-row">
                            <?php foreach ($cardSizes as $sPill): ?>
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
                            View & Order
                        </a>
                    <?php else: ?>
                        <button class="action-btn" disabled style="background:#cbd5e1; color:#64748b; cursor:not-allowed;">Sold Out</button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Social Media Feed Section -->
<?php if (!empty($socialFeed) && $q === '' && $cat === ''): ?>
    <section style="margin: 60px 0 35px 0;">
        <div style="text-align: center; margin-bottom: 25px;">
            <div style="text-transform: uppercase; font-size: 12px; letter-spacing: 1.5px; color: #888;">Follow Our Journey</div>
            <h2 style="font-size: 28px; margin: 4px 0 0 0; color: #111;">On the 'Gram & Beyond</h2>
            <p class="muted small" style="margin-top: 5px;">Tag us in your fits to get featured</p>
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
                            <span style="font-size: 13px; font-weight: 600; line-height: 1.3; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);"><?= e($sf['title']) ?> ↗</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Testimonials -->
<?php if ($testimonials): ?>
    <section style="margin: 50px 0 30px 0; background: #fff; padding: 45px 30px; border-radius: 16px; border: 1px solid #eee;">
        <div style="text-align: center; margin-bottom: 35px;">
            <div style="text-transform: uppercase; font-size: 12px; letter-spacing: 1.5px; color: #888;">Community Feedback</div>
            <h2 style="font-size:32px; margin-top:4px;">Loved By Our Customers</h2>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 22px;">
            <?php foreach ($testimonials as $t): ?>
                <div style="background: #fdfdfd; padding: 25px; border-radius: 12px; border: 1px solid #f0f0f0;">
                    <div style="color: #f39c12; margin-bottom: 10px;"><?= str_repeat('★', (int)$t['rating']) ?></div>
                    <p style="font-size: 14px; color: #444; line-height: 1.6; margin-bottom: 15px;">"<?= e($t['review_text']) ?>"</p>
                    <strong style="font-size:14px; color: var(--primary-color);">— <?= e($t['customer_name']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/app/footer.php'; ?>