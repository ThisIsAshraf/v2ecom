<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('theme');

// Available Font Options
$fontOptions = [
    'Inter, sans-serif'                    => 'Inter (Clean & Modern)',
    'Roboto, sans-serif'                   => 'Roboto (Standard & Clean)',
    'Poppins, sans-serif'                  => 'Poppins (Geometric & Friendly)',
    'Montserrat, sans-serif'               => 'Montserrat (Bold & Trendy)',
    'Playfair Display, serif'              => 'Playfair Display (Elegant Serif)',
    'system-ui, -apple-system, sans-serif' => 'System Native Font',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_theme') {
        $primaryColor = trim((string)($_POST['theme_primary_color'] ?? '#2c3e50'));

        // Theme Colors
        site_setting_save('theme_primary_color', $primaryColor);
        site_setting_save('theme_header_bg', trim((string)($_POST['theme_header_bg'] ?? '#1a252f')));
        site_setting_save('theme_announcement_text', trim((string)($_POST['theme_announcement_text'] ?? '#ffffff')));
        site_setting_save('theme_body_bg', trim((string)($_POST['theme_body_bg'] ?? '#f8f9fa')));
        site_setting_save('theme_card_bg', trim((string)($_POST['theme_card_bg'] ?? '#ffffff')));
        site_setting_save('theme_border_color', trim((string)($_POST['theme_border_color'] ?? '#e0e0e0')));
        site_setting_save('theme_footer_bg', trim((string)($_POST['theme_footer_bg'] ?? '#111111')));
        site_setting_save('theme_footer_text', trim((string)($_POST['theme_footer_text'] ?? '#ffffff')));

        // Sectional Typography Settings
        site_setting_save('font_nav_family', trim((string)($_POST['font_nav_family'] ?? 'Inter, sans-serif')));
        site_setting_save('font_nav_size', (string)max(10, min(30, (int)($_POST['font_nav_size'] ?? 15))));

        site_setting_save('font_hero_family', trim((string)($_POST['font_hero_family'] ?? 'Playfair Display, serif')));
        site_setting_save('font_hero_size', (string)max(16, min(60, (int)($_POST['font_hero_size'] ?? 32))));

        site_setting_save('font_badge_family', trim((string)($_POST['font_badge_family'] ?? 'Inter, sans-serif')));
        site_setting_save('font_badge_size', (string)max(10, min(24, (int)($_POST['font_badge_size'] ?? 14))));

        site_setting_save('font_catalog_family', trim((string)($_POST['font_catalog_family'] ?? 'Inter, sans-serif')));
        site_setting_save('font_catalog_size', (string)max(10, min(24, (int)($_POST['font_catalog_size'] ?? 15))));

        site_setting_save('font_social_family', trim((string)($_POST['font_social_family'] ?? 'Playfair Display, serif')));
        site_setting_save('font_social_size', (string)max(16, min(50, (int)($_POST['font_social_size'] ?? 28))));

        site_setting_save('font_footer_family', trim((string)($_POST['font_footer_family'] ?? 'Inter, sans-serif')));
        site_setting_save('font_footer_size', (string)max(10, min(24, (int)($_POST['font_footer_size'] ?? 14))));

        // Footer Content
        site_setting_save('footer_about', trim((string)($_POST['footer_about'] ?? '')));
        site_setting_save('footer_phone', trim((string)($_POST['footer_phone'] ?? '')));
        site_setting_save('footer_email', trim((string)($_POST['footer_email'] ?? '')));
        site_setting_save('footer_address', trim((string)($_POST['footer_address'] ?? '')));
        site_setting_save('footer_copyright', trim((string)($_POST['footer_copyright'] ?? '')));

        // Social Links
        site_setting_save('social_facebook', trim((string)($_POST['social_facebook'] ?? '')));
        site_setting_save('social_instagram', trim((string)($_POST['social_instagram'] ?? '')));
        site_setting_save('social_whatsapp', trim((string)($_POST['social_whatsapp'] ?? '')));
        site_setting_save('social_tiktok', trim((string)($_POST['social_tiktok'] ?? '')));
        site_setting_save('social_youtube', trim((string)($_POST['social_youtube'] ?? '')));

        // Audit Logging: Custom Theme & Typography Update
        log_admin_activity(
            'theme',
            'save_theme',
            "Updated storefront theme styles, sectional typography, footer details, and social links (Primary: {$primaryColor})"
        );

        flash('success', 'Storefront theme, sectional typography, footer, and social links updated successfully.');
        redirect('admin/system/theme');
    }

    if ($action === 'apply_preset') {
        $preset = (string)($_POST['preset'] ?? 'classic');

        if ($preset === 'dark') {
            site_setting_save('theme_primary_color', '#e74c3c');
            site_setting_save('theme_header_bg', '#111111');
            site_setting_save('theme_announcement_text', '#ffffff');
            site_setting_save('theme_body_bg', '#181818');
            site_setting_save('theme_card_bg', '#222222');
            site_setting_save('theme_border_color', '#333333');
            site_setting_save('theme_footer_bg', '#0a0a0a');
            site_setting_save('theme_footer_text', '#ffffff');
        } elseif ($preset === 'minimal') {
            site_setting_save('theme_primary_color', '#000000');
            site_setting_save('theme_header_bg', '#000000');
            site_setting_save('theme_announcement_text', '#ffffff');
            site_setting_save('theme_body_bg', '#ffffff');
            site_setting_save('theme_card_bg', '#ffffff');
            site_setting_save('theme_border_color', '#eeeeee');
            site_setting_save('theme_footer_bg', '#000000');
            site_setting_save('theme_footer_text', '#ffffff');
        } elseif ($preset === 'warm') {
            site_setting_save('theme_primary_color', '#d35400');
            site_setting_save('theme_header_bg', '#2c3e50');
            site_setting_save('theme_announcement_text', '#f39c12');
            site_setting_save('theme_body_bg', '#fcf8f2');
            site_setting_save('theme_card_bg', '#ffffff');
            site_setting_save('theme_border_color', '#f0e6d2');
            site_setting_save('theme_footer_bg', '#2c3e50');
            site_setting_save('theme_footer_text', '#fcf8f2');
        } else { // Classic Default
            site_setting_save('theme_primary_color', '#2c3e50');
            site_setting_save('theme_header_bg', '#1a252f');
            site_setting_save('theme_announcement_text', '#ffffff');
            site_setting_save('theme_body_bg', '#f8f9fa');
            site_setting_save('theme_card_bg', '#ffffff');
            site_setting_save('theme_border_color', '#e0e0e0');
            site_setting_save('theme_footer_bg', '#111111');
            site_setting_save('theme_footer_text', '#ffffff');
        }

        // Audit Logging: Theme Preset Applied
        log_admin_activity(
            'theme',
            'apply_preset',
            "Applied '" . ucfirst($preset) . "' storefront color theme preset"
        );

        flash('success', 'Applied ' . ucfirst($preset) . ' theme preset.');
        redirect('admin/system/theme');
    }
}

// Current Color Settings
$themePrimary      = site_setting('theme_primary_color', '#2c3e50');
$themeHeaderBg     = site_setting('theme_header_bg', '#1a252f');
$themeAnnounceText = site_setting('theme_announcement_text', '#ffffff');
$themeBodyBg       = site_setting('theme_body_bg', '#f8f9fa');
$themeCardBg       = site_setting('theme_card_bg', '#ffffff');
$themeBorder       = site_setting('theme_border_color', '#e0e0e0');
$themeFooterBg     = site_setting('theme_footer_bg', '#111111');
$themeFooterText   = site_setting('theme_footer_text', '#ffffff');

// Current Sectional Typography Settings
$navFont     = site_setting('font_nav_family', 'Inter, sans-serif');
$navSize     = (int)site_setting('font_nav_size', '15');

$heroFont    = site_setting('font_hero_family', 'Playfair Display, serif');
$heroSize    = (int)site_setting('font_hero_size', '32');

$badgeFont   = site_setting('font_badge_family', 'Inter, sans-serif');
$badgeSize   = (int)site_setting('font_badge_size', '14');

$catalogFont = site_setting('font_catalog_family', 'Inter, sans-serif');
$catalogSize = (int)site_setting('font_catalog_size', '15');

$socialFont  = site_setting('font_social_family', 'Playfair Display, serif');
$socialSize  = (int)site_setting('font_social_size', '28');

$footerFont  = site_setting('font_footer_family', 'Inter, sans-serif');
$footerSize  = (int)site_setting('font_footer_size', '14');

// Current Footer Content Settings
$footerAbout     = site_setting('footer_about', 'Modern wardrobe staples, everyday fits, and premium quality crafted for style.');
$footerPhone     = site_setting('footer_phone', '+880 1700-000000');
$footerEmail     = site_setting('footer_email', 'hello.divers.almarez@gmail.com');
$footerAddress   = site_setting('footer_address', 'Dhaka, Bangladesh');
$footerCopyright = site_setting('footer_copyright', '© ' . date('Y') . ' ' . store_name() . '. All rights reserved.');

// Current Social Links Settings
$socialFacebook  = site_setting('social_facebook', '');
$socialInstagram = site_setting('social_instagram', '');
$socialWhatsapp  = site_setting('social_whatsapp', '');
$socialTiktok    = site_setting('social_tiktok', '');
$socialYoutube   = site_setting('social_youtube', '');

admin_header('Storefront Theme & Footer Control'); ?>
<style>
    .panel select,
    .panel input[type="number"],
    .panel input[type="text"] {
        height: auto !important;
        min-height: 40px !important;
        padding: 8px 12px !important;
        line-height: 1.4 !important;
        box-sizing: border-box !important;
    }
</style>

<!-- Preset Quick Actions -->
<div class="panel" style="margin-bottom:22px;">
    <h2>One-Click Theme Presets</h2>
    <p class="muted small">Quickly set pre-configured color palettes across your site.</p>
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
        <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preset"><input type="hidden" name="preset" value="classic"><button class="button secondary">Classic Navy</button></form>
        <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preset"><input type="hidden" name="preset" value="minimal"><button class="button secondary">Monochrome Minimal</button></form>
        <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preset"><input type="hidden" name="preset" value="dark"><button class="button secondary">Midnight Dark</button></form>
        <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="apply_preset"><input type="hidden" name="preset" value="warm"><button class="button secondary">Warm Terracotta</button></form>
    </div>
</div>

<!-- Custom Controls Panel -->
<div class="panel" style="max-width:850px; margin-bottom:22px;">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_theme">

        <h2>Section-by-Section Typography Controls</h2>
        <p class="muted small" style="margin-top:-5px; margin-bottom:20px;">Individually adjust font styles and sizes for every section on your storefront.</p>

        <!-- 1. Header Navigation -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:15px;">1. Header Navigation & Announcement Bar</h3>
        <div class="form-grid">
            <div class="field">
                <label>Nav Font Family</label>
                <select name="font_nav_family" style="width:100%; min-height:40px; padding:6px 10px; line-height:1.4; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $navFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Nav Font Size (px)</label>
                <input type="number" name="font_nav_size" value="<?= $navSize ?>" min="10" max="30" required>
            </div>
        </div>

        <!-- 2. Hero & Headings -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">2. Hero Banner & Main Headings</h3>
        <div class="form-grid">
            <div class="field">
                <label>Heading Font Family</label>
                <select name="font_hero_family" style="width:100%; height:38px;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $heroFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Main Title Size (px)</label>
                <input type="number" name="font_hero_size" value="<?= $heroSize ?>" min="16" max="60" required>
            </div>
        </div>

        <!-- 3. Trust Bar & Categories -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">3. Trust Badges & Featured Categories</h3>
        <div class="form-grid">
            <div class="field">
                <label>Badge Font Family</label>
                <select name="font_badge_family" style="width:100%; height:38px;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $badgeFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Badge Font Size (px)</label>
                <input type="number" name="font_badge_size" value="<?= $badgeSize ?>" min="10" max="24" required>
            </div>
        </div>

        <!-- 4. Product Catalog -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">4. Product List & Catalog Area</h3>
        <div class="form-grid">
            <div class="field">
                <label>Product Card Font Family</label>
                <select name="font_catalog_family" style="width:100%; height:38px;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $catalogFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Product Text Size (px)</label>
                <input type="number" name="font_catalog_size" value="<?= $catalogSize ?>" min="10" max="24" required>
            </div>
        </div>

        <!-- 5. Social Feed Section -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">5. Social Media Feed Section</h3>
        <div class="form-grid">
            <div class="field">
                <label>Social Title Font Family</label>
                <select name="font_social_family" style="width:100%; height:38px;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $socialFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Social Title Size (px)</label>
                <input type="number" name="font_social_size" value="<?= $socialSize ?>" min="16" max="50" required>
            </div>
        </div>

        <!-- 6. Footer & Contact -->
        <h3 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">6. Footer Section & Contact Bar</h3>
        <div class="form-grid">
            <div class="field">
                <label>Footer Font Family</label>
                <select name="font_footer_family" style="width:100%; height:38px;">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $footerFont === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Footer Text Size (px)</label>
                <input type="number" name="font_footer_size" value="<?= $footerSize ?>" min="10" max="24" required>
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">

        <h2>Theme Colors</h2>
        <div class="form-grid">
            <div class="field">
                <label>Primary Accent Color</label>
                <input type="color" name="theme_primary_color" value="<?= e($themePrimary) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Header / Announcement Background</label>
                <input type="color" name="theme_header_bg" value="<?= e($themeHeaderBg) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Announcement Text Color</label>
                <input type="color" name="theme_announcement_text" value="<?= e($themeAnnounceText) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Page Body Background</label>
                <input type="color" name="theme_body_bg" value="<?= e($themeBodyBg) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Product Card Background</label>
                <input type="color" name="theme_card_bg" value="<?= e($themeCardBg) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Card / Section Border Color</label>
                <input type="color" name="theme_border_color" value="<?= e($themeBorder) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Footer Background Color</label>
                <input type="color" name="theme_footer_bg" value="<?= e($themeFooterBg) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>

            <div class="field">
                <label>Footer Text Color</label>
                <input type="color" name="theme_footer_text" value="<?= e($themeFooterText) ?>" style="height:40px; padding:2px; cursor:pointer;">
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">

        <h2>Footer Content Configuration</h2>
        <div class="field">
            <label>Brand Bio / About Text</label>
            <textarea name="footer_about" rows="3" placeholder="Brief summary of your store"><?= e($footerAbout) ?></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Support Phone</label>
                <input name="footer_phone" value="<?= e($footerPhone) ?>" placeholder="+880 1700-000000">
            </div>

            <div class="field">
                <label>Support Email</label>
                <input type="email" name="footer_email" value="<?= e($footerEmail) ?>" placeholder="support@yourdomain.com">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Store Physical Address</label>
                <input name="footer_address" value="<?= e($footerAddress) ?>" placeholder="Dhaka, Bangladesh">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Copyright Bar Text</label>
                <input name="footer_copyright" value="<?= e($footerCopyright) ?>" placeholder="© 2026 DiverseBD. All rights reserved.">
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid var(--line,#eee);">

        <h2>Social Media Links</h2>
        <div class="form-grid">
            <div class="field">
                <label>Facebook Page URL</label>
                <input name="social_facebook" value="<?= e($socialFacebook) ?>" placeholder="https://facebook.com/yourpage">
            </div>

            <div class="field">
                <label>Instagram Profile URL</label>
                <input name="social_instagram" value="<?= e($socialInstagram) ?>" placeholder="https://instagram.com/yourprofile">
            </div>

            <div class="field">
                <label>WhatsApp Number / Link</label>
                <input name="social_whatsapp" value="<?= e($socialWhatsapp) ?>" placeholder="https://wa.me/8801700000000">
            </div>

            <div class="field">
                <label>TikTok Profile URL</label>
                <input name="social_tiktok" value="<?= e($socialTiktok) ?>" placeholder="https://tiktok.com/@yourhandle">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>YouTube Channel URL</label>
                <input name="social_youtube" value="<?= e($socialYoutube) ?>" placeholder="https://youtube.com/@yourchannel">
            </div>
        </div>

        <button class="button" style="margin-top:20px;">Save Theme, Sectional Typography & Social Settings</button>
    </form>
</div>

<?php admin_footer(); ?>