<?php require_once __DIR__ . '/../_init.php';
admin_required();

$title = 'Admin Security, RBAC & Active Sessions';
$db = db();

// Available Modules for Permission Assignment
$availablePermissions = [
    'analytics'  => 'Traffic & Sales Analytics',
    'products'   => 'Products & Inventory',
    'categories' => 'Categories',
    'orders'     => 'Order Management',
    'reports'    => 'Sales Reports',
    'coupons'    => 'Discounts & Promotions',
    'customers'  => 'Customer Management',
    'contacts'   => 'Contact Messages',
    'settings'   => 'Store Branding & Settings',
    'theme'      => 'Storefront Theme',
    'slides'     => 'Hero Banner Slides',
    'social'     => 'Social Media Feed',
    'reviews'    => 'Customer Reviews',
    'env'        => '.ENV Configuration Manager',
    'security'   => 'Admin Security & RBAC',
];

// High-Risk Security Sensitive Keys Styled with Red Text
$sensitiveKeys = ['analytics', 'env', 'security'];

// Helper Function to Parse Browser & Device Type from User Agent
function parse_user_agent(?string $ua): array
{
    if (!$ua) return ['browser' => 'Unknown', 'device' => 'Desktop 💻'];

    $device = 'Desktop 💻';
    if (preg_match('/(mobile|android|iphone|ipad|ipod|blackberry|windows phone)/i', $ua)) {
        $device = preg_match('/(ipad|tablet)/i', $ua) ? 'Tablet 📱' : 'Mobile 📱';
    }

    $browser = 'Browser';
    if (str_contains($ua, 'Edg')) $browser = 'Edge';
    elseif (str_contains($ua, 'Chrome')) $browser = 'Chrome';
    elseif (str_contains($ua, 'Firefox')) $browser = 'Firefox';
    elseif (str_contains($ua, 'Safari')) $browser = 'Safari';
    elseif (str_contains($ua, 'OPR') || str_contains($ua, 'Opera')) $browser = 'Opera';

    return ['browser' => $browser, 'device' => $device];
}

// CSV Audit Log Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'login_logs') {
    if (!is_super_admin()) {
        flash('error', 'Action denied: Only Super Admins can export security logs.');
        redirect('admin/system/security');
    }

    // Audit Logging: Login Logs Export
    log_admin_activity('security', 'export_login_logs', 'Exported admin login attempt audit logs to CSV');

    $filename = 'login-audit-logs-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'Status', 'Email Attempted', 'IP Address', 'Device & Browser', 'Timestamp']);

    $logsStmt = $db->query('SELECT id, status, email_attempted, ip_address, user_agent, created_at FROM admin_login_logs ORDER BY id DESC');
    while ($row = $logsStmt->fetch(PDO::FETCH_ASSOC)) {
        $uaInfo = parse_user_agent($row['user_agent']);
        fputcsv($out, [
            $row['id'],
            $row['status'],
            $row['email_attempted'],
            $row['ip_address'],
            $uaInfo['device'] . ' - ' . $uaInfo['browser'],
            $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // 0. Update Max Admin Sessions Settings
    if ($action === 'update_max_sessions') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can update security settings.');
            redirect('admin/system/security');
        }

        $maxVal = max(1, (int)($_POST['max_admin_sessions'] ?? 2));
        $upsert = $db->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES ("max_admin_sessions", ?) ON DUPLICATE KEY UPDATE setting_value = ?');
        $upsert->execute([(string)$maxVal, (string)$maxVal]);

        // Audit Logging: Policy Adjustment
        log_admin_activity('security', 'update_session_limit', "Changed max concurrent admin sessions limit to {$maxVal}");

        flash('success', 'Max concurrent admin sessions set to ' . $maxVal . '.');
        redirect('admin/system/security');
    }

    // 1. Terminate Session Action
    if ($action === 'terminate_session') {
        $sessionId = trim((string)($_POST['session_id'] ?? ''));

        $st = $db->prepare('
            SELECT s.admin_id, a.name, a.email, a.is_super_admin 
            FROM admin_sessions s
            JOIN admins a ON s.admin_id = a.id
            WHERE s.id = ?
        ');
        $st->execute([$sessionId]);
        $targetSession = $st->fetch();

        if ($targetSession) {
            $targetIsSuper = (bool)$targetSession['is_super_admin'];

            if ($targetIsSuper && !is_super_admin()) {
                flash('error', 'Action denied: Secondary admins cannot terminate a Super Admin session.');
            } else {
                $db->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$sessionId]);

                // Audit Logging: Session Revocation
                log_admin_activity(
                    'security',
                    'terminate_session',
                    "Terminated active session ({$sessionId}) for admin: {$targetSession['name']} ({$targetSession['email']})"
                );

                flash('success', 'Admin session terminated successfully.');
            }
        }
        redirect('admin/system/security');
    }

    // 2. Add New Admin (Super Admin Only)
    if ($action === 'create_admin') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can create new administrators.');
            redirect('admin/system/security');
        }

        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = trim((string)($_POST['role'] ?? 'Staff Administrator'));
        $perms    = $_POST['permissions'] ?? [];

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            flash('error', 'Please provide a valid name, email address, and a password of at least 6 characters.');
            redirect('admin/system/security');
        }

        $checkEmail = $db->prepare('SELECT id FROM admins WHERE email = ?');
        $checkEmail->execute([$email]);
        if ($checkEmail->fetch()) {
            flash('error', 'An admin account with this email already exists.');
            redirect('admin/system/security');
        }

        $passHash = password_hash($password, PASSWORD_DEFAULT);
        $assignedPerms = array_values(array_intersect(array_keys($availablePermissions), (array)$perms));
        $permsJson = json_encode($assignedPerms);

        $ins = $db->prepare('INSERT INTO admins (name, email, password_hash, role, is_super_admin, permissions) VALUES (?, ?, ?, ?, 0, ?)');
        $ins->execute([$name, $email, $passHash, $role, $permsJson]);
        $newAdminId = (int)$db->lastInsertId();

        if (function_exists('sync_htpasswd_user')) {
            sync_htpasswd_user($email, $password);
        }

        // Audit Logging: Admin Created
        $permList = $assignedPerms ? implode(', ', $assignedPerms) : 'None';
        log_admin_activity(
            'security',
            'create_admin',
            "Created secondary administrator '{$name}' ({$email}) with role '{$role}'. Permissions: [{$permList}]"
        );

        flash('success', 'New admin account for ' . e($name) . ' created successfully.');
        redirect('admin/system/security');
    }

    // 3. Update Existing Admin Permissions, Role & Password (Super Admin Only)
    if ($action === 'update_admin') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can update admin privileges.');
            redirect('admin/system/security');
        }

        $adminId  = (int)($_POST['admin_id'] ?? 0);
        $role     = trim((string)($_POST['role'] ?? 'Staff Administrator'));
        $password = (string)($_POST['password'] ?? '');
        $perms    = $_POST['permissions'] ?? [];

        $chk = $db->prepare('SELECT is_super_admin, name, email FROM admins WHERE id = ?');
        $chk->execute([$adminId]);
        $targetAdmin = $chk->fetch();

        if (!$targetAdmin) {
            flash('error', 'Admin account not found.');
            redirect('admin/system/security');
        }

        $targetSuper = (bool)$targetAdmin['is_super_admin'];
        $assignedPerms = array_values(array_intersect(array_keys($availablePermissions), (array)$perms));
        $permsJson = $targetSuper ? null : json_encode($assignedPerms);

        $passwordChanged = false;

        if ($password !== '') {
            if (strlen($password) < 6) {
                flash('error', 'Password must be at least 6 characters long.');
                redirect('admin/system/security');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $db->prepare('UPDATE admins SET role = ?, password_hash = ?, permissions = ? WHERE id = ?');
            $up->execute([$role, $hash, $permsJson, $adminId]);

            if (function_exists('sync_htpasswd_user') && !empty($targetAdmin['email'])) {
                sync_htpasswd_user((string)$targetAdmin['email'], $password);
            }
            $passwordChanged = true;
        } else {
            $up = $db->prepare('UPDATE admins SET role = ?, permissions = ? WHERE id = ?');
            $up->execute([$role, $permsJson, $adminId]);
        }

        // Audit Logging: Admin Updated
        $notes = [];
        $notes[] = "role set to '{$role}'";
        if (!$targetSuper) {
            $permList = $assignedPerms ? implode(', ', $assignedPerms) : 'None';
            $notes[] = "permissions: [{$permList}]";
        }
        if ($passwordChanged) {
            $notes[] = 'password reset';
        }
        log_admin_activity(
            'security',
            'update_admin',
            "Updated administrator '{$targetAdmin['name']}' ({$targetAdmin['email']}): " . implode('; ', $notes)
        );

        flash('success', 'Admin account privileges and password updated successfully.');
        redirect('admin/system/security');
    }

    // 4. Delete Admin Account (Super Admin Only)
    if ($action === 'delete_admin') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can delete administrators.');
            redirect('admin/system/security');
        }

        $adminId = (int)($_POST['admin_id'] ?? 0);

        if ($adminId === (int)$_SESSION['admin_id']) {
            flash('error', 'You cannot delete your own active account.');
            redirect('admin/system/security');
        }

        $chk = $db->prepare('SELECT is_super_admin, name, email FROM admins WHERE id = ?');
        $chk->execute([$adminId]);
        $targetAdmin = $chk->fetch();

        if (!$targetAdmin) {
            flash('error', 'Admin account not found.');
            redirect('admin/system/security');
        }

        if ($targetAdmin['is_super_admin']) {
            flash('error', 'Action denied: Central Super Admin accounts cannot be deleted.');
            redirect('admin/system/security');
        }

        $db->prepare('DELETE FROM admin_sessions WHERE admin_id = ?')->execute([$adminId]);
        $db->prepare('DELETE FROM admins WHERE id = ?')->execute([$adminId]);

        // Audit Logging: Admin Account Deleted
        log_admin_activity(
            'security',
            'delete_admin',
            "Deleted secondary administrator account '{$targetAdmin['name']}' ({$targetAdmin['email']}) and revoked sessions"
        );

        flash('success', 'Administrator account removed successfully.');
        redirect('admin/system/security');
    }

    // 5. Clear Old Login Logs
    if ($action === 'clear_logs') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can purge security logs.');
            redirect('admin/system/security');
        }
        $count = (int)$db->query('SELECT COUNT(*) FROM admin_login_logs')->fetchColumn();
        $db->query('DELETE FROM admin_login_logs');

        // Audit Logging: Login Logs Purged
        log_admin_activity('security', 'clear_login_logs', "Purged {$count} records from the admin login attempt audit ledger");

        flash('success', 'All login attempt logs have been cleared.');
        redirect('admin/system/security');
    }
}

admin_header($title);

$admins = $db->query('SELECT * FROM admins ORDER BY is_super_admin DESC, id ASC')->fetchAll();
$sessions = $db->query('
    SELECT s.*, a.name AS admin_name, a.email AS admin_email, a.is_super_admin 
    FROM admin_sessions s
    JOIN admins a ON s.admin_id = a.id
    ORDER BY s.last_activity DESC
')->fetchAll();

// --- AUDIT LOG PAGINATION LOGIC ---
$logPage   = max(1, (int)($_GET['log_page'] ?? 1));
$perPage   = 15;
$totalLogs = 0;
$loginLogs = [];

try {
    $totalLogs  = (int)$db->query('SELECT COUNT(*) FROM admin_login_logs')->fetchColumn();
    $totalPages = max(1, (int)ceil($totalLogs / $perPage));
    if ($logPage > $totalPages) $logPage = $totalPages;
    $offset     = ($logPage - 1) * $perPage;

    $stLog = $db->prepare('
        SELECT id, admin_id, email_attempted, ip_address, user_agent, status, created_at 
        FROM admin_login_logs 
        ORDER BY id DESC 
        LIMIT ? OFFSET ?
    ');
    $stLog->bindValue(1, $perPage, PDO::PARAM_INT);
    $stLog->bindValue(2, $offset, PDO::PARAM_INT);
    $stLog->execute();
    $loginLogs = $stLog->fetchAll();
} catch (Throwable $e) {
    $totalPages = 1;
}

$editingAdminId = (int)($_GET['edit'] ?? 0);
$currentMaxSessions = (int)site_setting('max_admin_sessions', '2');
?>

<!-- Section 1: Active Admin Sessions & Session Limits -->
<div class="panel" style="margin-bottom:25px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
        <div>
            <h2 style="margin:0;">Active Signed-In Sessions</h2>
            <p class="muted small" style="margin:4px 0 0 0;">
                Monitor real-time devices logged into your store panel.
            </p>
        </div>

        <?php if (is_super_admin()): ?>
            <form method="post" action="<?= e(url('admin/system/security')) ?>" style="display:flex; align-items:center; gap:10px; background:#f8f9fa; padding:8px 12px; border-radius:6px; border:1px solid #e0e0e0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_max_sessions">
                <label style="font-size:12px; font-weight:bold; color:#444; margin:0; white-space:nowrap;">
                    Max Concurrent Sessions per Admin:
                </label>
                <input type="number" name="max_admin_sessions" value="<?= $currentMaxSessions ?>" min="1" max="10" required style="width:60px; padding:4px 8px; font-size:13px; text-align:center; border:1px solid #ccc; border-radius:4px;">
                <button type="submit" class="button secondary" style="padding:4px 10px; font-size:12px;">Save Limit</button>
            </form>
        <?php endif; ?>
    </div>

    <table style="width:100%; border-collapse:collapse; margin-top:10px;">
        <thead>
            <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                <th style="padding:10px;">Admin Account</th>
                <th style="padding:10px;">IP Address</th>
                <th style="padding:10px;">Browser / Device</th>
                <th style="padding:10px;">Last Active</th>
                <th style="padding:10px; text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
                <?php $isCurrentSession = ($s['id'] === session_id()); ?>
                <tr style="border-bottom:1px solid #eee; <?= $isCurrentSession ? 'background:#f0f9ff;' : '' ?>">
                    <td style="padding:10px;">
                        <b><?= e($s['admin_name']) ?></b><br>
                        <span class="small muted"><?= e($s['admin_email']) ?></span>
                        <?= $s['is_super_admin'] ? '<span style="background:#2c3e50; color:#fff; font-size:10px; padding:2px 6px; border-radius:4px; margin-left:4px;">SUPER ADMIN</span>' : '' ?>
                    </td>
                    <td style="padding:10px;">
                        <code><?= e($s['ip_address']) ?></code>
                    </td>
                    <td style="padding:10px; font-size:12px; color:#555; max-width:200px; word-break:break-word;">
                        <?= e($s['user_agent']) ?>
                    </td>
                    <td style="padding:10px; font-size:13px; color:#666;">
                        <?= date('d M Y, h:i A', strtotime($s['last_activity'])) ?>
                    </td>
                    <td style="padding:10px; text-align:right;">
                        <?php if ($isCurrentSession): ?>
                            <span style="color:#27ae60; font-weight:bold; font-size:12px;">Active Device</span>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('admin/system/security')) ?>" style="display:inline;" onsubmit="return confirm('Revoke this login session?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="terminate_session">
                                <input type="hidden" name="session_id" value="<?= e($s['id']) ?>">
                                <button class="button secondary danger" style="padding:5px 10px; font-size:12px;">Revoke Session</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Section 2: Login Attempt Audit Logs with Pagination & Export -->
<div class="panel" id="audit-log" style="margin-bottom:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0;">Login Attempt Audit Log (<?= number_format($totalLogs) ?> total)</h2>
            <p class="muted small" style="margin:4px 0 0 0;">
                Tracks all successful and failed admin sign-in attempts with device detection & IP Geolocation lookups.
            </p>
        </div>

        <?php if (is_super_admin() && !empty($totalLogs)): ?>
            <div style="display:flex; gap:8px;">
                <a class="button secondary" style="padding:5px 12px; font-size:12px;" href="?export=login_logs">📥 Export CSV</a>
                <form method="post" action="<?= e(url('admin/system/security')) ?>" onsubmit="return confirm('Purge all login audit logs?');" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear_logs">
                    <button class="button secondary" style="padding:5px 12px; font-size:12px; background:#fde8e8; color:#e74c3c;">Clear Audit Log</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($loginLogs)): ?>
        <p class="muted" style="padding:15px; text-align:center;">No recent login logs recorded.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                    <th style="padding:10px;">Status</th>
                    <th style="padding:10px;">Email Attempted</th>
                    <th style="padding:10px;">IP Address & Geolocation</th>
                    <th style="padding:10px;">Device & Browser</th>
                    <th style="padding:10px; text-align:right;">Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loginLogs as $log): ?>
                    <?php
                    $uaData = parse_user_agent($log['user_agent']);
                    $isSuccess = ($log['status'] === 'success');
                    ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:10px;">
                            <?php if ($isSuccess): ?>
                                <span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:bold; padding:3px 8px; border-radius:4px; font-size:11px;">
                                    ✓ SUCCESS
                                </span>
                            <?php else: ?>
                                <span style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-weight:bold; padding:3px 8px; border-radius:4px; font-size:11px;">
                                    ✕ FAILED
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px; font-weight:bold; font-size:13px;">
                            <?= e($log['email_attempted']) ?>
                        </td>
                        <td style="padding:10px;">
                            <code><?= e($log['ip_address']) ?></code>
                            <a href="https://ipinfo.io/<?= e($log['ip_address']) ?>" target="_blank" rel="noopener" style="font-size:11px; margin-left:6px; color:#2563eb; text-decoration:none; font-weight:500;">
                                🌐 Lookup Geo
                            </a>
                        </td>
                        <td style="padding:10px; font-size:12px; color:#475569;">
                            <b><?= $uaData['device'] ?></b> • <?= $uaData['browser'] ?>
                        </td>
                        <td style="padding:10px; text-align:right; font-size:12px; color:#64748b;">
                            <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Log Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:12px; border-top:1px solid #eee;">
                <span class="small muted">
                    Showing Page <b><?= $logPage ?></b> of <b><?= $totalPages ?></b>
                </span>
                <div style="display:flex; gap:6px;">
                    <?php if ($logPage > 1): ?>
                        <a href="?log_page=<?= $logPage - 1 ?>#audit-log" class="button secondary" style="padding:4px 10px; font-size:12px;">← Previous</a>
                    <?php endif; ?>

                    <?php if ($logPage < $totalPages): ?>
                        <a href="?log_page=<?= $logPage + 1 ?>#audit-log" class="button secondary" style="padding:4px 10px; font-size:12px;">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Section 3: Role-Based Admin Account Management -->
<div class="panel">
    <h2>Administrator Accounts & Privileges</h2>

    <table style="width:100%; border-collapse:collapse; margin-top:15px;">
        <thead>
            <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                <th style="padding:10px;">Name & Email</th>
                <th style="padding:10px;">Assigned Role</th>
                <th style="padding:10px;">Module Permissions</th>
                <th style="padding:10px; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $a): ?>
                <?php
                $pArr = $a['permissions'] ? json_decode($a['permissions'], true) : [];
                if (!is_array($pArr)) $pArr = [];
                $isEditingThis = ($editingAdminId === (int)$a['id']);
                ?>
                <tr style="border-bottom:1px solid #eee; vertical-align:top; <?= $isEditingThis ? 'background:#fffde7;' : '' ?>">
                    <td style="padding:12px 10px;">
                        <b><?= e($a['name']) ?></b><br>
                        <span class="small muted"><?= e($a['email']) ?></span>
                    </td>
                    <td style="padding:12px 10px;">
                        <span style="background:#eef2f7; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:bold; color:#2c3e50;">
                            <?= e($a['role']) ?>
                        </span>
                    </td>
                    <td style="padding:12px 10px; font-size:12px; color:#444;">
                        <?php if ($a['is_super_admin']): ?>
                            <b style="color:#27ae60;">Full Unrestricted System Access</b>
                        <?php elseif (empty($pArr)): ?>
                            <span style="color:#e74c3c;">No Permissions Assigned</span>
                        <?php else: ?>
                            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                <?php foreach ($pArr as $pk): ?>
                                    <span style="background:#e8f4f8; color:<?= in_array($pk, $sensitiveKeys, true) ? '#d32f2f' : '#16a085' ?>; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:<?= in_array($pk, $sensitiveKeys, true) ? 'bold' : 'normal' ?>;">
                                        <?= e($availablePermissions[$pk] ?? $pk) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 10px; text-align:right;">
                        <?php if (is_super_admin() && !$a['is_super_admin']): ?>
                            <a href="<?= e(url('admin/system/security?edit=' . $a['id'])) ?>#edit-form" class="button secondary" style="padding:4px 8px; font-size:12px; margin-right:4px;">Edit Privileges / Password</a>

                            <form method="post" action="<?= e(url('admin/system/security')) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this admin account?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                                <button class="button danger" style="padding:4px 8px; font-size:12px;">Delete</button>
                            </form>
                        <?php elseif ($a['is_super_admin']): ?>
                            <span class="small muted" style="font-weight:bold; color:#2c3e50;">Primary Central Admin</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (is_super_admin()): ?>
        <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">

        <?php
        $editTarget = null;
        if ($editingAdminId > 0) {
            foreach ($admins as $ad) {
                if ((int)$ad['id'] === $editingAdminId && !$ad['is_super_admin']) {
                    $editTarget = $ad;
                    break;
                }
            }
        }
        ?>

        <?php if ($editTarget): ?>
            <!-- Edit Admin Privileges & Password Form -->
            <div id="edit-form" style="background:#fff8e1; border:1px solid #ffe082; padding:20px; border-radius:8px; margin-top:15px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; color:#856404;">Edit Admin Privileges: <?= e($editTarget['name']) ?> (<?= e($editTarget['email']) ?>)</h3>
                    <a href="<?= e(url('admin/system/security')) ?>" style="color:#856404; font-size:13px;">Cancel Edit ✕</a>
                </div>

                <form method="post" action="<?= e(url('admin/system/security')) ?>" style="max-width:650px; margin-top:15px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" value="<?= $editTarget['id'] ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label>Role / Position Title</label>
                            <input name="role" value="<?= e($editTarget['role']) ?>" required placeholder="e.g., Inventory Manager">
                        </div>
                        <div class="field">
                            <label>New Password (Leave blank to keep unchanged)</label>
                            <input type="password" name="password" minlength="6" placeholder="Enter new password">
                        </div>
                    </div>

                    <?php
                    $currPerms = $editTarget['permissions'] ? json_decode($editTarget['permissions'], true) : [];
                    if (!is_array($currPerms)) $currPerms = [];
                    ?>
                    <div class="field" style="margin-top:15px;">
                        <label style="font-weight:bold; display:block; margin-bottom:8px;">Assigned Module Access Permissions:</label>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:10px; background:#fff; padding:15px; border-radius:6px; border:1px solid #e0e0e0;">
                            <?php foreach ($availablePermissions as $key => $label): ?>
                                <?php $isSensitive = in_array($key, $sensitiveKeys, true); ?>
                                <label style="font-size:13px; font-weight:<?= $isSensitive ? 'bold' : 'normal' ?>; color:<?= $isSensitive ? '#d32f2f' : '#333' ?>; cursor:pointer;">
                                    <input type="checkbox" name="permissions[]" value="<?= $key ?>" <?= in_array($key, $currPerms, true) ? 'checked' : '' ?> style="width:auto; margin-right:5px;">
                                    <?= e($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" style="margin-top:15px; background:#f39c12; border-color:#d35400;">Update Admin Account</button>
                </form>
            </div>
        <?php else: ?>
            <!-- Create New Admin Form -->
            <h3>Create Secondary Admin Account</h3>
            <form method="post" action="<?= e(url('admin/system/security')) ?>" style="max-width:650px; margin-top:15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_admin">

                <div class="form-grid">
                    <div class="field">
                        <label>Full Name *</label>
                        <input name="name" required placeholder="e.g., Jane Doe">
                    </div>
                    <div class="field">
                        <label>Email Address *</label>
                        <input type="email" name="email" required placeholder="staff@store.com">
                    </div>
                    <div class="field">
                        <label>Password *</label>
                        <input type="password" name="password" required minlength="6" placeholder="Min. 6 characters">
                    </div>
                    <div class="field">
                        <label>Role / Position Title</label>
                        <input name="role" value="Order Processor" placeholder="e.g., Inventory Manager">
                    </div>
                </div>

                <div class="field" style="margin-top:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:8px;">Grant Module Access Permissions:</label>
                    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:10px; background:#f9f9f9; padding:15px; border-radius:6px; border:1px solid #eee;">
                        <?php foreach ($availablePermissions as $key => $label): ?>
                            <?php $isSensitive = in_array($key, $sensitiveKeys, true); ?>
                            <label style="font-size:13px; font-weight:<?= $isSensitive ? 'bold' : 'normal' ?>; color:<?= $isSensitive ? '#d32f2f' : '#333' ?>; cursor:pointer;">
                                <input type="checkbox" name="permissions[]" value="<?= $key ?>" style="width:auto; margin-right:5px;">
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" style="margin-top:15px;">Create Administrator Account</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>