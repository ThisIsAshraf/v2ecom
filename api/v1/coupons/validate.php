<?php
require_once __DIR__ . '/../middleware.php';

// Dynamically permit orders:write, checkout, or master scopes
$allowedScopes = ['orders:write', 'checkout:read', 'admin', '*'];
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
    authenticate_api_request('orders:write');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(405, [
        'status'  => 'error',
        'message' => 'Method Not Allowed: Use POST method.'
    ]);
}

$input      = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$couponCode = strtoupper(trim((string)($input['code'] ?? '')));
$subtotal   = (float)($input['subtotal'] ?? 0.0);

if ($couponCode === '') {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Missing coupon code.'
    ]);
}

try {
    $db = db();

    $stmt = $db->prepare('
        SELECT * FROM coupons 
        WHERE UPPER(code) = ? 
          AND status = "active"
          AND (expires_at IS NULL OR expires_at >= NOW())
          AND (starts_at IS NULL OR starts_at <= NOW())
        LIMIT 1
    ');
    $stmt->execute([$couponCode]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        api_response(404, [
            'status'  => 'error',
            'message' => 'Coupon code is invalid, expired, or deactivated.'
        ]);
    }

    // Validate minimum cart requirement
    $minSpend = (float)($coupon['min_order_amount'] ?? 0.0);
    if ($subtotal > 0 && $subtotal < $minSpend) {
        api_response(422, [
            'status'  => 'error',
            'message' => "Order subtotal must be at least ৳" . number_format($minSpend, 2) . " to apply this coupon."
        ]);
    }

    // Check usage limits if configured
    if (isset($coupon['usage_limit']) && (int)$coupon['usage_limit'] > 0) {
        $usedCount = (int)($coupon['times_used'] ?? 0);
        if ($usedCount >= (int)$coupon['usage_limit']) {
            api_response(422, [
                'status'  => 'error',
                'message' => 'This coupon has reached its total redemption limit.'
            ]);
        }
    }

    // Calculate deduction
    $type     = $coupon['type'] ?? 'fixed'; // 'fixed' or 'percent'
    $value    = (float)$coupon['value'];
    $discount = 0.0;

    if ($type === 'percent') {
        $discount = ($subtotal * $value) / 100.0;
        if (isset($coupon['max_discount_amount']) && (float)$coupon['max_discount_amount'] > 0) {
            $discount = min($discount, (float)$coupon['max_discount_amount']);
        }
    } else {
        $discount = $value;
    }

    $discount = min($discount, $subtotal);

    api_response(200, [
        'status'  => 'success',
        'message' => 'Coupon applied successfully.',
        'data'    => [
            'code'            => $coupon['code'],
            'type'            => $type,
            'value'           => $value,
            'discount_amount' => round($discount, 2),
            'new_subtotal'    => round(max(0.0, $subtotal - $discount), 2)
        ]
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error validating coupon: ' . $e->getMessage()
    ]);
}
