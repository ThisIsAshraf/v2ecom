<?php
// Enforce JSON header globally
header('Content-Type: application/json; charset=UTF-8');

// Dynamic CORS for multi-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle CORS Preflight Pre-emptive response
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
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Alias wrapper for api_response
 */
if (!function_exists('send_api_response')) {
    function send_api_response(array $data, int $code = 200): void
    {
        api_response($code, $data);
    }
}

/**
 * Robust cross-server header extractor (supports Apache, Nginx, FastCGI, PHP-FPM)
 */
function get_request_headers(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $raw = getallheaders();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        }
    }

    // Inspect $_SERVER for Nginx / FastCGI / Apache environments
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headerKey = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($headers[$headerKey])) {
                $headers[$headerKey] = $value;
            }
        }
    }

    // Check direct Authorization headers often passed by proxy rewrites
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !isset($headers['authorization'])) {
        $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    return $headers;
}

/**
 * Authenticates API request against api_keys table with scope & rate-limit validation
 */
function authenticate_api_request(string $requiredScope = ''): array
{
    $headers = get_request_headers();
    $providedKey = $headers['x-api-key'] ?? '';

    if (!$providedKey && isset($headers['authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['authorization'], $matches)) {
            $providedKey = trim($matches[1]);
        }
    }

    // Fallback: Check query parameter ?api_key= for specific webhook callbacks
    if (!$providedKey && isset($_GET['api_key'])) {
        $providedKey = trim((string)$_GET['api_key']);
    }

    if (!$providedKey) {
        api_response(401, [
            'status'  => 'error',
            'message' => 'Unauthorized: Missing API Key in Authorization header or X-API-KEY header.'
        ]);
    }

    try {
        $db = db();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE api_key = ? LIMIT 1');
        $stmt->execute([$providedKey]);
        $keyRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyRecord || ($keyRecord['status'] ?? '') !== 'active') {
            api_response(401, [
                'status'  => 'error',
                'message' => 'Unauthorized: Invalid or revoked API Key.'
            ]);
        }

        // Daily Rate Limit Enforcement
        $rateLimit = (int)($keyRecord['rate_limit'] ?? 1000);
        $reqCount  = (int)($keyRecord['requests_count'] ?? 0);
        if ($rateLimit > 0 && $reqCount >= $rateLimit) {
            api_response(429, [
                'status'  => 'error',
                'message' => 'Too Many Requests: Daily API request rate limit reached (' . $rateLimit . ' requests/day).'
            ]);
        }

        // Scope verification with wildcard '*' support
        if ($requiredScope !== '') {
            $grantedScopes = json_decode($keyRecord['scopes'] ?? '[]', true) ?: [];
            $hasScope = in_array('*', $grantedScopes, true) ||
                in_array('admin', $grantedScopes, true) ||
                in_array($requiredScope, $grantedScopes, true);

            if (!$hasScope) {
                api_response(403, [
                    'status'  => 'error',
                    'message' => "Forbidden: API Key lacks the required scope '{$requiredScope}'."
                ]);
            }
        }

        // Increment request count and timestamp
        $db->prepare('UPDATE api_keys SET requests_count = requests_count + 1, last_used_at = NOW() WHERE id = ?')
            ->execute([$keyRecord['id']]);

        return $keyRecord;
    } catch (Throwable $e) {
        error_log('API Key Auth Exception: ' . $e->getMessage());
        api_response(500, [
            'status'  => 'error',
            'message' => 'Internal server error validating API Key credentials.'
        ]);
    }
}
