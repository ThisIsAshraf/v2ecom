<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('products');

// Handle Stock Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'adjust_stock') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $changeType = (string)($_POST['change_type'] ?? 'add');
        $qty = max(0, (int)($_POST['quantity'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));
        $adminId = $_SESSION['admin_id'] ?? null;

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $s = $pdo->prepare('SELECT id, name, sku, stock FROM products WHERE id = ? FOR UPDATE');
            $s->execute([$productId]);
            $product = $s->fetch();

            if (!$product) {
                $pdo->rollBack();
                flash('error', 'Product not found.');
                redirect('admin/catalog/stock-logs');
            }

            $variantInfo = null;
            $variantNotePrefix = '';

            // Handle Variant-specific stock adjustment
            if ($variantId > 0) {
                $vStmt = $pdo->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE');
                $vStmt->execute([$variantId, $productId]);
                $variantInfo = $vStmt->fetch(PDO::FETCH_ASSOC);

                if (!$variantInfo) {
                    $pdo->rollBack();
                    flash('error', 'Selected variant was not found for this product.');
                    redirect('admin/catalog/stock-logs');
                }

                $oldStock = (int)$variantInfo['stock'];
                $newStock = $oldStock;

                if ($changeType === 'add') {
                    $newStock = $oldStock + $qty;
                } elseif ($changeType === 'subtract') {
                    $newStock = max(0, $oldStock - $qty);
                } elseif ($changeType === 'set') {
                    $newStock = $qty;
                    $qty = abs($newStock - $oldStock);
                }

                // Update specific variant row
                $upVar = $pdo->prepare('UPDATE product_variants SET stock = ? WHERE id = ?');
                $upVar->execute([$newStock, $variantId]);

                // Recalculate aggregate product stock from all variants
                $sumStmt = $pdo->prepare('SELECT SUM(stock) FROM product_variants WHERE product_id = ?');
                $sumStmt->execute([$productId]);
                $totalNewStock = (int)$sumStmt->fetchColumn();

                $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$totalNewStock, $productId]);

                $optParts = [];
                if (!empty($variantInfo['color'])) $optParts[] = 'Color: ' . $variantInfo['color'];
                if (!empty($variantInfo['size']))  $optParts[] = 'Size: ' . $variantInfo['size'];
                $variantNotePrefix = '[' . implode(', ', $optParts) . '] ';
            } else {
                // Base aggregate stock adjustment (for non-variant products)
                $oldStock = (int)$product['stock'];
                $newStock = $oldStock;

                if ($changeType === 'add') {
                    $newStock = $oldStock + $qty;
                } elseif ($changeType === 'subtract') {
                    $newStock = max(0, $oldStock - $qty);
                } elseif ($changeType === 'set') {
                    $newStock = $qty;
                    $qty = abs($newStock - $oldStock);
                }

                $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$newStock, $productId]);
            }

            // Record log entry in stock_logs
            $finalNote = $variantNotePrefix . ($note ?: 'Manual adjustment');
            $log = $pdo->prepare('INSERT INTO stock_logs (product_id, admin_id, change_type, quantity, old_stock, new_stock, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $log->execute([$productId, $adminId, $changeType, $qty, $oldStock, $newStock, $finalNote]);

            $pdo->commit();

            // Central Audit Logging
            $actionLabel = strtoupper($changeType);
            log_admin_activity(
                'products',
                'adjust_stock',
                "Adjusted inventory for '{$product['name']}' ({$variantNotePrefix}SKU: {$product['sku']}): {$actionLabel} ({$oldStock} → {$newStock}). Reason: '{$finalNote}'"
            );

            flash('success', 'Stock updated for ' . $product['name'] . ' ' . $variantNotePrefix . '(' . $oldStock . ' → ' . $newStock . ').');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            flash('error', 'Could not update inventory stock: ' . $e->getMessage());
        }

        redirect('admin/catalog/stock-logs');
    }
}

// Fetch Products and their active variants for interactive UI mapping
$productsList = db()->query('SELECT id, name, sku, stock FROM products ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$variantsRaw = db()->query('SELECT id, product_id, color, size, stock, sku FROM product_variants ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$variantsByProduct = [];
foreach ($variantsRaw as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

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
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <select name="product_id" id="productSelectInput" required onchange="updateVariantDropdown()">
                    <option value="">-- Choose Item --</option>
                    <?php foreach ($productsList as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProductId === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (SKU: <?= e($p['sku']) ?> | Total Stock: <?= (int)$p['stock'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Variant Dropdown (Color + Size) -->
            <div class="field" id="variantSelectField">
                <label>Target Variant (Color / Size)</label>
                <select name="variant_id" id="variantSelectInput">
                    <option value="0">All Variants / Entire Product Base</option>
                </select>
                <small class="muted" style="display:block; margin-top:2px;">Select specific Color/Size to adjust that variant directly.</small>
            </div>

            <div class="field">
                <label>Action Type *</label>
                <select name="change_type" required>
                    <option value="add">➕ Add Stock (Restock)</option>
                    <option value="subtract">➖ Deduct Stock (Damaged / Return)</option>
                    <option value="set">🎯 Set Exact Stock Level</option>
                </select>
            </div>

            <div class="field">
                <label>Quantity *</label>
                <input type="number" min="0" name="quantity" required placeholder="e.g. 10">
            </div>

            <div class="field" style="grid-column: span 2;">
                <label>Note / Reason (Optional)</label>
                <input name="note" placeholder="e.g. Warehouse restock batch #821, Customer return">
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
                    <th>Variant & Note</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Stock (Before → After)</th>
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
                            <?php
                            $rawNote = (string)($l['note'] ?? '');
                            // Highlight bracketed variants e.g. [Color: Red, Size: M]
                            if (preg_match('/^(\[[^\]]+\])\s*(.*)$/', $rawNote, $m)) {
                                echo '<span style="display:inline-block; background:#e0e7ff; color:#3730a3; font-size:11px; font-weight:700; padding:2px 6px; border-radius:4px; margin-right:4px;">' . e($m[1]) . '</span> ';
                                echo e($m[2] ?: '—');
                            } else {
                                echo e($rawNote ?: '—');
                            }
                            ?>
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
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:20px;" class="muted">No stock logs found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const productVariantsMap = <?= json_encode($variantsByProduct, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function updateVariantDropdown() {
        const prodId = document.getElementById('productSelectInput').value;
        const varSelect = document.getElementById('variantSelectInput');
        varSelect.innerHTML = '<option value="0">All Variants / Entire Product Base</option>';

        if (prodId && productVariantsMap[prodId]) {
            const variants = productVariantsMap[prodId];
            variants.forEach(v => {
                const optParts = [];
                if (v.color) optParts.push('Color: ' + v.color);
                if (v.size) optParts.push('Size: ' + v.size);
                const optText = (optParts.join(', ') || 'Base Variant') + ' (SKU: ' + (v.sku || 'N/A') + ' | Stock: ' + v.stock + ')';

                const option = document.createElement('option');
                option.value = v.id;
                option.textContent = optText;
                varSelect.appendChild(option);
            });
        }
    }

    // Trigger on load if product is pre-selected
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('productSelectInput').value) {
            updateVariantDropdown();
        }
    });
</script>

<?php admin_footer(); ?>