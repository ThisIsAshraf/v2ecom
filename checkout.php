<?php require_once __DIR__ . '/app/bootstrap.php';

// Helper to ensure item colors, sizes, and variant stock details are properly loaded from the session cart
function resolve_checkout_items(): array
{
    $cart = $_SESSION['cart'] ?? [];
    if (!is_array($cart) || empty($cart)) {
        return [];
    }

    $productIds = [];
    $rawItems = [];

    foreach ($cart as $key => $item) {
        $pId   = 0;
        $qty   = 0;
        $color = '';
        $size  = '';
        $customPrice = null;

        if (is_array($item)) {
            $pId   = (int)($item['product_id'] ?? 0);
            $qty   = max(0, (int)($item['qty'] ?? 0));
            $color = trim((string)($item['color'] ?? ''));
            $size  = trim((string)($item['size'] ?? ''));
            if (isset($item['price'])) {
                $customPrice = (float)$item['price'];
            }
        } else {
            // Legacy format fallback: key is product_id, value is qty
            $pId = (int)$key;
            $qty = max(0, (int)$item);
        }

        if ($pId > 0 && $qty > 0) {
            $productIds[] = $pId;
            $rawItems[] = [
                'cart_key'     => (string)$key,
                'product_id'   => $pId,
                'qty'          => $qty,
                'color'        => $color,
                'size'         => $size,
                'custom_price' => $customPrice
            ];
        }
    }

    if (empty($productIds)) {
        return [];
    }

    $productIds = array_unique($productIds);
    $inMarks = implode(',', array_fill(0, count($productIds), '?'));

    // Fetch products
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($inMarks)");
    $stmt->execute(array_values($productIds));
    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $prodMap = [];
    foreach ($prods as $p) {
        $prodMap[$p['id']] = $p;
    }

    // Fetch variants to resolve exact pricing, variant SKU, and stock
    $vStmt = db()->prepare("SELECT * FROM product_variants WHERE product_id IN ($inMarks)");
    $vStmt->execute(array_values($productIds));
    $allVariants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    $vMatrix = [];
    foreach ($allVariants as $v) {
        $vk = (int)$v['product_id'] . '|' . trim((string)($v['color'] ?? '')) . '|' . trim((string)($v['size'] ?? ''));
        $vMatrix[$vk] = $v;
    }

    $items = [];
    foreach ($rawItems as $r) {
        $pId = $r['product_id'];
        if (!isset($prodMap[$pId])) {
            continue;
        }

        $p     = $prodMap[$pId];
        $price = (float)$p['price'];
        $qty   = $r['qty'];
        $sku   = $p['sku'] ?? null;
        $stock = (int)$p['stock'];

        $vk = $pId . '|' . $r['color'] . '|' . $r['size'];
        if (isset($vMatrix[$vk])) {
            $matchedVar = $vMatrix[$vk];
            $stock = (int)$matchedVar['stock'];
            $price += (float)($matchedVar['additional_price'] ?? 0);
            if (!empty($matchedVar['sku'])) {
                $sku = $matchedVar['sku'];
            }
        } elseif ($r['custom_price'] !== null) {
            $price = $r['custom_price'];
        }

        $items[] = [
            'cart_key'   => $r['cart_key'],
            'id'         => $p['id'],
            'name'       => $p['name'],
            'slug'       => $p['slug'],
            'sku'        => $sku,
            'price'      => $price,
            'qty'        => $qty,
            'stock'      => $stock,
            'active'     => (int)$p['active'],
            'color'      => $r['color'],
            'size'       => $r['size'],
            'line_total' => $price * $qty
        ];
    }

    return $items;
}

$items = resolve_checkout_items();
if (!$items) {
    flash('error', 'Your bag is empty.');
    redirect('cart.php');
}

$subtotal = 0;
foreach ($items as $i) {
    $subtotal += $i['line_total'];
}

$errors = [];
$couponCode = (string)($_SESSION['coupon_code'] ?? '');
$discount = 0;
$coupon = null;

// Fetch Normalized Shipping Cities
$cities = db()->query('SELECT * FROM shipping_cities ORDER BY zone_code ASC, city_name ASC')->fetchAll();

// Check if customer is logged in to pre-fill saved details
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

    // 2. Process Order Placement
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

        // Check Inventory (Both Aggregate and Per Color/Size Variant)
        $variantCheck = db()->prepare('
            SELECT stock FROM product_variants 
            WHERE product_id = ? 
              AND (color = ? OR (color IS NULL AND ? = "")) 
              AND (size = ? OR (size IS NULL AND ? = "")) 
            LIMIT 1
        ');

        foreach ($items as $i) {
            $s = db()->prepare('SELECT stock, active FROM products WHERE id=?');
            $s->execute([$i['id']]);
            $live = $s->fetch();

            if (!$live || !$live['active'] || (int)$live['stock'] < (int)$i['qty']) {
                $errors[] = 'Stock changed for ' . $i['name'] . '. Please update your bag.';
                continue;
            }

            // Check variant stock if item has color or size
            if (!empty($i['color']) || !empty($i['size'])) {
                $variantCheck->execute([$i['id'], $i['color'], $i['color'], $i['size'], $i['size']]);
                $vStock = $variantCheck->fetchColumn();
                if ($vStock !== false && (int)$vStock < (int)$i['qty']) {
                    $optLabel = trim(($i['color'] ? 'Color: ' . $i['color'] . ' ' : '') . ($i['size'] ? 'Size: ' . $i['size'] : ''));
                    $errors[] = 'Insufficient stock for ' . $i['name'] . ' (' . $optLabel . '). Only ' . (int)$vStock . ' available.';
                }
            }
        }

        if (!$errors) {
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
                } else {
                    $coupon = null;
                }
            }

            $shipping = (float)$cityObj['shipping_cost'];
            $total = max(0, $subtotal - $discount) + $shipping;
            $orderNo = 'DVBD' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
            $trackingNumber = 'TRK-' . strtoupper(bin2hex(random_bytes(4)));

            try {
                $pdo = db();
                $pdo->beginTransaction();

                $userId = $u['id'] ?? null;

                // 1. Save Address Record
                $addrStmt = $pdo->prepare('INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default) VALUES (?, ?, ?, ?, ?, 1)');
                $addrStmt->execute([$userId, $name, $phone, $address, $cityId]);
                $addressId = (int)$pdo->lastInsertId();

                // 2. Insert Order Record
                $ins = $pdo->prepare('
                    INSERT INTO orders (
                        user_id, order_number, customer_name, customer_email, customer_phone, 
                        address, shipping_address_id, city, city_id, notes, payment_method, 
                        status, subtotal, discount_amount, shipping_amount, total, 
                        coupon_code, coupon_id, tracking_number
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
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

                // 3. Line Items & Multi-Variant Inventory Deductions
                $line = $pdo->prepare('
                    INSERT INTO order_items (order_id, product_id, color, size, product_name, sku, quantity, unit_price, line_total) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stockUpdate = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?');
                $variantDeduct = $pdo->prepare('
                    UPDATE product_variants 
                    SET stock=stock-? 
                    WHERE product_id=? 
                      AND (color=? OR (color IS NULL AND ?="")) 
                      AND (size=? OR (size IS NULL AND ?="")) 
                      AND stock>=?
                ');

                foreach ($items as $i) {
                    $itemColor = $i['color'] !== '' ? $i['color'] : null;
                    $itemSize  = $i['size'] !== '' ? $i['size'] : null;

                    // Decrement color/size variant stock if configured
                    if (!empty($i['color']) || !empty($i['size'])) {
                        $variantDeduct->execute([
                            $i['qty'],
                            $i['id'],
                            $i['color'],
                            $i['color'],
                            $i['size'],
                            $i['size'],
                            $i['qty']
                        ]);

                        if ($variantDeduct->rowCount() === 0) {
                            $vExists = $pdo->prepare('
                                SELECT COUNT(*) FROM product_variants 
                                WHERE product_id=? 
                                  AND (color=? OR (color IS NULL AND ?="")) 
                                  AND (size=? OR (size IS NULL AND ?=""))
                            ');
                            $vExists->execute([$i['id'], $i['color'], $i['color'], $i['size'], $i['size']]);
                            if ((int)$vExists->fetchColumn() > 0) {
                                throw new RuntimeException('Inventory for selected variant changed during checkout.');
                            }
                        }
                    }

                    // Decrement overall aggregate product stock
                    $stockUpdate->execute([$i['qty'], $i['id'], $i['qty']]);
                    if ($stockUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Aggregate inventory changed during checkout.');
                    }

                    $line->execute([
                        $orderId,
                        $i['id'],
                        $itemColor,
                        $itemSize,
                        $i['name'],
                        $i['sku'] ?? null,
                        $i['qty'],
                        $i['price'],
                        $i['line_total']
                    ]);
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

                $s = $pdo->prepare('SELECT product_name name, color, size, quantity, unit_price price, line_total FROM order_items WHERE order_id=?');
                $s->execute([$orderId]);
                $mailItems = $s->fetchAll();

                // Format item names to include both colors & sizes for emails
                foreach ($mailItems as &$mi) {
                    $details = [];
                    if (!empty($mi['color'])) $details[] = 'Color: ' . $mi['color'];
                    if (!empty($mi['size']))  $details[] = 'Size: ' . $mi['size'];
                    if (!empty($details)) {
                        $mi['name'] .= ' (' . implode(', ', $details) . ')';
                    }
                }
                unset($mi);

                // Notifications
                if (function_exists('email_customer_order_placed')) {
                    email_customer_order_placed($order, $mailItems);
                }
                if (function_exists('email_admin_new_order')) {
                    email_admin_new_order($order, $mailItems);
                }

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
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; padding:12px 0; border-bottom:1px solid var(--line);">
                <div>
                    <span style="font-weight:600;"><?= e($i['name']) ?> × <?= (int)$i['qty'] ?></span>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:3px;">
                        <?php if (!empty($i['color'])): ?>
                            <span style="background:#e0e7ff; color:#3730a3; font-size:10px; font-weight:700; padding:2px 6px; border-radius:3px;">
                                Color: <?= e($i['color']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($i['size'])): ?>
                            <span style="background:#0f172a; color:#fff; font-size:10px; font-weight:700; padding:2px 6px; border-radius:3px;">
                                Size: <?= e($i['size']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <b><?= money($i['line_total']) ?></b>
            </div>
        <?php endforeach; ?>

        <form method="post" style="margin:18px 0">
            <?= csrf_field() ?>
            <div class="field"><label>Discount code</label><input name="coupon_code" placeholder="Enter coupon" value="<?= e((string)($coupon['code'] ?? '')) ?>"></div>
            <button class="button secondary" name="apply_coupon" value="1">Apply coupon</button>
            <?php if ($coupon): ?><button class="button secondary" name="remove_coupon" value="1" formaction="<?= e(url('remove-coupon.php')) ?>">Remove</button><?php endif; ?>
        </form>

        <div style="display:flex; justify-content:space-between; padding:8px 0"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
        <div style="display:flex; justify-content:space-between; padding:8px 0"><span>Discount</span><span>-<?= money($discount) ?></span></div>
        <div style="display:flex; justify-content:space-between; padding:8px 0"><span>Delivery Fee</span><span><?= money($shippingFee) ?></span></div>
        <hr>
        <div style="display:flex; justify-content:space-between; font-size:18px"><b>Total due</b><b><?= money(max(0, $subtotal - $discount) + $shippingFee) ?></b></div>
    </aside>
</div>

<?php require_once __DIR__ . '/app/footer.php'; ?>