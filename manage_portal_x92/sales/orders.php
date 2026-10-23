<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('orders');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $allowed = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    if (in_array($status, $allowed, true)) {
        $pdo = db();
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $s->execute([$id]);
        $order = $s->fetch();

        if ($order) {
            $oldStatus = $order['status'];

            // Update Status
            $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status, $id]);

            // Audit Note
            $note = 'Status updated in admin panel to ' . ucfirst($status);
            if ($oldStatus !== $status && $status === 'confirmed') {
                $note .= ' (Official Invoice sent to customer)';
            }

            $pdo->prepare('INSERT INTO order_status_history(order_id,status,note,changed_by) VALUES(?,?,?,?)')->execute([
                $id,
                $status,
                $note,
                $_SESSION['admin_id'] ?? null
            ]);
            $pdo->commit();

            // Trigger Official Invoice Email ONLY to Customer when Order is Confirmed
            if ($oldStatus !== 'confirmed' && $status === 'confirmed') {
                $linesStmt = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
                $linesStmt->execute([$id]);
                $orderItems = $linesStmt->fetchAll();

                // Send Official Invoice Email to Customer
                email_order_customer($order, $orderItems);
                flash('success', 'Order #' . $order['order_number'] . ' confirmed and official invoice emailed to customer!');
            } else {
                flash('success', 'Order status updated to ' . ucfirst($status) . '.');
            }
        } else {
            $pdo->rollBack();
            flash('error', 'Order not found.');
        }
    }
    redirect('admin/sales/orders' . ($id ? '?id=' . $id : ''));
}

$detail = null;
$lines = [];
$history = [];

if (isset($_GET['id'])) {
    $s = db()->prepare('SELECT * FROM orders WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    $detail = $s->fetch();
    if ($detail) {
        $s = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $s->execute([$detail['id']]);
        $lines = $s->fetchAll();
        $s = db()->prepare('SELECT * FROM order_status_history WHERE order_id=? ORDER BY id DESC');
        $s->execute([$detail['id']]);
        $history = $s->fetchAll();
    }
}

$statusFilter = (string)($_GET['status'] ?? '');
$sql = 'SELECT * FROM orders';
$args = [];
if ($statusFilter !== '') {
    $sql .= ' WHERE status=?';
    $args[] = $statusFilter;
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$s = db()->prepare($sql);
$s->execute($args);
$orders = $s->fetchAll();

admin_header('Order Management');

// Fetch Dynamic Invoice Branding
$invoiceColor = site_setting('invoice_color', '#2c3e50');
$invoiceNote = site_setting('invoice_footer_note', 'Thank you for shopping with us!');
$logoUrl = site_setting('logo_url', '');
?>

<!-- Print-Optimized Styles -->
<style type="text/css">
    @media print {
        body * {
            visibility: hidden;
        }

        #printable-invoice,
        #printable-invoice * {
            visibility: visible;
        }

        #printable-invoice {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
        }

        .no-print {
            display: none !important;
        }
    }

    .invoice-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid var(--line, #eee);
        padding: 35px;
        margin-bottom: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    }

    .invoice-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid <?= e($invoiceColor) ?>;
        padding-bottom: 20px;
        margin-bottom: 25px;
    }

    .invoice-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 30px;
    }

    .invoice-box {
        background: #fdfdfd;
        border: 1px solid #f0f0f0;
        padding: 16px;
        border-radius: 8px;
    }

    .invoice-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px;
    }

    .invoice-table th {
        background: <?= e($invoiceColor) ?>;
        color: #fff;
        padding: 12px 14px;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .invoice-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #eee;
    }

    .invoice-summary-box {
        margin-left: auto;
        width: 280px;
        background: #fafafa;
        border: 1px solid #eee;
        padding: 16px;
        border-radius: 8px;
    }
</style>

<?php if ($detail): ?>
    <div class="invoice-card" id="printable-invoice">

        <!-- Admin Controls Bar (No Print) -->
        <div class="section-heading no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
            <div>
                <div class="eyebrow">Order Inspection</div>
                <h2 style="margin:0;"><?= e($detail['order_number']) ?></h2>
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="window.print()" class="button" style="padding:8px 18px; font-weight:bold;">🖨️ Print Invoice</button>
                <a class="button secondary" href="<?= e(url('admin/sales/orders')) ?>">← Back to All Orders</a>
            </div>
        </div>

        <!-- Invoice Brand Header -->
        <div class="invoice-header">
            <div>
                <?php if ($logoUrl): ?>
                    <img src="<?= e(url($logoUrl)) ?>" alt="<?= e(store_name()) ?>" style="max-height: 55px; width: auto; margin-bottom: 8px;">
                <?php else: ?>
                    <h1 style="margin:0; font-size:28px; color:<?= e($invoiceColor) ?>; font-weight:800;"><?= e(store_name()) ?></h1>
                <?php endif; ?>
                <p style="margin:4px 0 0 0; color:#666; font-size:13px;"><?= e(url()) ?></p>
            </div>
            <div style="text-align:right;">
                <h2 style="margin:0; font-size: 24px; color:<?= e($invoiceColor) ?>;">INVOICE</h2>
                <p style="margin:4px 0 0 0; font-weight:bold; font-size:15px;"><?= e($detail['order_number']) ?></p>
                <p style="margin:2px 0 0 0; color:#777; font-size:13px;">Date: <?= date('d M, Y', strtotime($detail['created_at'])) ?></p>
            </div>
        </div>

        <!-- Customer & Billing Information -->
        <div class="invoice-grid">
            <div class="invoice-box">
                <b style="color:<?= e($invoiceColor) ?>; text-transform:uppercase; font-size:11px; letter-spacing:1px; display:block; margin-bottom:8px;">Billed To</b>
                <strong style="font-size:15px; display:block;"><?= e($detail['customer_name']) ?></strong>
                <span style="color:#555; font-size:13px; display:block; margin-top:2px;">📧 <?= e($detail['customer_email']) ?></span>
                <span style="color:#555; font-size:13px; display:block; margin-top:2px;">📞 <?= e($detail['customer_phone']) ?></span>
            </div>
            <div class="invoice-box">
                <b style="color:<?= e($invoiceColor) ?>; text-transform:uppercase; font-size:11px; letter-spacing:1px; display:block; margin-bottom:8px;">Shipping Address</b>
                <p style="margin:0; color:#333; font-size:13px; line-height:1.5;"><?= nl2br(e($detail['address'])) ?></p>
                <strong style="font-size:13px; color:<?= e($invoiceColor) ?>; display:block; margin-top:4px;"><?= e($detail['city']) ?></strong>
            </div>
        </div>

        <!-- Line Items Table -->
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="text-align:left; border-top-left-radius:6px;">Item Description</th>
                    <th style="text-align:center;">SKU</th>
                    <th style="text-align:center;">Qty</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right; border-top-right-radius:6px;">Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $l): ?>
                    <tr>
                        <td style="font-weight:600; color:#222;"><?= e($l['product_name']) ?></td>
                        <td style="text-align:center; color:#777; font-size:13px;"><?= e($l['sku'] ?: '—') ?></td>
                        <td style="text-align:center; font-weight:bold;"><?= (int)$l['quantity'] ?></td>
                        <td style="text-align:right; color:#555;"><?= money($l['unit_price']) ?></td>
                        <td style="text-align:right; font-weight:bold;"><?= money($l['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary Calculation & Payment Note -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:20px;">
            <div style="max-width:320px;">
                <b style="color:#666; font-size:12px; text-transform:uppercase;">Payment Info:</b>
                <p style="margin:4px 0 10px 0; font-weight:bold; color:<?= e($invoiceColor) ?>;">Cash on Delivery (COD)</p>
                <?php if (!empty($detail['notes'])): ?>
                    <b style="color:#666; font-size:12px; text-transform:uppercase;">Customer Note:</b>
                    <p style="margin:4px 0 0 0; font-size:13px; color:#555; background:#f9f9f9; padding:8px; border-radius:4px; border:1px dashed #ddd;"><?= e($detail['notes']) ?></p>
                <?php endif; ?>
            </div>

            <div class="invoice-summary-box">
                <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:14px; color:#555;">
                    <span>Subtotal:</span>
                    <span><?= money($detail['subtotal']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:14px; color:#c0392b;">
                    <span>Discount:</span>
                    <span>-<?= money($detail['discount_amount']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:14px; color:#555;">
                    <span>Delivery Fee:</span>
                    <span><?= money($detail['shipping_amount']) ?></span>
                </div>
                <hr style="margin:10px 0; border:0; border-top:1px solid #ddd;">
                <div style="display:flex; justify-content:space-between; font-size:18px; font-weight:bold; color:<?= e($invoiceColor) ?>;">
                    <span>Total Due:</span>
                    <span><?= money($detail['total']) ?></span>
                </div>
            </div>
        </div>

        <!-- Footer Thank You Note -->
        <div style="margin-top:40px; border-top:1px solid #eee; padding-top:15px; text-align:center; color:#777; font-size:13px;">
            <p style="margin:0; font-weight:600;"><?= e($invoiceNote) ?></p>
        </div>

        <!-- Order Status Audit Log (No Print) -->
        <div class="no-print" style="border-top:1px solid var(--line,#eee); padding-top:20px; margin-top:30px;">
            <form method="post" class="inline-form" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $detail['id'] ?>">
                <label><b>Update Fulfillment Status:</b></label>
                <select name="status" style="padding:6px 12px;">
                    <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'] as $st): ?>
                        <option value="<?= $st ?>" <?= $detail['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Save Status</button>
            </form>

            <h3 style="margin-top:20px;">Fulfillment Log History</h3>
            <?php foreach ($history as $h): ?>
                <p class="small muted" style="margin:4px 0;">
                    <span class="status" style="padding:2px 6px; font-weight:bold; font-size:11px;"><?= ucfirst(e($h['status'])) ?></span> —
                    <?= e($h['note'] ?? 'Updated') ?> ·
                    <span style="color:#888;"><?= date('M d, Y g:i a', strtotime($h['created_at'])) ?></span>
                </p>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Master Orders List Table -->
<div class="panel">
    <div class="section-heading" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h2>All Orders</h2>
        <form class="inline-form" method="get" style="display:flex; gap:8px;">
            <select name="status" style="padding:6px 10px;">
                <option value="">All Statuses</option>
                <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'] as $st): ?>
                    <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button secondary">Filter</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><b><?= e($o['order_number']) ?></b></td>
                        <td><?= e($o['customer_name']) ?></td>
                        <td><?= e($o['customer_phone']) ?></td>
                        <td><b><?= money($o['total']) ?></b></td>
                        <td>
                            <span class="status" style="padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold; background: <?= in_array($o['status'], ['delivered', 'completed'], true) ? '#e8f8f5; color:#27ae60;' : ($o['status'] === 'cancelled' ? '#fde8e8; color:#e74c3c;' : '#fef9e7; color:#f39c12;') ?>">
                                <?= ucfirst(e($o['status'])) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                        <td><a class="button secondary" style="padding:4px 10px; font-size:12px;" href="<?= e(url('admin/sales/orders?id=' . $o['id'])) ?>">Inspect Invoice</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;">No orders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>