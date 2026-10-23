<?php require_once __DIR__ . '/app/bootstrap.php';
$items = cart_items();
if (!$items) {
    flash('error', 'Your bag is empty.');
    redirect('cart.php');
}
$subtotal = cart_subtotal($items);
$errors = [];
$couponCode = (string)($_SESSION['coupon_code'] ?? '');
$discount = 0;
$coupon = null;

// Fetch Normalized Shipping Cities
$cities = db()->query('SELECT * FROM shipping_cities ORDER BY zone_code ASC, city_name ASC')->fetchAll();

// Check if customer is logged in to pre-fill saved details from customer_addresses
$u = function_exists('current_user') ? current_user() : null;
$savedAddress = null;

if ($u) {
    $st = db()->prepare('SELECT ca.*, sc.city_name FROM customer_addresses ca LEFT JOIN shipping_cities sc ON sc.id = ca.city_id WHERE ca.user_id = ? ORDER BY ca.is_default DESC, ca.id DESC LIMIT 1');
    $st->execute([$u['id']]);
    $savedAddress = $st->fetch();
}

$defaultName    = $u['name'] ?? (string)($_POST['customer_name'] ?? '');
$defaultEmail   = $u['email'] ?? (string)($_POST['customer_email'] ?? '');
$defaultPhone   = $savedAddress['phone'] ?? $u['phone'] ?? (string)($_POST['customer_phone'] ?? '');
$defaultAddress = $savedAddress['street_address'] ?? $u['address'] ?? (string)($_POST['address'] ?? '');
$defaultCityId  = (int)($_POST['city_id'] ?? ($savedAddress['city_id'] ?? ($cities[0]['id'] ?? 1)));

// Determine Active Shipping Fee
$selectedCity = null;
foreach ($cities as $c) {
    if ((int)$c['id'] === $defaultCityId) {
        $selectedCity = $c;
        break;
    }
}
$shippingFee = $selectedCity ? (float)$selectedCity['shipping_cost'] : 120.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // 1. Handle Coupon Application
    if (isset($_POST['apply_coupon'])) {
        $code = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $s = db()->prepare('SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit IS NULL OR used_count<usage_limit)');
        $s->execute([$code]);
        $c = $s->fetch();
        if ($c && $subtotal >= (float)$c['min_order']) {
            $_SESSION['coupon_code'] = $code;
            flash('success', 'Coupon applied.');
        } else {
            unset($_SESSION['coupon_code']);
            flash('error', 'Coupon is invalid, expired, or does not meet the minimum order.');
        }
        redirect('checkout.php');
    }

    // 2. Only Process Order Placement if "place_order" action button was pressed
    if (isset($_POST['place_order'])) {
        $name    = trim((string)($_POST['customer_name'] ?? ''));
        $email   = trim((string)($_POST['customer_email'] ?? ''));
        $phone   = trim((string)($_POST['customer_phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $cityId  = (int)($_POST['city_id'] ?? 0);
        $notes   = trim((string)($_POST['notes'] ?? ''));

        if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (mb_strlen($phone) < 7) $errors[] = 'Please enter a valid phone number.';
        if (mb_strlen($address) < 8) $errors[] = 'Please enter your delivery address.';

        // Validate City
        $cityStmt = db()->prepare('SELECT * FROM shipping_cities WHERE id = ? LIMIT 1');
        $cityStmt->execute([$cityId]);
        $cityObj = $cityStmt->fetch();
        if (!$cityObj) $errors[] = 'Please select a valid delivery city.';

        foreach ($items as $i) {
            $s = db()->prepare('SELECT stock,active FROM products WHERE id=?');
            $s->execute([$i['id']]);
            $live = $s->fetch();
            if (!$live || !$live['active'] || (int)$live['stock'] < (int)$i['qty']) $errors[] = 'Stock changed for ' . $i['name'] . '. Please update your bag.';
        }

        if (!$errors) {
            $subtotal = cart_subtotal($items);
            $coupon = null;
            $discount = 0;
            $code = (string)($_SESSION['coupon_code'] ?? '');
            if ($code !== '') {
                $s = db()->prepare('SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit IS NULL OR used_count<usage_limit)');
                $s->execute([$code]);
                $coupon = $s->fetch();
                if ($coupon && $subtotal >= (float)$coupon['min_order']) {
                    $discount = $coupon['discount_type'] === 'percent' ? $subtotal * (float)$coupon['discount_value'] / 100 : (float)$coupon['discount_value'];
                    $discount = min($subtotal, $discount);
                    if ($coupon['max_discount'] !== null) $discount = min($discount, (float)$coupon['max_discount']);
                } else $coupon = null;
            }

            $shipping = (float)$cityObj['shipping_cost'];
            $total = max(0, $subtotal - $discount) + $shipping;
            $orderNo = 'DVBD' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));

            // Generate a unique tracking code for internal & API tracking
            $trackingNumber = 'TRK-' . strtoupper(bin2hex(random_bytes(4)));

            try {
                $pdo = db();
                $pdo->beginTransaction();

                $userId = $u['id'] ?? null;

                // 1. Save Normalized Address Record
                $addrStmt = $pdo->prepare('INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default) VALUES (?, ?, ?, ?, ?, 1)');
                $addrStmt->execute([$userId, $name, $phone, $address, $cityId]);
                $addressId = (int)$pdo->lastInsertId();

                // 2. Insert Normalized Order Record
                $ins = $pdo->prepare('INSERT INTO orders (user_id, order_number, customer_name, customer_email, customer_phone, address, shipping_address_id, city, city_id, notes, payment_method, status, subtotal, discount_amount, shipping_amount, total, coupon_code, coupon_id, tracking_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([
                    $userId,
                    $orderNo,
                    $name,
                    $email,
                    $phone,
                    $address,
                    $addressId,
                    $cityObj['city_name'],
                    $cityId,
                    $notes,
                    'COD',
                    'pending',
                    $subtotal,
                    $discount,
                    $shipping,
                    $total,
                    $coupon['code'] ?? null,
                    $coupon['id'] ?? null,
                    $trackingNumber
                ]);
                $orderId = (int)$pdo->lastInsertId();

                // 3. Line Items & Inventory Updates
                $line = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, sku, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stockUpdate = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?');

                foreach ($items as $i) {
                    $stockUpdate->execute([$i['qty'], $i['id'], $i['qty']]);
                    if ($stockUpdate->rowCount() !== 1) throw new RuntimeException('Inventory changed during checkout.');
                    $line->execute([$orderId, $i['id'], $i['name'], $i['sku'] ?? null, $i['qty'], $i['price'], $i['line_total']]);
                }

                $pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')->execute([$orderId, 'pending', 'Order placed by customer']);

                // 4. Track Coupon Redemption
                if ($coupon) {
                    $redemption = $pdo->prepare('INSERT INTO coupon_redemptions (coupon_id, order_id, user_id, discount_amount) VALUES (?, ?, ?, ?)');
                    $redemption->execute([$coupon['id'], $orderId, $userId, $discount]);
                    $pdo->prepare('UPDATE coupons SET used_count=used_count+1 WHERE id=?')->execute([$coupon['id']]);
                }

                $pdo->commit();

                $s = $pdo->prepare('SELECT * FROM orders WHERE id=?');
                $s->execute([$orderId]);
                $order = $s->fetch();

                $s = $pdo->prepare('SELECT product_name name, quantity, unit_price price, line_total FROM order_items WHERE order_id=?');
                $s->execute([$orderId]);
                $mailItems = $s->fetchAll();

                // 1. Send Order Confirmation Receipt to Customer
                email_customer_order_placed($order, $mailItems);

                // 2. Send New Order Alert to Admin
                email_admin_new_order($order, $mailItems);

                $_SESSION['last_order_number'] = $orderNo;
                unset($_SESSION['cart'], $_SESSION['coupon_code']);
                redirect('order-success.php');
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('Checkout error: ' . $e->getMessage());
                $errors[] = 'We could not place the order. Please review your bag and try again.';
            }
        }
    }
}

if (isset($_SESSION['coupon_code'])) {
    $s = db()->prepare('SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit IS NULL OR used_count<usage_limit)');
    $s->execute([$_SESSION['coupon_code']]);
    $coupon = $s->fetch();
    if ($coupon && $subtotal >= (float)$coupon['min_order']) {
        $discount = $coupon['discount_type'] === 'percent' ? $subtotal * (float)$coupon['discount_value'] / 100 : (float)$coupon['discount_value'];
        $discount = min($subtotal, $discount);
        if ($coupon['max_discount'] !== null) $discount = min($discount, (float)$coupon['max_discount']);
    } else {
        unset($_SESSION['coupon_code']);
        $coupon = null;
    }
}

$pageTitle = 'Checkout';
require_once __DIR__ . '/app/header.php'; ?>
<h1 class="page-title">Checkout</h1>
<p class="muted">Cash on delivery · No online payment required</p>

<?php if ($u): ?>
    <div class="notice success" style="margin-bottom:20px;">
        Logged in as <b><?= e($u['name']) ?></b> (<?= e($u['email']) ?>). Your saved details have been automatically filled below.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="notice error">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="checkout-layout">
    <form method="post" class="panel">
        <?= csrf_field() ?>
        <h2>Delivery details</h2>
        <div class="field"><label>Full name *</label><input name="customer_name" required value="<?= e($defaultName) ?>"></div>
        <div class="form-grid">
            <div class="field"><label>Email address *</label><input type="email" name="customer_email" required value="<?= e($defaultEmail) ?>"></div>
            <div class="field"><label>Phone number *</label><input name="customer_phone" required value="<?= e($defaultPhone) ?>"></div>
        </div>
        <div class="field"><label>Full delivery address *</label><textarea name="address" required><?= e($defaultAddress) ?></textarea></div>

        <!-- Dynamic City Selector -->
        <div class="field">
            <label>City / Zone *</label>
            <select name="city_id" required onchange="this.form.submit()" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;">
                <?php foreach ($cities as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $defaultCityId ? 'selected' : '' ?>>
                        <?= e($c['city_name']) ?> (<?= $c['zone_code'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?> — <?= money($c['shipping_cost']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field"><label>Order notes (optional)</label><textarea name="notes"><?= e((string)($_POST['notes'] ?? '')) ?></textarea></div>
        <div class="notice success">Payment method: <b>Cash on delivery</b>. Pay the delivery person when your order arrives.</div>
        <button name="place_order" value="1" style="width:100%">Place order · <?= money(max(0, $subtotal - $discount) + $shippingFee) ?></button>
    </form>
    <aside class="panel">
        <h2>Order summary</h2>
        <?php foreach ($items as $i): ?>
            <div style="display:flex;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid var(--line)"><span><?= e($i['name']) ?> × <?= (int)$i['qty'] ?></span><b><?= money($i['line_total']) ?></b></div>
        <?php endforeach; ?>
        <form method="post" style="margin:18px 0">
            <?= csrf_field() ?>
            <div class="field"><label>Discount code</label><input name="coupon_code" placeholder="Enter coupon" value="<?= e((string)($coupon['code'] ?? '')) ?>"></div>
            <button class="button secondary" name="apply_coupon" value="1">Apply coupon</button>
            <?php if ($coupon): ?><button class="button secondary" name="remove_coupon" value="1" formaction="<?= e(url('remove-coupon.php')) ?>">Remove</button><?php endif; ?>
        </form>
        <div style="display:flex;justify-content:space-between;padding:8px 0"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
        <div style="display:flex;justify-content:space-between;padding:8px 0"><span>Discount</span><span>-<?= money($discount) ?></span></div>
        <div style="display:flex;justify-content:space-between;padding:8px 0"><span>Delivery Fee</span><span><?= money($shippingFee) ?></span></div>
        <hr>
        <div style="display:flex;justify-content:space-between;font-size:18px"><b>Total due</b><b><?= money(max(0, $subtotal - $discount) + $shippingFee) ?></b></div>
    </aside>
</div>
<?php require_once __DIR__ . '/app/footer.php'; ?>