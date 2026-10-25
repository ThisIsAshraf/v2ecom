<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('coupons');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $type = (string)($_POST['discount_type'] ?? 'percent');
        $value = (float)($_POST['discount_value'] ?? 0);
        $min = max(0, (float)($_POST['min_order'] ?? 0));
        $max = trim((string)($_POST['max_discount'] ?? ''));
        $limit = trim((string)($_POST['usage_limit'] ?? ''));
        $ends = trim((string)($_POST['ends_at'] ?? ''));

        if (!preg_match('/^[A-Z0-9_-]{3,60}$/', $code) || !in_array($type, ['percent', 'fixed'], true) || $value <= 0 || ($type === 'percent' && $value > 100)) {
            flash('error', 'Enter a valid code and discount. Percent must be between 1 and 100.');
            redirect('admin/sales/coupons');
        }

        try {
            $formattedEnds = $ends !== '' ? str_replace('T', ' ', $ends) . ':00' : null;
            $maxDiscountVal = $max !== '' ? (float)$max : null;
            $usageLimitVal = $limit !== '' ? (int)$limit : null;

            $s = db()->prepare('INSERT INTO coupons(code,discount_type,discount_value,min_order,max_discount,usage_limit,ends_at,active) VALUES(?,?,?,?,?,?,?,1)');
            $s->execute([$code, $type, $value, $min, $maxDiscountVal, $usageLimitVal, $formattedEnds]);
            $couponId = (int)db()->lastInsertId();

            // Audit Logging: Coupon Creation
            $discountStr = ($type === 'percent') ? "{$value}%" : money($value);
            $limitStr = $usageLimitVal ? "Limit: {$usageLimitVal}" : 'Unlimited usage';
            $expiryStr = $formattedEnds ? "Expires: {$formattedEnds}" : 'No expiry';
            log_admin_activity(
                'coupons',
                'create_coupon',
                "Created coupon '{$code}' (ID #{$couponId}): Discount {$discountStr}, Min Order: " . money($min) . ", {$limitStr}, {$expiryStr}"
            );

            flash('success', 'Coupon created.');
        } catch (Throwable $e) {
            flash('error', 'Could not create coupon. Coupon codes must be unique.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch coupon code and current state for accurate audit logging
        $chk = db()->prepare('SELECT code, active FROM coupons WHERE id=?');
        $chk->execute([$id]);
        $coupon = $chk->fetch();

        if ($coupon) {
            db()->prepare('UPDATE coupons SET active=1-active WHERE id=?')->execute([$id]);
            $newState = empty($coupon['active']) ? 'Active (Enabled)' : 'Disabled';

            // Audit Logging: Coupon Status Toggle
            log_admin_activity(
                'coupons',
                'toggle_status',
                "Toggled coupon '{$coupon['code']}' (ID #{$id}) status to {$newState}"
            );

            flash('success', 'Coupon state updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $chk = db()->prepare('SELECT code FROM coupons WHERE id=?');
        $chk->execute([$id]);
        $coupon = $chk->fetch();

        if ($coupon) {
            // Unlink redemptions if needed and delete
            db()->prepare('DELETE FROM coupon_redemptions WHERE coupon_id=?')->execute([$id]);
            db()->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);

            // Audit Logging: Coupon Deletion
            log_admin_activity('coupons', 'delete_coupon', "Deleted coupon code '{$coupon['code']}' (ID #{$id})");
            flash('success', 'Coupon deleted.');
        }
    }
    redirect('admin/sales/coupons');
}

$coupons = db()->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll();
admin_header('Discounts & promotions'); ?>

<div class="panel" style="margin-bottom:22px">
    <h2>Create a coupon</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="field"><label>Coupon code</label><input name="code" placeholder="SUMMER15" required></div>
            <div class="field"><label>Discount type</label><select name="discount_type">
                    <option value="percent">Percentage (%)</option>
                    <option value="fixed">Fixed amount</option>
                </select></div>
            <div class="field"><label>Discount value</label><input type="number" name="discount_value" min="0.01" step="0.01" required></div>
            <div class="field"><label>Minimum order</label><input type="number" name="min_order" min="0" step="0.01" value="0"></div>
            <div class="field"><label>Maximum discount (optional)</label><input type="number" name="max_discount" min="0" step="0.01"></div>
            <div class="field"><label>Usage limit (optional)</label><input type="number" name="usage_limit" min="1"></div>
            <div class="field"><label>Expiry date/time (optional)</label><input type="datetime-local" name="ends_at"></div>
        </div><button>Create coupon</button>
    </form>
</div>

<div class="panel">
    <h2>Coupons</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Discount</th>
                    <th>Minimum order</th>
                    <th>Usage</th>
                    <th>Expiry</th>
                    <th>State</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody><?php foreach ($coupons as $c): ?><tr>
                        <td><b><?= e($c['code']) ?></b></td>
                        <td><?= e($c['discount_type'] === 'percent' ? $c['discount_value'] . '%' : money($c['discount_value'])) ?><?php if ($c['max_discount']): ?><br><small>max <?= money($c['max_discount']) ?></small><?php endif; ?></td>
                        <td><?= money($c['min_order']) ?></td>
                        <td><?= $c['used_count'] ?> / <?= e((string)($c['usage_limit'] ?? '∞')) ?></td>
                        <td><?= e($c['ends_at'] ?? 'No expiry') ?></td>
                        <td>
                            <span class="status" style="padding:2px 8px; border-radius:4px; font-weight:bold; background:<?= $c['active'] ? '#e8f8f5; color:#27ae60;' : '#fde8e8; color:#e74c3c;' ?>">
                                <?= $c['active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            <form method="post" style="display:inline; margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="button secondary" style="padding:4px 8px; font-size:12px;"><?= $c['active'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete coupon <?= e($c['code']) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="button secondary" style="padding:4px 8px; font-size:12px; background:#fde8e8; color:#e74c3c; border-color:#fadbd8;">Delete</button>
                            </form>
                        </td>
                    </tr><?php endforeach; ?>
                <?php if (!$coupons): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;" class="muted">No coupons created yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_footer(); ?>