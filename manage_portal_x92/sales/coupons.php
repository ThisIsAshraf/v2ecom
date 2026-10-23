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
            $s = db()->prepare('INSERT INTO coupons(code,discount_type,discount_value,min_order,max_discount,usage_limit,ends_at,active) VALUES(?,?,?,?,?,?,?,1)');
            $s->execute([$code, $type, $value, $min, $max !== '' ? (float)$max : null, $limit !== '' ? (int)$limit : null, $ends !== '' ? str_replace('T', ' ', $ends) . ':00' : null]);
            flash('success', 'Coupon created.');
        } catch (Throwable $e) {
            flash('error', 'Could not create coupon. Coupon codes must be unique.');
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE coupons SET active=1-active WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success', 'Coupon state updated.');
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
                    <th></th>
                </tr>
            </thead>
            <tbody><?php foreach ($coupons as $c): ?><tr>
                        <td><b><?= e($c['code']) ?></b></td>
                        <td><?= e($c['discount_type'] === 'percent' ? $c['discount_value'] . '%' : money($c['discount_value'])) ?><?php if ($c['max_discount']): ?><br><small>max <?= money($c['max_discount']) ?></small><?php endif; ?></td>
                        <td><?= money($c['min_order']) ?></td>
                        <td><?= $c['used_count'] ?> / <?= e((string)($c['usage_limit'] ?? '∞')) ?></td>
                        <td><?= e($c['ends_at'] ?? 'No expiry') ?></td>
                        <td><?= $c['active'] ? 'Active' : 'Disabled' ?></td>
                        <td>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="button secondary" style="padding:7px"><?= $c['active'] ? 'Disable' : 'Enable' ?></button></form>
                        </td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</div><?php admin_footer(); ?>