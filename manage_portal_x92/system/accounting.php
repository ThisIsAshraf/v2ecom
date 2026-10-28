<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('reports');

$title = 'Accountant Ledger & P&L Statement';

$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

$start = $from . ' 00:00:00';
$end   = $to . ' 23:59:59';
$params = [$start, $end];

// Fetch Full Income Statement (P&L) Data
$pnlStmt = db()->prepare("
    SELECT 
        COALESCE(SUM(o.subtotal), 0) AS gross_sales,
        COALESCE(SUM(o.discount_amount), 0) AS total_discounts,
        COALESCE(SUM(o.shipping_amount), 0) AS shipping_collected,
        COALESCE(SUM(o.total), 0) AS net_revenue,
        COALESCE(SUM(cogs.total_cogs), 0) AS total_cogs
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(quantity * cost_price) AS total_cogs
        FROM order_items
        GROUP BY order_id
    ) cogs ON cogs.order_id = o.id
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled', 'returned')
");
$pnlStmt->execute($params);
$pnl = $pnlStmt->fetch(PDO::FETCH_ASSOC);

$grossSales  = (float)$pnl['gross_sales'];
$discounts   = (float)$pnl['total_discounts'];
$shipping    = (float)$pnl['shipping_collected'];
$netRevenue  = (float)$pnl['net_revenue'];
$cogs        = (float)$pnl['total_cogs'];
$grossProfit = $netRevenue - $cogs;
$marginPct   = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 1) : 0.0;

admin_header($title); ?>

<div class="panel" style="margin-bottom:22px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <h2>Financial Statement & P&L Ledger</h2>
        <form method="get" class="inline-form">
            <input type="date" name="from" value="<?= e($from) ?>">
            <input type="date" name="to" value="<?= e($to) ?>">
            <button class="button">Filter Period</button>
        </form>
    </div>
</div>

<div class="panel" style="max-width:700px; margin:0 auto 25px auto; padding:25px; border:1px solid #cbd5e1;">
    <h3 style="margin-top:0; border-bottom:2px solid #0f172a; padding-bottom:10px;">Income Statement (P&L)</h3>
    <p class="muted small">For period: <b><?= e($from) ?></b> to <b><?= e($to) ?></b></p>

    <table style="width:100%; border-collapse:collapse; margin-top:15px; font-size:14px;">
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 0;">Gross Product Sales</td>
            <td style="text-align:right; font-weight:bold;"><?= money($grossSales) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0; color:#dc2626;">
            <td style="padding:10px 0;">Less: Coupon Discounts</td>
            <td style="text-align:right; font-weight:bold;">- <?= money($discounts) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 0;">Add: Shipping Fees Collected</td>
            <td style="text-align:right; font-weight:bold;">+ <?= money($shipping) ?></td>
        </tr>
        <tr style="border-bottom:2px solid #0f172a; background:#f8fafc; font-size:15px;">
            <td style="padding:12px 10px; font-weight:bold;">Net Operating Revenue</td>
            <td style="padding:12px 10px; text-align:right; font-weight:bold; color:#2563eb;"><?= money($netRevenue) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0; color:#dc2626;">
            <td style="padding:10px 0;">Less: Cost of Goods Sold (COGS)</td>
            <td style="text-align:right; font-weight:bold;">- <?= money($cogs) ?></td>
        </tr>
        <tr style="border-bottom:2px solid #16a34a; background:#f0fdf4; font-size:16px;">
            <td style="padding:14px 10px; font-weight:bold; color:#166534;">GROSS OPERATING PROFIT</td>
            <td style="padding:14px 10px; text-align:right; font-weight:bold; color:#166534;"><?= money($grossProfit) ?></td>
        </tr>
    </table>

    <div style="margin-top:20px; text-align:center; padding:12px; background:#e0e7ff; border-radius:6px; font-size:14px; font-weight:bold; color:#3730a3;">
        Overall Gross Profit Margin: <?= $marginPct ?>%
    </div>
</div>

<?php admin_footer(); ?>