<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('reports');

$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$end = $to . ' 23:59:59';
$start = $from . ' 00:00:00';
$params = [$start, $end];

$s = db()->prepare("SELECT COUNT(*) order_count, COALESCE(SUM(total),0) gross, COALESCE(SUM(discount_amount),0) discounts, COALESCE(AVG(total),0) average_order FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN ('cancelled','returned')");
$s->execute($params);
$summary = $s->fetch();

$s = db()->prepare("SELECT status, COUNT(*) count, COALESCE(SUM(total),0) total FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY count DESC");
$s->execute($params);
$byStatus = $s->fetchAll();

$s = db()->prepare("SELECT oi.product_name, SUM(oi.quantity) units, SUM(oi.line_total) revenue FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled','returned') GROUP BY oi.product_id, oi.product_name ORDER BY units DESC LIMIT 10");
$s->execute($params);
$top = $s->fetchAll();

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', store_name())) . '-sales-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Date', 'Customer', 'Phone', 'Status', 'Subtotal', 'Discount', 'Delivery', 'Total', 'Payment']);

    $s = db()->prepare('SELECT order_number, created_at, customer_name, customer_phone, status, subtotal, discount_amount, shipping_amount, total, payment_method FROM orders WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC');
    $s->execute($params);

    while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
    fclose($out);
    exit;
}

admin_header('Sales reports'); ?>

<div class="panel" style="margin-bottom:22px">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <!-- Date Form -->
        <form method="get" class="inline-form" style="flex-wrap:wrap">
            <div class="field" style="margin:0"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="field" style="margin:0"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <button class="button">Run report</button>
            <a class="button secondary" href="<?= e(url('admin/system/reports?from=' . urlencode($from) . '&to=' . urlencode($to) . '&export=csv')) ?>">Export CSV</a>
        </form>

        <!-- Quick Filter Shortcuts -->
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'))) ?>">Today</a>
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-01') . '&to=' . date('Y-m-d'))) ?>">This Month</a>
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-d', strtotime('-30 days')) . '&to=' . date('Y-m-d'))) ?>">Last 30 Days</a>
        </div>
    </div>
</div>

<div class="stats">
    <div class="stat"><span>Orders (excluding cancelled/returned)</span><strong><?= $summary['order_count'] ?></strong></div>
    <div class="stat"><span>Order value</span><strong><?= money($summary['gross']) ?></strong></div>
    <div class="stat"><span>Discounts granted</span><strong><?= money($summary['discounts']) ?></strong></div>
    <div class="stat"><span>Average order value</span><strong><?= money($summary['average_order']) ?></strong></div>
</div>

<div class="form-grid">
    <div class="panel">
        <h2>Orders by status</h2>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Orders</th>
                    <th>Order value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byStatus as $r): ?>
                    <tr>
                        <td><span class="status" style="padding:2px 8px; font-size:12px; font-weight:bold;"><?= ucfirst(e($r['status'])) ?></span></td>
                        <td><?= $r['count'] ?></td>
                        <td><?= money($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2>Top products by units</h2>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Units</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top as $r): ?>
                    <tr>
                        <td><b><?= e($r['product_name']) ?></b></td>
                        <td><span class="status" style="padding:2px 6px; background:#e8f8f5; color:#27ae60; font-weight:bold;"><?= $r['units'] ?></span></td>
                        <td><?= money($r['revenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$top): ?>
                    <tr>
                        <td colspan="3">No sales recorded in this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="muted small">Report period: <?= e($from) ?> through <?= e($to) ?>. Revenue excludes cancelled and returned orders; COD order value is confirmed upon final delivery.</p>

<?php admin_footer(); ?>