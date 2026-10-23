<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('customers');

$search = trim((string)($_GET['q'] ?? ''));

// SQL Query using MAX/ANY_VALUE for strict mode compliance
$sql = "
    SELECT 
        customer_email,
        MAX(customer_name) as customer_name,
        customer_phone,
        MAX(city) as city,
        COUNT(id) as total_orders,
        SUM(total) as lifetime_spend,
        MAX(created_at) as last_order_date
    FROM orders
    WHERE status NOT IN ('cancelled', 'returned')
";

$params = [];

if ($search !== '') {
    $sql .= " AND (customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ? OR city LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$sql .= " GROUP BY customer_email, customer_phone ORDER BY lifetime_spend DESC LIMIT 200";

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', store_name())) . '-customers-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    // Header Row
    fputcsv($out, ['Customer Name', 'Email Address', 'Phone Number', 'City / Location', 'Total Orders', 'Lifetime Spend', 'Last Active Date']);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
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

$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Customer Order History Inspection View
$inspectCustomer = null;
$customerOrders = [];
if (isset($_GET['inspect_email'])) {
    $inspectEmail = trim((string)$_GET['inspect_email']);
    $s = db()->prepare('SELECT * FROM orders WHERE customer_email = ? ORDER BY id DESC');
    $s->execute([$inspectEmail]);
    $customerOrders = $s->fetchAll();
    if ($customerOrders) {
        $inspectCustomer = $customerOrders[0];
    }
}

admin_header('Customer Directory & Intelligence'); ?>

<!-- Search Bar & Export Controls -->
<div class="panel" style="margin-bottom: 22px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <h2>Customer Directory</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <form method="get" class="inline-form" style="display:flex; gap:8px;">
                <input name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, city..." style="padding: 6px 12px; min-width: 250px;">
                <button class="button">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="button secondary" href="<?= e(url('manage_portal_x92/customers.php')) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <a class="button secondary" style="padding: 7px 14px; font-weight:bold;" href="<?= e(url('manage_portal_x92/customers.php?q=' . urlencode($search) . '&export=csv')) ?>">
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
            <a class="button secondary" style="padding:4px 10px; font-size:12px;" href="<?= e(url('manage_portal_x92/customers.php')) ?>">Close Inspection</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Delivery Partner</th>
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
                            <td><?= e($co['courier_name'] ?: 'Unassigned') ?></td>
                            <td>
                                <a class="button secondary" style="padding:3px 8px; font-size:12px;" href="<?= e(url('manage_portal_x92/orders.php?id=' . $co['id'])) ?>">Open Invoice</a>
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
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('manage_portal_x92/customers.php?inspect_email=' . urlencode($c['customer_email']))) ?>">Inspect Orders</a>
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('manage_portal_x92/contacts.php')) ?>">Message</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customers): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;">No customer records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small" style="margin-top:15px;">Customer metrics exclude cancelled or returned orders.</p>
</div>

<?php admin_footer(); ?>