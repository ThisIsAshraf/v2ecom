<?php
// Enforce JSON header globally
header('Content-Type: application/json; charset=UTF-8');

// Dynamic CORS for multi-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Webhook-Secret, X-Requested-With');
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
 * Authenticates API request against api_keys table with:
 * - Plaintext API Key ID (`db_...`) matching
 * - Hashed Secret Token verification (`password_verify`)
 * - Multi-scope verification
 * - Automated 24-hour rate-limit rolling reset
 * 
 * @param string|array $requiredScopes A single scope or array of acceptable scopes
 * @return array The authenticated api_keys database row
 */
function authenticate_api_request(string|array $requiredScopes = ''): array
{
    $headers = get_request_headers();
    $providedKey = $headers['x-api-key'] ?? $headers['x-webhook-secret'] ?? '';

    if (!$providedKey && isset($headers['authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['authorization'], $matches)) {
            $providedKey = trim($matches[1]);
        }
    }

    // Fallback: Check query parameter ?api_key= or ?token= for external webhooks
    if (!$providedKey && (isset($_GET['api_key']) || isset($_GET['token']))) {
        $providedKey = trim((string)($_GET['api_key'] ?? $_GET['token']));
    }

    if (!$providedKey) {
        api_response(401, [
            'status'  => 'error',
            'message' => 'Unauthorized: Missing API Key in Authorization header, X-API-KEY header, or query parameters.'
        ]);
    }

    try {
        $db = db();
        $keyRecord = null;

        // 1. Direct match by API Key ID (e.g., "db_b397b0a1...")
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE api_key = ? AND status = "active" LIMIT 1');
        $stmt->execute([$providedKey]);
        $directMatch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($directMatch) {
            $keyRecord = $directMatch;
        } else {
            // 2. Check if client supplied the raw Secret Token (verified against password_hash)
            $tokenCandidates = $db->query('
                SELECT * FROM api_keys 
                WHERE status = "active" 
                  AND secret_token IS NOT NULL 
                  AND secret_token != ""
            ')->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tokenCandidates as $candidate) {
                if (password_verify($providedKey, $candidate['secret_token'])) {
                    $keyRecord = $candidate;
                    break;
                }
            }
        }

        if (!$keyRecord) {
            api_response(401, [
                'status'  => 'error',
                'message' => 'Unauthorized: Invalid or revoked API Key / Secret Token.'
            ]);
        }

        // Daily Rate Limit Enforcement with 24-hour Calendar Day Auto-Reset
        $rateLimit      = (int)($keyRecord['rate_limit'] ?? 1000);
        $reqCount       = (int)($keyRecord['requests_count'] ?? 0);
        $lastUsedAt     = $keyRecord['last_used_at'] ? strtotime($keyRecord['last_used_at']) : 0;
        $isDifferentDay = date('Y-m-d', $lastUsedAt) !== date('Y-m-d');

        if ($isDifferentDay) {
            $reqCount = 0; // Reset counter for the new calendar day
        }

        if ($rateLimit > 0 && $reqCount >= $rateLimit) {
            api_response(429, [
                'status'  => 'error',
                'message' => "Too Many Requests: Daily API request rate limit reached ({$rateLimit} requests/day). Limit resets at midnight."
            ]);
        }

        // Normalize required scopes into an array
        $neededList = [];
        if (is_array($requiredScopes)) {
            $neededList = array_filter(array_map('trim', $requiredScopes));
        } elseif (trim($requiredScopes) !== '') {
            $neededList = [trim($requiredScopes)];
        }

        // Scope verification with wildcard '*' and 'admin' master key support
        if (!empty($neededList)) {
            $grantedScopes = json_decode($keyRecord['scopes'] ?? '[]', true) ?: [];

            $isMaster = in_array('*', $grantedScopes, true) || in_array('admin', $grantedScopes, true);

            // True if at least one required scope is held by the key
            $hasAnyScope = $isMaster || (bool)array_intersect($neededList, $grantedScopes);

            if (!$hasAnyScope) {
                api_response(403, [
                    'status'  => 'error',
                    'message' => "Forbidden: API Key lacks required scope(s): " . implode(' OR ', $neededList) . "."
                ]);
            }
        }

        // Increment request counter and update last_used_at timestamp
        $newCount = $isDifferentDay ? 1 : ($reqCount + 1);
        $db->prepare('UPDATE api_keys SET requests_count = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([$newCount, $keyRecord['id']]);

        return $keyRecord;
    } catch (Throwable $e) {
        error_log('API Key Auth Exception: ' . $e->getMessage());
        api_response(500, [
            'status'  => 'error',
            'message' => 'Internal server error validating API Key credentials.'
        ]);
    }
}
