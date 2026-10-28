<?php require_once __DIR__ . '/../_init.php';

admin_required();
require_permission('analytics');

$title = 'Enterprise Business Intelligence & Traffic Hub';

// --- 1. DATE RANGE & COMPARISON SETUP ---
$range = trim((string)($_GET['range'] ?? 'today'));
switch ($range) {
    case '7days':
        $startDate     = date('Y-m-d', strtotime('-6 days'));
        $endDate       = date('Y-m-d');
        $prevStartDate = date('Y-m-d', strtotime('-13 days'));
        $prevEndDate   = date('Y-m-d', strtotime('-7 days'));
        $rangeLabel    = 'Last 7 Days';
        break;
    case '30days':
        $startDate     = date('Y-m-d', strtotime('-29 days'));
        $endDate       = date('Y-m-d');
        $prevStartDate = date('Y-m-d', strtotime('-59 days'));
        $prevEndDate   = date('Y-m-d', strtotime('-30 days'));
        $rangeLabel    = 'Last 30 Days';
        break;
    case 'today':
    default:
        $startDate     = date('Y-m-d');
        $endDate       = date('Y-m-d');
        $prevStartDate = date('Y-m-d', strtotime('-1 day'));
        $prevEndDate   = date('Y-m-d', strtotime('-1 day'));
        $rangeLabel    = 'Today';
        break;
}

// --- 2. CSV EXECUTIVE REPORT EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'executive-analytics-' . $range . '-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    log_admin_activity('analytics', 'export_report', "Exported executive analytics report for range: {$rangeLabel}");

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Hour', 'Page Views', 'Unique Visitors', 'Valid Orders', 'Gross Revenue (Tk)', 'Est. COGS (Tk)', 'Net Profit (Tk)', 'Avg Order Value (Tk)']);

    if ($range === 'today') {
        $expStmt = db()->query("
            SELECT 
                CONCAT(HOURS.hr, ':00') AS label_key,
                COALESCE(v.visits, 0) AS visits,
                COALESCE(v.unique_visits, 0) AS unique_visits,
                COALESCE(o.orders, 0) AS orders,
                COALESCE(o.revenue, 0) AS revenue,
                COALESCE(o.cogs, 0) AS cogs
            FROM (
                SELECT 0 AS hr UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
                UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 
                UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 
                UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23
            ) AS HOURS
            LEFT JOIN (
                SELECT HOUR(created_at) AS hr, COUNT(*) AS visits, COUNT(DISTINCT ip_address) AS unique_visits
                FROM visitor_logs WHERE DATE(created_at) = CURDATE() GROUP BY HOUR(created_at)
            ) v ON v.hr = HOURS.hr
            LEFT JOIN (
                SELECT 
                    HOUR(o.created_at) AS hr, 
                    COUNT(DISTINCT o.id) AS orders, 
                    SUM(o.total) AS revenue,
                    SUM(c.item_cogs) AS cogs
                FROM orders o
                LEFT JOIN (
                    SELECT order_id, SUM(quantity * cost_price) AS item_cogs 
                    FROM order_items GROUP BY order_id
                ) c ON c.order_id = o.id
                WHERE o.status NOT IN ('cancelled', 'returned') AND DATE(o.created_at) = CURDATE() 
                GROUP BY HOUR(o.created_at)
            ) o ON o.hr = HOURS.hr
            ORDER BY HOURS.hr ASC
        ");
    } else {
        $expStmt = db()->prepare("
            SELECT 
                d.dt AS label_key,
                COALESCE(v.visits, 0) AS visits,
                COALESCE(v.unique_visits, 0) AS unique_visits,
                COALESCE(o.orders, 0) AS orders,
                COALESCE(o.revenue, 0) AS revenue,
                COALESCE(o.cogs, 0) AS cogs
            FROM (
                SELECT DATE(DATE_SUB(?, INTERVAL seq DAY)) AS dt
                FROM (
                    SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
                    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 
                    UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 
                    UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 
                    UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                ) AS seqs
                WHERE DATE_SUB(?, INTERVAL seq DAY) >= ?
            ) d
            LEFT JOIN (
                SELECT DATE(created_at) AS dt, COUNT(*) AS visits, COUNT(DISTINCT ip_address) AS unique_visits
                FROM visitor_logs WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)
            ) v ON v.dt = d.dt
            LEFT JOIN (
                SELECT 
                    DATE(o.created_at) AS dt, 
                    COUNT(DISTINCT o.id) AS orders, 
                    SUM(o.total) AS revenue,
                    SUM(c.item_cogs) AS cogs
                FROM orders o
                LEFT JOIN (
                    SELECT order_id, SUM(quantity * cost_price) AS item_cogs 
                    FROM order_items GROUP BY order_id
                ) c ON c.order_id = o.id
                WHERE o.status NOT IN ('cancelled', 'returned') AND DATE(o.created_at) BETWEEN ? AND ? 
                GROUP BY DATE(o.created_at)
            ) o ON o.dt = d.dt
            ORDER BY d.dt ASC
        ");
        $expStmt->execute([$endDate, $endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);
    }

    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        $ordCount = (int)$r['orders'];
        $revVal   = (float)$r['revenue'];
        $cogsVal  = (float)$r['cogs'];
        $profit   = $revVal - $cogsVal;
        $aovVal   = $ordCount > 0 ? round($revVal / $ordCount, 2) : 0.0;
        fputcsv($out, [$r['label_key'], $r['visits'], $r['unique_visits'], $ordCount, number_format($revVal, 2), number_format($cogsVal, 2), number_format($profit, 2), number_format($aovVal, 2)]);
    }
    fclose($out);
    exit;
}

// --- 3. CURRENT vs PRIOR PERIOD METRICS (INCL. PROFIT & COGS) ---
function getPeriodStats($sDate, $eDate)
{
    $st = db()->prepare("
        SELECT 
            (SELECT COUNT(*) FROM visitor_logs WHERE DATE(created_at) BETWEEN ? AND ?) AS views,
            (SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(created_at) BETWEEN ? AND ?) AS uniques,
            (SELECT COUNT(*) FROM orders WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) BETWEEN ? AND ?) AS orders,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) BETWEEN ? AND ?) AS revenue,
            (SELECT COALESCE(SUM(oi.quantity * oi.cost_price), 0) 
             FROM order_items oi 
             JOIN orders o ON o.id = oi.order_id 
             WHERE o.status NOT IN ('cancelled', 'returned') AND DATE(o.created_at) BETWEEN ? AND ?) AS cogs
    ");
    $st->execute([$sDate, $eDate, $sDate, $eDate, $sDate, $eDate, $sDate, $eDate, $sDate, $eDate]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

$curr = getPeriodStats($startDate, $endDate);
$prev = getPeriodStats($prevStartDate, $prevEndDate);

$viewsCurr   = (int)($curr['views'] ?? 0);
$uniquesCurr = (int)($curr['uniques'] ?? 0);
$ordersCurr  = (int)($curr['orders'] ?? 0);
$revenueCurr = (float)($curr['revenue'] ?? 0.0);
$cogsCurr    = (float)($curr['cogs'] ?? 0.0);
$profitCurr  = $revenueCurr - $cogsCurr;

$viewsPrev   = (int)($prev['views'] ?? 0);
$uniquesPrev = (int)($prev['uniques'] ?? 0);
$ordersPrev  = (int)($prev['orders'] ?? 0);
$revenuePrev = (float)($prev['revenue'] ?? 0.0);
$cogsPrev    = (float)($prev['cogs'] ?? 0.0);
$profitPrev  = $revenuePrev - $cogsPrev;

function calcDeltaPercent($current, $previous)
{
    if ($previous <= 0) return $current > 0 ? 100.0 : 0.0;
    return round((($current - $previous) / $previous) * 100, 1);
}

$deltaViews   = calcDeltaPercent($viewsCurr, $viewsPrev);
$deltaUniques = calcDeltaPercent($uniquesCurr, $uniquesPrev);
$deltaOrders  = calcDeltaPercent($ordersCurr, $ordersPrev);
$deltaRevenue = calcDeltaPercent($revenueCurr, $revenuePrev);
$deltaProfit  = calcDeltaPercent($profitCurr, $profitPrev);

$aovCurr    = $ordersCurr > 0 ? round($revenueCurr / $ordersCurr, 2) : 0.0;
$convCurr   = $uniquesCurr > 0 ? round(($ordersCurr / $uniquesCurr) * 100, 2) : 0.0;
$marginCurr = $revenueCurr > 0 ? round(($profitCurr / $revenueCurr) * 100, 1) : 0.0;

// --- 4. DEVICE & TRAFFIC ATTRIBUTION ---
$deviceStmt = db()->prepare("
    SELECT COALESCE(device_type, 'Desktop') AS device, COUNT(*) AS count 
    FROM visitor_logs 
    WHERE DATE(created_at) BETWEEN ? AND ? 
    GROUP BY COALESCE(device_type, 'Desktop')
");
$deviceStmt->execute([$startDate, $endDate]);
$deviceBreakdown = $deviceStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Top Browsers
$browserStmt = db()->prepare("
    SELECT COALESCE(browser, 'Other') AS bname, COUNT(*) AS count 
    FROM visitor_logs 
    WHERE DATE(created_at) BETWEEN ? AND ? 
    GROUP BY COALESCE(browser, 'Other') ORDER BY count DESC LIMIT 4
");
$browserStmt->execute([$startDate, $endDate]);
$browserBreakdown = $browserStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 5. FUNNEL, FULFILLMENT & TOP PRODUCT QUERIES ---
$funnelStmt = db()->prepare("
    SELECT 
        (SELECT COUNT(*) FROM visitor_logs WHERE page_url LIKE '%product.php%' AND DATE(created_at) BETWEEN ? AND ?) AS prod_views,
        (SELECT COUNT(*) FROM visitor_logs WHERE page_url LIKE '%cart.php%' AND DATE(created_at) BETWEEN ? AND ?) AS cart_views,
        (SELECT COUNT(*) FROM visitor_logs WHERE page_url LIKE '%checkout.php%' AND DATE(created_at) BETWEEN ? AND ?) AS checkout_views
");
$funnelStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$funnelRaw = $funnelStmt->fetch(PDO::FETCH_ASSOC);

$funnelProd     = (int)($funnelRaw['prod_views'] ?? 0);
$funnelCart     = (int)($funnelRaw['cart_views'] ?? 0);
$funnelCheckout = (int)($funnelRaw['checkout_views'] ?? 0);

$statusStmt = db()->prepare("
    SELECT status, COUNT(id) AS count
    FROM orders
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY status
");
$statusStmt->execute([$startDate, $endDate]);
$orderStatuses = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$topProductsStmt = db()->prepare("
    SELECT oi.product_name, SUM(oi.quantity) as units_sold, SUM(oi.line_total) as revenue_generated
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status NOT IN ('cancelled', 'returned') AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY oi.product_id, oi.product_name
    ORDER BY units_sold DESC LIMIT 5
");
$topProductsStmt->execute([$startDate, $endDate]);
$topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

$topCitiesStmt = db()->prepare("
    SELECT city, COUNT(id) AS orders_count, SUM(total) AS city_revenue 
    FROM orders 
    WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY city ORDER BY city_revenue DESC LIMIT 5
");
$topCitiesStmt->execute([$startDate, $endDate]);
$topCities = $topCitiesStmt->fetchAll(PDO::FETCH_ASSOC);

$topPagesStmt = db()->prepare("
    SELECT page_url, COUNT(*) AS hits 
    FROM visitor_logs 
    WHERE DATE(created_at) BETWEEN ? AND ? 
    GROUP BY page_url 
    ORDER BY hits DESC LIMIT 5
");
$topPagesStmt->execute([$startDate, $endDate]);
$topPages = $topPagesStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 6. COMPREHENSIVE EMAIL SNAPSHOT HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_snapshot_email') {
    verify_csrf();

    $adminEmail = trim(site_setting('admin_email', envv('MAIL_FROM_ADDRESS', '')));

    if ($adminEmail !== '') {
        $subject = '📊 Executive BI Analytics Snapshot (' . $rangeLabel . ') - ' . store_name();

        $topProdHtml = '';
        if (!empty($topProducts)) {
            foreach ($topProducts as $tp) {
                $topProdHtml .= '<tr style="border-bottom: 1px solid #eee;"><td style="padding:8px; font-size:13px;"><b>' . e($tp['product_name']) . '</b></td><td style="padding:8px; font-size:13px; text-align:center; color:#2563eb; font-weight:bold;">' . (int)$tp['units_sold'] . '</td><td style="padding:8px; font-size:13px; text-align:right; color:#16a34a; font-weight:bold;">' . money($tp['revenue_generated']) . '</td></tr>';
            }
        } else {
            $topProdHtml = '<tr><td colspan="3" style="padding:10px; text-align:center; color:#999; font-size:13px;">No sales in this period.</td></tr>';
        }

        $topCityHtml = '';
        if (!empty($topCities)) {
            foreach ($topCities as $tc) {
                $topCityHtml .= '<tr style="border-bottom: 1px solid #eee;"><td style="padding:8px; font-size:13px;"><b>' . e($tc['city'] ?: 'Unspecified') . '</b></td><td style="padding:8px; font-size:13px; text-align:center; color:#2563eb; font-weight:bold;">' . (int)$tc['orders_count'] . '</td><td style="padding:8px; font-size:13px; text-align:right; color:#16a34a; font-weight:bold;">' . money($tc['city_revenue']) . '</td></tr>';
            }
        } else {
            $topCityHtml = '<tr><td colspan="3" style="padding:10px; text-align:center; color:#999; font-size:13px;">No city data in this period.</td></tr>';
        }

        $body = '
        <div style="font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; padding: 25px; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff;">
            <div style="border-bottom: 2px solid #2563eb; padding-bottom: 12px; margin-bottom: 20px;">
                <h2 style="color: #2563eb; margin: 0; font-size: 22px;">' . e(store_name()) . ' - Executive BI Report</h2>
                <p style="color: #64748b; font-size: 13px; margin: 4px 0 0 0;">Active Window: <b>' . e($rangeLabel) . ' (' . date('d M Y') . ')</b></p>
            </div>
            
            <h3 style="color: #0f172a; font-size: 15px; margin-bottom: 10px;">📈 Key Performance Indicators</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px;">
                <tr style="background: #f8fafc;"><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Gross Revenue</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #16a34a; font-size: 16px;">' . money($revenueCurr) . '</td></tr>
                <tr><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Est. Net Profit</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #2563eb; font-size: 16px;">' . money($profitCurr) . ' (' . $marginCurr . '%)</td></tr>
                <tr style="background: #f8fafc;"><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Valid Orders</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #2563eb;">' . number_format($ordersCurr) . '</td></tr>
                <tr><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Page Views</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold;">' . number_format($viewsCurr) . '</td></tr>
                <tr style="background: #f8fafc;"><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Unique Visitors</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #0d9488;">' . number_format($uniquesCurr) . '</td></tr>
                <tr><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Conversion Rate</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #d97706;">' . $convCurr . '%</td></tr>
                <tr style="background: #f8fafc;"><td style="padding: 10px; border: 1px solid #e2e8f0; font-weight: bold;">Average Order Value (AOV)</td><td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #4f46e5;">' . money($aovCurr) . '</td></tr>
            </table>

            <h3 style="color: #0f172a; font-size: 15px; margin-bottom: 8px;">🏆 Best Selling Products</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px; border: 1px solid #e2e8f0;">
                <thead><tr style="background: #2563eb; color: #ffffff; font-size: 12px; text-transform: uppercase;"><th style="padding: 8px; text-align: left;">Product</th><th style="padding: 8px; text-align: center;">Units</th><th style="padding: 8px; text-align: right;">Revenue</th></tr></thead>
                <tbody>' . $topProdHtml . '</tbody>
            </table>

            <h3 style="color: #0f172a; font-size: 15px; margin-bottom: 8px;">📍 Geographic Demand (Top Cities)</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px; border: 1px solid #e2e8f0;">
                <thead><tr style="background: #0f172a; color: #ffffff; font-size: 12px; text-transform: uppercase;"><th style="padding: 8px; text-align: left;">City</th><th style="padding: 8px; text-align: center;">Orders</th><th style="padding: 8px; text-align: right;">Spend</th></tr></thead>
                <tbody>' . $topCityHtml . '</tbody>
            </table>
        </div>';

        if (send_store_email($adminEmail, 'Admin', $subject, $body)) {
            flash('success', 'Executive analytics snapshot emailed successfully to ' . e($adminEmail) . '!');
        } else {
            flash('error', 'Failed to send email. Check SMTP settings in Site Settings or .env.');
        }
    } else {
        flash('error', 'Admin email address is not configured.');
    }

    redirect('admin/system/analytics?range=' . urlencode($range));
}

// --- 7. AUTOMATED SERVER TRAFFIC THRESHOLD & AUTO-EMAIL ALERT ---
$todayTotalViews   = (int)db()->query("SELECT COUNT(*) FROM visitor_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayTotalUniques = (int)db()->query("SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayTotalOrders  = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) = CURDATE()")->fetchColumn();

$hostingStatus = 'NORMAL';
$advisoryMessage = 'Your current traffic is well within server capacity limits.';

if ($todayTotalViews >= 500 || $todayTotalUniques >= 100 || $todayTotalOrders >= 15) {
    $hostingStatus = 'WARNING';
    $advisoryMessage = '⚠️ <b>Server High Traffic Advisory:</b> Daily load is high (' . number_format($todayTotalViews) . ' views, ' . number_format($todayTotalUniques) . ' visitors). Consider upgrading hosting to prevent downtime.';

    $alertSentKey = '_traffic_alert_sent_' . date('Y-m-d');
    if (empty($_SESSION[$alertSentKey])) {
        $adminEmail = trim(site_setting('admin_email', envv('MAIL_FROM_ADDRESS', '')));
        if ($adminEmail !== '') {
            $alertSubj = '🚨 High Traffic Alert - ' . store_name() . ' (' . date('d M Y') . ')';
            $alertBody = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #fecaca; border-radius: 8px; background: #fff5f5;">
                <h2 style="color: #dc2626; margin-top: 0;">⚠️ Server Load Threshold Exceeded</h2>
                <p>Your store <b>' . e(store_name()) . '</b> has exceeded standard daily free hosting load thresholds today:</p>
                <ul>
                    <li><b>Page Views:</b> ' . number_format($todayTotalViews) . '</li>
                    <li><b>Unique Visitors:</b> ' . number_format($todayTotalUniques) . '</li>
                    <li><b>Orders Today:</b> ' . number_format($todayTotalOrders) . '</li>
                </ul>
            </div>';

            if (send_store_email($adminEmail, 'Admin', $alertSubj, $alertBody)) {
                $_SESSION[$alertSentKey] = true;
            }
        }
    }
}

// --- 8. REAL-TIME ACTIVE VISITORS (LAST 5 MINUTES) ---
$liveActiveVisitors = (int)db()->query("
    SELECT COUNT(DISTINCT ip_address) 
    FROM visitor_logs 
    WHERE created_at >= NOW() - INTERVAL 5 MINUTE
")->fetchColumn();

// --- 9. CHART DATASET GENERATION ---
$labels  = [];
$visits  = [];
$uniques = [];
$orders  = [];

if ($range === 'today') {
    $chartTitle = "Hourly Activity Matrix (Today)";
    $chartStmt = db()->prepare("
        SELECT HOURS.hr AS label_key, COALESCE(v.visits, 0) AS visits, COALESCE(v.unique_visits, 0) AS unique_visits, COALESCE(o.orders, 0) AS orders
        FROM (SELECT 0 AS hr UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23) AS HOURS
        LEFT JOIN (SELECT HOUR(created_at) AS hr, COUNT(*) AS visits, COUNT(DISTINCT ip_address) AS unique_visits FROM visitor_logs WHERE DATE(created_at) = CURDATE() GROUP BY HOUR(created_at)) v ON v.hr = HOURS.hr
        LEFT JOIN (SELECT HOUR(created_at) AS hr, COUNT(*) AS orders FROM orders WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at)) o ON o.hr = HOURS.hr
        ORDER BY HOURS.hr ASC
    ");
    $chartStmt->execute();
    while ($r = $chartStmt->fetch(PDO::FETCH_ASSOC)) {
        $labels[]  = sprintf("%02d:00", $r['label_key']);
        $visits[]  = (int)$r['visits'];
        $uniques[] = (int)$r['unique_visits'];
        $orders[]  = (int)$r['orders'];
    }
} else {
    $chartTitle = "Daily Activity Matrix (" . $rangeLabel . ")";
    $chartStmt = db()->prepare("
        SELECT d.dt AS label_key, COALESCE(v.visits, 0) AS visits, COALESCE(v.unique_visits, 0) AS unique_visits, COALESCE(o.orders, 0) AS orders
        FROM (
            SELECT DATE(DATE_SUB(?, INTERVAL seq DAY)) AS dt
            FROM (SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29) AS seqs
            WHERE DATE_SUB(?, INTERVAL seq DAY) >= ?
        ) d
        LEFT JOIN (SELECT DATE(created_at) AS dt, COUNT(*) AS visits, COUNT(DISTINCT ip_address) AS unique_visits FROM visitor_logs WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)) v ON v.dt = d.dt
        LEFT JOIN (SELECT DATE(created_at) AS dt, COUNT(*) AS orders FROM orders WHERE status NOT IN ('cancelled', 'returned') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)) o ON o.dt = d.dt
        ORDER BY d.dt ASC
    ");
    $chartStmt->execute([$endDate, $endDate, $startDate, $startDate, $endDate, $startDate, $endDate]);
    while ($r = $chartStmt->fetch(PDO::FETCH_ASSOC)) {
        $labels[]  = date('d M', strtotime($r['label_key']));
        $visits[]  = (int)$r['visits'];
        $uniques[] = (int)$r['unique_visits'];
        $orders[]  = (int)$r['orders'];
    }
}

admin_header($title); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .bi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 22px;
    }

    .bi-live-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #ecfdf5;
        border: 1px solid #10b981;
        color: #065f46;
        padding: 6px 14px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 13px;
    }

    .bi-live-dot {
        width: 9px;
        height: 9px;
        background: #10b981;
        border-radius: 50%;
        animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
        0% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.4;
            transform: scale(1.3);
        }

        100% {
            opacity: 1;
            transform: scale(1);
        }
    }

    .grid-kpi {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .kpi-card {
        background: var(--panel-bg, #fff);
        padding: 18px;
        border-radius: 10px;
        border: 1px solid var(--line, #cbd5e1);
    }

    .kpi-card h3 {
        margin: 0 0 6px;
        font-size: 11px;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .kpi-card .value {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
    }

    .delta-tag {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        margin-top: 6px;
    }

    .delta-up {
        background: #dcfce7;
        color: #15803d;
    }

    .delta-down {
        background: #fee2e2;
        color: #b91c1c;
    }

    .grid-split {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }

    @media (max-width: 1024px) {
        .grid-split {
            grid-template-columns: 1fr;
        }
    }

    .chart-box {
        background: var(--panel-bg, #fff);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--line, #cbd5e1);
    }

    .funnel-step {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        background: #f8fafc;
        border-radius: 6px;
        margin-bottom: 8px;
        border-left: 4px solid #2563eb;
    }

    .status-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }

    .alert-banner {
        padding: 14px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        line-height: 1.5;
    }

    .alert-normal {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
    }

    .alert-warning {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }
</style>

<div class="panel" style="margin-bottom: 25px;">
    <!-- BI Header & Controls -->
    <div class="bi-header">
        <div>
            <h2 style="margin: 0; font-size: 22px;">Executive Business Intelligence</h2>
            <span class="muted small">Active Window: <b><?= e($rangeLabel) ?></b> (vs. Prior Period)</span>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div class="bi-live-badge">
                <span class="bi-live-dot"></span>
                <span><?= number_format($liveActiveVisitors) ?> Live Active Now</span>
            </div>

            <!-- Date Range Selector -->
            <form method="GET" style="margin:0;">
                <select name="range" onchange="this.form.submit()" style="padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 700; font-size: 13px;">
                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="7days" <?= $range === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30days" <?= $range === '30days' ? 'selected' : '' ?>>Last 30 Days</option>
                </select>
            </form>

            <!-- Refresh Button -->
            <button onclick="location.reload()" class="button" style="padding: 7px 14px; font-size: 13px; font-weight:700;">
                🔄 Refresh
            </button>

            <!-- CSV Export -->
            <a href="<?= e(url('admin/system/analytics?range=' . urlencode($range) . '&export=csv')) ?>" class="button secondary" style="padding: 7px 12px; font-size: 12px; font-weight:700;">
                📥 Export CSV
            </a>

            <!-- Snapshot Email Button -->
            <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_snapshot_email">
                <button type="submit" class="button secondary" style="padding: 7px 12px; font-size: 12px;">✉️ Email Snapshot</button>
            </form>
        </div>
    </div>

    <!-- Server Traffic Load Advisory Banner -->
    <div class="alert-banner <?= $hostingStatus === 'WARNING' ? 'alert-warning' : 'alert-normal' ?>">
        <?= $advisoryMessage ?>
    </div>

    <!-- KPI Metrics Grid with Comparison Deltas & Net Profit -->
    <div class="grid-kpi">
        <div class="kpi-card">
            <h3>Gross Revenue</h3>
            <div class="value"><?= money($revenueCurr) ?></div>
            <span class="delta-tag <?= $deltaRevenue >= 0 ? 'delta-up' : 'delta-down' ?>">
                <?= $deltaRevenue >= 0 ? '▲' : '▼' ?> <?= abs($deltaRevenue) ?>% vs prior
            </span>
        </div>

        <div class="kpi-card">
            <h3>Est. Net Profit</h3>
            <div class="value" style="color: #16a34a;"><?= money($profitCurr) ?></div>
            <span class="delta-tag <?= $deltaProfit >= 0 ? 'delta-up' : 'delta-down' ?>">
                <?= $deltaProfit >= 0 ? '▲' : '▼' ?> <?= abs($deltaProfit) ?>% vs prior
            </span>
        </div>

        <div class="kpi-card">
            <h3>Profit Margin</h3>
            <div class="value" style="color: #2563eb;"><?= $marginCurr ?>%</div>
            <span class="muted small" style="display:block; margin-top:4px;">Net Profit / Revenue</span>
        </div>

        <div class="kpi-card">
            <h3>Completed Orders</h3>
            <div class="value"><?= number_format($ordersCurr) ?></div>
            <span class="delta-tag <?= $deltaOrders >= 0 ? 'delta-up' : 'delta-down' ?>">
                <?= $deltaOrders >= 0 ? '▲' : '▼' ?> <?= abs($deltaOrders) ?>% vs prior
            </span>
        </div>

        <div class="kpi-card">
            <h3>Conversion Rate</h3>
            <div class="value" style="color: #d97706;"><?= $convCurr ?>%</div>
            <span class="muted small" style="display:block; margin-top:4px;">Orders / Visitors</span>
        </div>

        <div class="kpi-card">
            <h3>Avg. Order Value</h3>
            <div class="value" style="color: #4f46e5;"><?= money($aovCurr) ?></div>
            <span class="muted small" style="display:block; margin-top:4px;">Revenue / Order</span>
        </div>
    </div>

    <!-- Main Chart & Conversion Funnel Grid -->
    <div class="grid-split">
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:16px;"><?= e($chartTitle) ?></h3>
            <canvas id="mainChart" height="110"></canvas>
        </div>

        <!-- E-Commerce Dropoff Funnel -->
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">📊 Conversion Funnel</h3>
            <p class="muted small" style="margin-top:0; margin-bottom:15px;">Storefront user journey progression.</p>

            <div class="funnel-step" style="border-color: #2563eb;">
                <div><b>1. Product Pages</b><br><span class="muted small">Product detail views</span></div>
                <div style="font-weight:800; font-size:16px;"><?= number_format($funnelProd) ?></div>
            </div>

            <div class="funnel-step" style="border-color: #0d9488;">
                <div><b>2. Cart Items</b><br><span class="muted small">Cart reviews</span></div>
                <div style="font-weight:800; font-size:16px;"><?= number_format($funnelCart) ?></div>
            </div>

            <div class="funnel-step" style="border-color: #d97706;">
                <div><b>3. Checkout Started</b><br><span class="muted small">Checkout visits</span></div>
                <div style="font-weight:800; font-size:16px;"><?= number_format($funnelCheckout) ?></div>
            </div>

            <div class="funnel-step" style="border-color: #16a34a; background:#f0fdf4;">
                <div><b>4. Orders Placed</b><br><span class="muted small">Completed purchases</span></div>
                <div style="font-weight:800; font-size:16px; color:#16a34a;"><?= number_format($ordersCurr) ?></div>
            </div>

            <!-- Fulfillment Status Health Breakdown -->
            <div style="margin-top:20px; padding-top:15px; border-top:1px solid #cbd5e1;">
                <h4 style="margin:0 0 8px 0; font-size:13px; color:#475569;">Fulfillment Health Breakdown</h4>
                <div class="status-row"><span>🟡 Pending Confirmation:</span> <b><?= (int)($orderStatuses['pending'] ?? 0) ?></b></div>
                <div class="status-row"><span>🔵 Confirmed / In Packing:</span> <b><?= (int)($orderStatuses['confirmed'] ?? 0) ?></b></div>
                <div class="status-row"><span>🟣 Dispatched / Shipped:</span> <b><?= (int)($orderStatuses['shipped'] ?? 0) ?></b></div>
                <div class="status-row"><span>🟢 Successfully Delivered:</span> <b style="color:#16a34a;"><?= (int)($orderStatuses['delivered'] ?? 0) ?></b></div>
            </div>
        </div>
    </div>

    <!-- Device Type & Hardware Breakdown Section -->
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">📱 Device Share Matrix</h3>
            <div style="display:flex; justify-content:space-around; align-items:center; padding:15px 0;">
                <div style="text-align:center;">
                    <div style="font-size:22px;">📱</div>
                    <div style="font-weight:800; font-size:18px; margin-top:4px;"><?= number_format((int)($deviceBreakdown['Mobile'] ?? 0)) ?></div>
                    <span class="muted small">Mobile Users</span>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:22px;">💻</div>
                    <div style="font-weight:800; font-size:18px; margin-top:4px;"><?= number_format((int)($deviceBreakdown['Desktop'] ?? 0)) ?></div>
                    <span class="muted small">Desktop Users</span>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:22px;">📟</div>
                    <div style="font-weight:800; font-size:18px; margin-top:4px;"><?= number_format((int)($deviceBreakdown['Tablet'] ?? 0)) ?></div>
                    <span class="muted small">Tablet Users</span>
                </div>
            </div>
        </div>

        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">🌐 Top Web Browsers</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #cbd5e1; text-align:left; background:#f8fafc;">
                        <th style="padding:6px 10px;">Browser Engine</th>
                        <th style="padding:6px 10px; text-align:right;">Traffic Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($browserBreakdown as $bb): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:6px 10px; font-weight:600;"><?= e($bb['bname']) ?></td>
                            <td style="padding:6px 10px; text-align:right; font-weight:700; color:#2563eb;"><?= number_format((int)$bb['count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$browserBreakdown): ?>
                        <tr>
                            <td colspan="2" class="muted" style="padding:10px; text-align:center;">No browser metrics recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bottom Tables: Top Sellers, Geographic Locations & Popular Pages -->
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
        <!-- Top Products Table -->
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">🏆 Top Best Sellers</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #cbd5e1; text-align:left; background:#f8fafc;">
                        <th style="padding:8px 10px;">Product Name</th>
                        <th style="padding:8px 10px; text-align:center;">Units</th>
                        <th style="padding:8px 10px; text-align:right;">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProducts as $tp): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px; font-weight:600; color:#0f172a;"><?= e($tp['product_name']) ?></td>
                            <td style="padding:8px 10px; text-align:center; font-weight:700; color:#2563eb;"><?= (int)$tp['units_sold'] ?></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:700; color:#16a34a;"><?= money($tp['revenue_generated']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topProducts): ?>
                        <tr>
                            <td colspan="3" class="muted" style="padding:15px; text-align:center;">No completed sales in this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Geographic Revenue Breakdown -->
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">📍 Top Purchasing Cities</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #cbd5e1; text-align:left; background:#f8fafc;">
                        <th style="padding:8px 10px;">City</th>
                        <th style="padding:8px 10px; text-align:center;">Orders</th>
                        <th style="padding:8px 10px; text-align:right;">Spend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topCities as $tc): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px; font-weight:600; color:#0f172a;"><?= e($tc['city'] ?: 'Unspecified') ?></td>
                            <td style="padding:8px 10px; text-align:center; font-weight:700; color:#2563eb;"><?= (int)$tc['orders_count'] ?></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:700; color:#16a34a;"><?= money($tc['city_revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topCities): ?>
                        <tr>
                            <td colspan="3" class="muted" style="padding:15px; text-align:center;">No order locations recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Visited Pages -->
        <div class="chart-box">
            <h3 style="margin-top:0; margin-bottom:12px;">🔥 Popular Page Routes</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #cbd5e1; text-align:left; background:#f8fafc;">
                        <th style="padding:8px 10px;">Page Route</th>
                        <th style="padding:8px 10px; text-align:right;">Total Hits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topPages as $tPage): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px; font-weight:600; color:#0f172a;"><?= e($tPage['page_url'] ?: '/') ?></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:700; color:#2563eb;"><?= number_format((int)$tPage['hits']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topPages): ?>
                        <tr>
                            <td colspan="2" class="muted" style="padding:15px; text-align:center;">No traffic logged for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('mainChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                    label: 'Page Views',
                    data: <?= json_encode($visits) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Unique Visitors',
                    data: <?= json_encode($uniques) ?>,
                    borderColor: '#0d9488',
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    tension: 0.3
                },
                {
                    label: 'Completed Orders',
                    data: <?= json_encode($orders) ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.15)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
</script>

<?php admin_footer(); ?>