<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'orders:read' scope
$apiKey = authenticate_api_request('orders:read');

$orderId     = trim((string)($_GET['order_id'] ?? ''));
$orderNumber = trim((string)($_GET['order_number'] ?? ''));
$phone       = trim((string)($_GET['phone'] ?? ''));

if ($orderId === '' && $orderNumber === '') {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Please provide either order_id or order_number query parameter.'
    ]);
}

try {
    $db = db();
    $where  = [];
    $params = [];

    if ($orderId !== '') {
        $where[]  = 'o.id = ?';
        $params[] = $orderId;
    } elseif ($orderNumber !== '') {
        $where[]  = 'o.order_number = ?';
        $params[] = $orderNumber;
    }

    if ($phone !== '') {
        $where[]  = 'o.customer_phone = ?';
        $params[] = $phone;
    }

    // Normalized SQL query with LEFT JOINs on shipping_cities and customer_addresses
    $sql = '
        SELECT 
            o.id, 
            o.order_number, 
            o.customer_name, 
            o.customer_email, 
            o.customer_phone, 
            o.address, 
            COALESCE(sc.city_name, o.city) AS city, 
            sc.zone_code,
            o.notes,
            o.payment_method, 
            o.status, 
            o.tracking_number, 
            o.subtotal,
            o.discount_amount,
            o.shipping_amount,
            o.total, 
            o.coupon_code, 
            o.created_at 
        FROM orders o
        LEFT JOIN shipping_cities sc ON sc.id = o.city_id
        LEFT JOIN customer_addresses ca ON ca.id = o.shipping_address_id
        WHERE ' . implode(' AND ', $where) . ' 
        LIMIT 1
    ';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        api_response(404, [
            'status'  => 'error',
            'message' => 'Order not found.'
        ]);
    }

    // Numeric casting for order totals
    $order['id']              = (int)$order['id'];
    $order['subtotal']        = (float)$order['subtotal'];
    $order['discount_amount'] = (float)$order['discount_amount'];
    $order['shipping_amount'] = (float)$order['shipping_amount'];
    $order['total']           = (float)$order['total'];

    // Fetch line items including color, size, and primary cover thumbnail
    $itemsStmt = $db->prepare('
        SELECT 
            oi.id,
            oi.product_id, 
            oi.product_name, 
            oi.color,
            oi.size,
            oi.sku, 
            oi.quantity, 
            oi.unit_price, 
            oi.line_total,
            COALESCE(pi.image_url, p.image_url) AS image_url
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_images pi ON pi.product_id = oi.product_id AND pi.is_primary = 1
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ');
    $itemsStmt->execute([$order['id']]);
    $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedItems = [];
    foreach ($rawItems as $item) {
        $img = $item['image_url'];
        $formattedItems[] = [
            'id'           => (int)$item['id'],
            'product_id'   => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'color'        => !empty($item['color']) ? $item['color'] : null,
            'size'         => !empty($item['size']) ? $item['size'] : null,
            'sku'          => $item['sku'],
            'quantity'     => (int)$item['quantity'],
            'unit_price'   => (float)$item['unit_price'],
            'line_total'   => (float)$item['line_total'],
            'image_url'    => !empty($img) ? (str_starts_with($img, 'http') ? $img : url($img)) : null,
        ];
    }
    $order['items'] = $formattedItems;

    // Fetch chronological tracking status history
    $historyStmt = $db->prepare('
        SELECT status, note, created_at 
        FROM order_status_history 
        WHERE order_id = ? 
        ORDER BY id ASC
    ');
    $historyStmt->execute([$order['id']]);
    $order['status_history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    api_response(200, [
        'status' => 'success',
        'data'   => $order
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
