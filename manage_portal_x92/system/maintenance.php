<?php
require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');

$title = 'Maintenance Mode Control';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $mode   = isset($_POST['maintenance_mode']) ? '1' : '0';
    $mTitle = trim((string)($_POST['maintenance_title'] ?? ''));
    $mMsg   = trim((string)($_POST['maintenance_message'] ?? ''));

    // Check prior maintenance mode state for accurate audit logging
    $oldMode = site_setting('maintenance_mode', '0');

    site_setting_save('maintenance_mode', $mode);
    site_setting_save('maintenance_title', $mTitle);
    site_setting_save('maintenance_message', $mMsg);

    // Audit Logging: Maintenance Mode State Change & Content Update
    $statusText = ($mode === '1') ? 'ENABLED (Storefront Closed)' : 'DISABLED (Storefront Live)';
    $transition = ($oldMode !== $mode)
        ? "State changed from " . ($oldMode === '1' ? 'ENABLED' : 'DISABLED') . " to {$statusText}"
        : "Settings updated (State: {$statusText})";

    log_admin_activity(
        'settings',
        'update_maintenance_mode',
        "{$transition}. Headline: '{$mTitle}', Notice: '{$mMsg}'"
    );

    flash('success', 'Maintenance mode settings updated successfully.');
    redirect('admin/system/maintenance');
}

admin_header($title);

$mode   = site_setting('maintenance_mode', '0');
$mTitle = site_setting('maintenance_title', 'We’ll Be Right Back!');
$mMsg   = site_setting('maintenance_message', 'Our website is currently undergoing scheduled maintenance. We should be back online shortly.');
?>

<div class="panel" style="max-width:750px; margin: 0 auto;">
    <h2>Storefront Maintenance Window</h2>
    <p class="muted small" style="margin-bottom:20px;">
        When active, regular customers see a maintenance landing page. Active admin sessions can continue browsing and testing storefront pages normally.
    </p>

    <form method="post">
        <?= csrf_field() ?>

        <div style="background: <?= $mode === '1' ? '#fde8e8' : '#e8f8f5' ?>; padding: 18px; border-radius: 8px; border: 1px solid <?= $mode === '1' ? '#fadbd8' : '#a3e4d7' ?>; margin-bottom: 20px;">
            <label style="font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 12px; font-size: 15px; color: <?= $mode === '1' ? '#c0392b' : '#27ae60' ?>;">
                <input type="checkbox" name="maintenance_mode" value="1" <?= $mode === '1' ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                Enable Storefront Maintenance Mode
            </label>
            <p style="margin: 6px 0 0 32px; font-size: 12px; color: #666;">
                Status: <b><?= $mode === '1' ? '🔒 MAINTENANCE ACTIVE (Storefront Closed)' : '🟢 LIVE (Storefront Open)' ?></b>
            </p>
        </div>

        <div class="field">
            <label>Maintenance Page Headline *</label>
            <input name="maintenance_title" required value="<?= e($mTitle) ?>" placeholder="e.g. We’ll Be Right Back!">
        </div>

        <div class="field" style="margin-top:15px;">
            <label>Maintenance Notice Message *</label>
            <textarea name="maintenance_message" rows="4" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;"><?= e($mMsg) ?></textarea>
        </div>

        <button type="submit" style="margin-top:20px;">Save Maintenance Settings</button>
    </form>
</div>

<?php admin_footer(); ?>