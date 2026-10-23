<?php require_once __DIR__ . '/_init.php';

// KPI Metrics
$stats = [];
$stats['orders'] = (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$stats['pending'] = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','processing')")->fetchColumn();
$stats['products'] = (int)db()->query('SELECT COUNT(*) FROM products WHERE active=1')->fetchColumn();
$stats['stock'] = (int)db()->query('SELECT COUNT(*) FROM products WHERE active=1 AND stock<=5')->fetchColumn();
$stats['unread_messages'] = (int)db()->query("SELECT COUNT(*) FROM contacts WHERE status='new'")->fetchColumn();

// Revenue Calculations
$monthlySales = (float)db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','returned') AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
$lifetimeSales = (float)db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','returned')")->fetchColumn();

// Data Lists
$recentOrders = db()->query('SELECT id, order_number, customer_name, total, status, created_at FROM orders ORDER BY id DESC LIMIT 8')->fetchAll();
$lowStockProducts = db()->query('SELECT id, name, sku, stock, price FROM products WHERE active=1 AND stock<=5 ORDER BY stock ASC LIMIT 5')->fetchAll();

admin_header('Dashboard'); ?>

<!-- Top Row Stats -->
<div class="stats" style="margin-bottom: 20px;">
    <div class="stat">
        <span>Total Orders</span>
        <strong><?= $stats['orders'] ?></strong>
    </div>
    <div class="stat">
        <span>Open / Pending Orders</span>
        <strong style="color: #e67e22;"><?= $stats['pending'] ?></strong>
    </div>
    <div class="stat">
        <span>Active Products</span>
        <strong><?= $stats['products'] ?></strong>
    </div>
    <div class="stat">
        <span>Low Stock Alerts</span>
        <strong style="<?= $stats['stock'] > 0 ? 'color: #e74c3c;' : '' ?>"><?= $stats['stock'] ?></strong>
    </div>
</div>

<!-- Revenue & Messages Overview -->
<div class="stats" style="margin-bottom: 25px;">
    <div class="stat" style="grid-column: span 2;">
        <span>Sales This Month</span>
        <strong style="color: #27ae60;"><?= money($monthlySales) ?></strong>
        <span class="muted small" style="margin-top:4px;">Lifetime revenue: <b><?= money($lifetimeSales) ?></b></span>
    </div>
    <div class="stat" style="grid-column: span 2;">
        <span>Customer Inbox</span>
        <strong style="<?= $stats['unread_messages'] > 0 ? 'color: #3498db;' : '' ?>"><?= $stats['unread_messages'] ?> Unread</strong>
        <p style="margin-top: 10px;">
            <a class="button" href="<?= e(url('manage_portal_x92/contacts.php')) ?>">Open Messages</a>
            <a class="button secondary" href="<?= e(url('manage_portal_x92/products.php')) ?>">Add Product</a>
        </p>
    </div>
</div>

<!-- Grid Layout: Recent Orders & Low Stock Alerts -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start;">

    <!-- Left Column: Recent Orders -->
    <div class="panel">
        <div class="section-heading" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2>Recent Orders</h2>
            <a href="<?= e(url('manage_portal_x92/orders.php')) ?>">View all orders →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('manage_portal_x92/orders.php?id=' . $o['id'])) ?>">
                                    <b><?= e($o['order_number']) ?></b>
                                </a>
                            </td>
                            <td><?= e($o['customer_name']) ?></td>
                            <td><b><?= money($o['total']) ?></b></td>
                            <td>
                                <span class="status" style="padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold; background: <?= in_array($o['status'], ['delivered', 'completed'], true) ? '#e8f8f5; color:#27ae60;' : '#fef9e7; color:#f39c12;' ?>">
                                    <?= ucfirst(e($o['status'])) ?>
                                </span>
                            </td>
                            <td><?= date('M d, g:i a', strtotime($o['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentOrders): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 20px;">No orders recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column: Low Stock Alerts -->
    <div class="panel">
        <div class="section-heading" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2 style="color: #c0392b;">Low Stock Alert</h2>
            <a href="<?= e(url('manage_portal_x92/products.php')) ?>">Manage</a>
        </div>
        <?php if ($lowStockProducts): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Stock</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockProducts as $p): ?>
                            <tr>
                                <td>
                                    <b><?= e($p['name']) ?></b><br>
                                    <span class="muted small"><?= e($p['sku']) ?></span>
                                </td>
                                <td>
                                    <span style="color: #e74c3c; font-weight: bold;"><?= (int)$p['stock'] ?> left</span>
                                </td>
                                <td>
                                    <a class="button secondary" style="padding: 4px 8px; font-size: 11px;" href="<?= e(url('manage_portal_x92/products.php?edit=' . $p['id'])) ?>">Restock</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted" style="text-align: center; padding: 20px 0;">All product stock levels are healthy! 👍</p>
        <?php endif; ?>
    </div>

</div>

<?php admin_footer(); ?>