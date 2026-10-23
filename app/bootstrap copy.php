<?php

declare(strict_types=1);

// 1. Load Composer Autoloader at startup so PHPMailer & vendor libraries are globally indexed
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Tiny .env reader, no extra dependency required.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        $_ENV[trim($key)] = $value;
    }
}

if (!function_exists('envv')) {
    function envv(string $key, string $default = ''): string
    {
        return (string)($_ENV[$key] ?? getenv($key) ?: $default);
    }
}

date_default_timezone_set(envv('APP_TIMEZONE', 'Asia/Dhaka'));
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;
        $dsn = 'mysql:host=' . envv('DB_HOST', '127.0.0.1') . ';port=' . envv('DB_PORT', '3306') . ';dbname=' . envv('DB_NAME', 'v2ecom') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, envv('DB_USER', 'root'), envv('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        return $pdo;
    }
}

if (!function_exists('e')) {
    function e(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('site_setting')) {
    function site_setting(string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $st = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
            $st->execute([$key]);
            $val = $st->fetchColumn();

            if ($val !== false && $val !== null) {
                $cache[$key] = (string)$val;
                return $cache[$key];
            }

            // Insert default value into DB if key doesn't exist
            $ins = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key');
            $ins->execute([$key, $default]);

            $cache[$key] = $default;
            return $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('site_setting_save')) {
    function site_setting_save(string $key, string $value): void
    {
        $st = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $st->execute([$key, $value]);
    }
}

if (!function_exists('store_name')) {
    function store_name(): string
    {
        return site_setting('site_name', envv('APP_NAME', 'Diverse'));
    }
}

if (!function_exists('brand_html')) {
    function brand_html(): string
    {
        $name = e(store_name());
        $logo = trim(site_setting('logo_url', ''));
        if ($logo !== '') return '<img class="brand-logo" src="' . e(url($logo)) . '" alt="' . $name . ' logo">';
        return $name;
    }
}

if (!function_exists('money')) {
    function money(float|int|string $amount): string
    {
        $symbol = site_setting('currency_symbol', envv('CURRENCY', '৳'));
        return e($symbol) . number_format((float)$amount, 2);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return rtrim(envv('APP_URL', 'http://localhost/v2ecom'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        header('Location: ' . url($path));
        exit;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        if (!hash_equals($_SESSION['_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
            http_response_code(419);
            exit('Invalid session token. Refresh and try again.');
        }
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}

if (!function_exists('cart_count')) {
    function cart_count(): int
    {
        $n = 0;
        foreach (($_SESSION['cart'] ?? []) as $qty) $n += (int)$qty;
        return $n;
    }
}

if (!function_exists('cart_items')) {
    function cart_items(): array
    {
        $cart = $_SESSION['cart'] ?? [];
        if (!$cart) return [];
        $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($id) => $id > 0));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id,sku,name,slug,price,compare_price,image_url,stock,active FROM products WHERE id IN ($marks)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $p) {
            $qty = max(1, (int)($cart[$p['id']] ?? 1));
            $p['qty'] = min($qty, (int)$p['stock']);
            $p['line_total'] = $p['qty'] * (float)$p['price'];
            if ($p['qty'] > 0 && $p['active']) $out[] = $p;
        }
        return $out;
    }
}

if (!function_exists('cart_subtotal')) {
    function cart_subtotal(array $items): float
    {
        return array_sum(array_map(fn($i) => (float)$i['line_total'], $items));
    }
}

if (!function_exists('admin_required')) {
    function admin_required(): void
    {
        if (empty($_SESSION['admin_id'])) {
            redirect('admin/login');
        }

        try {
            $check = db()->prepare('SELECT id FROM admin_sessions WHERE id = ? AND admin_id = ?');
            $check->execute([session_id(), $_SESSION['admin_id']]);

            if (!$check->fetch()) {
                unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_email']);
                flash('error', 'Your session was ended because another device signed in or it was revoked.');
                redirect('admin/login');
            }

            db()->prepare('UPDATE admin_sessions SET last_activity = NOW() WHERE id = ?')->execute([session_id()]);
        } catch (Throwable $e) {
            // Fallback
        }
    }
}

if (!function_exists('send_store_email')) {
    function send_store_email(string $to, string $name, string $subject, string $html, string $attachmentContent = '', string $attachmentFilename = 'invoice.html'): bool
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        $getSetting = function (string $key, string $envKey, string $default = ''): string {
            $dbVal = trim(site_setting($key, ''));
            return $dbVal !== '' ? $dbVal : envv($envKey, $default);
        };

        $mailHost = $getSetting('mail_host', 'MAIL_HOST', 'smtp.gmail.com');
        $mailPort = (int)$getSetting('mail_port', 'MAIL_PORT', '587');
        $mailEnc  = $getSetting('mail_encryption', 'MAIL_ENCRYPTION', 'tls');
        $mailUser = $getSetting('mail_username', 'MAIL_USERNAME', '');
        $mailPass = $getSetting('mail_password', 'MAIL_PASSWORD', '');
        $fromAddr = $getSetting('mail_from_address', 'MAIL_FROM_ADDRESS', $mailUser);
        $fromName = $getSetting('mail_from_name', 'MAIL_FROM_NAME', store_name());

        if (!is_file($autoload) || $mailUser === '' || $mailPass === '') return false;
        require_once $autoload;

        $mailClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
        if (!class_exists($mailClass)) return false;

        try {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new $mailClass(true);
            $mail->isSMTP();

            $mail->Host       = $mailHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailUser;
            $mail->Password   = $mailPass;
            $mail->Port       = $mailPort;
            $mail->SMTPSecure = $mailEnc;
            $mail->CharSet    = 'UTF-8';

            // Bypass SSL Peer Verification for free shared hosting/local environments
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom($fromAddr, $fromName);
            $mail->addAddress($to, $name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html));

            if ($attachmentContent !== '') {
                $mail->addStringAttachment($attachmentContent, $attachmentFilename, 'base64', 'text/html');
            }

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('Email send failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('email_customer_order_placed')) {
    function email_customer_order_placed(array $order, array $items): void
    {
        $customerEmail = trim((string)($order['customer_email'] ?? ''));
        if ($customerEmail === '') {
            return;
        }

        $emailColor = site_setting('invoice_color', '#2c3e50');
        $storeName  = store_name();
        $logoUrl    = site_setting('logo_url', '');

        $subjTemplate  = site_setting('email_order_placed_subject', 'Order Received - #{order_number}');
        $introTemplate = site_setting('email_order_placed_intro', 'We have received your order #{order_number}. Our team is reviewing the details and will confirm your order shortly.');

        $replacements = [
            '{order_number}'  => $order['order_number'] ?? '',
            '{customer_name}' => $order['customer_name'] ?? '',
            '{total}'         => money($order['total'] ?? 0),
            '{store_name}'    => $storeName,
        ];

        $subject   = str_replace(array_keys($replacements), array_values($replacements), $subjTemplate);
        $introText = str_replace(array_keys($replacements), array_values($replacements), $introTemplate);

        $logoHtml = $logoUrl
            ? '<img src="' . e(url($logoUrl)) . '" alt="' . e($storeName) . '" style="max-height:50px; margin-bottom:10px;">'
            : '<h2 style="color:' . $emailColor . '; margin:0; font-size:24px;">' . e($storeName) . '</h2>';

        $rows = '';
        foreach ($items as $i) {
            $pName  = e($i['name'] ?? $i['product_name'] ?? 'Item');
            $pQty   = max(1, (int)($i['qty'] ?? $i['quantity'] ?? 1));
            $unitP  = (float)($i['price'] ?? $i['unit_price'] ?? 0);
            $pTotal = money($unitP * $pQty);

            $rows .= '
                <tr style="border-bottom:1px solid #eeeeee;">
                    <td style="padding:10px; font-size:14px; color:#222;">' . $pName . ' × ' . $pQty . '</td>
                    <td style="padding:10px; font-size:14px; text-align:right; font-weight:bold; color:#222;">' . $pTotal . '</td>
                </tr>';
        }

        $html = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 25px; border: 1px solid #e5e5de; border-radius: 10px; background-color: #ffffff;">
            <div style="border-bottom: 2px solid ' . $emailColor . '; padding-bottom: 15px; margin-bottom: 20px;">
                ' . $logoHtml . '
                <h3 style="margin: 8px 0 0 0; color: ' . $emailColor . '; font-size: 20px;">' . e($subject) . '</h3>
            </div>
            
            <p style="font-size:15px; color:#333;">Hello <b>' . e($order['customer_name']) . '</b>,</p>
            <p style="font-size:14px; color:#555; line-height:1.5;">' . nl2br(e($introText)) . '</p>
            
            <table style="width:100%; border-collapse:collapse; margin: 20px 0;">
                <thead>
                    <tr style="background:' . $emailColor . '; color:#ffffff;">
                        <th style="text-align:left; padding:10px; font-size:13px; text-transform:uppercase;">Item</th>
                        <th style="text-align:right; padding:10px; font-size:13px; text-transform:uppercase;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $rows . '
                    <tr>
                        <td style="padding:8px 10px; font-size:13px; color:#666;">Delivery Fee</td>
                        <td style="text-align:right; padding:8px 10px; font-size:13px; color:#666;">' . money($order['shipping_amount'] ?? 0) . '</td>
                    </tr>
                    <tr style="font-size:16px; font-weight:bold; border-top:2px solid #ddd; background:#fdfdfd;">
                        <td style="padding:12px 10px; color:#222;">Total Due (COD)</td>
                        <td style="text-align:right; padding:12px 10px; color:' . $emailColor . ';">' . money($order['total']) . '</td>
                    </tr>
                </tbody>
            </table>

            <p style="color:#777; font-size:12px; text-align:center; margin-top:25px; border-top:1px solid #eee; padding-top:15px;">
                ' . e(site_setting('invoice_footer_note', 'Thank you for shopping with us!')) . '
            </p>
        </div>';

        send_store_email($customerEmail, $order['customer_name'], $subject, $html);
    }
}

if (!function_exists('email_admin_new_order')) {
    function email_admin_new_order(array $order, array $items): void
    {
        $adminEmail = trim(site_setting('admin_email', envv('MAIL_FROM_ADDRESS', '')));
        if ($adminEmail === '') {
            return;
        }

        $emailColor = site_setting('invoice_color', '#2c3e50');
        $storeName  = store_name();

        $subject = '🔔 New COD Order #' . $order['order_number'] . ' received!';
        $html = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e5de; border-radius: 8px;">
            <h2 style="color:' . $emailColor . '; margin-top:0;">New COD Order Received</h2>
            <p>A new order has been placed on <b>' . e($storeName) . '</b>.</p>
            
            <div style="background:#f9f9f9; padding:15px; border-radius:6px; margin:15px 0;">
                <p style="margin:4px 0;"><b>Order Number:</b> ' . e($order['order_number']) . '</p>
                <p style="margin:4px 0;"><b>Customer Name:</b> ' . e($order['customer_name']) . '</p>
                <p style="margin:4px 0;"><b>Phone:</b> ' . e($order['customer_phone']) . '</p>
                <p style="margin:4px 0;"><b>Email:</b> ' . e($order['customer_email']) . '</p>
                <p style="margin:4px 0;"><b>Delivery Fee:</b> ' . money($order['shipping_amount'] ?? 0) . '</p>
                <p style="margin:4px 0;"><b>Total Amount:</b> ' . money($order['total']) . ' (COD)</p>
            </div>

            <p><a href="' . e(url('admin/sales/orders?id=' . $order['id'])) . '" style="background:' . $emailColor . '; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Open Order in Admin Panel</a></p>
        </div>';

        send_store_email($adminEmail, 'Store Admin', $subject, $html);
    }
}

if (!function_exists('email_order_customer')) {
    function email_order_customer(array $order, array $items): void
    {
        $customerEmail = trim((string)($order['customer_email'] ?? ''));
        if ($customerEmail === '') {
            return;
        }

        $emailColor   = site_setting('invoice_color', '#2c3e50');
        $logoUrl      = site_setting('logo_url', '');
        $storeName    = store_name();
        $invoiceNote  = site_setting('invoice_footer_note', 'Thank you for shopping with us!');
        $subjTemplate = site_setting('email_invoice_subject', 'Official Tax Invoice - Order #{order_number}');

        $replacements = [
            '{order_number}'  => $order['order_number'] ?? '',
            '{customer_name}' => $order['customer_name'] ?? '',
            '{total}'         => money($order['total'] ?? 0),
            '{store_name}'    => $storeName,
        ];

        $emailSubject = str_replace(array_keys($replacements), array_values($replacements), $subjTemplate);

        $logoHtml = $logoUrl
            ? '<img src="' . e(url($logoUrl)) . '" alt="' . e($storeName) . '" style="max-height:50px; margin-bottom:10px;">'
            : '<h2 style="color:' . $emailColor . '; margin:0; font-size:24px;">' . e($storeName) . '</h2>';

        $rows = '';
        foreach ($items as $i) {
            $pName  = e($i['product_name'] ?? $i['name'] ?? 'Product Item');
            $pQty   = max(1, (int)($i['quantity'] ?? $i['qty'] ?? 1));
            $unitP  = (float)($i['price'] ?? $i['unit_price'] ?? 0);
            $pTotal = money($unitP * $pQty);

            $rows .= '
                <tr style="border-bottom:1px solid #eeeeee;">
                    <td style="padding:12px; font-size:14px; color:#222222;"><b>' . $pName . '</b></td>
                    <td style="padding:12px; font-size:14px; text-align:center; color:#555555;">' . $pQty . '</td>
                    <td style="padding:12px; font-size:14px; text-align:right; color:#222222; font-weight:bold;">' . $pTotal . '</td>
                </tr>';
        }

        $attachmentHtml = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice ' . e($order['order_number']) . '</title>
<style>
    body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 40px; background: #fff; }
    .invoice-card { max-width: 700px; margin: auto; border: 1px solid #e0e0e0; padding: 40px; border-radius: 8px; }
    .header { display: flex; justify-content: space-between; border-bottom: 2px solid ' . $emailColor . '; padding-bottom: 20px; margin-bottom: 30px; }
    table { width: 100%; border-collapse: collapse; margin: 25px 0; }
    th { background: ' . $emailColor . '; color: #fff; padding: 12px; text-align: left; font-size: 13px; text-transform: uppercase; }
    td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
    .totals { margin-left: auto; width: 280px; background: #f9f9f9; padding: 15px; border-radius: 6px; }
    .totals div { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
</style>
</head>
<body>
<div class="invoice-card">
    <div class="header">
        <div>
            ' . $logoHtml . '
            <p style="margin:5px 0 0 0; color:#666; font-size:13px;">' . e(url()) . '</p>
        </div>
        <div style="text-align:right;">
            <h1 style="margin:0; font-size:24px; color:' . $emailColor . ';">INVOICE</h1>
            <p style="margin:4px 0 0 0; font-weight:bold;">' . e($order['order_number']) . '</p>
            <p style="margin:2px 0 0 0; color:#777; font-size:13px;">Date: ' . date('d M, Y', strtotime($order['created_at'])) . '</p>
        </div>
    </div>
    
    <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
        <div style="width:48%; background:#fafafa; padding:15px; border-radius:6px; border:1px solid #f0f0f0;">
            <b style="color:' . $emailColor . '; text-transform:uppercase; font-size:11px; display:block; margin-bottom:6px;">Billed To</b>
            <strong>' . e($order['customer_name']) . '</strong><br>
            <span style="font-size:13px; color:#555;">' . e($order['customer_email']) . '</span><br>
            <span style="font-size:13px; color:#555;">' . e($order['customer_phone']) . '</span>
        </div>
        <div style="width:48%; background:#fafafa; padding:15px; border-radius:6px; border:1px solid #f0f0f0;">
            <b style="color:' . $emailColor . '; text-transform:uppercase; font-size:11px; display:block; margin-bottom:6px;">Shipping Address</b>
            <span style="font-size:13px; color:#333;">' . nl2br(e($order['address'])) . '</span><br>
            <strong style="font-size:13px; color:' . $emailColor . ';">' . e($order['city']) . '</strong>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item Description</th>
                <th style="text-align:center;">Qty</th>
                <th style="text-align:right;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            ' . $rows . '
        </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
            <b>Payment Method:</b>
            <p style="margin:4px 0 0 0; font-weight:bold; color:' . $emailColor . ';">Cash on Delivery (COD)</p>
        </div>
        <div class="totals">
            <div><span>Subtotal:</span> <span>' . money($order['subtotal']) . '</span></div>
            <div><span>Discount:</span> <span style="color:#c0392b;">-' . money($order['discount_amount']) . '</span></div>
            <div><span>Delivery Fee:</span> <span>' . money($order['shipping_amount']) . '</span></div>
            <hr style="border:0; border-top:1px solid #ddd; margin:8px 0;">
            <div style="font-size:16px; font-weight:bold; color:' . $emailColor . ';"><span>Total Due:</span> <span>' . money($order['total']) . '</span></div>
        </div>
    </div>

    <div style="margin-top:40px; border-top:1px solid #eee; padding-top:15px; text-align:center; color:#777; font-size:13px;">
        <p style="margin:0;">' . e($invoiceNote) . '</p>
    </div>
</div>
</body>
</html>';

        $bodyHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 25px; border: 1px solid #e5e5de; border-radius: 10px; background-color: #ffffff;">
            <div style="border-bottom: 2px solid ' . $emailColor . '; padding-bottom: 15px; margin-bottom: 20px;">
                ' . $logoHtml . '
                <h3 style="margin: 8px 0 0 0; color: ' . $emailColor . '; font-size: 20px;">Order Confirmed #' . e($order['order_number']) . '</h3>
            </div>
            
            <p style="font-size:15px; color:#333;">Hello <b>' . e($order['customer_name']) . '</b>,</p>
            <p style="font-size:14px; color:#555; line-height:1.5;">Great news! Your order has been officially <b>confirmed</b> and is now being packaged for dispatch. A printable copy of your official invoice is attached to this email.</p>
            
            <table style="width:100%; border-collapse:collapse; margin: 20px 0;" cellpadding="0" cellspacing="0">
                <thead>
                    <tr style="background:' . $emailColor . '; color:#ffffff;">
                        <th style="text-align:left; padding:10px; font-size:13px; text-transform:uppercase;">Item Description</th>
                        <th style="text-align:center; padding:10px; font-size:13px; text-transform:uppercase;">Qty</th>
                        <th style="text-align:right; padding:10px; font-size:13px; text-transform:uppercase;">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $rows . '
                    <tr>
                        <td colspan="2" style="padding:8px 10px; font-size:13px; color:#666;">Subtotal</td>
                        <td style="text-align:right; padding:8px 10px; font-size:13px; color:#666;">' . money($order['subtotal']) . '</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding:8px 10px; font-size:13px; color:#c0392b;">Discount</td>
                        <td style="text-align:right; padding:8px 10px; font-size:13px; color:#c0392b;">-' . money($order['discount_amount']) . '</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding:8px 10px; font-size:13px; color:#666;">Delivery Fee</td>
                        <td style="text-align:right; padding:8px 10px; font-size:13px; color:#666;">' . money($order['shipping_amount']) . '</td>
                    </tr>
                    <tr style="font-size:16px; font-weight:bold; border-top:2px solid #ddd; background:#fdfdfd;">
                        <td colspan="2" style="padding:12px 10px; color:#222;">Total Amount Due (COD)</td>
                        <td style="text-align:right; padding:12px 10px; color:' . $emailColor . ';">' . money($order['total']) . '</td>
                    </tr>
                </tbody>
            </table>

            <div style="background:#f9f9f9; border:1px solid #eee; padding:12px; border-radius:6px; margin-bottom:20px;">
                <b style="font-size:12px; color:#666; text-transform:uppercase;">Delivery Address:</b>
                <p style="margin:4px 0 0 0; font-size:13px; color:#333; line-height:1.4;">' . nl2br(e($order['address'])) . '<br><b>' . e($order['city']) . '</b></p>
            </div>

            <p style="color:#777; font-size:12px; text-align:center; margin-top:25px; border-top:1px solid #eee; padding-top:15px;">
                ' . e($invoiceNote) . '
            </p>
        </div>';

        send_store_email(
            $customerEmail,
            $order['customer_name'],
            $emailSubject,
            $bodyHtml,
            $attachmentHtml,
            'invoice-' . $order['order_number'] . '.html'
        );
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        static $user = null;
        if ($user !== null) return $user;

        try {
            $st = db()->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
            $st->execute([$_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
            if (!$user) unset($_SESSION['user_id']);
            return $user;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('user_required')) {
    function user_required(): array
    {
        $u = current_user();
        if (!$u) {
            flash('error', 'Please log in or register to access your account.');
            redirect('user_login.php');
        }
        return $u;
    }
}

if (!function_exists('email_user_welcome')) {
    function email_user_welcome(array $user): void
    {
        $subject = 'Welcome to ' . store_name() . '!';
        $html = '
        <div style="font-family:Arial,sans-serif; max-width:600px; margin:auto; padding:20px; border:1px solid #eee; border-radius:8px;">
            <h2 style="color:' . site_setting('invoice_color', '#2c3e50') . ';">Welcome, ' . e($user['name']) . '!</h2>
            <p>Thank you for creating an account with <b>' . e(store_name()) . '</b>.</p>
            <p>Your account allows you to place orders faster without filling out your details every time.</p>
            <p><a href="' . e(url('user_login.php')) . '" style="background:' . site_setting('invoice_color', '#2c3e50') . '; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Log In to Your Account</a></p>
        </div>';
        send_store_email($user['email'], $user['name'], $subject, $html);
    }
}

if (!function_exists('email_user_password_reset')) {
    function email_user_password_reset(string $email, string $name, string $tempPassword): void
    {
        $subject = 'Password Reset Request - ' . store_name();
        $html = '
        <div style="font-family:Arial,sans-serif; max-width:600px; margin:auto; padding:20px; border:1px solid #eee; border-radius:8px;">
            <h3 style="color:' . site_setting('invoice_color', '#2c3e50') . ';">Password Reset Request</h3>
            <p>Hello <b>' . e($name) . '</b>,</p>
            <p>We generated a temporary password for your account as requested:</p>
            <div style="background:#f4f4f4; padding:12px; font-size:18px; font-weight:bold; letter-spacing:2px; text-align:center; border-radius:6px; margin:15px 0;">
                ' . e($tempPassword) . '
            </div>
            <p>Please log in using this temporary password and update it immediately in your account settings.</p>
            <p><a href="' . e(url('user_login.php')) . '" style="background:' . site_setting('invoice_color', '#2c3e50') . '; color:#fff; padding:10px 18px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Log In Now</a></p>
        </div>';
        send_store_email($email, $name, $subject, $html);
    }
}

// Check if the currently logged-in admin is a Super Admin
if (!function_exists('is_super_admin')) {
    function is_super_admin(): bool
    {
        if (empty($_SESSION['admin_id'])) return false;
        static $isSuper = null;
        if ($isSuper !== null) return $isSuper;

        try {
            $st = db()->prepare('SELECT is_super_admin FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin_id']]);
            $isSuper = (bool)$st->fetchColumn();
            return $isSuper;
        } catch (Throwable $e) {
            return false;
        }
    }
}

// Check if the active admin has permission for a specific module
if (!function_exists('has_permission')) {
    function has_permission(string $module): bool
    {
        if (is_super_admin()) return true; // Super Admin has unlimited access

        if (empty($_SESSION['admin_id'])) return false;

        try {
            $st = db()->prepare('SELECT permissions FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin_id']]);
            $raw = (string)$st->fetchColumn();

            if ($raw === '') return false;

            $perms = json_decode($raw, true);
            return is_array($perms) && in_array($module, $perms, true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

// Enforce permission guard on admin pages
if (!function_exists('require_permission')) {
    function require_permission(string $module): void
    {
        admin_required();
        if (!has_permission($module)) {
            flash('error', 'Access denied: You do not have permission to manage ' . ucfirst($module) . '.');
            redirect('admin/dashboard');
        }
    }
}

if (!function_exists('sync_htpasswd_user')) {
    function sync_htpasswd_user(string $username, string $plainPassword): bool
    {
        $htpasswdPath = __DIR__ . '/../manage_portal_x92/.htpasswd';
        if (!file_exists($htpasswdPath)) {
            return false;
        }

        $lines = file($htpasswdPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $updatedLines = [];
        $userFound = false;

        // Generate SHA1 hash in Apache htpasswd format: {SHA}base64_encode(sha1(pass, raw))
        $hash = '{SHA}' . base64_encode(sha1($plainPassword, true));
        $newLine = $username . ':' . $hash;

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (trim($parts[0]) === $username) {
                $updatedLines[] = $newLine;
                $userFound = true;
            } else {
                $updatedLines[] = $line;
            }
        }

        if (!$userFound) {
            $updatedLines[] = $newLine;
        }

        return file_put_contents($htpasswdPath, implode("\n", $updatedLines) . "\n") !== false;
    }
}

// --- MAINTENANCE WINDOW ENFORCEMENT GUARD ---
if (site_setting('maintenance_mode', '0') === '1') {
    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    $isAdminRoute = str_contains($reqUri, '/manage_portal_x92/') || str_contains($reqUri, '/admin/');

    // Check if user is an active admin AND has explicitly requested live storefront preview mode (?preview=1)
    $isLoggedInAdmin = !empty($_SESSION['admin_id']);
    $isAdminPreview  = isset($_GET['preview']) || isset($_GET['admin_test']);

    // Display Maintenance Page to all public visitors, customers, and admins NOT in explicit preview mode
    if (!$isAdminRoute && (!$isLoggedInAdmin || !$isAdminPreview)) {
        http_response_code(503);

        // Send strict anti-cache headers so browsers never cache the live page during maintenance
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Retry-After: 3600');

        $mTitle = site_setting('maintenance_title', 'We’ll Be Right Back!');
        $mMsg   = site_setting('maintenance_message', 'Our website is currently undergoing scheduled maintenance.');
        $store  = store_name();
        $logo   = site_setting('logo_url', '');
?>
        <!doctype html>
        <html lang="en">

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?= e($mTitle) ?> · <?= e($store) ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f8fafc;
                    color: #1e293b;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                    box-sizing: border-box;
                }

                .m-card {
                    background: #ffffff;
                    max-width: 520px;
                    width: 100%;
                    padding: 40px 30px;
                    border-radius: 16px;
                    text-align: center;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
                    border: 1px solid #e2e8f0;
                }

                .m-card h1 {
                    font-size: 26px;
                    margin: 15px 0 10px 0;
                    color: #0f172a;
                }

                .m-card p {
                    font-size: 15px;
                    color: #64748b;
                    line-height: 1.6;
                    margin-bottom: 25px;
                }

                .badge {
                    background: #fef3c7;
                    color: #92400e;
                    font-size: 12px;
                    font-weight: bold;
                    padding: 6px 12px;
                    border-radius: 20px;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    display: inline-block;
                }
            </style>
        </head>

        <body>
            <div class="m-card">
                <?php if ($logo): ?>
                    <img src="<?= e(url($logo)) ?>" alt="<?= e($store) ?>" style="max-height: 60px; margin-bottom: 10px;">
                <?php else: ?>
                    <div class="badge">🛠️ Under Maintenance</div>
                <?php endif; ?>
                <h1><?= e($mTitle) ?></h1>
                <p><?= nl2br(e($mMsg)) ?></p>
                <div style="font-size: 13px; color: #94a3b8;">&copy; <?= date('Y') ?> <?= e($store) ?>. All rights reserved.</div>
            </div>
        </body>

        </html>
<?php
        exit;
    }
}

// Automatic Traffic Logger (Ignores admin portal requests)
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (!str_contains($reqUri, '/manage_portal_x92/') && !str_contains($reqUri, '/admin/')) {
    try {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $url = substr($reqUri, 0, 255);
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        $stmt = db()->prepare("INSERT INTO visitor_logs (ip_address, page_url, user_agent) VALUES (?, ?, ?)");
        $stmt->execute([$ip, $url, $ua]);
    } catch (Throwable $e) {
        // Silently ignore log errors
    }
}
