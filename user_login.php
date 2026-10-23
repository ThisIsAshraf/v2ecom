<?php
require_once __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('account.php');
}

// Handle Forgot Password Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));

    $st = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "active"');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user) {
        $tempPassword = bin2hex(random_bytes(4)); // 8-char temporary password
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
        email_user_password_reset($user['email'], $user['name'], $tempPassword);
    }

    flash('success', 'If an account exists with that email, a temporary password has been sent.');
    redirect('user_login.php');
}

// Handle Customer Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    verify_csrf();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // Query user with default saved address and city zone details
    $st = db()->prepare('
        SELECT 
            u.*, 
            ca.street_address AS saved_street_address,
            ca.phone AS saved_phone,
            sc.id AS saved_city_id,
            sc.city_name AS saved_city_name
        FROM users u
        LEFT JOIN customer_addresses ca ON ca.user_id = u.id AND ca.is_default = 1
        LEFT JOIN shipping_cities sc ON sc.id = ca.city_id
        WHERE u.email = ?
        LIMIT 1
    ');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user && $user['status'] === 'blocked') {
        flash('error', 'Your account has been suspended. Please contact support.');
    } elseif ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];

        flash('success', 'Welcome back, ' . $user['name'] . '!');
        redirect(isset($_GET['checkout']) ? 'checkout.php' : 'account.php');
    } else {
        flash('error', 'Invalid email address or password.');
    }
}

$pageTitle = 'Account Login';
require_once __DIR__ . '/app/header.php';
?>

<div style="max-width:420px; margin:40px auto;" class="panel">
    <h1>Customer Login</h1>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="login_submit" value="1">
        <div class="field">
            <label>Email Address</label>
            <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button style="width:100%;">Log In</button>
    </form>

    <hr style="margin:20px 0;">

    <h3>Forgot Password?</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="request_reset" value="1">
        <div class="field">
            <input type="email" name="email" required placeholder="Enter email address...">
        </div>
        <button class="button secondary" style="width:100%;">Send Temporary Password</button>
    </form>
    <p class="small muted" style="margin-top:15px; text-align:center;">Don't have an account? <a href="user_register.php">Sign up here</a></p>
</div>

<?php require_once __DIR__ . '/app/footer.php'; ?>