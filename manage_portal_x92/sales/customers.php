<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('customers');

$search = trim((string)($_GET['q'] ?? ''));

// SQL Query Base
$whereSql = "WHERE o.status NOT IN ('cancelled', 'returned')";
$params = [];

if ($search !== '') {
    $whereSql .= " AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ? OR o.city LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', store_name())) . '-customers-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Audit Logging: Customer Directory Export
    $filterNote = $search !== '' ? " with search filter: '{$search}'" : " (all customers)";
    log_admin_activity('customers', 'export_csv', 'Exported customer directory and spending intelligence to CSV' . $filterNote);

    $out = fopen('php://output', 'w');

    // Header Row
    fputcsv($out, ['Customer ID', 'Customer Name', 'Email Address', 'Phone Number', 'City / Location', 'Total Orders', 'Lifetime Spend', 'Last Active Date']);

    $exportSql = "
        SELECT 
            MAX(u.id) as user_id,
            o.customer_email,
            MAX(o.customer_name) as customer_name,
            o.customer_phone,
            MAX(o.city) as city,
            COUNT(o.id) as total_orders,
            SUM(o.total) as lifetime_spend,
            MAX(o.created_at) as last_order_date
        FROM orders o
        LEFT JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = o.customer_email COLLATE utf8mb4_unicode_ci
        $whereSql
        GROUP BY o.customer_email, o.customer_phone 
        ORDER BY lifetime_spend DESC
    ";

    $stmt = db()->prepare($exportSql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $usrId = !empty($row['user_id']) ? ('USR-' . $row['user_id']) : 'Guest';
        fputcsv($out, [
            $usrId,
            $row['customer_name'],
            $row['customer_email'],
            $row['customer_phone'],
            $row['city'] ?: 'N/A',
            $row['total_orders'],
            number_format((float)$row['lifetime_spend'], 2),
            $row['last_order_date']
        ]);
    }
    fclose($out);
    exit;
}

// --- PAGINATION LOGIC ---
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

// Count Total Unique Customers
$countSql = "SELECT COUNT(DISTINCT o.customer_email, o.customer_phone) FROM orders o $whereSql";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalCustomers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalCustomers / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Fetch Paginated Customers
$sql = "
    SELECT 
        MAX(u.id) as user_id,
        o.customer_email,
        MAX(o.customer_name) as customer_name,
        o.customer_phone,
        MAX(o.city) as city,
        COUNT(o.id) as total_orders,
        SUM(o.total) as lifetime_spend,
        MAX(o.created_at) as last_order_date
    FROM orders o
    LEFT JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = o.customer_email COLLATE utf8mb4_unicode_ci
    $whereSql
    GROUP BY o.customer_email, o.customer_phone 
    ORDER BY lifetime_spend DESC 
    LIMIT $limit OFFSET $offset
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Customer Order History Inspection View
$inspectCustomer = null;
$customerOrders = [];
if (isset($_GET['inspect_email'])) {
    $inspectEmail = trim((string)$_GET['inspect_email']);
    $s = db()->prepare('SELECT * FROM orders WHERE customer_email = ? ORDER BY id DESC');
    $s->execute([$inspectEmail]);
    $customerOrders = $s->fetchAll(PDO::FETCH_ASSOC);
    if ($customerOrders) {
        $inspectCustomer = $customerOrders[0];

        // Audit Logging: Customer History Inspected
        log_admin_activity(
            'customers',
            'inspect_history',
            "Inspected order history and metrics for customer: {$inspectCustomer['customer_name']} ({$inspectEmail})"
        );
    }
}

admin_header('Customer Directory & Intelligence'); ?>

<!-- Search Bar & Export Controls -->
<div class="panel" style="margin-bottom: 22px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <h2>Customer Directory (<?= number_format($totalCustomers) ?>)</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <form method="get" class="inline-form" style="display:flex; gap:8px;">
                <input name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, city..." style="padding: 6px 12px; min-width: 250px;">
                <button class="button">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="button secondary" href="<?= e(url('admin/sales/customers')) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <a class="button secondary" style="padding: 7px 14px; font-weight:bold;" href="<?= e(url('admin/sales/customers?q=' . urlencode($search) . '&export=csv')) ?>">
                📥 Export CSV
            </a>
        </div>
    </div>
</div>

<!-- Customer Inspection View (If Selected) -->
<?php if ($inspectCustomer): ?>
    <div class="panel" style="margin-bottom: 22px; background: #f8f9fa; border: 1px solid #ddd;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Order History for <?= e($inspectCustomer['customer_name']) ?> (<?= e($inspectCustomer['customer_email']) ?>)</h3>
            <a class="button secondary" style="padding:4px 10px; font-size:12px;" href="<?= e(url('admin/sales/customers')) ?>">Close Inspection</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Tracking Number</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerOrders as $co): ?>
                        <tr>
                            <td><b><?= e($co['order_number']) ?></b></td>
                            <td><?= date('M d, Y', strtotime($co['created_at'])) ?></td>
                            <td><b><?= money($co['total']) ?></b></td>
                            <td>
                                <span class="status" style="padding:2px 6px; font-weight:bold; font-size:11px; background: <?= $co['status'] === 'delivered' ? '#e8f8f5; color:#27ae60;' : '#fef9e7; color:#f39c12;' ?>">
                                    <?= ucfirst(e($co['status'])) ?>
                                </span>
                            </td>
                            <td><?= e($co['tracking_number'] ?: 'Unassigned') ?></td>
                            <td>
                                <a class="button secondary" style="padding:3px 8px; font-size:12px;" href="<?= e(url('admin/sales/orders?id=' . $co['id'])) ?>">Open Order</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Customers Directory Table -->
<div class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name & Email</th>
                    <th>Phone Number</th>
                    <th>City / Location</th>
                    <th>Total Orders</th>
                    <th>Lifetime Spend</th>
                    <th>Last Active</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <?php if (!empty($c['user_id'])): ?>
                                <span class="status" style="padding:2px 6px; font-weight:bold; font-size:11px; background:#e0e7ff; color:#3730a3;">
                                    #USR-<?= (int)$c['user_id'] ?>
                                </span>
                            <?php else: ?>
                                <span class="muted small" style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:11px;">
                                    Guest
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <b><?= e($c['customer_name']) ?></b><br>
                            <span class="muted small"><?= e($c['customer_email']) ?></span>
                        </td>
                        <td><?= e($c['customer_phone']) ?></td>
                        <td><?= e($c['city'] ?: '—') ?></td>
                        <td>
                            <span class="status" style="padding:2px 8px; font-weight:bold; background:#e8f8f5; color:#27ae60;">
                                <?= (int)$c['total_orders'] ?> <?= (int)$c['total_orders'] === 1 ? 'order' : 'orders' ?>
                            </span>
                        </td>
                        <td><b><?= money($c['lifetime_spend']) ?></b></td>
                        <td><?= date('M d, Y', strtotime($c['last_order_date'])) ?></td>
                        <td style="display:flex; gap:6px;">
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('admin/sales/customers?inspect_email=' . urlencode($c['customer_email']))) ?>">Inspect Orders</a>
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('admin/system/contacts')) ?>">Message</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customers): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px;" class="muted">No customer records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:12px; border-top:1px solid #eee;">
            <span class="small muted">
                Page <b><?= $page ?></b> of <b><?= $totalPages ?></b>
            </span>
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url('admin/sales/customers?page=' . ($page - 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">← Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(url('admin/sales/customers?page=' . ($page + 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <p class="muted small" style="margin-top:15px;">Customer metrics exclude cancelled or returned orders.</p>
</div>

<?php admin_footer(); ?>