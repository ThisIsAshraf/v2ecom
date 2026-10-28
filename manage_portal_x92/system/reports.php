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

// Summary Statistics SQL (Includes Revenue, COGS, Net Profit & Margin)
$s = db()->prepare("
    SELECT 
        COUNT(DISTINCT o.id) AS order_count, 
        COALESCE(SUM(o.total), 0) AS gross, 
        COALESCE(SUM(o.discount_amount), 0) AS discounts, 
        COALESCE(AVG(o.total), 0) AS average_order,
        COALESCE(SUM(cogs.total_cogs), 0) AS total_cogs
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(quantity * cost_price) AS total_cogs
        FROM order_items
        GROUP BY order_id
    ) cogs ON cogs.order_id = o.id
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled', 'returned')
");
$s->execute($params);
$summary = $s->fetch(PDO::FETCH_ASSOC);

$grossRevenue = (float)$summary['gross'];
$totalCogs    = (float)$summary['total_cogs'];
$netProfit    = $grossRevenue - $totalCogs;
$marginPct    = $grossRevenue > 0 ? round(($netProfit / $grossRevenue) * 100, 1) : 0.0;

// Orders by Status
$s = db()->prepare("SELECT status, COUNT(*) count, COALESCE(SUM(total),0) total FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY count DESC");
$s->execute($params);
$byStatus = $s->fetchAll(PDO::FETCH_ASSOC);

// Top Products by Units & Profitability
$s = db()->prepare("
    SELECT 
        oi.product_name, 
        SUM(oi.quantity) AS units, 
        SUM(oi.line_total) AS revenue,
        SUM(oi.quantity * oi.cost_price) AS cogs,
        (SUM(oi.line_total) - SUM(oi.quantity * oi.cost_price)) AS product_profit
    FROM order_items oi 
    JOIN orders o ON o.id = oi.order_id 
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled', 'returned') 
    GROUP BY oi.product_id, oi.product_name 
    ORDER BY units DESC 
    LIMIT 10
");
$s->execute($params);
$top = $s->fetchAll(PDO::FETCH_ASSOC);

// CSV Export Handler (Includes COGS & Profit per order)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', store_name())) . '-sales-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    log_admin_activity('reports', 'export_csv', "Exported financial sales & profit report from {$from} to {$to}");

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order Number', 'Date', 'Customer Name', 'Phone', 'Status', 'Subtotal', 'Discount', 'Delivery', 'Total Revenue', 'Order COGS', 'Net Profit', 'Payment Method']);

    $s = db()->prepare("
        SELECT 
            o.order_number, 
            o.created_at, 
            o.customer_name, 
            o.customer_phone, 
            o.status, 
            o.subtotal, 
            o.discount_amount, 
            o.shipping_amount, 
            o.total, 
            COALESCE(cogs.order_cogs, 0) AS order_cogs,
            (o.total - COALESCE(cogs.order_cogs, 0)) AS order_profit,
            o.payment_method 
        FROM orders o
        LEFT JOIN (
            SELECT order_id, SUM(quantity * cost_price) AS order_cogs
            FROM order_items
            GROUP BY order_id
        ) cogs ON cogs.order_id = o.id
        WHERE o.created_at BETWEEN ? AND ? 
        ORDER BY o.created_at DESC
    ");
    $s->execute($params);

    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['order_number'],
            $r['created_at'],
            $r['customer_name'],
            $r['customer_phone'],
            $r['status'],
            number_format((float)$r['subtotal'], 2),
            number_format((float)$r['discount_amount'], 2),
            number_format((float)$r['shipping_amount'], 2),
            number_format((float)$r['total'], 2),
            number_format((float)$r['order_cogs'], 2),
            number_format((float)$r['order_profit'], 2),
            $r['payment_method']
        ]);
    }
    fclose($out);
    exit;
}

admin_header('Sales & Profitability Reports'); ?>

<div class="panel" style="margin-bottom:22px">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <!-- Date Form -->
        <form method="get" class="inline-form" style="flex-wrap:wrap">
            <div class="field" style="margin:0"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="field" style="margin:0"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <button class="button">Run Report</button>
            <a class="button secondary" href="<?= e(url('admin/system/reports?from=' . urlencode($from) . '&to=' . urlencode($to) . '&export=csv')) ?>">📥 Export CSV</a>
        </form>

        <!-- Quick Filter Shortcuts -->
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'))) ?>">Today</a>
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-01') . '&to=' . date('Y-m-d'))) ?>">This Month</a>
            <a class="button secondary" style="padding:6px 10px; font-size:12px;" href="<?= e(url('admin/system/reports?from=' . date('Y-m-d', strtotime('-30 days')) . '&to=' . date('Y-m-d'))) ?>">Last 30 Days</a>
        </div>
    </div>
</div>

<!-- Financial Summary KPI Cards -->
<div class="stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px; margin-bottom:22px;">
    <div class="stat"><span>Valid Orders</span><strong><?= number_format((int)$summary['order_count']) ?></strong></div>
    <div class="stat"><span>Gross Revenue</span><strong><?= money($grossRevenue) ?></strong></div>
    <div class="stat"><span style="color:#2563eb;">Est. Net Profit</span><strong style="color:#2563eb;"><?= money($netProfit) ?></strong></div>
    <div class="stat"><span style="color:#16a34a;">Profit Margin</span><strong style="color:#16a34a;"><?= $marginPct ?>%</strong></div>
    <div class="stat"><span>Total COGS</span><strong><?= money($totalCogs) ?></strong></div>
    <div class="stat"><span>Avg Order Value</span><strong><?= money((float)$summary['average_order']) ?></strong></div>
</div>

<div class="form-grid">
    <!-- Orders by Status Table -->
    <div class="panel">
        <h2>Orders by Status</h2>
        <div class="table-wrap">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:8px 10px;">Status</th>
                        <th style="padding:8px 10px; text-align:center;">Orders</th>
                        <th style="padding:8px 10px; text-align:right;">Total Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byStatus as $r): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;"><span class="status" style="padding:2px 8px; font-size:12px; font-weight:bold;"><?= ucfirst(e($r['status'])) ?></span></td>
                            <td style="padding:8px 10px; text-align:center; font-weight:bold;"><?= number_format((int)$r['count']) ?></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:bold;"><?= money($r['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Products & Margin Breakdown Table -->
    <div class="panel">
        <h2>Top Products & Margin Performance</h2>
        <div class="table-wrap">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:8px 10px;">Product Name</th>
                        <th style="padding:8px 10px; text-align:center;">Units</th>
                        <th style="padding:8px 10px; text-align:right;">Revenue</th>
                        <th style="padding:8px 10px; text-align:right;">Net Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $r):
                        $pRev    = (float)$r['revenue'];
                        $pProfit = (float)$r['product_profit'];
                    ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;"><b><?= e($r['product_name']) ?></b></td>
                            <td style="padding:8px 10px; text-align:center;"><span class="status" style="padding:2px 6px; background:#e8f8f5; color:#27ae60; font-weight:bold;"><?= number_format((int)$r['units']) ?></span></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:bold;"><?= money($pRev) ?></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:bold; color:#16a34a;"><?= money($pProfit) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$top): ?>
                        <tr>
                            <td colspan="4" style="padding:15px; text-align:center;" class="muted">No sales recorded in this date range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="muted small" style="margin-top:15px;">Report period: <?= e($from) ?> through <?= e($to) ?>. Revenue & profit metrics exclude cancelled and returned orders.</p>

<?php admin_footer(); ?>