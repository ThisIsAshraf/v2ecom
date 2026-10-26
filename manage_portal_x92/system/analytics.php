<?php require_once __DIR__ . '/../_init.php';

admin_required();
require_permission('analytics');

$title = 'Real-Time Traffic & Order Analytics';

// 1. Fetch Today's Overall Stats
$todayStats = db()->query("
    SELECT 
        (SELECT COUNT(*) FROM visitor_logs WHERE DATE(created_at) = CURDATE()) AS total_views,
        (SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE DATE(created_at) = CURDATE()) AS unique_visitors,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) AS total_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE()) AS total_revenue
")->fetch(PDO::FETCH_ASSOC);

$viewsToday   = (int)($todayStats['total_views'] ?? 0);
$uniquesToday = (int)($todayStats['unique_visitors'] ?? 0);
$ordersToday  = (int)($todayStats['total_orders'] ?? 0);
$revenueToday = (float)($todayStats['total_revenue'] ?? 0.0);

// 2. Handle Manual "Send Email Snapshot" Button Click
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_snapshot_email') {
    verify_csrf();

    $adminEmail = trim(site_setting('admin_email', envv('MAIL_FROM_ADDRESS', '')));

    if ($adminEmail !== '') {
        $subject = '📊 Daily Analytics Snapshot - ' . date('d M, Y') . ' (' . store_name() . ')';
        $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff;">
            <h2 style="color: #2563eb; margin-top: 0; border-bottom: 2px solid #2563eb; padding-bottom: 10px;">
                ' . e(store_name()) . ' - Analytics Snapshot
            </h2>
            <p style="color: #475569; font-size: 14px;">Here is your store performance snapshot for <b>' . date('l, d F Y') . '</b>:</p>
            
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr style="background: #f8fafc;">
                    <td style="padding: 12px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Total Page Views</td>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #2563eb;">' . number_format($viewsToday) . '</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Unique Visitors</td>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #0d9488;">' . number_format($uniquesToday) . '</td>
                </tr>
                <tr style="background: #f8fafc;">
                    <td style="padding: 12px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Orders Placed Today</td>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #16a34a;">' . number_format($ordersToday) . '</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Today\'s Total Revenue</td>
                    <td style="padding: 12px; border: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #0f172a;">' . money($revenueToday) . '</td>
                </tr>
            </table>

            <p style="font-size: 12px; color: #94a3b8; text-align: center; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                Generated manually from Admin Portal at ' . date('h:i A') . '
            </p>
        </div>';

        $sent = send_store_email($adminEmail, 'Admin', $subject, $body);

        if ($sent) {
            flash('success', 'Analytics snapshot successfully emailed to ' . e($adminEmail) . '!');
        } else {
            flash('error', 'Failed to send email. Please check your SMTP settings in Site Settings or .env file.');
        }
    } else {
        flash('error', 'Admin email is not configured in site settings or .env file.');
    }

    redirect('admin/system/analytics');
}

// 3. Automated Hosting Threshold Logic
$hostingStatus = 'NORMAL';
$advisoryMessage = 'Your current traffic is well within free hosting limits.';

if ($viewsToday >= 500 || $uniquesToday >= 100 || $ordersToday >= 15) {
    $hostingStatus = 'WARNING';
    $advisoryMessage = '<b>Action Recommended:</b> Your traffic/order volume is approaching free hosting thresholds. Consider upgrading to standard shared hosting or a VPS to prevent site downtime or CPU throttling.';
}

// 4. Fetch Hourly Breakdown (Isolated Subqueries prevent Cartesian Duplication)
$hourlyDataStmt = db()->query("
    SELECT 
        HOURS.hr AS hour,
        COALESCE(v.visits, 0) AS visits,
        COALESCE(v.unique_visits, 0) AS unique_visits,
        COALESCE(o.orders, 0) AS orders
    FROM (
        SELECT 0 AS hr UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
        UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 
        UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 
        UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 
        UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23
    ) AS HOURS
    LEFT JOIN (
        SELECT 
            HOUR(created_at) AS hr, 
            COUNT(*) AS visits, 
            COUNT(DISTINCT ip_address) AS unique_visits
        FROM visitor_logs 
        WHERE DATE(created_at) = CURDATE()
        GROUP BY HOUR(created_at)
    ) v ON v.hr = HOURS.hr
    LEFT JOIN (
        SELECT 
            HOUR(created_at) AS hr, 
            COUNT(*) AS orders
        FROM orders 
        WHERE DATE(created_at) = CURDATE()
        GROUP BY HOUR(created_at)
    ) o ON o.hr = HOURS.hr
    ORDER BY HOURS.hr ASC
");
$hourlyData = $hourlyDataStmt->fetchAll(PDO::FETCH_ASSOC);

$labels  = [];
$visits  = [];
$uniques = [];
$orders  = [];

foreach ($hourlyData as $row) {
    $labels[]  = sprintf("%02d:00", $row['hour']);
    $visits[]  = (int)$row['visits'];
    $uniques[] = (int)$row['unique_visits'];
    $orders[]  = (int)$row['orders'];
}

admin_header($title);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .analytics-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .btn-group {
        display: flex;
        gap: 10px;
    }

    .grid-kpi {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .kpi-card {
        background: var(--panel-bg, #fff);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--line, #e2e8f0);
    }

    .kpi-card h3 {
        margin: 0 0 6px;
        font-size: 13px;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
    }

    .kpi-card .value {
        font-size: 26px;
        font-weight: 700;
        color: #0f172a;
    }

    .chart-box {
        background: var(--panel-bg, #fff);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--line, #e2e8f0);
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
    <div class="analytics-header">
        <h2 style="margin: 0;">Real-Time Visitor & Order Tracking</h2>
        <div class="btn-group">
            <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_snapshot_email">
                <button type="submit" class="button secondary" style="padding: 8px 14px;">
                    ✉️ Email Snapshot to Admin
                </button>
            </form>
            <button onclick="location.reload()" class="button" style="padding: 8px 14px;">🔄 Refresh</button>
        </div>
    </div>

    <div class="alert-banner <?= $hostingStatus === 'WARNING' ? 'alert-warning' : 'alert-normal' ?>">
        <?= $advisoryMessage ?>
    </div>

    <div class="grid-kpi">
        <div class="kpi-card">
            <h3>Total Page Views Today</h3>
            <div class="value"><?= number_format($viewsToday) ?></div>
        </div>
        <div class="kpi-card">
            <h3>Unique Visitors Today</h3>
            <div class="value"><?= number_format($uniquesToday) ?></div>
        </div>
        <div class="kpi-card">
            <h3>Orders Placed Today</h3>
            <div class="value"><?= number_format($ordersToday) ?></div>
        </div>
        <div class="kpi-card">
            <h3>Today's Revenue</h3>
            <div class="value"><?= money($revenueToday) ?></div>
        </div>
    </div>

    <div class="chart-box">
        <h3 style="margin-top: 0; margin-bottom: 16px;">Hourly Activity Breakdown (24 Hours)</h3>
        <canvas id="hourlyChart" height="90"></canvas>
    </div>
</div>

<script>
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                    label: 'Page Views',
                    data: <?= json_encode($visits) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
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
                    label: 'Orders Placed',
                    data: <?= json_encode($orders) ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.2)',
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