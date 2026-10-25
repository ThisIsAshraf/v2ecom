<?php
require_once __DIR__ . '/../middleware.php';

// Accept inventory:write, products:write, admin, or wildcard scopes
$allowedScopes = ['inventory:write', 'products:write', 'admin', '*'];
$authed = false;

foreach ($allowedScopes as $scope) {
    try {
        authenticate_api_request($scope);
        $authed = true;
        break;
    } catch (Throwable $e) {
        // Fall through
    }
}

if (!$authed) {
    authenticate_api_request('inventory:write');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Wrap single adjustment into a batch list if necessary
$adjustments = isset($input['adjustments']) && is_array($input['adjustments'])
    ? $input['adjustments']
    : [$input];

if (empty($adjustments) || !isset($adjustments[0])) {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: No adjustment items provided in payload.'
    ]);
}

try {
    $db = db();
    $db->beginTransaction();

    $results = [];
    $affectedProductIds = [];

    // Prepared statements for lookups
    $bySkuStmt       = $db->prepare('SELECT id, product_id, color, size, stock, sku FROM product_variants WHERE sku = ? LIMIT 1');
    $byIdStmt        = $db->prepare('SELECT id, product_id, color, size, stock, sku FROM product_variants WHERE id = ? LIMIT 1');
    $byColorSizeStmt = $db->prepare('
        SELECT id, product_id, color, size, stock, sku 
        FROM product_variants 
        WHERE product_id = ? 
          AND (color = ? OR (color IS NULL AND ? = "")) 
          AND (size = ? OR (size IS NULL AND ? = "")) 
        LIMIT 1
    ');
    $updateStmt = $db->prepare('UPDATE product_variants SET stock = ? WHERE id = ?');
    $logStmt    = $db->prepare('INSERT INTO stock_logs (product_id, admin_id, change_type, quantity, old_stock, new_stock, note) VALUES (?, NULL, ?, ?, ?, ?, ?)');

    foreach ($adjustments as $idx => $adj) {
        $sku        = trim((string)($adj['sku'] ?? ''));
        $variantId  = (int)($adj['variant_id'] ?? 0);
        $productId  = (int)($adj['product_id'] ?? 0);
        $color      = trim((string)($adj['color'] ?? ''));
        $size       = trim((string)($adj['size'] ?? ''));
        $mode       = strtolower(trim((string)($adj['mode'] ?? 'set'))); // 'set', 'increment', or 'decrement'
        $quantity   = (int)($adj['quantity'] ?? $adj['stock'] ?? 0);
        $reasonNote = trim((string)($adj['note'] ?? 'API Stock Adjustment'));

        if (!in_array($mode, ['set', 'increment', 'decrement'], true)) {
            $db->rollBack();
            api_response(422, [
                'status'  => 'error',
                'message' => "Invalid adjustment mode '{$mode}' at index {$idx}. Allowed: set, increment, decrement."
            ]);
        }

        // Find variant
        $variant = null;
        if ($sku !== '') {
            $bySkuStmt->execute([$sku]);
            $variant = $bySkuStmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($variantId > 0) {
            $byIdStmt->execute([$variantId]);
            $variant = $byIdStmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($productId > 0 && ($color !== '' || $size !== '')) {
            $byColorSizeStmt->execute([$productId, $color, $color, $size, $size]);
            $variant = $byColorSizeStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$variant) {
            $db->rollBack();
            api_response(404, [
                'status'  => 'error',
                'message' => "Variant target not found at index {$idx} (SKU: '{$sku}', Variant ID: {$variantId}, Product ID: {$productId}, Color: '{$color}', Size: '{$size}')."
            ]);
        }

        $oldStock = (int)$variant['stock'];
        $newStock = $oldStock;

        if ($mode === 'set') {
            $newStock = max(0, $quantity);
        } elseif ($mode === 'increment') {
            $newStock = $oldStock + max(0, $quantity);
        } elseif ($mode === 'decrement') {
            $newStock = max(0, $oldStock - max(0, $quantity));
        }

        $delta = $newStock - $oldStock;

        // Update variant stock
        $updateStmt->execute([$newStock, $variant['id']]);

        // Audit Trail into stock_logs
        $optParts = [];
        if (!empty($variant['color'])) $optParts[] = 'Color: ' . $variant['color'];
        if (!empty($variant['size']))  $optParts[] = 'Size: ' . $variant['size'];
        $vTag = $optParts ? '[' . implode(', ', $optParts) . '] ' : '';
        $logChangeType = $mode === 'increment' ? 'add' : ($mode === 'decrement' ? 'subtract' : 'set');

        $logStmt->execute([
            $variant['product_id'],
            $logChangeType,
            abs($delta),
            $oldStock,
            $newStock,
            $vTag . $reasonNote
        ]);

        $affectedProductIds[(int)$variant['product_id']] = true;

        $results[] = [
            'variant_id' => (int)$variant['id'],
            'sku'        => $variant['sku'],
            'color'      => $variant['color'],
            'size'       => $variant['size'],
            'mode'       => $mode,
            'old_stock'  => $oldStock,
            'new_stock'  => $newStock,
            'delta'      => $delta
        ];
    }

    // Synchronize aggregate product stock for all touched products
    $syncProdStmt = $db->prepare('
        UPDATE products 
        SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) 
        WHERE id = ?
    ');

    foreach (array_keys($affectedProductIds) as $pId) {
        $syncProdStmt->execute([$pId, $pId]);
    }

    $db->commit();

    api_response(200, [
        'status'  => 'success',
        'message' => 'Inventory adjusted successfully.',
        'count'   => count($results),
        'data'    => $results
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error updating inventory: ' . $e->getMessage()
    ]);
}
