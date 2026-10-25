<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'auth:login' scope or wildcard access
authenticate_api_request('auth:login');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Valid email and password are required.'
    ]);
}

try {
    $db = db();

    // Normalized query joining user account with default delivery address & shipping city details
    $stmt = $db->prepare('
        SELECT 
            u.id, 
            u.name, 
            u.email, 
            u.password_hash, 
            COALESCE(ca.phone, u.phone) AS phone, 
            COALESCE(ca.street_address, u.address) AS address, 
            COALESCE(sc.city_name, u.city) AS city, 
            ca.city_id,
            sc.zone_code,
            sc.shipping_cost,
            u.status 
        FROM users u
        LEFT JOIN customer_addresses ca ON ca.user_id = u.id AND ca.is_default = 1
        LEFT JOIN shipping_cities sc ON sc.id = ca.city_id
        WHERE u.email = ? 
        LIMIT 1
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        api_response(401, [
            'status'  => 'error',
            'message' => 'Invalid email or password.'
        ]);
    }

    if (($user['status'] ?? 'active') === 'blocked') {
        api_response(403, [
            'status'  => 'error',
            'message' => 'Account is blocked. Please contact store support.'
        ]);
    }

    // Generate API bearer/session token
    $token = bin2hex(random_bytes(32));

    api_response(200, [
        'status'  => 'success',
        'message' => 'Authentication successful.',
        'token'   => $token,
        'user'    => [
            'id'            => (int)$user['id'],
            'name'          => $user['name'],
            'email'         => $user['email'],
            'role'          => 'customer',
            'phone'         => $user['phone'] ?? '',
            'address'       => $user['address'] ?? '',
            'city'          => $user['city'] ?? '',
            'city_id'       => $user['city_id'] ? (int)$user['city_id'] : null,
            'zone_code'     => $user['zone_code'] ?? null,
            'shipping_cost' => $user['shipping_cost'] !== null ? (float)$user['shipping_cost'] : null,
            'status'        => $user['status'] ?? 'active'
        ]
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Internal authentication error: ' . $e->getMessage()
    ]);
}
