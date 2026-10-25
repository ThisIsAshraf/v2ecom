<?php
require_once __DIR__ . '/../middleware.php';

// Dynamically permit auth:login, customers:write, or master scopes
$allowedScopes = ['auth:login', 'customers:write', 'admin', '*'];
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
    authenticate_api_request('auth:login');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$name     = trim((string)($input['name'] ?? ''));
$email    = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
$phone    = trim((string)($input['phone'] ?? ''));
$address  = trim((string)($input['address'] ?? ''));
$cityId   = (int)($input['city_id'] ?? 0);
$cityName = trim((string)($input['city'] ?? ''));

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: name, valid email, and a password (minimum 6 characters) are required.'
    ]);
}

try {
    $db = db();

    // Check existing email
    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        api_response(409, [
            'status'  => 'error',
            'message' => 'An account with this email address already exists.'
        ]);
    }

    $db->beginTransaction();

    // Insert user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $db->prepare('
        INSERT INTO users (name, email, password_hash, phone, address, city, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, "active", NOW())
    ');
    $ins->execute([
        $name,
        $email,
        $hash,
        $phone !== '' ? $phone : null,
        $address !== '' ? $address : null,
        $cityName !== '' ? $cityName : null
    ]);

    $userId = (int)$db->lastInsertId();

    // Create default saved address if address information is provided
    if ($address !== '' && $cityId > 0) {
        $addrStmt = $db->prepare('
            INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ');
        $addrStmt->execute([$userId, $name, $phone, $address, $cityId]);
    }

    $db->commit();

    $token = bin2hex(random_bytes(32));

    api_response(201, [
        'status'  => 'success',
        'message' => 'User registration successful.',
        'token'   => $token,
        'user'    => [
            'id'      => $userId,
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'address' => $address,
            'city'    => $cityName,
            'status'  => 'active'
        ]
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    api_response(500, [
        'status'  => 'error',
        'message' => 'Registration error: ' . $e->getMessage()
    ]);
}
