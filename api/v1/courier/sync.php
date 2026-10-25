<?php
require_once __DIR__ . '/../middleware.php';

// Accept courier:sync, orders:write, or master wildcard scopes dynamically
$validScopes = ['courier:sync', 'orders:write', 'admin', '*'];

$apiKeyRecord = null;
foreach ($validScopes as $scope) {
    try {
        // Test scope without exiting immediately on error
        $headers = get_request_headers();
        $providedKey = $headers['x-api-key'] ?? $headers['x-webhook-secret'] ?? '';

        if (!$providedKey && isset($headers['authorization'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers['authorization'], $matches)) {
                $providedKey = trim($matches[1]);
            }
        }

        if (!$providedKey && (isset($_GET['api_key']) || isset($_GET['token']))) {
            $providedKey = trim((string)($_GET['api_key'] ?? $_GET['token']));
        }

        if ($providedKey) {
            $db = db();
            $stmt = $db->prepare('SELECT * FROM api_keys WHERE api_key = ? LIMIT 1');
            $stmt->execute([$providedKey]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($candidate && ($candidate['status'] ?? '') === 'active') {
                $scopes = json_decode($candidate['scopes'] ?? '[]', true) ?: [];
                if (in_array('*', $scopes, true) || in_array('admin', $scopes, true) || in_array('courier:sync', $scopes, true) || in_array('orders:write', $scopes, true)) {
                    $apiKeyRecord = $candidate;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // Fall through to standard auth check
    }
}

// If custom dynamic matching didn't resolve, perform standard middleware auth
if (!$apiKeyRecord) {
    authenticate_api_request('courier:sync');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true) ?: $_POST;

// Support various courier payload naming conventions
$orderId        = (int)($input['order_id'] ?? $input['consignment_id'] ?? 0);
$orderNumber    = trim((string)($input['order_number'] ?? $input['invoice'] ?? $input['merchant_order_id'] ?? ''));
$trackingNumber = trim((string)($input['tracking_number'] ?? $input['tracking_code'] ?? $input['consignment_id'] ?? ''));
$rawStatus      = strtolower(trim((string)($input['status'] ?? $input['order_status'] ?? '')));
$courierName    = trim((string)($input['courier_name'] ?? $input['courier'] ?? 'Courier Service'));
$courierNote    = trim((string)($input['note'] ?? $input['reason'] ?? $input['comments'] ?? ''));

if (($orderId <= 0 && $orderNumber === '') || ($trackingNumber === '' && $rawStatus === '')) {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Valid order identifier (order_id or order_number) and update payload (tracking_number or status) are required.'
    ]);
}

// Map common external third-party courier webhook statuses to internal states
$statusMapping = [
    'pending'              => 'pending',
    'in_review'            => 'pending',
    'hold'                 => 'pending',
    'processing'           => 'processing',
    'accepted'             => 'processing',
    'ready_to_ship'        => 'processing',
    'shipped'              => 'shipped',
    'in_transit'           => 'shipped',
    'out_for_delivery'     => 'shipped',
    'delivered'            => 'delivered',
    'successful'           => 'delivered',
    'completed'            => 'delivered',
    'cancelled'            => 'cancelled',
    'cancelled_by_customer' => 'cancelled',
    'cancelled_by_merchant' => 'cancelled',
    'returned'             => 'returned',
    'return'               => 'returned',
    'partial_delivered'    => 'returned',
    'lost'                 => 'cancelled',
    'failed'               => 'returned'
];

$normalizedStatus = '';
if ($rawStatus !== '') {
    $normalizedStatus = $statusMapping[$rawStatus] ?? $rawStatus;
    $validStatuses    = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    if (!in_array($normalizedStatus, $validStatuses, true)) {
        api_response(422, [
            'status'  => 'error',
            'message' => "Unprocessable Entity: Unrecognized status '{$rawStatus}'. Allowed: " . implode(', ', $validStatuses)
        ]);
    }
}

try {
    $db = db();

    // Locate order
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
            'status'  => 'error',
            'message' => 'Order not found in store database.'
        ]);
    }

    $db->beginTransaction();

    $updates = [];
    $params  = [];

    if ($trackingNumber !== '') {
        $updates[] = 'tracking_number = ?';
        $params[]  = $trackingNumber;
    }

    if ($normalizedStatus !== '') {
        $updates[] = 'status = ?';
        $params[]  = $normalizedStatus;
    }

    $params[] = $order['id'];

    if (!empty($updates)) {
        $sql = 'UPDATE orders SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->prepare($sql)->execute($params);
    }

    // Automated Stock Restock on Order Cancellation or Return
    $isNowCancelledOrReturned = in_array($normalizedStatus, ['cancelled', 'returned'], true);
    $wasActiveState           = !in_array($order['status'], ['cancelled', 'returned'], true);

    if ($isNowCancelledOrReturned && $wasActiveState) {
        $itemStmt = $db->prepare('SELECT product_id, size, quantity FROM order_items WHERE order_id = ?');
        $itemStmt->execute([$order['id']]);
        $orderLines = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $upProductStock = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        $upVariantStock = $db->prepare('UPDATE product_variants SET stock = stock + ? WHERE product_id = ? AND size = ?');

        foreach ($orderLines as $ol) {
            $qty = (int)$ol['quantity'];
            $pId = (int)$ol['product_id'];
            $sz  = trim((string)($ol['size'] ?? ''));

            if ($sz !== '') {
                $upVariantStock->execute([$qty, $pId, $sz]);
            }
            $upProductStock->execute([$qty, $pId]);
        }
    }

    // Record Courier Audit Log Entry
    if ($normalizedStatus !== '' && $normalizedStatus !== $order['status']) {
        $auditNote = 'Courier Status Sync: ' . ucfirst($normalizedStatus);
        if ($courierName !== '') {
            $auditNote .= ' via ' . $courierName;
        }
        if ($trackingNumber !== '') {
            $auditNote .= ' (Tracking #: ' . $trackingNumber . ')';
        }
        if ($courierNote !== '') {
            $auditNote .= ' — ' . $courierNote;
        }
        if ($isNowCancelledOrReturned && $wasActiveState) {
            $auditNote .= ' [Inventory Restocked]';
        }

        $logStmt = $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)');
        $logStmt->execute([$order['id'], $normalizedStatus, $auditNote]);
    }

    $db->commit();

    api_response(200, [
        'status'  => 'success',
        'message' => 'Courier status and consignment details synchronized successfully.',
        'data'    => [
            'order_id'        => (int)$order['id'],
            'order_number'    => $order['order_number'],
            'tracking_number' => $trackingNumber !== '' ? $trackingNumber : ($order['tracking_number'] ?? ''),
            'status'          => $normalizedStatus !== '' ? $normalizedStatus : $order['status'],
            'shipping_zone'   => $order['zone_code'] ?? 'outside_dhaka',
            'restocked'       => ($isNowCancelledOrReturned && $wasActiveState)
        ]
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
