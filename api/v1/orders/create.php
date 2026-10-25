<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'orders:write' scope or master wildcard access
authenticate_api_request('orders:write');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Extract Customer & Order Parameters
$customerName    = trim((string)($input['customer_name'] ?? ''));
$customerEmail   = trim((string)($input['customer_email'] ?? ''));
$customerPhone   = trim((string)($input['customer_phone'] ?? ''));
$address         = trim((string)($input['address'] ?? ''));
$cityId          = (int)($input['city_id'] ?? 0);
$cityName        = trim((string)($input['city'] ?? ''));
$paymentMethod   = strtoupper(trim((string)($input['payment_method'] ?? 'COD')));
$notes           = trim((string)($input['notes'] ?? ''));
$couponCode      = trim((string)($input['coupon_code'] ?? ''));
$discountAmount  = max(0.0, (float)($input['discount_amount'] ?? 0.0));
$items           = $input['items'] ?? [];

// Basic Payload Validations
if ($customerName === '' || $customerPhone === '' || $address === '') {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: customer_name, customer_phone, and address are mandatory fields.'
    ]);
}

if (!is_array($items) || empty($items)) {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Order must contain at least one line item in items array.'
    ]);
}

try {
    $db = db();

    // 1. Resolve Shipping Details from shipping_cities
    $shippingCost = 120.00; // Standard outside-city fallback
    $resolvedCity = $cityName;
    $zoneCode     = 'outside_dhaka';

    if ($cityId > 0) {
        $cStmt = $db->prepare('SELECT id, city_name, zone_code, shipping_cost FROM shipping_cities WHERE id = ? LIMIT 1');
        $cStmt->execute([$cityId]);
        $cityRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cityRow) {
            $shippingCost = (float)$cityRow['shipping_cost'];
            $resolvedCity = $cityRow['city_name'];
            $zoneCode     = $cityRow['zone_code'];
        }
    } elseif ($cityName !== '') {
        $cStmt = $db->prepare('SELECT id, city_name, zone_code, shipping_cost FROM shipping_cities WHERE LOWER(city_name) = LOWER(?) LIMIT 1');
        $cStmt->execute([$cityName]);
        $cityRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cityRow) {
            $cityId       = (int)$cityRow['id'];
            $shippingCost = (float)$cityRow['shipping_cost'];
            $resolvedCity = $cityRow['city_name'];
            $zoneCode     = $cityRow['zone_code'];
        }
    }

    $db->beginTransaction();

    // 2. Validate Items & Check Real-Time Variant Inventory
    $processedItems = [];
    $subtotal       = 0.0;

    $prodCheck = $db->prepare('SELECT id, name, price, stock, sku FROM products WHERE id = ? LIMIT 1');
    $varCheck  = $db->prepare('SELECT id, sku, stock FROM product_variants WHERE product_id = ? AND size = ? LIMIT 1');

    foreach ($items as $idx => $line) {
        $productId = (int)($line['product_id'] ?? 0);
        $size      = trim((string)($line['size'] ?? ''));
        $quantity  = (int)($line['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $db->rollBack();
            api_response(422, [
                'status'  => 'error',
                'message' => "Invalid line item at index {$idx}: Valid product_id and quantity (> 0) required."
            ]);
        }

        // Check Product existence
        $prodCheck->execute([$productId]);
        $product = $prodCheck->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $db->rollBack();
            api_response(404, [
                'status'  => 'error',
                'message' => "Product with ID {$productId} was not found."
            ]);
        }

        $unitPrice = (float)$product['price'];
        $itemSku   = $product['sku'] ?? ('PROD-' . $product['id']);
        $variantId = null;

        // If size is specified, check against product_variants
        if ($size !== '') {
            $varCheck->execute([$productId, $size]);
            $variant = $varCheck->fetch(PDO::FETCH_ASSOC);

            if (!$variant) {
                $db->rollBack();
                api_response(422, [
                    'status'  => 'error',
                    'message' => "Size '{$size}' is unavailable for product '{$product['name']}'."
                ]);
            }

            if ((int)$variant['stock'] < $quantity) {
                $db->rollBack();
                api_response(409, [
                    'status'  => 'error',
                    'message' => "Insufficient variant stock for '{$product['name']}' (Size: {$size}). Available: {$variant['stock']}, Requested: {$quantity}."
                ]);
            }

            $variantId = (int)$variant['id'];
            if (!empty($variant['sku'])) {
                $itemSku = $variant['sku'];
            }
        } else {
            // No size specified; check base product stock
            if ((int)$product['stock'] < $quantity) {
                $db->rollBack();
                api_response(409, [
                    'status'  => 'error',
                    'message' => "Insufficient aggregate stock for '{$product['name']}'. Available: {$product['stock']}, Requested: {$quantity}."
                ]);
            }
        }

        $lineTotal  = $unitPrice * $quantity;
        $subtotal  += $lineTotal;

        $processedItems[] = [
            'product_id'   => $productId,
            'variant_id'   => $variantId,
            'product_name' => $product['name'],
            'size'         => $size !== '' ? $size : null,
            'sku'          => $itemSku,
            'quantity'     => $quantity,
            'unit_price'   => $unitPrice,
            'line_total'   => $lineTotal,
        ];
    }

    // 3. Financial Computations
    $grandTotal = max(0.0, ($subtotal - $discountAmount) + $shippingCost);

    // 4. Generate Unique Order & Tracking Identifiers
    $orderNumber    = 'DVBD' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
    $trackingNumber = 'TRK-' . strtoupper(bin2hex(random_bytes(4)));

    // 5. Insert Orders Record
    $orderInsert = $db->prepare('
        INSERT INTO orders (
            order_number, 
            customer_name, 
            customer_email, 
            customer_phone, 
            address, 
            city_id, 
            city, 
            payment_method, 
            status, 
            tracking_number, 
            subtotal, 
            discount_amount, 
            shipping_amount, 
            total, 
            coupon_code, 
            notes, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');

    $orderInsert->execute([
        $orderNumber,
        $customerName,
        $customerEmail !== '' ? $customerEmail : null,
        $customerPhone,
        $address,
        $cityId > 0 ? $cityId : null,
        $resolvedCity,
        $paymentMethod,
        'pending',
        $trackingNumber,
        $subtotal,
        $discountAmount,
        $shippingCost,
        $grandTotal,
        $couponCode !== '' ? $couponCode : null,
        $notes !== '' ? $notes : null
    ]);

    $newOrderId = (int)$db->lastInsertId();

    // 6. Insert Line Items and Deduct Inventory
    $itemInsert     = $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, size, sku, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $upVariantStock = $db->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?');
    $upProductStock = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');

    foreach ($processedItems as $item) {
        $itemInsert->execute([
            $newOrderId,
            $item['product_id'],
            $item['product_name'],
            $item['size'],
            $item['sku'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total']
        ]);

        // Atomic Variant Deduction
        if ($item['variant_id'] !== null) {
            $upVariantStock->execute([$item['quantity'], $item['variant_id']]);
        }
        // Atomic Base Deduction
        $upProductStock->execute([$item['quantity'], $item['product_id']]);
    }

    // 7. Initial Status Audit Log Entry
    $histStmt = $db->prepare('INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())');
    $histStmt->execute([$newOrderId, 'pending', 'Order placed via API. Inventory allocated.']);

    $db->commit();

    // Return complete order confirmation
    api_response(201, [
        'status'  => 'success',
        'message' => 'Order created successfully.',
        'data'    => [
            'order_id'        => $newOrderId,
            'order_number'    => $orderNumber,
            'tracking_number' => $trackingNumber,
            'status'          => 'pending',
            'subtotal'        => (float)$subtotal,
            'shipping_amount' => (float)$shippingCost,
            'discount_amount' => (float)$discountAmount,
            'total'           => (float)$grandTotal,
            'zone_code'       => $zoneCode,
            'items_count'     => count($processedItems),
            'items'           => $processedItems
        ]
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    api_response(500, [
        'status'  => 'error',
        'message' => 'Failed to create order: ' . $e->getMessage()
    ]);
}
