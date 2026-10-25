<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('settings');

$title = 'API Keys & Integration Management';
$db = db();

// Available API Scopes
$availableScopes = [
    'auth:login'      => 'Customer & Staff Login SSO',
    'orders:read'     => 'Read Order Details',
    'orders:write'    => 'Create & Update Orders',
    'courier:sync'    => 'Courier Partner Integration (e.g., Pathao/Steadfast)',
    'customers:read'  => 'Read Customer Profile Data',
    'customers:write' => 'Manage Customer Database',
    'products:read'   => 'Read Product Catalog & Stock'
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
        $scopes    = $_POST['scopes'] ?? [];
        $rateLimit = max(100, (int)($_POST['rate_limit'] ?? 1000));

        if ($keyName === '') {
            flash('error', 'Please provide a descriptive name for this API key.');
            redirect('admin/system/api-keys');
        }

        $apiKey      = 'db_' . bin2hex(random_bytes(16));
        $secretToken = bin2hex(random_bytes(32));
        $assignedScopes = array_values(array_intersect(array_keys($availableScopes), (array)$scopes));
        $scopesJson  = json_encode($assignedScopes);

        $ins = $db->prepare('INSERT INTO api_keys (key_name, api_key, secret_token, status, scopes, rate_limit) VALUES (?, ?, ?, "active", ?, ?)');
        $ins->execute([$keyName, $apiKey, password_hash($secretToken, PASSWORD_DEFAULT), $scopesJson, $rateLimit]);
        $newKeyId = (int)$db->lastInsertId();

        // Audit Logging: API Key Created
        $scopesList = $assignedScopes ? implode(', ', $assignedScopes) : 'None';
        log_admin_activity(
            'security',
            'create_api_key',
            "Generated API Key '{$keyName}' (ID #{$newKeyId}, Key: {$apiKey}, Limit: {$rateLimit}/day). Scopes: [{$scopesList}]"
        );

        $_SESSION['new_api_secret'] = $secretToken;
        flash('success', 'API Key created successfully. Make sure to copy your Secret Token below!');
        redirect('admin/system/api-keys');
    }

    // 2. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        $keyId = (int)($_POST['key_id'] ?? 0);

        // Fetch prior details for accurate audit record
        $chk = $db->prepare('SELECT key_name, api_key, status FROM api_keys WHERE id = ?');
        $chk->execute([$keyId]);
        $key = $chk->fetch();

        if ($key) {
            $newStatus = $key['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE api_keys SET status = ? WHERE id = ?')->execute([$newStatus, $keyId]);

            // Audit Logging: API Key Status Toggled
            $stateLabel = ucfirst($newStatus);
            log_admin_activity(
                'security',
                'toggle_api_key_status',
                "Toggled API Key '{$key['key_name']}' ({$key['api_key']}, ID #{$keyId}) status to {$stateLabel}"
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

            // Audit Logging: API Key Revoked
            log_admin_activity(
                'security',
                'revoke_api_key',
                "Permanently revoked and deleted API Key '{$key['key_name']}' ({$key['api_key']}, ID #{$keyId})"
            );

            flash('success', 'API Key revoked and permanently deleted.');
        }
        redirect('admin/system/api-keys');
    }
}

admin_header($title);
$apiKeys = $db->query('SELECT * FROM api_keys ORDER BY id DESC')->fetchAll();
$newSecret = $_SESSION['new_api_secret'] ?? null;
unset($_SESSION['new_api_secret']);
?>

<?php if ($newSecret): ?>
    <div class="panel" style="background:#e8f8f5; border:1px solid #2ecc71; margin-bottom:20px;">
        <h3 style="color:#27ae60; margin-top:0;">⚠️ Secret Token Generated</h3>
        <p class="small">Please save this secret token now. It will <b>not</b> be displayed again:</p>
        <code style="font-size:16px; background:#fff; padding:8px 12px; border:1px solid #ccc; display:block; border-radius:4px; margin-bottom:10px; word-break:break-all;">
            <?= e($newSecret) ?>
        </code>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:25px;">
    <h2>Active API Keys & Integrations</h2>
    <p class="muted small" style="margin-top:-5px; margin-bottom:15px;">
        Manage external application access tokens for logins, courier services, and CRMs.
    </p>

    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#f4f6f8; text-align:left; border-bottom:2px solid #eee;">
                <th style="padding:10px;">Integration Name</th>
                <th style="padding:10px;">API Key ID</th>
                <th style="padding:10px;">Granted Scopes</th>
                <th style="padding:10px;">Status</th>
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
                        <?php foreach ($scopesArr as $sc): ?>
                            <span style="background:#eef2f7; padding:2px 6px; border-radius:3px; margin-right:3px; color:#2c3e50;">
                                <?= e($sc) ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td style="padding:10px;">
                        <span style="color:<?= $k['status'] === 'active' ? '#27ae60' : '#e74c3c' ?>; font-weight:bold; font-size:12px;">
                            <?= strtoupper(e($k['status'])) ?>
                        </span>
                    </td>
                    <td style="padding:10px; font-size:12px; color:#555;"><?= number_format($k['requests_count']) ?></td>
                    <td style="padding:10px; text-align:right;">
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                            <button class="button secondary" style="padding:4px 8px; font-size:11px;">Toggle</button>
                        </form>
                        <?php if (is_super_admin()): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this API Key?');">
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
                    <td colspan="6" style="text-align:center; padding:20px;" class="muted">No API keys generated yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (is_super_admin()): ?>
    <div class="panel" style="max-width:650px;">
        <h3>Generate New Integration Key</h3>
        <form method="post" style="margin-top:15px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_key">

            <div class="form-grid">
                <div class="field">
                    <label>Application / Service Name *</label>
                    <input name="key_name" required placeholder="e.g., Pathao Courier Service">
                </div>
                <div class="field">
                    <label>Daily Request Limit</label>
                    <input type="number" name="rate_limit" value="1000" min="100">
                </div>
            </div>

            <div class="field" style="margin-top:15px;">
                <label style="font-weight:bold; display:block; margin-bottom:8px;">Grant API Access Scopes:</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:10px; background:#f9f9f9; padding:15px; border-radius:6px; border:1px solid #eee;">
                    <?php foreach ($availableScopes as $key => $label): ?>
                        <label style="font-size:13px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="scopes[]" value="<?= $key ?>" style="width:auto; margin-right:5px;">
                            <?= e($label) ?> (<code><?= e($key) ?></code>)
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" style="margin-top:15px;">Generate API Credentials</button>
        </form>
    </div>
<?php endif; ?>

<?php admin_footer(); ?>