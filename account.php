<?php
require_once __DIR__ . '/app/bootstrap.php';

// Requires customer to be logged in
$u = user_required();

$errors = [];

// Fetch Normalized Shipping Cities
$cities = db()->query('SELECT * FROM shipping_cities ORDER BY city_name ASC')->fetchAll();

// Fetch Customer's Default Saved Address
$addrStmt = db()->prepare('
    SELECT ca.*, sc.city_name 
    FROM customer_addresses ca 
    LEFT JOIN shipping_cities sc ON sc.id = ca.city_id 
    WHERE ca.user_id = ? 
    ORDER BY ca.is_default DESC, ca.id DESC 
    LIMIT 1
');
$addrStmt->execute([$u['id']]);
$savedAddress = $addrStmt->fetch();

// Handle Profile & Address Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    verify_csrf();
    $name    = trim((string)($_POST['name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $cityId  = (int)($_POST['city_id'] ?? 0);

    if (mb_strlen($name) < 2) $errors[] = 'Full name is required.';
    if (mb_strlen($phone) < 7) $errors[] = 'Please enter a valid phone number.';
    if (mb_strlen($address) < 5) $errors[] = 'Please enter a valid street address.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Update user table profile info
            $st = $pdo->prepare('UPDATE users SET name=?, phone=? WHERE id=?');
            $st->execute([$name, $phone, $u['id']]);

            // Upsert into customer_addresses
            if ($savedAddress) {
                $upAddr = $pdo->prepare('UPDATE customer_addresses SET full_name=?, phone=?, street_address=?, city_id=? WHERE id=?');
                $upAddr->execute([$name, $phone, $address, $cityId, $savedAddress['id']]);
            } else {
                $insAddr = $pdo->prepare('INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default) VALUES (?, ?, ?, ?, ?, 1)');
                $insAddr->execute([$u['id'], $name, $phone, $address, $cityId]);
            }

            $pdo->commit();
            flash('success', 'Your account details and delivery address have been saved.');
            redirect('account.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Failed to update account details.';
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf();
    $currentPass = (string)($_POST['current_password'] ?? '');
    $newPass     = (string)($_POST['new_password'] ?? '');

    if (!password_verify($currentPass, $u['password_hash'])) {
        $errors[] = 'Your current password was incorrect.';
    } elseif (strlen($newPass) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }

    if (!$errors) {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $u['id']]);
        flash('success', 'Your password has been changed successfully.');
        redirect('account.php');
    }
}

// Fetch Customer's Past Orders using Normalized Joins
$ordersStmt = db()->prepare('
    SELECT o.*, sc.city_name 
    FROM orders o
    LEFT JOIN shipping_cities sc ON sc.id = o.city_id
    WHERE o.user_id = ? OR o.customer_email = ? 
    ORDER BY o.id DESC
');
$ordersStmt->execute([$u['id'], $u['email']]);
$userOrders = $ordersStmt->fetchAll();

$pageTitle = 'My Account';
require_once __DIR__ . '/app/header.php';
?>

<div style="max-width: 900px; margin: 30px auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
        <h1 style="margin:0;">My Account</h1>
        <a href="<?= e(url('user_logout.php')) ?>" class="button secondary" style="padding:8px 16px;">Log Out</a>
    </div>

    <?php if ($errors): ?>
        <div class="notice error">
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 25px;">

        <!-- Saved Delivery Profile -->
        <div class="panel">
            <h2>Saved Shipping Details</h2>
            <p class="muted small" style="margin-bottom:15px;">These details automatically pre-fill your checkout page.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="update_profile" value="1">
                <div class="field"><label>Full Name *</label><input name="name" required value="<?= e($u['name']) ?>"></div>
                <div class="field"><label>Email Address</label><input value="<?= e($u['email']) ?>" disabled style="background:#f4f4f4;"></div>
                <div class="field"><label>Phone Number *</label><input name="phone" required value="<?= e($savedAddress['phone'] ?? $u['phone'] ?? '') ?>"></div>
                <div class="field"><label>Delivery Address *</label><textarea name="address" required><?= e($savedAddress['street_address'] ?? $u['address'] ?? '') ?></textarea></div>

                <!-- Normalized City Dropdown -->
                <div class="field">
                    <label>City / Zone *</label>
                    <select name="city_id" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;">
                        <?php
                        $activeCityId = (int)($savedAddress['city_id'] ?? 1);
                        foreach ($cities as $c):
                        ?>
                            <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $activeCityId ? 'selected' : '' ?>>
                                <?= e($c['city_name']) ?> (<?= $c['zone_code'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="button" style="width:100%;">Save Address Info</button>
            </form>

            <hr style="margin:25px 0;">

            <h3>Security & Password</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">
                <div class="field"><label>Current Password</label><input type="password" name="current_password" required></div>
                <div class="field"><label>New Password (min 6 chars)</label><input type="password" name="new_password" required></div>
                <button class="button secondary" style="width:100%;">Update Password</button>
            </form>
        </div>

        <!-- Order History -->
        <div class="panel">
            <h2>Order History</h2>
            <?php if (!$userOrders): ?>
                <p class="muted">You haven't placed any orders yet.</p>
                <a href="<?= e(url('index.php')) ?>" class="button secondary">Start Shopping</a>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userOrders as $o): ?>
                                <tr>
                                    <td><b><?= e($o['order_number']) ?></b></td>
                                    <td><b><?= money($o['total']) ?></b></td>
                                    <td>
                                        <span class="status" style="padding:2px 6px; font-size:11px; font-weight:bold; border-radius:4px; background:<?= in_array($o['status'], ['delivered', 'confirmed']) ? '#e8f8f5;color:#27ae60;' : ($o['status'] === 'cancelled' ? '#fde8e8;color:#e74c3c;' : '#fef9e7;color:#f39c12;') ?>">
                                            <?= ucfirst(e($o['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/app/footer.php'; ?>