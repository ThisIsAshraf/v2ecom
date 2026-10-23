<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('customers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_status') {
        db()->prepare('UPDATE users SET status = IF(status="active","blocked","active") WHERE id=?')->execute([$userId]);
        flash('success', 'User account status updated.');
    } elseif ($action === 'reset_pass') {
        $st = db()->prepare('SELECT email, name FROM users WHERE id=?');
        $st->execute([$userId]);
        $u = $st->fetch();
        if ($u) {
            $tempPass = bin2hex(random_bytes(4));
            $hash = password_hash($tempPass, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
            email_user_password_reset($u['email'], $u['name'], $tempPass);
            flash('success', 'Temporary password sent to ' . $u['email']);
        }
    }
    redirect('admin/sales/users');
}

$users = db()->query('SELECT u.*, COUNT(o.id) total_orders FROM users u LEFT JOIN orders o ON o.user_id=u.id GROUP BY u.id ORDER BY u.id DESC')->fetchAll();
admin_header('Customer Management');
?>

<div class="panel">
    <h2>Registered Customers</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><b>#<?= $u['id'] ?></b></td>
                        <td><?= e($u['name']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($u['phone'] ?: '—') ?></td>
                        <td><b><?= (int)$u['total_orders'] ?></b></td>
                        <td>
                            <span class="status" style="background:<?= $u['status'] === 'active' ? '#e8f8f5;color:#27ae60;' : '#fde8e8;color:#e74c3c;' ?>">
                                <?= ucfirst($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button name="action" value="toggle_status" class="button secondary" style="padding:4px 8px; font-size:11px;">
                                    <?= $u['status'] === 'active' ? 'Block' : 'Unblock' ?>
                                </button>
                                <button name="action" value="reset_pass" class="button secondary" style="padding:4px 8px; font-size:11px;">
                                    Reset Password
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>