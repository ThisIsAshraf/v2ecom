</main>

<?php
// Dynamic Settings
$footerAbout     = site_setting('footer_about', 'Modern wardrobe staples, everyday fits, and premium quality crafted for style.');
$footerPhone     = site_setting('footer_phone', '+880 1700-000000');
$footerEmail     = site_setting('footer_email', 'hello.divers.almarez@gmail.com');
$footerAddress   = site_setting('footer_address', 'Dhaka, Bangladesh');
$footerCopyright = site_setting('footer_copyright', '© ' . date('Y') . ' ' . store_name() . '. All rights reserved.');

// Dynamic Social Links
$socials = [
    'facebook'  => site_setting('social_facebook', ''),
    'instagram' => site_setting('social_instagram', ''),
    'whatsapp'  => site_setting('social_whatsapp', ''),
    'tiktok'    => site_setting('social_tiktok', ''),
    'youtube'   => site_setting('social_youtube', ''),
];
?>

<footer class="site-footer" style="padding: 50px 0 0 0; margin-top: 60px; border-top: 1px solid var(--line, #eee);">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">

        <!-- 3-Column Footer Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 40px; margin-bottom: 40px;">

            <!-- Column 1: Brand & Socials -->
            <div>
                <a class="brand" href="<?= e(url('index.php')) ?>" style="display:inline-block; margin-bottom:12px; font-size:22px; text-decoration:none; font-weight:bold;"><?= brand_html() ?></a>
                <p style="font-size: 14px; line-height: 1.6; margin: 0 0 18px 0; opacity: 0.85;"><?= e($footerAbout) ?></p>

                <!-- SVG Social Icons -->
                <?php if (array_filter($socials)): ?>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <?php if ($socials['facebook']): ?>
                            <a href="<?= e($socials['facebook']) ?>" target="_blank" rel="noopener" aria-label="Facebook" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:rgba(150,150,150,0.2); text-decoration:none;">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($socials['instagram']): ?>
                            <a href="<?= e($socials['instagram']) ?>" target="_blank" rel="noopener" aria-label="Instagram" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:rgba(150,150,150,0.2); text-decoration:none;">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($socials['whatsapp']): ?>
                            <a href="<?= e($socials['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="WhatsApp" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:rgba(150,150,150,0.2); text-decoration:none;">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($socials['tiktok']): ?>
                            <a href="<?= e($socials['tiktok']) ?>" target="_blank" rel="noopener" aria-label="TikTok" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:rgba(150,150,150,0.2); text-decoration:none;">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.28-2.85 1.16-5.64 3.61-7.07 1.25-.74 2.72-1.09 4.17-1.01.09 1.47.02 2.94.02 4.41-.57-.08-1.15.01-1.69.19-1.22.39-2.18 1.41-2.45 2.65-.3 1.25.13 2.58 1.05 3.44.89.85 2.18 1.19 3.39.87 1.27-.32 2.27-1.37 2.52-2.64.12-.66.12-1.34.12-2.01V.02z" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($socials['youtube']): ?>
                            <a href="<?= e($socials['youtube']) ?>" target="_blank" rel="noopener" aria-label="YouTube" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:rgba(150,150,150,0.2); text-decoration:none;">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
                                </svg>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Column 2: Navigation -->
            <div>
                <h4 style="margin: 0 0 15px 0; font-size: 16px;">Store Navigation</h4>
                <ul style="list-style: none; padding: 0; margin: 0; line-height: 2; font-size: 14px;">
                    <li><a href="<?= e(url('index.php')) ?>" style="text-decoration: none; color: inherit; opacity: 0.9;">Shop All</a></li>
                    <li><a href="<?= e(url('index.php?category=new-arrivals')) ?>" style="text-decoration: none; color: inherit; opacity: 0.9;">New Arrivals</a></li>
                    <li><a href="<?= e(url('track-order.php')) ?>" style="text-decoration: none; color: inherit; opacity: 0.9;">Track Order</a></li>
                    <li><a href="<?= e(url('contact.php')) ?>" style="text-decoration: none; color: inherit; opacity: 0.9;">Contact Support</a></li>
                </ul>
            </div>

            <!-- Column 3: Contact Details -->
            <div>
                <h4 style="margin: 0 0 15px 0; font-size: 16px;">Contact Details</h4>
                <ul style="list-style: none; padding: 0; margin: 0; line-height: 2.2; font-size: 14px;">
                    <?php if ($footerPhone): ?>
                        <li style="display:flex; align-items:center; gap:8px;">📞 <span><?= e($footerPhone) ?></span></li>
                    <?php endif; ?>
                    <?php if ($footerEmail): ?>
                        <li style="display:flex; align-items:center; gap:8px;">📧 <span><?= e($footerEmail) ?></span></li>
                    <?php endif; ?>
                    <?php if ($footerAddress): ?>
                        <li style="display:flex; align-items:center; gap:8px;">📍 <span><?= e($footerAddress) ?></span></li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>

    </div>

    <!-- Centered Full-Width Bottom Bar -->
    <div style="border-top: 1px solid rgba(150,150,150,0.2); padding: 20px 0; text-align: center; width: 100%;">
        <small style="opacity: 0.8; font-size: 13px;"><?= e($footerCopyright) ?></small>
    </div>
</footer>

<?php
// Load Dynamic Pop-up Offer Banner Settings ONLY on the homepage (index.php)
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$popEnabled  = site_setting('popup_enabled', '0');

if ($popEnabled === '1' && ($currentPage === 'index.php' || $currentPage === '')):
    $popTitle   = site_setting('popup_title', 'Special Offer');
    $popMessage = site_setting('popup_message', 'Explore our current collection sale!');
    $popImg     = site_setting('popup_image_url', '');
    $popBtnText = site_setting('popup_button_text', 'Shop Offer');
    $popBtnUrl  = site_setting('popup_button_url', 'index.php#shop');

    // Format Image Source
    $popImgSrc = '';
    if (!empty($popImg)) {
        if (str_starts_with($popImg, 'http://') || str_starts_with($popImg, 'https://')) {
            $popImgSrc = $popImg;
        } else {
            $popImgSrc = url($popImg);
        }
    }
?>

    <!-- Pop-up Overlay CSS -->
    <style>
        .offer-popup-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            padding: 20px;
        }

        .offer-popup-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        .offer-popup-card {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            position: relative;
            transform: scale(0.85);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .offer-popup-backdrop.active .offer-popup-card {
            transform: scale(1);
        }

        .offer-popup-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.6);
            color: #fff;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            transition: background 0.2s;
        }

        .offer-popup-close:hover {
            background: rgba(0, 0, 0, 0.85);
        }

        .offer-popup-img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
        }

        .offer-popup-body {
            padding: 25px;
            text-align: center;
        }

        .offer-popup-body h3 {
            font-size: 22px;
            margin: 0 0 10px 0;
            color: #1e293b;
            font-weight: 700;
        }

        .offer-popup-body p {
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .offer-popup-btn {
            display: inline-block;
            width: 100%;
            padding: 12px 20px;
            background: #25483d;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            border-radius: 8px;
            box-sizing: border-box;
            transition: background 0.2s;
        }

        .offer-popup-btn:hover {
            background: #1b352c;
        }
    </style>

    <!-- Pop-up Overlay HTML -->
    <div id="offerPopupBackdrop" class="offer-popup-backdrop">
        <div class="offer-popup-card">
            <button type="button" class="offer-popup-close" onclick="closeOfferPopup()">✕</button>

            <?php if (!empty($popImgSrc)): ?>
                <img src="<?= e($popImgSrc) ?>" alt="<?= e($popTitle) ?>" class="offer-popup-img">
            <?php endif; ?>

            <div class="offer-popup-body">
                <h3><?= e($popTitle) ?></h3>
                <p><?= e($popMessage) ?></p>
                <a href="<?= e(url($popBtnUrl)) ?>" class="offer-popup-btn"><?= e($popBtnText) ?> →</a>
            </div>
        </div>
    </div>

    <!-- Pop-up Display Logic -->
    <script>
        function showOfferPopup() {
            const modal = document.getElementById("offerPopupBackdrop");
            if (modal) {
                modal.classList.add("active");
            }
        }

        function closeOfferPopup() {
            const modal = document.getElementById("offerPopupBackdrop");
            if (modal) {
                modal.classList.remove("active");
            }
        }

        // Trigger pop-up display automatically on homepage load
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(showOfferPopup, 800);
            });
        } else {
            setTimeout(showOfferPopup, 800);
        }
    </script>
<?php endif; ?>

</body>

</html>