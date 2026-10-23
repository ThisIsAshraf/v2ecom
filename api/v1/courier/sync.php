<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'courier:sync' scope
authenticate_api_request('courier:sync');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status' => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$orderId        = (int)($input['order_id'] ?? 0);
$orderNumber    = trim((string)($input['order_number'] ?? ''));
$trackingNumber = trim((string)($input['tracking_number'] ?? ''));
$status         = trim((string)($input['status'] ?? ''));

if (($orderId <= 0 && $orderNumber === '') || ($trackingNumber === '' && $status === '')) {
    api_response(400, [
        'status' => 'error',
        'message' => 'Bad Request: Valid order_id (or order_number) and either tracking_number or status are required in JSON body.'
    ]);
}

try {
    $db = db();

    // Verify order exists using normalized city join
    if ($orderId > 0) {
        $check = $db->prepare('
            SELECT o.id, o.order_number, o.status, o.tracking_number, sc.zone_code, sc.city_name
            FROM orders o
            LEFT JOIN shipping_cities sc ON sc.id = o.city_id
            WHERE o.id = ? LIMIT 1
        ');
        $check->execute([$orderId]);
    } else {
        $check = $db->prepare('
            SELECT o.id, o.order_number, o.status, o.tracking_number, sc.zone_code, sc.city_name
            FROM orders o
            LEFT JOIN shipping_cities sc ON sc.id = o.city_id
            WHERE o.order_number = ? LIMIT 1
        ');
        $check->execute([$orderNumber]);
    }

    $order = $check->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        api_response(404, [
            'status' => 'error',
            'message' => 'Order not found in database.'
        ]);
    }

    $updates = [];
    $params  = [];

    if ($trackingNumber !== '') {
        $updates[] = 'tracking_number = ?';
        $params[]  = $trackingNumber;
    }

    if ($status !== '') {
        $updates[] = 'status = ?';
        $params[]  = $status;
    }

    $params[] = $order['id'];

    $sql = 'UPDATE orders SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);

    // Record Courier Audit Log Entry if status changed
    if ($status !== '' && $status !== $order['status']) {
        $auditNote = 'Courier Status Sync: ' . ucfirst($status);
        if ($trackingNumber !== '') {
            $auditNote .= ' (Tracking #: ' . $trackingNumber . ')';
        }
        $logStmt = $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)');
        $logStmt->execute([$order['id'], $status, $auditNote]);
    }

    api_response(200, [
        'status' => 'success',
        'message' => 'Courier status and tracking details updated successfully.',
        'data' => [
            'order_id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'tracking_number' => $trackingNumber !== '' ? $trackingNumber : ($order['tracking_number'] ?? ''),
            'status' => $status !== '' ? $status : $order['status'],
            'shipping_zone' => $order['zone_code'] ?? 'outside_dhaka'
        ]
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
