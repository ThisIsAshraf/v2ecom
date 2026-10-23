<?php
header('Content-Type: application/json; charset=UTF-8');

// Enable CORS for third-party client applications
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load application bootstrap helper
require_once __DIR__ . '/../../app/bootstrap.php';

/**
 * Send JSON API response and terminate execution.
 * 
 * @return never
 */
function api_response(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Alias wrapper for api_response to support alternative endpoint calls
 */
if (!function_exists('send_api_response')) {
    function send_api_response(array $data, int $code = 200): void
    {
        api_response($code, $data);
    }
}

function authenticate_api_request(string $requiredScope = ''): array
{
    $headers = getallheaders();
    $providedKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? '';

    if (!$providedKey && isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $providedKey = trim($matches[1]);
        }
    }

    if (!$providedKey) {
        api_response(401, [
            'status' => 'error',
            'message' => 'Unauthorized: Missing API Key in Authorization or X-API-KEY header.'
        ]);
    }

    try {
        $db = db();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE api_key = ? LIMIT 1');
        $stmt->execute([$providedKey]);
        $keyRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyRecord || $keyRecord['status'] !== 'active') {
            api_response(401, [
                'status' => 'error',
                'message' => 'Unauthorized: Invalid or revoked API Key.'
            ]);
        }

        // Rate Limit Enforcement
        $rateLimit = (int)($keyRecord['rate_limit'] ?? 1000);
        $reqCount  = (int)($keyRecord['requests_count'] ?? 0);
        if ($rateLimit > 0 && $reqCount >= $rateLimit) {
            api_response(429, [
                'status' => 'error',
                'message' => 'Too Many Requests: Daily API request rate limit reached.'
            ]);
        }

        // Scope check
        if ($requiredScope !== '') {
            $grantedScopes = json_decode($keyRecord['scopes'] ?? '[]', true) ?: [];
            if (!in_array($requiredScope, $grantedScopes, true)) {
                api_response(403, [
                    'status' => 'error',
                    'message' => "Forbidden: API Key lacks the required scope '{$requiredScope}'."
                ]);
            }
        }

        // Update usage stats
        $db->prepare('UPDATE api_keys SET requests_count = requests_count + 1, last_used_at = NOW() WHERE id = ?')
            ->execute([$keyRecord['id']]);

        return $keyRecord;
    } catch (Throwable $e) {
        api_response(500, [
            'status' => 'error',
            'message' => 'Internal server error validating API Key.'
        ]);
    }
}
