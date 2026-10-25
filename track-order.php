<?php
require_once __DIR__ . '/app/bootstrap.php';

$orderNo = strtoupper(trim((string)($_GET['order_number'] ?? '')));
$contact = trim((string)($_GET['contact'] ?? ''));

$order   = null;
$lines   = [];
$history = [];
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($orderNo !== '' || $contact !== '')) {
    if ($orderNo === '' || $contact === '') {
        $error = 'Please enter both your Order Number and Email/Phone.';
    } else {
        // Query joining normalized shipping_cities table for city name and delivery zone details
        $s = db()->prepare('
            SELECT 
                o.*, 
                COALESCE(sc.city_name, o.city) AS city,
                sc.shipping_cost AS city_shipping_fee,
                sc.zone_code
            FROM orders o
            LEFT JOIN shipping_cities sc ON sc.id = o.city_id
            WHERE UPPER(o.order_number) = ? 
            AND (LOWER(o.customer_email) = ? OR o.customer_phone = ?)
            LIMIT 1
        ');
        $s->execute([$orderNo, strtolower($contact), $contact]);
        $order = $s->fetch();

        if ($order) {
            $s = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
            $s->execute([$order['id']]);
            $lines = $s->fetchAll();

            $s = db()->prepare('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC');
            $s->execute([$order['id']]);
            $history = $s->fetchAll();
        } else {
            $error = 'No matching order found. Please check your order details and try again.';
        }
    }
}

$pageTitle = 'Track Your Order';
require_once __DIR__ . '/app/header.php';
?>

<div style="max-width: 720px; margin: 0 auto 40px auto;">
    <h1 class="page-title">Track Your Order</h1>
    <p class="muted">Check the real-time fulfillment status of your delivery.</p>

    <!-- Lookup Form -->
    <div class="panel" style="margin-bottom: 25px;">
        <form method="get" action="track-order.php">
            <div class="form-grid">
                <div class="field">
                    <label>Order Number *</label>
                    <input name="order_number" required placeholder="e.g. DVBD2610186B89E4" value="<?= e($orderNo) ?>">
                </div>
                <div class="field">
                    <label>Email or Phone Number *</label>
                    <input name="contact" required placeholder="Your email or phone" value="<?= e($contact) ?>">
                </div>
            </div>
            <button class="button" style="width: 100%; margin-top: 10px;">Track Order</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="notice error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Order Tracking Details Result -->
    <?php if ($order): ?>
        <div class="panel">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; border-bottom:1px solid var(--line, #eee); padding-bottom:15px; margin-bottom:15px;">
                <div>
                    <h2 style="margin:0;"><?= e($order['order_number']) ?></h2>
                    <p class="muted small" style="margin:4px 0 0 0;">Placed on <?= date('F j, Y g:i a', strtotime($order['created_at'])) ?></p>
                    <?php if (!empty($order['tracking_number'])): ?>
                        <p style="margin:4px 0 0 0; font-size:13px; color:#2563eb; font-weight:600;">
                            Consignment / Courier Tracking: <code><?= e($order['tracking_number']) ?></code>
                        </p>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="status" style="padding: 6px 12px; border-radius: 4px; font-weight: bold; text-transform: uppercase; font-size: 13px; background: <?= in_array($order['status'], ['delivered', 'completed'], true) ? '#e8f8f5; color:#27ae60;' : ($order['status'] === 'cancelled' ? '#fde8e8; color:#e74c3c;' : '#fef9e7; color:#f39c12;') ?>">
                        <?= ucfirst(e($order['status'])) ?>
                    </span>
                </div>
            </div>

            <!-- Shipping Information -->
            <div class="form-grid" style="margin-bottom: 20px;">
                <div>
                    <b>Recipient</b>
                    <p style="margin:4px 0 0 0; color:#555;"><?= e($order['customer_name']) ?><br><?= e($order['customer_phone']) ?></p>
                </div>
                <div>
                    <b>Delivery Address</b>
                    <p style="margin:4px 0 0 0; color:#555;"><?= nl2br(e($order['address'])) ?><br><b><?= e($order['city']) ?></b></p>
                </div>
            </div>

            <!-- Items Ordered Table (Includes Color and Size Options) -->
            <h3>Items in Order</h3>
            <div class="table-wrap" style="margin-bottom: 20px;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--line, #eee); text-align:left;">
                            <th style="padding:10px 8px;">Item Description</th>
                            <th style="padding:10px 8px; text-align:center;">Qty</th>
                            <th style="padding:10px 8px; text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 8px;">
                                    <span style="font-weight:600; display:block;"><?= e($l['product_name']) ?></span>

                                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
                                        <?php if (!empty($l['color'])): ?>
                                            <span style="display:inline-block; background:#e0e7ff; color:#3730a3; font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px;">
                                                Color: <?= e($l['color']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($l['size'])): ?>
                                            <span style="display:inline-block; background:#0f172a; color:#fff; font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px;">
                                                Size: <?= e($l['size']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($l['sku'])): ?>
                                            <span class="muted small" style="font-size:11px; padding:2px 4px;">
                                                SKU: <?= e($l['sku']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding:10px 8px; text-align:center; font-weight:bold;"><?= (int)$l['quantity'] ?></td>
                                <td style="padding:10px 8px; text-align:right;"><b><?= money($l['line_total']) ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary Totals -->
            <div style="background:#f8f9fa; padding:15px; border-radius:6px; border:1px solid #eee; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span>Subtotal</span><span><?= money($order['subtotal']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span>Discount</span><span style="color:#c0392b;">-<?= money($order['discount_amount']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span>Delivery Fee</span><span><?= money($order['shipping_amount']) ?></span>
                </div>
                <hr style="margin:8px 0; border:0; border-top:1px solid #ddd;">
                <div style="display:flex; justify-content:space-between; font-size:18px;">
                    <b>Total Due (COD)</b><b><?= money($order['total']) ?></b>
                </div>
            </div>

            <!-- Status Timeline -->
            <h3>Fulfillment Updates</h3>
            <?php foreach ($history as $h): ?>
                <div style="border-left: 2px solid var(--accent, #333); padding-left: 12px; margin-bottom: 12px;">
                    <strong style="text-transform: capitalize;"><?= e($h['status']) ?></strong>
                    <span class="muted small"> — <?= date('M d, Y g:i a', strtotime($h['created_at'])) ?></span>
                    <p style="margin: 2px 0 0 0; font-size: 13px; color: #555;"><?= e($h['note'] ?: 'Status updated.') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/app/footer.php'; ?>