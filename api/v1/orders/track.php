<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'orders:read' scope
$apiKey = authenticate_api_request('orders:read');

$orderId     = trim((string)($_GET['order_id'] ?? ''));
$orderNumber = trim((string)($_GET['order_number'] ?? ''));
$phone       = trim((string)($_GET['phone'] ?? ''));

if ($orderId === '' && $orderNumber === '') {
    api_response(400, [
        'status' => 'error',
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
            o.payment_method, 
            o.status, 
            o.tracking_number, 
            o.total, 
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
            'status' => 'error',
            'message' => 'Order not found.'
        ]);
    }

    // Fetch line items for tracking response
    $itemsStmt = $db->prepare('
        SELECT product_id, product_name, sku, quantity, unit_price, line_total 
        FROM order_items 
        WHERE order_id = ?
    ');
    $itemsStmt->execute([$order['id']]);
    $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    api_response(200, [
        'status' => 'success',
        'data' => $order
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
