<?php
require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');

$title = 'Delivery Cities & Shipping Rates';
$db = db();

// Handle Form Submissions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // 1. Add New City / Division
    if ($action === 'create_city') {
        $cityName     = trim((string)($_POST['city_name'] ?? ''));
        $zoneCode     = trim((string)($_POST['zone_code'] ?? 'outside_dhaka'));
        $shippingCost = max(0, (float)($_POST['shipping_cost'] ?? 120.00));

        if ($cityName === '') {
            flash('error', 'City/Division name is required.');
        } else {
            try {
                $ins = $db->prepare('INSERT INTO shipping_cities (city_name, zone_code, shipping_cost) VALUES (?, ?, ?)');
                $ins->execute([$cityName, $zoneCode, $shippingCost]);
                flash('success', 'New delivery location "' . e($cityName) . '" added successfully.');
            } catch (Throwable $e) {
                flash('error', 'Location already exists or invalid data provided.');
            }
        }
        redirect('admin/sales/cities');
    }

    // 2. Update Existing City / Division
    if ($action === 'update_city') {
        $cityId       = (int)($_POST['city_id'] ?? 0);
        $cityName     = trim((string)($_POST['city_name'] ?? ''));
        $zoneCode     = trim((string)($_POST['zone_code'] ?? 'outside_dhaka'));
        $shippingCost = max(0, (float)($_POST['shipping_cost'] ?? 120.00));

        if ($cityId <= 0 || $cityName === '') {
            flash('error', 'Invalid city details.');
        } else {
            try {
                $up = $db->prepare('UPDATE shipping_cities SET city_name = ?, zone_code = ?, shipping_cost = ? WHERE id = ?');
                $up->execute([$cityName, $zoneCode, $shippingCost, $cityId]);
                flash('success', 'Delivery rate for "' . e($cityName) . '" updated successfully.');
            } catch (Throwable $e) {
                flash('error', 'Failed to update delivery city.');
            }
        }
        redirect('admin/sales/cities');
    }

    // 3. Delete City / Division
    if ($action === 'delete_city') {
        $cityId = (int)($_POST['city_id'] ?? 0);
        if ($cityId > 0) {
            $db->prepare('DELETE FROM shipping_cities WHERE id = ?')->execute([$cityId]);
            flash('success', 'Delivery city removed.');
        }
        redirect('admin/sales/cities');
    }
}

// Fetch all cities ordered by zone
$cities = $db->query('SELECT * FROM shipping_cities ORDER BY zone_code ASC, city_name ASC')->fetchAll();
$editingId = (int)($_GET['edit'] ?? 0);

admin_header($title);
?>

<div class="panel" style="max-width:900px; margin:0 auto 30px auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2>Manage Delivery Cities & Shipping Rates</h2>
            <p class="muted small" style="margin:4px 0 0 0;">
                Configure dynamic cash-on-delivery rates for Bangladesh divisions and zones.
            </p>
        </div>
    </div>

    <!-- Active Cities List Table -->
    <table style="width:100%; border-collapse:collapse; margin-bottom:30px;">
        <thead>
            <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                <th style="padding:10px;">City / Division</th>
                <th style="padding:10px;">Delivery Zone</th>
                <th style="padding:10px;">Shipping Fee</th>
                <th style="padding:10px; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cities as $c): ?>
                <?php $isEditingThis = ($editingId === (int)$c['id']); ?>
                <tr style="border-bottom:1px solid #eee; <?= $isEditingThis ? 'background:#fffde7;' : '' ?>">
                    <td style="padding:10px;"><b><?= e($c['city_name']) ?></b></td>
                    <td style="padding:10px;">
                        <span style="background:<?= $c['zone_code'] === 'inside_dhaka' ? '#e8f8f5; color:#27ae60;' : '#fef9e7; color:#d35400;' ?>; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold;">
                            <?= $c['zone_code'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?>
                        </span>
                    </td>
                    <td style="padding:10px; font-weight:bold; color:#2c3e50;">
                        <?= money($c['shipping_cost']) ?>
                    </td>
                    <td style="padding:10px; text-align:right;">
                        <a href="<?= e(url('admin/sales/cities?edit=' . $c['id'])) ?>#city-form" class="button secondary" style="padding:4px 8px; font-size:12px; margin-right:4px;">Edit</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this delivery city?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_city">
                            <input type="hidden" name="city_id" value="<?= $c['id'] ?>">
                            <button class="button danger" style="padding:4px 8px; font-size:12px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cities): ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:20px;" class="muted">No delivery locations configured.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">

    <!-- Form Section: Edit or Create -->
    <?php
    $editTarget = null;
    if ($editingId > 0) {
        foreach ($cities as $ci) {
            if ((int)$ci['id'] === $editingId) {
                $editTarget = $ci;
                break;
            }
        }
    }
    ?>

    <div id="city-form">
        <?php if ($editTarget): ?>
            <h3>Edit Delivery City: <?= e($editTarget['city_name']) ?></h3>
            <form method="post" action="<?= e(url('admin/sales/cities')) ?>#city-form" style="max-width:550px; margin-top:15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_city">
                <input type="hidden" name="city_id" value="<?= $editTarget['id'] ?>">

                <div class="field">
                    <label>City / Division Name *</label>
                    <input name="city_name" value="<?= e($editTarget['city_name']) ?>" required placeholder="e.g. Chattogram">
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Zone Classification *</label>
                        <select name="zone_code" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;">
                            <option value="inside_dhaka" <?= $editTarget['zone_code'] === 'inside_dhaka' ? 'selected' : '' ?>>Inside Dhaka</option>
                            <option value="outside_dhaka" <?= $editTarget['zone_code'] === 'outside_dhaka' ? 'selected' : '' ?>>Outside Dhaka</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Shipping Fee (৳) *</label>
                        <input type="number" step="0.01" name="shipping_cost" value="<?= (float)$editTarget['shipping_cost'] ?>" required>
                    </div>
                </div>
                <button type="submit">Update Delivery City</button>
                <a href="<?= e(url('admin/sales/cities')) ?>" class="button secondary" style="margin-left:8px;">Cancel Edit</a>
            </form>
        <?php else: ?>
            <h3>Add New Delivery Location</h3>
            <form method="post" action="<?= e(url('admin/sales/cities')) ?>#city-form" style="max-width:550px; margin-top:15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_city">

                <div class="field">
                    <label>City / Division Name *</label>
                    <input name="city_name" required placeholder="e.g. Sylhet">
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Zone Classification *</label>
                        <select name="zone_code" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;">
                            <option value="inside_dhaka">Inside Dhaka</option>
                            <option value="outside_dhaka" selected>Outside Dhaka</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Shipping Fee (৳) *</label>
                        <input type="number" step="0.01" name="shipping_cost" value="120.00" required>
                    </div>
                </div>
                <button type="submit">Add Delivery City</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php admin_footer(); ?>