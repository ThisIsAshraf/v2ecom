<?php require_once __DIR__ . '/../_init.php';
admin_required();

// Strict Access Control: Super Admin ONLY
if (!is_super_admin()) {
    flash('error', 'Access Denied: Only Central Super Administrators can view system audit logs.');
    redirect('admin/dashboard');
}

$db = db();
$search = trim((string)($_GET['q'] ?? ''));
$moduleFilter = trim((string)($_GET['module'] ?? ''));

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'admin-activity-audit-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['Log ID', 'Admin Name', 'Email', 'Module', 'Action', 'Description', 'IP Address', 'Timestamp']);

    $exportSql = 'SELECT * FROM admin_activity_logs WHERE 1=1';
    $exportParams = [];

    if ($search !== '') {
        $exportSql .= ' AND (admin_name LIKE ? OR admin_email LIKE ? OR description LIKE ? OR ip_address LIKE ?)';
        $term = '%' . $search . '%';
        array_push($exportParams, $term, $term, $term, $term);
    }
    if ($moduleFilter !== '') {
        $exportSql .= ' AND module = ?';
        $exportParams[] = $moduleFilter;
    }
    $exportSql .= ' ORDER BY id DESC';

    $st = $db->prepare($exportSql);
    $st->execute($exportParams);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'],
            $r['admin_name'],
            $r['admin_email'],
            strtoupper($r['module']),
            $r['action'],
            $r['description'],
            $r['ip_address'],
            $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// Clear Logs Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'clear_all_activities') {
        $db->query('TRUNCATE TABLE admin_activity_logs');
        log_admin_activity('security', 'purge_logs', 'Purged all system activity audit trail logs');
        flash('success', 'All activity audit logs have been successfully cleared.');
        redirect('admin/system/activity-logs');
    }
}

// Build Search & Filter Conditions
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(admin_name LIKE ? OR admin_email LIKE ? OR description LIKE ? OR ip_address LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($moduleFilter !== '') {
    $whereClauses[] = 'module = ?';
    $params[] = $moduleFilter;
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Pagination (20 logs per page)
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;

$countStmt = $db->prepare("SELECT COUNT(*) FROM admin_activity_logs $whereSql");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalLogs / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Fetch Filtered Logs
$sql = "SELECT * FROM admin_activity_logs $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
$st = $db->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

// Distinct Modules for Filter Dropdown
$modules = $db->query('SELECT DISTINCT module FROM admin_activity_logs ORDER BY module ASC')->fetchAll(PDO::FETCH_COLUMN);

admin_header('System Activity & Audit Trail');
?>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
            <h2 style="margin:0;">System Activity & Audit Trail (<?= number_format($totalLogs) ?>)</h2>
            <p class="muted small" style="margin:4px 0 0 0;">
                Tracks administrative modifications, status shifts, updates, and removals across all modules.
            </p>
        </div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <a href="<?= e(url('admin/system/activity-logs?export=csv' . ($moduleFilter ? '&module=' . urlencode($moduleFilter) : '') . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:6px 14px; font-size:12px;">
                📥 Export CSV
            </a>

            <form method="post" action="<?= e(url('admin/system/activity-logs')) ?>" onsubmit="return confirm('Permanently clear all activity audit logs? This cannot be undone.');" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_all_activities">
                <button class="button secondary danger" style="padding:6px 14px; font-size:12px; background:#fde8e8; color:#e74c3c;">
                    Clear Activity Logs
                </button>
            </form>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="get" action="<?= e(url('admin/system/activity-logs')) ?>" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search admin, email, description, IP..." style="padding:6px 12px; min-width:280px; font-size:13px;">

        <select name="module" style="padding:6px 10px; font-size:13px;">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
                <option value="<?= e($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= strtoupper(e($m)) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="button secondary" style="padding:6px 14px; font-size:13px;">Filter</button>
        <?php if ($search !== '' || $moduleFilter !== ''): ?>
            <a href="<?= e(url('admin/system/activity-logs')) ?>" class="button secondary" style="padding:6px 12px; font-size:13px;">Reset</a>
        <?php endif; ?>
    </form>

    <!-- Audit Logs Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Admin Account</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Activity Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td style="white-space:nowrap; font-size:12px; color:#555;">
                            <?= date('M d, Y', strtotime($l['created_at'])) ?><br>
                            <span class="muted small"><?= date('h:i:s A', strtotime($l['created_at'])) ?></span>
                        </td>
                        <td>
                            <b><?= e($l['admin_name'] ?: 'System / Automated') ?></b><br>
                            <span class="small muted"><?= e($l['admin_email'] ?: '—') ?></span>
                        </td>
                        <td>
                            <span class="status" style="padding:3px 8px; border-radius:4px; font-size:11px; font-weight:bold; background:#eef2f7; color:#2c3e50;">
                                <?= strtoupper(e($l['module'])) ?>
                            </span>
                        </td>
                        <td>
                            <code><?= e($l['action']) ?></code>
                        </td>
                        <td style="font-size:13px; max-width:350px;">
                            <?= e($l['description']) ?>
                        </td>
                        <td>
                            <code><?= e($l['ip_address']) ?></code>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:25px;" class="muted">No activity records found.</td>
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
                    <a href="<?= e(url('admin/system/activity-logs?page=' . ($page - 1) . ($moduleFilter ? '&module=' . urlencode($moduleFilter) : '') . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">← Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(url('admin/system/activity-logs?page=' . ($page + 1) . ($moduleFilter ? '&module=' . urlencode($moduleFilter) : '') . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>