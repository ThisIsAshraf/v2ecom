<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('products');

// Handle Stock Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'adjust_stock') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $changeType = (string)($_POST['change_type'] ?? 'add');
        $qty = max(1, (int)($_POST['quantity'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));
        $adminId = $_SESSION['admin_id'] ?? null;

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $s = $pdo->prepare('SELECT id, name, stock FROM products WHERE id = ? FOR UPDATE');
            $s->execute([$productId]);
            $product = $s->fetch();

            if (!$product) {
                $pdo->rollBack();
                flash('error', 'Product not found.');
                redirect('admin/catalog/stock-logs');
            }

            $oldStock = (int)$product['stock'];
            $newStock = $oldStock;

            if ($changeType === 'add') {
                $newStock = $oldStock + $qty;
            } elseif ($changeType === 'subtract') {
                $newStock = max(0, $oldStock - $qty);
            } elseif ($changeType === 'set') {
                $newStock = max(0, (int)$_POST['quantity']);
                $qty = abs($newStock - $oldStock);
            }

            // Update product stock
            $update = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
            $update->execute([$newStock, $productId]);

            // Record log entry
            $log = $pdo->prepare('INSERT INTO stock_logs (product_id, admin_id, change_type, quantity, old_stock, new_stock, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $log->execute([$productId, $adminId, $changeType, $qty, $oldStock, $newStock, $note ?: 'Manual adjustment']);

            $pdo->commit();
            flash('success', 'Stock updated for ' . $product['name'] . ' (' . $oldStock . ' → ' . $newStock . ').');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            flash('error', 'Could not update inventory stock.');
        }

        redirect('admin/catalog/stock-logs');
    }
}

// Fetch Products for Dropdown
$productsList = db()->query('SELECT id, name, sku, stock FROM products ORDER BY name ASC')->fetchAll();

// Filter Logs
$filterProductId = (int)($_GET['product_id'] ?? 0);
$sql = '
    SELECT sl.*, p.name product_name, p.sku
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
';
$params = [];

if ($filterProductId > 0) {
    $sql .= ' WHERE sl.product_id = ?';
    $params[] = $filterProductId;
}

$sql .= ' ORDER BY sl.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

admin_header('Inventory Stock Logs'); ?>

<!-- Quick Adjust Stock Form -->
<div class="panel" style="margin-bottom: 22px;">
    <h2>Adjust Inventory Stock</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="adjust_stock">

        <div class="form-grid">
            <div class="field">
                <label>Select Product *</label>
                <select name="product_id" required>
                    <option value="">-- Choose Item --</option>
                    <?php foreach ($productsList as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProductId === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (SKU: <?= e($p['sku']) ?> | Current: <?= (int)$p['stock'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Action Type *</label>
                <select name="change_type" required>
                    <option value="add">➕ Add Stock (Restock)</option>
                    <option value="subtract">➖ Deduct Stock (Damaged/Lost)</option>
                    <option value="set">🎯 Set Exact Stock Level</option>
                </select>
            </div>

            <div class="field">
                <label>Quantity *</label>
                <input type="number" min="1" name="quantity" required placeholder="e.g. 10">
            </div>

            <div class="field">
                <label>Note / Reason (Optional)</label>
                <input name="note" placeholder="e.g. Restocked from supplier, Damaged unit">
            </div>
        </div>

        <p style="margin-top: 15px;">
            <button class="button">Apply Adjustment</button>
        </p>
    </form>
</div>

<!-- Stock Log Audit History Table -->
<div class="panel">
    <div class="section-heading" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h2>Audit History Log</h2>
        <form method="get" class="inline-form" style="display:flex; gap:8px;">
            <select name="product_id" style="padding: 6px 10px;">
                <option value="0">All Products</option>
                <?php foreach ($productsList as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $filterProductId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button secondary">Filter</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Product / SKU</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Stock (Before → After)</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= date('M d, Y g:i a', strtotime($l['created_at'])) ?></td>
                        <td>
                            <b><?= e($l['product_name']) ?></b><br>
                            <span class="muted small"><?= e($l['sku']) ?></span>
                        </td>
                        <td>
                            <span class="status" style="padding:2px 8px; font-weight:bold; font-size:11px; background: <?= $l['change_type'] === 'add' ? '#e8f8f5; color:#27ae60;' : ($l['change_type'] === 'subtract' ? '#fde8e8; color:#e74c3c;' : '#eaf2f8; color:#2980b9;') ?>">
                                <?= strtoupper(e($l['change_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <b><?= $l['change_type'] === 'add' ? '+' : ($l['change_type'] === 'subtract' ? '-' : '') ?><?= (int)$l['quantity'] ?></b>
                        </td>
                        <td><?= (int)$l['old_stock'] ?> → <b><?= (int)$l['new_stock'] ?></b></td>
                        <td><?= e($l['note'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:20px;">No stock logs found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>