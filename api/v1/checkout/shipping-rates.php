<?php
require_once __DIR__ . '/../middleware.php';

// Dynamically permit read, checkout, or master scopes
$allowedScopes = ['checkout:read', 'orders:read', 'orders:write', 'admin', '*'];
$authed = false;

foreach ($allowedScopes as $scope) {
    try {
        authenticate_api_request($scope);
        $authed = true;
        break;
    } catch (Throwable $e) {
        // Continue fallback checks
    }
}

if (!$authed) {
    authenticate_api_request('checkout:read');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use GET or POST method.'
    ]);
}

try {
    $db = db();

    // If city_id or city name query is supplied, calculate specific rate
    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?: $_POST)
        : $_GET;

    $cityId   = (int)($input['city_id'] ?? 0);
    $cityName = trim((string)($input['city'] ?? ''));

    if ($cityId > 0 || $cityName !== '') {
        if ($cityId > 0) {
            $stmt = $db->prepare('SELECT id, city_name, zone_code, shipping_cost, is_active FROM shipping_cities WHERE id = ? LIMIT 1');
            $stmt->execute([$cityId]);
        } else {
            $stmt = $db->prepare('SELECT id, city_name, zone_code, shipping_cost, is_active FROM shipping_cities WHERE LOWER(city_name) = LOWER(?) LIMIT 1');
            $stmt->execute([$cityName]);
        }

        $matchedCity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$matchedCity) {
            // Default outside-Dhaka fallback
            api_response(200, [
                'status' => 'success',
                'data'   => [
                    'city_id'       => null,
                    'city_name'     => $cityName !== '' ? $cityName : 'Other / Nationwide',
                    'zone_code'     => 'outside_dhaka',
                    'shipping_cost' => 120.00,
                    'is_fallback'   => true
                ]
            ]);
        }

        api_response(200, [
            'status' => 'success',
            'data'   => [
                'city_id'       => (int)$matchedCity['id'],
                'city_name'     => $matchedCity['city_name'],
                'zone_code'     => $matchedCity['zone_code'],
                'shipping_cost' => (float)$matchedCity['shipping_cost'],
                'is_fallback'   => false
            ]
        ]);
    }

    // If no specific city is requested, return the full list of shipping cities
    $listStmt = $db->query('SELECT id, city_name, zone_code, shipping_cost FROM shipping_cities ORDER BY city_name ASC');
    $cities = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($cities as $c) {
        $formatted[] = [
            'id'            => (int)$c['id'],
            'city_name'     => $c['city_name'],
            'zone_code'     => $c['zone_code'],
            'shipping_cost' => (float)$c['shipping_cost']
        ];
    }

    api_response(200, [
        'status' => 'success',
        'count'  => count($formatted),
        'data'   => $formatted
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error loading shipping rates: ' . $e->getMessage()
    ]);
}
