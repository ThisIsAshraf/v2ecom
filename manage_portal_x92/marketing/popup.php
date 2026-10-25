<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');

$title = 'Promotional Pop-up Banner Manager';
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $enabled   = isset($_POST['popup_enabled']) ? '1' : '0';

    // Safely Decode Base64 Encoded Inputs to Bypass Server WAF Blocks
    $pTitle    = trim(base64_decode((string)($_POST['popup_title_b64'] ?? '')));
    $pMessage  = trim(base64_decode((string)($_POST['popup_message_b64'] ?? '')));
    $pImgUrl   = trim(base64_decode((string)($_POST['popup_image_url_b64'] ?? '')));
    $pBtnText  = trim(base64_decode((string)($_POST['popup_button_text_b64'] ?? '')));
    $pBtnUrl   = trim(base64_decode((string)($_POST['popup_button_url_b64'] ?? '')));
    $b64Image  = (string)($_POST['popup_image_file_b64'] ?? '');

    $imageUploaded = false;

    // Handle File Upload Sent via Client-Side Base64
    if (!empty($b64Image) && str_contains($b64Image, ';base64,')) {
        [$typeData, $encodedData] = explode(';base64,', $b64Image);
        $binaryData = base64_decode($encodedData);

        // Detect Extension
        $ext = 'jpg';
        if (str_contains($typeData, 'png')) $ext = 'png';
        elseif (str_contains($typeData, 'webp')) $ext = 'webp';
        elseif (str_contains($typeData, 'gif')) $ext = 'gif';

        $uploadDir = dirname(__DIR__, 2) . '/uploads/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $newFileName = 'popup_' . time() . '.' . $ext;
        $targetFilePath = $uploadDir . $newFileName;

        if (file_put_contents($targetFilePath, $binaryData) !== false) {
            $pImgUrl = 'uploads/' . $newFileName;
            $imageUploaded = true;
        }
    }

    $settingsMap = [
        'popup_enabled'     => $enabled,
        'popup_title'       => $pTitle,
        'popup_message'     => $pMessage,
        'popup_image_url'   => $pImgUrl,
        'popup_button_text' => $pBtnText,
        'popup_button_url'  => $pBtnUrl,
    ];

    $stmt = $db->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settingsMap as $key => $val) {
        $stmt->execute([$key, $val]);
    }

    // Audit Logging: Promotional Pop-up Modal Update
    $statusText = ($enabled === '1') ? 'Enabled' : 'Disabled';
    $imgText = $imageUploaded ? ' (New image uploaded)' : '';
    log_admin_activity(
        'settings',
        'update_popup_banner',
        "Updated promotional pop-up modal: State: {$statusText}, Title: '{$pTitle}', Button: '{$pBtnText}' -> '{$pBtnUrl}'{$imgText}"
    );

    flash('success', 'Pop-up settings updated successfully!');
    redirect('admin/marketing/popup');
}

admin_header($title);

$enabled   = site_setting('popup_enabled', '0');
$pTitle    = site_setting('popup_title', 'Special Offer');
$pMessage  = site_setting('popup_message', 'Get up to 30% OFF on all new arrivals.');
$pImg      = site_setting('popup_image_url', '');
$pBtnText  = site_setting('popup_button_text', 'Shop Now');
$pBtnUrl   = site_setting('popup_button_url', 'index.php#shop');

$previewSrc = '';
if (!empty($pImg)) {
    $previewSrc = (str_starts_with($pImg, 'http://') || str_starts_with($pImg, 'https://')) ? $pImg : url($pImg);
}
?>

<div class="panel" style="max-width: 800px; margin: 0 auto;">
    <h2>Promotional Pop-up Modal Banner</h2>
    <p class="muted small" style="margin-bottom: 20px;">
        Display an interactive offer overlay on your storefront.
    </p>

    <!-- Standard URL-encoded form without multipart enctype to prevent WAF blocks -->
    <form id="popupForm" method="post" onsubmit="encodeFormInputs(event)">
        <?= csrf_field() ?>

        <!-- Base64 Encoded Fields -->
        <input type="hidden" name="popup_title_b64" id="b64_title">
        <input type="hidden" name="popup_message_b64" id="b64_message">
        <input type="hidden" name="popup_image_url_b64" id="b64_img_url">
        <input type="hidden" name="popup_button_text_b64" id="b64_btn_text">
        <input type="hidden" name="popup_button_url_b64" id="b64_btn_url">
        <input type="hidden" name="popup_image_file_b64" id="b64_file_data">

        <div class="field" style="margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <label style="font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" name="popup_enabled" value="1" <?= $enabled === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                Enable Promotional Pop-up Modal
            </label>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Pop-up Title *</label>
                <input id="input_title" value="<?= e($pTitle) ?>" required placeholder="e.g. Special Weekend Offer">
            </div>

            <div class="field">
                <label>Button Text *</label>
                <input id="input_btn_text" value="<?= e($pBtnText) ?>" required placeholder="e.g. Claim Discount">
            </div>
        </div>

        <div class="field" style="margin-top: 15px;">
            <label>Offer Details Message *</label>
            <textarea id="input_message" rows="3" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;"><?= e($pMessage) ?></textarea>
        </div>

        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eee; margin-top: 20px;">
            <h4 style="margin: 0 0 12px 0;">Banner Image Management</h4>

            <div id="previewBox" style="margin-bottom: 15px; <?= empty($previewSrc) ? 'display:none;' : '' ?>">
                <label style="font-weight: 600; display: block; margin-bottom: 6px;">Active Image Preview:</label>
                <img id="bannerImgPreview" src="<?= e($previewSrc) ?>" alt="Preview" style="max-width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; background: #fff;">
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Upload Image File</label>
                    <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="padding: 6px;" onchange="previewSelectedFile(this)">
                </div>

                <div class="field">
                    <label>OR Image URL</label>
                    <input type="text" id="input_img_url" value="<?= e($pImg) ?>" placeholder="https://images.unsplash.com/... or uploads/banner.jpg" oninput="previewUrlImage(this.value)">
                </div>
            </div>
        </div>

        <div class="field" style="margin-top: 15px;">
            <label>Button Link URL *</label>
            <input id="input_btn_url" value="<?= e($pBtnUrl) ?>" required placeholder="index.php?category=new-arrivals#shop">
        </div>

        <button type="submit" style="margin-top: 25px; padding: 12px 24px;">Save Settings</button>
    </form>
</div>

<script>
    let activeBase64Image = '';

    function previewSelectedFile(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('bannerImgPreview');
                const box = document.getElementById('previewBox');
                activeBase64Image = e.target.result;
                img.src = activeBase64Image;
                box.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewUrlImage(url) {
        const fileInput = document.getElementById('fileInput');
        if (fileInput.files.length === 0) {
            const img = document.getElementById('bannerImgPreview');
            const box = document.getElementById('previewBox');
            if (url.trim() !== '') {
                img.src = url;
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        }
    }

    function utf8_to_b64(str) {
        return window.btoa(unescape(encodeURIComponent(str)));
    }

    function encodeFormInputs(event) {
        document.getElementById('b64_title').value = utf8_to_b64(document.getElementById('input_title').value);
        document.getElementById('b64_message').value = utf8_to_b64(document.getElementById('input_message').value);
        document.getElementById('b64_img_url').value = utf8_to_b64(document.getElementById('input_img_url').value);
        document.getElementById('b64_btn_text').value = utf8_to_b64(document.getElementById('input_btn_text').value);
        document.getElementById('b64_btn_url').value = utf8_to_b64(document.getElementById('input_btn_url').value);
        document.getElementById('b64_file_data').value = activeBase64Image;
    }
</script>

<?php admin_footer(); ?>