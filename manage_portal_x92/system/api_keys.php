<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');

$title = 'API Keys & Integration Management';
$db = db();

// Available API Scopes grouped by operational module
$availableScopes = [
    '*'               => '⚡ Full Master Access (All Endpoints)',
    'auth:login'      => 'Customer & Staff Login / Registration SSO',
    'products:read'   => 'Read Product Catalog, Variants & Stock Levels',
    'products:write'  => 'Create / Update Product & Stock Records',
    'orders:read'     => 'Read Order Tracking, Items & Order History',
    'orders:write'    => 'Create Orders & Validate Coupons',
    'checkout:read'   => 'Calculate Delivery Rates & List Shipping Cities',
    'courier:sync'    => 'Courier Partner Webhook Sync & Automatic Restock',
    'customers:read'  => 'Read Customer Profiles, LTV Metrics & Addresses',
    'customers:write' => 'Manage Customer Accounts & Delivery Addresses',
    'inventory:write' => 'Adjust Warehouse & POS Variant Inventory'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // 1. Generate New API Key
    if ($action === 'create_key') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can manage API credentials.');
            redirect('admin/system/api-keys');
        }

        $keyName   = trim((string)($_POST['key_name'] ?? ''));
        $scopes    = (array)($_POST['scopes'] ?? []);
        $rateLimit = max(100, (int)($_POST['rate_limit'] ?? 1000));

        if ($keyName === '') {
            flash('error', 'Please provide a descriptive name for this API key.');
            redirect('admin/system/api-keys');
        }

        // If wildcard selected, assign only '*'
        if (in_array('*', $scopes, true)) {
            $assignedScopes = ['*'];
        } else {
            $assignedScopes = array_values(array_intersect(array_keys($availableScopes), $scopes));
        }

        if (empty($assignedScopes)) {
            flash('error', 'Please select at least one permission scope for this key.');
            redirect('admin/system/api-keys');
        }

        $apiKey      = 'db_' . bin2hex(random_bytes(16));
        $secretToken = bin2hex(random_bytes(32));
        $scopesJson  = json_encode($assignedScopes);

        $ins = $db->prepare('INSERT INTO api_keys (key_name, api_key, secret_token, status, scopes, rate_limit) VALUES (?, ?, ?, "active", ?, ?)');
        $ins->execute([$keyName, $apiKey, password_hash($secretToken, PASSWORD_DEFAULT), $scopesJson, $rateLimit]);
        $newKeyId = (int)$db->lastInsertId();

        // Audit Logging
        $scopesList = implode(', ', $assignedScopes);
        log_admin_activity(
            'security',
            'create_api_key',
            "Generated API Key '{$keyName}' (ID #{$newKeyId}, Key: {$apiKey}, Limit: {$rateLimit}/day). Scopes: [{$scopesList}]"
        );

        $_SESSION['new_api_key']    = $apiKey;
        $_SESSION['new_api_secret'] = $secretToken;
        flash('success', 'API Key generated successfully. Save credentials below.');
        redirect('admin/system/api-keys');
    }

    // 2. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        $keyId = (int)($_POST['key_id'] ?? 0);

        $chk = $db->prepare('SELECT key_name, api_key, status FROM api_keys WHERE id = ?');
        $chk->execute([$keyId]);
        $key = $chk->fetch();

        if ($key) {
            $newStatus = $key['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE api_keys SET status = ? WHERE id = ?')->execute([$newStatus, $keyId]);

            log_admin_activity(
                'security',
                'toggle_api_key_status',
                "Toggled API Key '{$key['key_name']}' ({$key['api_key']}, ID #{$keyId}) status to " . ucfirst($newStatus)
            );

            flash('success', 'API Key status updated.');
        }
        redirect('admin/system/api-keys');
    }

    // 3. Delete / Revoke Key
    if ($action === 'delete_key') {
        if (!is_super_admin()) {
            flash('error', 'Action denied: Only Super Admins can revoke API keys.');
            redirect('admin/system/api-keys');
        }
        $keyId = (int)($_POST['key_id'] ?? 0);

        $chk = $db->prepare('SELECT key_name, api_key FROM api_keys WHERE id = ?');
        $chk->execute([$keyId]);
        $key = $chk->fetch();

        if ($key) {
            $db->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]);

            log_admin_activity(
                'security',
                'revoke_api_key',
                "Permanently revoked API Key '{$key['key_name']}' ({$key['api_key']}, ID #{$keyId})"
            );

            flash('success', 'API Key revoked and permanently deleted.');
        }
        redirect('admin/system/api-keys');
    }
}

admin_header($title);
$apiKeys   = $db->query('SELECT * FROM api_keys ORDER BY id DESC')->fetchAll();
$newKey    = $_SESSION['new_api_key'] ?? null;
$newSecret = $_SESSION['new_api_secret'] ?? null;
unset($_SESSION['new_api_key'], $_SESSION['new_api_secret']);
?>

<?php if ($newSecret): ?>
    <div class="panel" style="background:#e8f8f5; border:1px solid #2ecc71; margin-bottom:20px; padding:15px; border-radius:6px;">
        <h3 style="color:#27ae60; margin-top:0;">⚠️ New Credentials Generated</h3>
        <p class="small">Save these credentials now. The secret token is stored hashed and will <b>not</b> be displayed again:</p>

        <div style="margin-bottom:10px;">
            <label style="font-size:12px; font-weight:bold; color:#555;">API Key ID (Header: <code>X-API-KEY</code>):</label>
            <code style="font-size:14px; background:#fff; padding:6px 10px; border:1px solid #ddd; display:block; border-radius:4px; font-family:monospace;"><?= e($newKey) ?></code>
        </div>

        <div>
            <label style="font-size:12px; font-weight:bold; color:#555;">Secret Token / Bearer Token:</label>
            <code style="font-size:14px; background:#fff; padding:6px 10px; border:1px solid #ddd; display:block; border-radius:4px; font-family:monospace; word-break:break-all;"><?= e($newSecret) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:25px;">
    <h2>Active API Keys & Integrations</h2>
    <p class="muted small" style="margin-top:-5px; margin-bottom:15px;">
        Manage access credentials for mobile apps, couriers (Steadfast, Pathao), POS registers, and headless frontends.
    </p>

    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                <th style="padding:10px;">Integration Name</th>
                <th style="padding:10px;">API Key ID</th>
                <th style="padding:10px;">Granted Scopes</th>
                <th style="padding:10px;">Status</th>
                <th style="padding:10px;">Daily Limit</th>
                <th style="padding:10px;">Total Calls</th>
                <th style="padding:10px; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($apiKeys as $k): ?>
                <?php $scopesArr = json_decode($k['scopes'] ?? '[]', true) ?: []; ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><b><?= e($k['key_name']) ?></b></td>
                    <td style="padding:10px;"><code><?= e($k['api_key']) ?></code></td>
                    <td style="padding:10px; font-size:11px;">
                        <?php if (in_array('*', $scopesArr, true)): ?>
                            <span style="background:#27ae60; color:#fff; padding:2px 8px; border-radius:3px; font-weight:bold;">
                                ⚡ ALL (Wildcard)
                            </span>
                        <?php else: ?>
                            <?php foreach ($scopesArr as $sc): ?>
                                <span style="background:#eef2f7; padding:2px 6px; border-radius:3px; margin:2px 3px 2px 0; display:inline-block; color:#2c3e50;">
                                    <?= e($sc) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px;">
                        <span style="color:<?= $k['status'] === 'active' ? '#27ae60' : '#e74c3c' ?>; font-weight:bold; font-size:12px;">
                            <?= strtoupper(e($k['status'])) ?>
                        </span>
                    </td>
                    <td style="padding:10px; font-size:12px; color:#555;"><?= number_format((int)($k['rate_limit'] ?? 1000)) ?>/day</td>
                    <td style="padding:10px; font-size:12px; color:#555;"><?= number_format((int)($k['requests_count'] ?? 0)) ?></td>
                    <td style="padding:10px; text-align:right;">
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                            <button class="button secondary" style="padding:4px 8px; font-size:11px;">Toggle</button>
                        </form>
                        <?php if (is_super_admin()): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this API Key permanently?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_key">
                                <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                                <button class="button danger" style="padding:4px 8px; font-size:11px;">Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$apiKeys): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:20px;" class="muted">No API keys generated yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (is_super_admin()): ?>
    <div class="panel" style="max-width:750px;">
        <h3>Generate New Integration Key</h3>
        <form method="post" style="margin-top:15px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_key">

            <div class="form-grid">
                <div class="field">
                    <label>Application / Service Name *</label>
                    <input name="key_name" required placeholder="e.g., POS Billing Software or Steadfast Courier">
                </div>
                <div class="field">
                    <label>Daily Request Rate Limit</label>
                    <input type="number" name="rate_limit" value="5000" min="100">
                </div>
            </div>

            <div class="field" style="margin-top:15px;">
                <label style="font-weight:bold; display:block; margin-bottom:8px;">Select Allowed Scopes:</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:10px; background:#f9f9f9; padding:15px; border-radius:6px; border:1px solid #eee;">
                    <?php foreach ($availableScopes as $key => $label): ?>
                        <label style="font-size:13px; font-weight:normal; cursor:pointer; display:flex; align-items:center;">
                            <input type="checkbox" name="scopes[]" value="<?= $key ?>" style="width:auto; margin-right:8px;" <?= $key === '*' ? 'id="scope_wildcard"' : 'class="scope_individual"' ?>>
                            <span><?= e($label) ?> (<code><?= e($key) ?></code>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" style="margin-top:15px;">Generate API Key & Credentials</button>
        </form>
    </div>

    <script>
        // Uncheck individual scopes if Wildcard (*) is toggled
        document.getElementById('scope_wildcard')?.addEventListener('change', function() {
            if (this.checked) {
                document.querySelectorAll('.scope_individual').forEach(cb => cb.checked = false);
            }
        });
        document.querySelectorAll('.scope_individual').forEach(cb => {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    const wildcard = document.getElementById('scope_wildcard');
                    if (wildcard) wildcard.checked = false;
                }
            });
        });
    </script>
<?php endif; ?>

<?php admin_footer(); ?>