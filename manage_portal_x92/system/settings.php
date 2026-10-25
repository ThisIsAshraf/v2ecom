<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');
$title = 'Store Branding, Mailer & Email Templates';
$uploadDir = dirname(__DIR__, 2) . '/uploads';
$uploadUrl = 'uploads';

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'test_email') {
        $testEmail = trim((string)($_POST['test_email_address'] ?? ''));
        if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $sent = send_store_email(
                $testEmail,
                'Admin Test User',
                'SMTP Configuration Test - ' . store_name(),
                '<h3>SMTP Setup Working!</h3><p>This is a test email sent from your ' . e(store_name()) . ' admin panel.</p>'
            );
            if ($sent) {
                flash('success', 'Test email sent successfully to ' . $testEmail);
            } else {
                flash('error', 'Failed to send test email. Please check your SMTP settings and credentials.');
            }
        } else {
            flash('error', 'Please enter a valid recipient email address.');
        }
        redirect('admin/system/settings');
    }

    if ($action === 'save_settings') {
        $name = trim((string)($_POST['site_name'] ?? ''));
        $announcement = trim((string)($_POST['announcement'] ?? ''));
        $adminEmail = trim((string)($_POST['admin_notification_email'] ?? ''));
        $currency = trim((string)($_POST['currency_symbol'] ?? '৳'));

        // Logo Display Size Controller (DB Stored)
        $logoHeight = max(30, min(180, (int)($_POST['logo_height'] ?? 58)));

        // SEO Settings
        $seoTitle = trim((string)($_POST['seo_meta_title'] ?? ''));
        $seoDesc  = trim((string)($_POST['seo_meta_description'] ?? ''));
        $seoKeys  = trim((string)($_POST['seo_meta_keywords'] ?? ''));
        $seoOgImg = trim((string)($_POST['seo_og_image'] ?? ''));

        // SMTP Settings 
        $mailHost = trim((string)($_POST['mail_host'] ?? 'smtp.gmail.com'));
        $mailPort = trim((string)($_POST['mail_port'] ?? '587'));
        $mailEnc  = trim((string)($_POST['mail_encryption'] ?? 'tls'));
        $mailUser = trim((string)($_POST['mail_username'] ?? ''));
        $mailPass = trim((string)($_POST['mail_password'] ?? ''));
        $fromAddr = trim((string)($_POST['mail_from_address'] ?? ''));
        $fromName = trim((string)($_POST['mail_from_name'] ?? ''));

        // Custom Email Template & Invoice Customizations
        $emailColor    = trim((string)($_POST['email_accent_color'] ?? '#2c3e50'));
        $orderRecSubj  = trim((string)($_POST['email_order_placed_subject'] ?? 'Order Received - #{order_number}'));
        $orderRecIntro = trim((string)($_POST['email_order_placed_intro'] ?? 'We have received your order #{order_number}. Our team is reviewing the details and will confirm your order shortly.'));
        $invoiceSubj   = trim((string)($_POST['email_invoice_subject'] ?? 'Official Tax Invoice - Order #{order_number}'));
        $invoiceNote   = trim((string)($_POST['invoice_footer_note'] ?? 'Thank you for shopping with us!'));

        // Store Guarantee / Trust Badges Customization (All 4 badges with icons)
        $trustKeys = [
            'trust_badge_1_icon',
            'trust_badge_1_title',
            'trust_badge_1_sub',
            'trust_badge_2_icon',
            'trust_badge_2_title',
            'trust_badge_2_sub',
            'trust_badge_3_icon',
            'trust_badge_3_title',
            'trust_badge_3_sub',
            'trust_badge_4_icon',
            'trust_badge_4_title',
            'trust_badge_4_sub'
        ];
        foreach ($trustKeys as $tk) {
            if (isset($_POST[$tk])) {
                site_setting_save($tk, trim((string)$_POST[$tk]));
            }
        }

        if ($name === '') {
            flash('error', 'Please enter a valid store name.');
            redirect('admin/system/settings');
        }

        // Save Branding & Logo Height
        site_setting_save('site_name', $name);
        site_setting_save('announcement', $announcement);
        site_setting_save('admin_notification_email', $adminEmail);
        site_setting_save('currency_symbol', $currency);
        site_setting_save('logo_height', (string)$logoHeight);

        // Save SEO Settings
        site_setting_save('seo_meta_title', $seoTitle);
        site_setting_save('seo_meta_description', $seoDesc);
        site_setting_save('seo_meta_keywords', $seoKeys);
        site_setting_save('seo_og_image', $seoOgImg);

        // Save SMTP Credentials 
        site_setting_save('mail_host', $mailHost);
        site_setting_save('mail_port', $mailPort);
        site_setting_save('mail_encryption', $mailEnc);
        site_setting_save('mail_username', $mailUser);
        site_setting_save('mail_password', $mailPass);
        site_setting_save('mail_from_address', $fromAddr);
        site_setting_save('mail_from_name', $fromName);

        // Save Email Template Customizations
        site_setting_save('invoice_color', $emailColor);
        site_setting_save('email_order_placed_subject', $orderRecSubj);
        site_setting_save('email_order_placed_intro', $orderRecIntro);
        site_setting_save('email_invoice_subject', $invoiceSubj);
        site_setting_save('invoice_footer_note', $invoiceNote);

        // Handle Logo Removal 
        if (!empty($_POST['remove_logo'])) {
            $old = site_setting('logo_url', '');
            if ($old !== '' && str_starts_with($old, $uploadUrl . '/')) {
                $oldPath = dirname(__DIR__, 2) . '/' . $old;
                if (is_file($oldPath)) @unlink($oldPath);
            }
            site_setting_save('logo_url', '');
        }

        // Handle Logo Upload 
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['logo'];
            if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= 2 * 1024 * 1024) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                if (isset($extensions[$mime])) {
                    $filename = 'logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
                        site_setting_save('logo_url', $uploadUrl . '/' . $filename);
                    }
                }
            }
        }

        flash('success', 'Store configuration, guarantees, and branding settings updated.');
        redirect('admin/system/settings');
    }
}

admin_header($title);
$currentLogo = site_setting('logo_url', '');
$logoHeight  = (int)site_setting('logo_height', '58');
if ($logoHeight <= 0) $logoHeight = 58;

$adminEmail  = site_setting('admin_notification_email', envv('ADMIN_NOTIFICATION_EMAIL', ''));
$currency    = site_setting('currency_symbol', envv('CURRENCY', '৳'));

// SEO Settings
$seoTitle = site_setting('seo_meta_title', 'Shop Modern Clothing & Wardrobe Essentials');
$seoDesc  = site_setting('seo_meta_description', 'Discover modern clothing, premium wardrobe staples, and new season drops with nationwide Cash on Delivery in Bangladesh.');
$seoKeys  = site_setting('seo_meta_keywords', 'clothing, fashion, online shop, bangladesh, modern fit, wardrobe');
$seoOgImg = site_setting('seo_og_image', '');

// SMTP Settings 
$mailHost = site_setting('mail_host', envv('MAIL_HOST', 'smtp.gmail.com'));
$mailPort = site_setting('mail_port', envv('MAIL_PORT', '587'));
$mailEnc  = site_setting('mail_encryption', envv('MAIL_ENCRYPTION', 'tls'));
$mailUser = site_setting('mail_username', envv('MAIL_USERNAME', ''));
$mailPass = site_setting('mail_password', envv('MAIL_PASSWORD', ''));
$fromAddr = site_setting('mail_from_address', envv('MAIL_FROM_ADDRESS', ''));
$fromName = site_setting('mail_from_name', envv('MAIL_FROM_NAME', store_name()));

// Email Template Settings
$emailColor    = site_setting('invoice_color', '#2c3e50');
$orderRecSubj  = site_setting('email_order_placed_subject', 'Order Received - #{order_number}');
$orderRecIntro = site_setting('email_order_placed_intro', 'We have received your order #{order_number}. Our team is reviewing the details and will confirm your order shortly.');
$invoiceSubj   = site_setting('email_invoice_subject', 'Official Tax Invoice - Order #{order_number}');
$invoiceNote   = site_setting('invoice_footer_note', 'Thank you for shopping with us!');
?>

<div class="panel" style="max-width:850px; margin-bottom:25px;">
    <h2>Store Branding & Identity</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="form-grid">
            <div class="field">
                <label>Store Name *</label>
                <input name="site_name" required value="<?= e(store_name()) ?>">
            </div>
            <div class="field">
                <label>Currency Symbol *</label>
                <input name="currency_symbol" required value="<?= e($currency) ?>">
            </div>
        </div>

        <div class="field">
            <label>Admin Notification Email</label>
            <input type="email" name="admin_notification_email" value="<?= e($adminEmail) ?>">
            <p class="small muted">Receives order alerts when a customer places an order.</p>
        </div>

        <div class="field">
            <label>Header Announcement Bar</label>
            <input name="announcement" value="<?= e(site_setting('announcement', '')) ?>">
        </div>

        <!-- Store Logo & Dynamic Size Control -->
        <div class="form-grid" style="align-items:flex-start;">
            <div class="field">
                <label>Store Logo File</label>
                <input name="logo" type="file" accept="image/*">
                <?php if ($currentLogo !== ''): ?>
                    <div style="margin-top:10px; padding:10px; background:#fafafa; border:1px solid #eee; border-radius:6px; display:inline-block;">
                        <span class="small muted" style="display:block; margin-bottom:4px;">Current Preview:</span>
                        <img src="<?= e(url($currentLogo)) ?>" style="height: <?= (int)$logoHeight ?>px; max-height:120px; width:auto; display:block; object-fit:contain;">
                    </div>
                    <label style="margin-top:8px; display:block;"><input type="checkbox" name="remove_logo" value="1" style="width:auto"> Remove logo</label>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Logo Display Height (px)</label>
                <input type="number" name="logo_height" min="30" max="180" step="2" value="<?= (int)$logoHeight ?>" placeholder="e.g. 60">
                <p class="small muted">Adjusts how tall your logo renders on storefront bars (recommended: 50px - 75px).</p>
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid var(--line,#eee);">

        <!-- Store Guarantees / Badges (Full 4 Badges with Icons, Titles & Subtitles) -->
        <h2>Homepage Store Guarantees / Badges</h2>
        <p class="muted small" style="margin-top:-5px; margin-bottom:20px;">Manage the icons, titles, and descriptions of all 4 trust badges displayed on your homepage.</p>

        <!-- Badge 1 -->
        <div style="background:#fafafa; border:1px solid #eee; padding:15px; border-radius:8px; margin-bottom:15px;">
            <strong style="color:var(--primary-color, #2c3e50); display:block; margin-bottom:10px;">Badge 1</strong>
            <div style="display:grid; grid-template-columns: 80px 1fr 2fr; gap:12px;">
                <div class="field" style="margin:0;">
                    <label>Icon</label>
                    <input name="trust_badge_1_icon" value="<?= e(site_setting('trust_badge_1_icon', '🚀')) ?>" style="text-align:center; font-size:18px;">
                </div>
                <div class="field" style="margin:0;">
                    <label>Title</label>
                    <input name="trust_badge_1_title" value="<?= e(site_setting('trust_badge_1_title', 'Nationwide Shipping')) ?>">
                </div>
                <div class="field" style="margin:0;">
                    <label>Subtitle / Description</label>
                    <input name="trust_badge_1_sub" value="<?= e(site_setting('trust_badge_1_sub', 'Fast delivery across Bangladesh')) ?>">
                </div>
            </div>
        </div>

        <!-- Badge 2 -->
        <div style="background:#fafafa; border:1px solid #eee; padding:15px; border-radius:8px; margin-bottom:15px;">
            <strong style="color:var(--primary-color, #2c3e50); display:block; margin-bottom:10px;">Badge 2</strong>
            <div style="display:grid; grid-template-columns: 80px 1fr 2fr; gap:12px;">
                <div class="field" style="margin:0;">
                    <label>Icon</label>
                    <input name="trust_badge_2_icon" value="<?= e(site_setting('trust_badge_2_icon', '💵')) ?>" style="text-align:center; font-size:18px;">
                </div>
                <div class="field" style="margin:0;">
                    <label>Title</label>
                    <input name="trust_badge_2_title" value="<?= e(site_setting('trust_badge_2_title', 'Cash on Delivery')) ?>">
                </div>
                <div class="field" style="margin:0;">
                    <label>Subtitle / Description</label>
                    <input name="trust_badge_2_sub" value="<?= e(site_setting('trust_badge_2_sub', 'Pay safely at your doorstep')) ?>">
                </div>
            </div>
        </div>

        <!-- Badge 3 -->
        <div style="background:#fafafa; border:1px solid #eee; padding:15px; border-radius:8px; margin-bottom:15px;">
            <strong style="color:var(--primary-color, #2c3e50); display:block; margin-bottom:10px;">Badge 3</strong>
            <div style="display:grid; grid-template-columns: 80px 1fr 2fr; gap:12px;">
                <div class="field" style="margin:0;">
                    <label>Icon</label>
                    <input name="trust_badge_3_icon" value="<?= e(site_setting('trust_badge_3_icon', '✨')) ?>" style="text-align:center; font-size:18px;">
                </div>
                <div class="field" style="margin:0;">
                    <label>Title</label>
                    <input name="trust_badge_3_title" value="<?= e(site_setting('trust_badge_3_title', 'Premium Fabric')) ?>">
                </div>
                <div class="field" style="margin:0;">
                    <label>Subtitle / Description</label>
                    <input name="trust_badge_3_sub" value="<?= e(site_setting('trust_badge_3_sub', 'Crafted for long-lasting comfort')) ?>">
                </div>
            </div>
        </div>

        <!-- Badge 4 -->
        <div style="background:#fafafa; border:1px solid #eee; padding:15px; border-radius:8px; margin-bottom:15px;">
            <strong style="color:var(--primary-color, #2c3e50); display:block; margin-bottom:10px;">Badge 4</strong>
            <div style="display:grid; grid-template-columns: 80px 1fr 2fr; gap:12px;">
                <div class="field" style="margin:0;">
                    <label>Icon</label>
                    <input name="trust_badge_4_icon" value="<?= e(site_setting('trust_badge_4_icon', '🔄')) ?>" style="text-align:center; font-size:18px;">
                </div>
                <div class="field" style="margin:0;">
                    <label>Title</label>
                    <input name="trust_badge_4_title" value="<?= e(site_setting('trust_badge_4_title', 'Hassle-Free Exchange')) ?>">
                </div>
                <div class="field" style="margin:0;">
                    <label>Subtitle / Description</label>
                    <input name="trust_badge_4_sub" value="<?= e(site_setting('trust_badge_4_sub', 'Easy returns & order support')) ?>">
                </div>
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid var(--line,#eee);">

        <h2>🔍 Search Engine Optimization (SEO & Social Sharing)</h2>
        <div class="field">
            <label>Default Homepage Meta Title</label>
            <input name="seo_meta_title" value="<?= e($seoTitle) ?>" placeholder="Shop Modern Clothing & Wardrobe Essentials">
        </div>

        <div class="field">
            <label>Default Meta Description (Google snippet text)</label>
            <textarea name="seo_meta_description" rows="3" placeholder="Describe your store in 150-160 characters..."><?= e($seoDesc) ?></textarea>
        </div>

        <div class="field">
            <label>Meta Keywords (Comma separated)</label>
            <input name="seo_meta_keywords" value="<?= e($seoKeys) ?>" placeholder="clothing, fashion, online shop, bangladesh">
        </div>

        <div class="field">
            <label>Social Share Preview Image URL (Facebook, WhatsApp, Twitter)</label>
            <input name="seo_og_image" value="<?= e($seoOgImg) ?>" placeholder="uploads/social-banner.jpg">
            <p class="small muted">Optional. If empty, defaults to store logo.</p>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid var(--line,#eee);">

        <h2>SMTP & Mailer Credentials</h2>
        <div class="form-grid">
            <div class="field">
                <label>SMTP Host</label>
                <input name="mail_host" value="<?= e($mailHost) ?>" placeholder="smtp.gmail.com">
            </div>
            <div class="field">
                <label>SMTP Port</label>
                <input name="mail_port" value="<?= e($mailPort) ?>" placeholder="587">
            </div>
            <div class="field">
                <label>Encryption</label>
                <select name="mail_encryption">
                    <option value="tls" <?= $mailEnc === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                    <option value="ssl" <?= $mailEnc === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                </select>
            </div>
            <div class="field">
                <label>SMTP Username / Email</label>
                <input type="email" name="mail_username" value="<?= e($mailUser) ?>">
            </div>
            <div class="field">
                <label>SMTP Password / App Password</label>
                <input type="password" name="mail_password" value="<?= e($mailPass) ?>">
            </div>
            <div class="field">
                <label>Sender From Email</label>
                <input type="email" name="mail_from_address" value="<?= e($fromAddr) ?>">
            </div>
            <div class="field" style="grid-column: span 2;">
                <label>Sender From Name</label>
                <input name="mail_from_name" value="<?= e($fromName) ?>">
            </div>
        </div>

        <hr style="margin:25px 0; border:0; border-top:1px solid var(--line,#eee);">

        <h2>Customizable Email Templates & Invoices</h2>
        <p class="muted small" style="margin-top:-5px; margin-bottom:15px;">Use placeholders like <code>{order_number}</code> or <code>{customer_name}</code> inside text fields.</p>

        <div class="form-grid">
            <div class="field" style="grid-column: span 2;">
                <label>Email Theme Primary Color</label>
                <input type="color" name="email_accent_color" value="<?= e($emailColor) ?>" style="height:42px; padding:2px; cursor:pointer;">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Order Placed Email Subject</label>
                <input name="email_order_placed_subject" value="<?= e($orderRecSubj) ?>">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Order Placed Intro Message</label>
                <textarea name="email_order_placed_intro" rows="3"><?= e($orderRecIntro) ?></textarea>
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Confirmed Order Invoice Email Subject</label>
                <input name="email_invoice_subject" value="<?= e($invoiceSubj) ?>">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Invoice Footer / Thank You Note</label>
                <input name="invoice_footer_note" value="<?= e($invoiceNote) ?>">
            </div>
        </div>

        <button type="submit" style="margin-top:20px;">Save All Settings</button>
    </form>
</div>

<!-- Send Test Email Panel -->
<div class="panel" style="max-width:850px;">
    <h2>Test Mail Configuration</h2>
    <form method="post" class="inline-form" style="display:flex; gap:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_email">
        <input type="email" name="test_email_address" required placeholder="Enter recipient email..." style="padding:8px 12px; flex:1;">
        <button class="button secondary">Send Test Email</button>
    </form>
</div>

<?php admin_footer(); ?>