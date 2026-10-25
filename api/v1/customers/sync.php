<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'customers:read' scope
authenticate_api_request('customers:read');

$email = trim((string)($_GET['email'] ?? ''));
$phone = trim((string)($_GET['phone'] ?? ''));
$id    = (int)($_GET['id'] ?? 0);

if ($email === '' && $phone === '' && $id <= 0) {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Provide id, email, or phone query parameter.'
    ]);
}

try {
    $db = db();
    $where  = [];
    $params = [];

    if ($id > 0) {
        $where[]  = 'u.id = ?';
        $params[] = $id;
    } elseif ($email !== '') {
        $where[]  = 'u.email = ?';
        $params[] = $email;
    } elseif ($phone !== '') {
        $where[]  = 'u.phone = ?';
        $params[] = $phone;
    }

    // Normalized query joining users with default saved address and city zone
    $stmt = $db->prepare('
        SELECT 
            u.id, 
            u.name, 
            u.email, 
            COALESCE(ca.phone, u.phone) AS phone, 
            COALESCE(ca.street_address, u.address) AS address, 
            COALESCE(sc.city_name, u.city) AS city, 
            sc.zone_code,
            u.status, 
            u.created_at 
        FROM users u
        LEFT JOIN customer_addresses ca ON ca.user_id = u.id AND ca.is_default = 1
        LEFT JOIN shipping_cities sc ON sc.id = ca.city_id
        WHERE ' . implode(' AND ', $where) . ' 
        LIMIT 1
    ');
    $stmt->execute($params);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        api_response(404, [
            'status'  => 'error',
            'message' => 'Customer profile not found.'
        ]);
    }

    // Explicit type casting
    $customer['id'] = (int)$customer['id'];

    // Lifetime Order Metrics & Summary
    $metricsStmt = $db->prepare('
        SELECT 
            COUNT(id) AS total_orders,
            COALESCE(SUM(total), 0) AS total_spent,
            MAX(created_at) AS last_order_at
        FROM orders 
        WHERE user_id = ? AND status != "cancelled"
    ');
    $metricsStmt->execute([$customer['id']]);
    $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

    $customer['metrics'] = [
        'total_orders'  => (int)($metrics['total_orders'] ?? 0),
        'total_spent'   => (float)($metrics['total_spent'] ?? 0.0),
        'last_order_at' => $metrics['last_order_at'] ?? null,
    ];

    // Fetch all saved customer delivery addresses
    $addrStmt = $db->prepare('
        SELECT 
            ca.id AS address_id,
            ca.full_name,
            ca.phone,
            ca.street_address,
            sc.city_name AS city,
            sc.zone_code,
            ca.is_default
        FROM customer_addresses ca
        LEFT JOIN shipping_cities sc ON sc.id = ca.city_id
        WHERE ca.user_id = ?
        ORDER BY ca.is_default DESC, ca.id DESC
    ');
    $addrStmt->execute([$customer['id']]);
    $savedAddrs = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($savedAddrs as &$sa) {
        $sa['address_id'] = (int)$sa['address_id'];
        $sa['is_default'] = (bool)$sa['is_default'];
    }
    unset($sa);
    $customer['saved_addresses'] = $savedAddrs;

    // Optional: Return last 5 recent orders including sizes
    $recentOrdersStmt = $db->prepare('
        SELECT 
            id, 
            order_number, 
            status, 
            total, 
            tracking_number, 
            created_at 
        FROM orders 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 5
    ');
    $recentOrdersStmt->execute([$customer['id']]);
    $recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($recentOrders) {
        $recentOrderIds = array_column($recentOrders, 'id');
        $inMarks = implode(',', array_fill(0, count($recentOrderIds), '?'));

        $itemsStmt = $db->prepare("
            SELECT order_id, product_name, size, quantity, unit_price, line_total 
            FROM order_items 
            WHERE order_id IN ($inMarks)
        ");
        $itemsStmt->execute($recentOrderIds);

        $itemsMap = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $iRow) {
            $itemsMap[$iRow['order_id']][] = [
                'name'       => $iRow['product_name'],
                'size'       => !empty($iRow['size']) ? $iRow['size'] : null,
                'quantity'   => (int)$iRow['quantity'],
                'unit_price' => (float)$iRow['unit_price'],
                'line_total' => (float)$iRow['line_total'],
            ];
        }

        foreach ($recentOrders as &$ro) {
            $ro['id']    = (int)$ro['id'];
            $ro['total'] = (float)$ro['total'];
            $ro['items'] = $itemsMap[$ro['id']] ?? [];
        }
        unset($ro);
    }
    $customer['recent_orders'] = $recentOrders;

    api_response(200, [
        'status' => 'success',
        'data'   => $customer
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
