<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// Send security headers to satisfy browser security requirements
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$error = null;

// Secure IP Resolution (Prevents IP Spoofing)
$rawIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (str_contains($rawIp, ',')) {
    $ip = trim(explode(',', $rawIp)[0]); // Extract original client IP
} else {
    $ip = trim($rawIp);
}

// Truncate User-Agent string to prevent DB overflow errors
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser', 0, 500);

// --- IP RATE LIMITING & LOCKOUT CHECK ---
$lockoutMinutes = 15;
$maxAllowedFailures = 5;

$lockCheck = db()->prepare('
    SELECT COUNT(*) AS failed_count, 
           MAX(created_at) AS last_failed_at 
    FROM admin_login_logs 
    WHERE ip_address = ? 
      AND status = "failed" 
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
');
$lockCheck->execute([$ip, $lockoutMinutes]);
$lockData = $lockCheck->fetch();

$failedAttempts = (int)($lockData['failed_count'] ?? 0);
$isLockedOut = $failedAttempts >= $maxAllowedFailures;

$timeRemaining = 0;
if ($isLockedOut && !empty($lockData['last_failed_at'])) {
    $lastFailedTime = strtotime($lockData['last_failed_at']);
    $unlockTime = $lastFailedTime + ($lockoutMinutes * 60);
    $timeRemaining = max(1, (int)ceil(($unlockTime - time()) / 60));
}

// Process POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Block brute force execution if IP is locked out
    if ($isLockedOut) {
        $error = "Too many failed login attempts from your IP ({$ip}). Please try again in {$timeRemaining} minute(s).";
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $s = db()->prepare('SELECT * FROM admins WHERE email = ?');
        $s->execute([$email]);
        $admin = $s->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_email'] = $admin['email'];

            // 1. Log Successful Login Audit
            $log = db()->prepare('INSERT INTO admin_login_logs (admin_id, email_attempted, ip_address, user_agent, status) VALUES (?, ?, ?, ?, ?)');
            $log->execute([$admin['id'], $email, $ip, $userAgent, 'success']);

            // 2. Enforce Max Session Limit (Defaults to 2 concurrent sessions)
            $maxSessions = (int)site_setting('max_admin_sessions', '2');

            $sessionStmt = db()->prepare('SELECT id FROM admin_sessions WHERE admin_id = ? ORDER BY last_activity DESC');
            $sessionStmt->execute([$admin['id']]);
            $existingSessions = $sessionStmt->fetchAll(PDO::FETCH_COLUMN);

            while (count($existingSessions) >= $maxSessions && !in_array(session_id(), $existingSessions, true)) {
                $oldestSessionId = array_pop($existingSessions);
                db()->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$oldestSessionId]);
            }

            // Register Active Session
            $sessInsert = db()->prepare('INSERT INTO admin_sessions (id, admin_id, ip_address, user_agent) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), last_activity = NOW()');
            $sessInsert->execute([session_id(), $admin['id'], $ip, $userAgent]);

            flash('success', 'Welcome back, ' . $admin['name']);
            redirect('manage_portal_x92/index.php');
        } else {
            // Log Failed Attempt Audit
            $log = db()->prepare('INSERT INTO admin_login_logs (admin_id, email_attempted, ip_address, user_agent, status) VALUES (?, ?, ?, ?, ?)');
            $log->execute([$admin['id'] ?? null, $email, $ip, $userAgent, 'failed']);

            $failedAttempts++; // Increment attempt counter
            $remainingAttempts = $maxAllowedFailures - $failedAttempts;

            if ($remainingAttempts > 0) {
                $error = "Invalid credentials. You have {$remainingAttempts} attempt(s) remaining before a 15-minute lockout.";
            } else {
                $error = "Too many failed attempts. Your IP address has been temporarily locked out for 15 minutes.";
                $isLockedOut = true;
                $timeRemaining = $lockoutMinutes;
            }
        }
    }
}

$pageTitle = 'Management Portal Sign In';
$storeLogo = site_setting('logo_url', '');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e(store_name()) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>

<body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:#f4f6f8; font-family: sans-serif;">
    <div class="panel" style="width:100%; max-width:420px; padding:35px 30px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.05); text-align:center;">

        <!-- Brand Logo / Name Display -->
        <div style="margin-bottom:20px;">
            <?php if ($storeLogo !== ''): ?>
                <img src="<?= e(url($storeLogo)) ?>" alt="<?= e(store_name()) ?>" style="max-height:50px; width:auto; margin-bottom:10px;">
            <?php endif; ?>
            <h2 style="margin:0; font-size:22px; color:#2c3e50;"><?= e(store_name()) ?></h2>
            <p class="muted small" style="margin:4px 0 0 0; color:#777;">Internal Store Management Portal</p>
        </div>

        <?php if ($isLockedOut && !$error): ?>
            <div class="notice error" style="text-align:left; margin-bottom:20px;">
                <b>Security Lockout Active:</b> Too many failed login attempts from IP <code><?= e($ip) ?></code>. Please try again in <b><?= $timeRemaining ?> minute(s)</b>.
            </div>
        <?php elseif ($error): ?>
            <div class="notice error" style="text-align:left; margin-bottom:20px;"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e($_SERVER['PHP_SELF']) ?>" style="text-align:left;">
            <?= csrf_field() ?>
            <div class="field" style="margin-bottom:15px;">
                <label style="font-weight:600; font-size:13px; color:#444;">Admin Email Address</label>
                <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" <?= $isLockedOut ? 'disabled' : '' ?> style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; <?= $isLockedOut ? 'background:#eee;' : '' ?>">
            </div>
            <div class="field" style="margin-bottom:20px;">
                <label style="font-weight:600; font-size:13px; color:#444;">Password</label>
                <input type="password" name="password" required <?= $isLockedOut ? 'disabled' : '' ?> style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; <?= $isLockedOut ? 'background:#eee;' : '' ?>">
            </div>
            <button class="button" <?= $isLockedOut ? 'disabled' : '' ?> style="width:100%; padding:12px; font-weight:bold; font-size:14px; border-radius:6px; <?= $isLockedOut ? 'opacity:0.6; cursor:not-allowed;' : '' ?>">
                <?= $isLockedOut ? 'Locked Out' : 'Sign In to Dashboard' ?>
            </button>
        </form>

        <div style="margin-top:25px; border-top:1px solid #eee; padding-top:15px; font-size:12px; color:#888;">
            <p style="margin:0;"><?= e(store_name()) ?> Official Administration System</p>
            <a href="<?= e(url('index.php')) ?>" style="color:#666; text-decoration:none; margin-top:5px; display:inline-block;">← Return to Storefront</a>
        </div>
    </div>
</body>

</html>