<?php
require_once __DIR__ . '/bootstrap.php';

$storeName = store_name();
$pageTitle = $pageTitle ?? site_setting('seo_meta_title', 'Shop Modern Clothing & Wardrobe Essentials');
$metaDesc  = $metaDescription ?? site_setting('seo_meta_description', 'Discover modern clothing, premium wardrobe staples, and new season drops with nationwide Cash on Delivery in Bangladesh.');
$metaKeys  = $metaKeywords ?? site_setting('seo_meta_keywords', 'clothing, fashion, online shop, bangladesh, modern fit, wardrobe');
$ogImage   = $ogImage ?? site_setting('seo_og_image', site_setting('logo_url', ''));

$announcement = site_setting('announcement', 'New season, new essentials — free delivery offers may apply');

// Fetch Storefront Theme Preferences
$themePrimary     = site_setting('theme_primary_color', '#2c3e50');
$themeHeaderBg    = site_setting('theme_header_bg', '#1a252f');
$themeAnnounceText = site_setting('theme_announcement_text', '#ffffff');
$themeBodyBg      = site_setting('theme_body_bg', '#f8f9fa');
$themeCardBg      = site_setting('theme_card_bg', '#ffffff');
$themeBorder      = site_setting('theme_border_color', '#e0e0e0');
$themeFooterBg    = site_setting('theme_footer_bg', '#111111');
$themeFooterText  = site_setting('theme_footer_text', '#ffffff');

// Section-Specific Typography Settings
$navFont     = site_setting('font_nav_family', 'Inter, sans-serif');
$navSize     = site_setting('font_nav_size', '15');

$heroFont    = site_setting('font_hero_family', 'Playfair Display, serif');
$heroSize    = site_setting('font_hero_size', '32');

$badgeFont   = site_setting('font_badge_family', 'Inter, sans-serif');
$badgeSize   = site_setting('font_badge_size', '14');

$catalogFont = site_setting('font_catalog_family', 'Inter, sans-serif');
$catalogSize = site_setting('font_catalog_size', '15');

$socialFont  = site_setting('font_social_family', 'Playfair Display, serif');
$socialSize  = site_setting('font_social_size', '28');

$footerFont  = site_setting('font_footer_family', 'Inter, sans-serif');
$footerSize  = site_setting('font_footer_size', '14');

$cssUrl = preg_replace('/^https?:/', '', url('assets/style.css'));
$currentUrl = url($_SERVER['REQUEST_URI'] ?? '');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Dynamic SEO Meta Tags -->
    <title><?= e($pageTitle) ?> | <?= e($storeName) ?></title>
    <meta name="description" content="<?= e($metaDesc) ?>">
    <meta name="keywords" content="<?= e($metaKeys) ?>">
    <link rel="canonical" href="<?= e($currentUrl) ?>">

    <!-- Open Graph (Facebook, WhatsApp, LinkedIn Cards) -->
    <meta property="og:site_name" content="<?= e($storeName) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?> | <?= e($storeName) ?>">
    <meta property="og:description" content="<?= e($metaDesc) ?>">
    <meta property="og:url" content="<?= e($currentUrl) ?>">
    <meta property="og:type" content="website">
    <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= e(str_starts_with($ogImage, 'http') ? $ogImage : url($ogImage)) ?>">
    <?php endif; ?>

    <!-- Search Engine Ownership Verification -->
    <meta name="google-site-verification" content="vyZTQMWGBgop0OdB97oEAbU1p4rZsjmSJkxrn5UM_Kg" />

    <!-- Google Web Fonts Preload -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400;600;700&family=Poppins:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?= e($cssUrl) ?>?v=<?= time() ?>">

    <!-- Dynamic Sectional Typography & Theme Styling -->
    <style type="text/css">
        :root {
            --accent: <?= e($themePrimary) ?>;
            --line: <?= e($themeBorder) ?>;
            --panel-bg: <?= e($themeCardBg) ?>;
        }

        body {
            background-color: <?= e($themeBodyBg) ?>;
        }

        /* 1. Header & Navigation Section */
        .site-header,
        .site-header nav a,
        .announcement {
            font-family: <?= e($navFont) ?> !important;
            font-size: <?= (int)$navSize ?>px !important;
        }

        /* 2. Hero & Main Headings */
        .hero-slide,
        .hero-slide h1,
        .hero-slide h2,
        h1,
        h2 {
            font-family: <?= e($heroFont) ?> !important;
        }

        .hero-slide h1,
        h1 {
            font-size: <?= (int)$heroSize ?>px !important;
        }

        /* 3. Badges & Categories */
        .trust-bar,
        .category-card,
        .tag {
            font-family: <?= e($badgeFont) ?> !important;
            font-size: <?= (int)$badgeSize ?>px !important;
        }

        /* 4. Product Catalog Area */
        .product-card,
        .product-card h3,
        .product-card .price {
            font-family: <?= e($catalogFont) ?> !important;
            font-size: <?= (int)$catalogSize ?>px !important;
        }

        /* 5. Social Feed Section */
        .social-feed,
        .social-feed h2,
        .social-feed-title {
            font-family: <?= e($socialFont) ?> !important;
            font-size: <?= (int)$socialSize ?>px !important;
        }

        /* 6. Footer & Contact Bar */
        .site-footer,
        .site-footer a,
        .site-footer p,
        .site-footer span {
            font-family: <?= e($footerFont) ?> !important;
            font-size: <?= (int)$footerSize ?>px !important;
            color: <?= e($themeFooterText) ?>;
        }

        .announcement {
            background-color: <?= e($themeHeaderBg) ?>;
            color: <?= e($themeAnnounceText) ?>;
        }

        .button,
        button:not(.secondary) {
            background-color: <?= e($themePrimary) ?>;
            border-color: <?= e($themePrimary) ?>;
        }

        .product-card,
        .panel,
        .trust-bar {
            background-color: <?= e($themeCardBg) ?>;
            border-color: <?= e($themeBorder) ?>;
        }

        .site-footer {
            background-color: <?= e($themeFooterBg) ?>;
        }
    </style>
</head>

<body>
    <div class="announcement"><?= e($announcement) ?></div>
    <header class="site-header">
        <a class="brand" href="<?= e(url('index.php')) ?>"><?= brand_html() ?></a>
        <nav>
            <a href="<?= e(url('index.php')) ?>">Shop</a>
            <a href="<?= e(url('index.php?category=new-arrivals')) ?>">New arrivals</a>
            <a href="<?= e(url('track-order.php')) ?>">Track Order</a>
            <a href="<?= e(url('contact.php')) ?>">Contact</a>
            <a class="cart-link" href="<?= e(url('cart.php')) ?>">Bag (<?= cart_count() ?>)</a>

            <?php if ($u = current_user()): ?>
                <a href="<?= e(url('account.php')) ?>">Account (<?= e($u['name']) ?>)</a>
                <a href="<?= e(url('user_logout.php')) ?>" class="muted">Log out</a>
            <?php else: ?>
                <a href="<?= e(url('user_login.php')) ?>">Log in</a>
            <?php endif; ?>
        </nav>
    </header>
    <main class="container">
        <?php if ($m = flash('success')): ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
        <?php if ($m = flash('error')): ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>