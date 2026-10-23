<?php require_once __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect('account.php');

// Fetch Normalized Shipping Cities
$cities = db()->query('SELECT * FROM shipping_cities ORDER BY zone_code ASC, city_name ASC')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name     = trim((string)($_POST['name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    $address  = trim((string)($_POST['address'] ?? ''));
    $cityId   = (int)($_POST['city_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    // Check duplicate email
    $check = db()->prepare('SELECT id FROM users WHERE email=?');
    $check->execute([$email]);
    if ($check->fetch()) $errors[] = 'An account with this email already exists.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Find Selected City Name
            $cityName = '';
            foreach ($cities as $c) {
                if ((int)$c['id'] === $cityId) {
                    $cityName = $c['city_name'];
                    break;
                }
            }

            // 1. Create User Account (Saving city string for easy reference)
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (name, email, password_hash, phone, address, city) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$name, $email, $hash, $phone, $address, $cityName]);
            $userId = (int)$pdo->lastInsertId();

            // 2. Save Default Normalized Shipping Address if address details provided
            if ($address !== '' || $cityId > 0) {
                $addrIns = $pdo->prepare('INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default) VALUES (?, ?, ?, ?, ?, 1)');
                $addrIns->execute([$userId, $name, $phone, $address, $cityId ?: null]);
            }

            $pdo->commit();

            $_SESSION['user_id'] = $userId;
            $user = ['id' => $userId, 'name' => $name, 'email' => $email];
            email_user_welcome($user);

            flash('success', 'Account created successfully! Welcome to ' . store_name());
            redirect('account.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registration error: ' . $e->getMessage());
            $errors[] = 'Failed to create account. Please try again.';
        }
    }
}

$pageTitle = 'Create Account';
require_once __DIR__ . '/app/header.php';
?>
<div style="max-width:450px; margin:40px auto;" class="panel">
    <h1>Create an Account</h1>
    <?php if ($errors): ?>
        <div class="notice error">
            <ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Full Name *</label><input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div class="field"><label>Email Address *</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
        <div class="field"><label>Phone Number</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>"></div>
        <div class="field"><label>Delivery Address</label><textarea name="address"><?= e($_POST['address'] ?? '') ?></textarea></div>

        <!-- Normalized City Dropdown -->
        <div class="field">
            <label>City / Zone *</label>
            <select name="city_id" required style="width:100%; padding:10px; border-radius:6px; border:1px solid #ccc;">
                <option value="">-- Select Delivery City --</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ((int)($_POST['city_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= e($c['city_name']) ?> (<?= $c['zone_code'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?> — <?= money($c['shipping_cost']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field"><label>Password (min 6 chars) *</label><input type="password" name="password" required></div>
        <button style="width:100%;">Register Account</button>
    </form>
    <p class="small muted" style="margin-top:15px; text-align:center;">Already have an account? <a href="user_login.php">Log in here</a></p>
</div>
<?php require_once __DIR__ . '/app/footer.php'; ?>