<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'customers:read' scope
authenticate_api_request('customers:read');

$email = trim((string)($_GET['email'] ?? ''));
$phone = trim((string)($_GET['phone'] ?? ''));
$id    = (int)($_GET['id'] ?? 0);

if ($email === '' && $phone === '' && $id <= 0) {
    api_response(400, [
        'status' => 'error',
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
            'status' => 'error',
            'message' => 'Customer profile not found.'
        ]);
    }

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
    $addrStmt->execute([(int)$customer['id']]);
    $customer['saved_addresses'] = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

    api_response(200, [
        'status' => 'success',
        'data' => $customer
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
