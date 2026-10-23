<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('customers');

// Download Sample CSV Import Template
if (isset($_GET['download_template']) && $_GET['download_template'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-import-template.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email', 'phone', 'password', 'city', 'address', 'status']);
    fputcsv($out, ['Tanvir Ahmed', 'tanvir.demo@example.com', '01711223344', '12345678', 'Dhaka', 'Banani Road 11', 'active']);
    fputcsv($out, ['Nusrat Jahan', 'nusrat.demo@example.com', '01811998877', '12345678', 'Chittagong', 'GEC Circle', 'active']);
    fclose($out);
    exit;
}

// Handle Search Query
$search = trim((string)($_GET['q'] ?? ''));

$whereSql = '';
$params   = [];

if ($search !== '') {
    $whereSql = "WHERE u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.city LIKE ? OR u.id = ?";
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, (int)$search];
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', store_name())) . '-users-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['User ID', 'Name', 'Email Address', 'Phone Number', 'City', 'Address', 'Status', 'Total Orders', 'Registered Date']);

    $exportSql = "
        SELECT u.*, COUNT(o.id) as total_orders 
        FROM users u 
        LEFT JOIN orders o ON o.user_id = u.id 
        $whereSql 
        GROUP BY u.id 
        ORDER BY u.id DESC
    ";

    $stmt = db()->prepare($exportSql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['phone'] ?: 'N/A',
            $row['city'] ?: 'N/A',
            $row['address'] ?: 'N/A',
            ucfirst($row['status']),
            $row['total_orders'],
            $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    // --- BULK CSV IMPORT HANDLER ---
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid CSV file.');
            redirect('admin/sales/users');
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            flash('error', 'Invalid file type. Only CSV files (.csv) are supported.');
            redirect('admin/sales/users');
        }

        $handle = fopen($tmpFile, 'r');
        if (!$handle) {
            flash('error', 'Could not open the uploaded CSV file.');
            redirect('admin/sales/users');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            flash('error', 'CSV file appears to be empty.');
            redirect('admin/sales/users');
        }

        // Header Column Index Map
        $headerMap = [];
        foreach ($headers as $idx => $colName) {
            $key = strtolower(trim($colName));
            $headerMap[$key] = $idx;
        }

        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue; // Skip empty rows

            $name     = trim((string)($row[$headerMap['name'] ?? 0] ?? ''));
            $email    = trim((string)($row[$headerMap['email'] ?? 1] ?? ''));
            $phone    = trim((string)($row[$headerMap['phone'] ?? 2] ?? ''));
            $rawPass  = trim((string)($row[$headerMap['password'] ?? 3] ?? '12345678'));
            $city     = trim((string)($row[$headerMap['city'] ?? 4] ?? ''));
            $address  = trim((string)($row[$headerMap['address'] ?? 5] ?? ''));
            $status   = strtolower(trim((string)($row[$headerMap['status'] ?? 6] ?? 'active')));
            $status   = in_array($status, ['active', 'blocked'], true) ? $status : 'active';

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            // Check Existing Account by Email
            $checkUser = db()->prepare('SELECT id FROM users WHERE email = ?');
            $checkUser->execute([$email]);
            $existingUser = $checkUser->fetch();

            if ($existingUser) {
                // Update profile info (preserve existing password unless explicit raw password passed)
                $up = db()->prepare('UPDATE users SET name=?, phone=?, city=?, address=?, status=? WHERE id=?');
                $up->execute([$name, $phone ?: null, $city ?: null, $address ?: null, $status, $existingUser['id']]);
                $updated++;
            } else {
                // Insert New User
                $passHash = password_hash($rawPass, PASSWORD_DEFAULT);
                $ins = db()->prepare('INSERT INTO users (name, email, password_hash, phone, city, address, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([$name, $email, $passHash, $phone ?: null, $city ?: null, $address ?: null, $status]);
                $imported++;
            }
        }
        fclose($handle);

        flash('success', "User Import Complete: $imported new users created, $updated updated, $skipped skipped due to invalid data.");
        redirect('admin/sales/users');
    }

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

// --- PAGINATION LOGIC ---
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$countSql = "SELECT COUNT(*) FROM users u $whereSql";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalUsers / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT u.*, COUNT(o.id) total_orders 
    FROM users u 
    LEFT JOIN orders o ON o.user_id = u.id 
    $whereSql 
    GROUP BY u.id 
    ORDER BY u.id DESC 
    LIMIT $limit OFFSET $offset
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

admin_header('Customer Management');
?>

<!-- Section: Bulk Import Users via CSV -->
<div class="panel" style="margin-bottom:22px; background:#f0fdf4; border:1px solid #bbf7d0;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0; color:#166534;">👥 Bulk Import Registered Users</h3>
            <p class="muted small" style="margin:4px 0 0 0;">Upload a CSV file to register or update multiple user accounts in bulk.</p>
        </div>
        <a href="<?= e(url('admin/sales/users?download_template=csv')) ?>" class="button secondary" style="padding:6px 12px; font-size:12px; background:#fff;">📄 Download CSV Template</a>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:15px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">
        <input type="file" name="csv_file" accept=".csv" required style="font-size:13px;">
        <button type="submit" style="background:#15803d; border-color:#166534; padding:8px 16px;">Upload & Import CSV</button>
    </form>
</div>

<!-- Section: Users Table & Search -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
        <h2>Registered Customers (<?= number_format($totalUsers) ?>)</h2>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="get" style="display:flex; gap:6px; margin:0;">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, city..." style="padding:6px 10px; font-size:13px; border:1px solid #ccc; border-radius:4px; width:220px;">
                <button type="submit" class="button secondary" style="padding:6px 12px; font-size:13px;">Search</button>
                <?php if ($search !== ''): ?>
                    <a href="<?= e(url('admin/sales/users')) ?>" class="button secondary" style="padding:6px 10px; font-size:13px;">Reset</a>
                <?php endif; ?>
            </form>
            <a href="<?= e(url('admin/sales/users?export=csv' . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:6px 12px; font-size:13px;">📥 Export CSV</a>
        </div>
    </div>

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
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;" class="muted">No registered users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:12px; border-top:1px solid #eee;">
            <span class="small muted">
                Page <b><?= $page ?></b> of <b><?= $totalPages ?></b>
            </span>
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url('admin/sales/users?page=' . ($page - 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">← Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(url('admin/sales/users?page=' . ($page + 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>