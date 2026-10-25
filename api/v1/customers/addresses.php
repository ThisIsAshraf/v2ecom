<?php
require_once __DIR__ . '/../middleware.php';

// Dynamically permit customers:read, customers:write, or master scopes
$allowedScopes = ['customers:read', 'customers:write', 'admin', '*'];
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
    authenticate_api_request('customers:read');
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = db();

    // 1. GET: Fetch all saved addresses for a user
    if ($method === 'GET') {
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            api_response(400, ['status' => 'error', 'message' => 'Bad Request: user_id query parameter is required.']);
        }

        $stmt = $db->prepare('
            SELECT 
                ca.id AS address_id,
                ca.full_name,
                ca.phone,
                ca.street_address,
                sc.city_name AS city,
                sc.zone_code,
                sc.shipping_cost,
                ca.is_default
            FROM customer_addresses ca
            LEFT JOIN shipping_cities sc ON sc.id = ca.city_id
            WHERE ca.user_id = ?
            ORDER BY ca.is_default DESC, ca.id DESC
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['address_id']    = (int)$r['address_id'];
            $r['is_default']    = (bool)$r['is_default'];
            $r['shipping_cost'] = $r['shipping_cost'] !== null ? (float)$r['shipping_cost'] : null;
        }
        unset($r);

        api_response(200, ['status' => 'success', 'data' => $rows]);
    }

    // 2. POST: Add a new delivery address
    if ($method === 'POST') {
        $input         = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userId        = (int)($input['user_id'] ?? 0);
        $fullName      = trim((string)($input['full_name'] ?? ''));
        $phone         = trim((string)($input['phone'] ?? ''));
        $streetAddress = trim((string)($input['street_address'] ?? ''));
        $cityId        = (int)($input['city_id'] ?? 0);
        $isDefault     = !empty($input['is_default']) ? 1 : 0;

        if ($userId <= 0 || $fullName === '' || $phone === '' || $streetAddress === '' || $cityId <= 0) {
            api_response(400, [
                'status'  => 'error',
                'message' => 'Bad Request: user_id, full_name, phone, street_address, and city_id are required.'
            ]);
        }

        $db->beginTransaction();

        // If this address is set as default, remove default flag from previous ones
        if ($isDefault === 1) {
            $db->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        }

        $ins = $db->prepare('
            INSERT INTO customer_addresses (user_id, full_name, phone, street_address, city_id, is_default, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $ins->execute([$userId, $fullName, $phone, $streetAddress, $cityId, $isDefault]);
        $addressId = (int)$db->lastInsertId();

        $db->commit();

        api_response(201, [
            'status'     => 'success',
            'message'    => 'Address added successfully.',
            'address_id' => $addressId
        ]);
    }

    // 3. DELETE: Delete an address
    if ($method === 'DELETE') {
        $input     = json_decode(file_get_contents('php://input'), true) ?: $_GET;
        $addressId = (int)($input['address_id'] ?? 0);
        $userId    = (int)($input['user_id'] ?? 0);

        if ($addressId <= 0 || $userId <= 0) {
            api_response(400, ['status' => 'error', 'message' => 'Bad Request: address_id and user_id are required.']);
        }

        $del = $db->prepare('DELETE FROM customer_addresses WHERE id = ? AND user_id = ?');
        $del->execute([$addressId, $userId]);

        api_response(200, ['status' => 'success', 'message' => 'Address deleted successfully.']);
    }

    api_response(405, ['status' => 'error', 'message' => 'Method Not Allowed']);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    api_response(500, ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
