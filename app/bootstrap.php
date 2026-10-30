<?php

declare(strict_types=1);

// 1. Load Composer Autoloader at startup so PHPMailer, Dompdf & vendor libraries are globally indexed
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;
use Dompdf\Options;

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
        $pdo = new PDO($dsn, envv('DB_USER', 'root'), envv('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        // Force MySQL session timezone to match application timezone on shared/cloud hosts (e.g. InfinityFree)
        try {
            $pdo->exec("SET time_zone = '+06:00'");
        } catch (Throwable $e) {
            // Silently fall back if DB host restricts timezone table manipulation
        }

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

// Dedicated ASCII/Clean currency formatter for PDF rendering (Prevents '?' encoding bugs)
if (!function_exists('money_pdf')) {
    function money_pdf(float|int|string $amount): string
    {
        return 'Tk ' . number_format((float)$amount, 2);
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
    function send_store_email(
        string $to,
        string $name,
        string $subject,
        string $html,
        string $attachmentContent = '',
        string $attachmentFilename = 'invoice.pdf',
        string $attachmentMime = 'application/pdf'
    ): bool {
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

            // Bypass SSL Peer Verification for local/shared environments
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

            // Attach PDF or fallback attachment
            if ($attachmentContent !== '') {
                $mail->addStringAttachment($attachmentContent, $attachmentFilename, 'base64', $attachmentMime);
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

// Generates PDF or returns HTML string fallback
if (!function_exists('generate_order_pdf_invoice')) {
    function generate_order_pdf_invoice(string $html): string
    {
        if (class_exists('\\Dompdf\\Dompdf')) {
            try {
                $options = new Options();
                $options->set('isRemoteEnabled', true);
                $options->set('isHtml5ParserEnabled', true);
                $options->set('defaultFont', 'Helvetica');

                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                return $dompdf->output();
            } catch (Throwable $e) {
                error_log('Dompdf render error: ' . $e->getMessage());
            }
        }
        return '';
    }
}

if (!function_exists('email_order_customer')) {
    function email_order_customer(array $order, array $items): void
    {
        $customerEmail = trim((string)($order['customer_email'] ?? ''));
        if ($customerEmail === '') {
            return;
        }

        $emailColor   = site_setting('invoice_color', '#17594f');
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

        $pdfRows = '';
        foreach ($items as $i) {
            $pName  = e($i['product_name'] ?? $i['name'] ?? 'Product Item');
            $pQty   = max(1, (int)($i['quantity'] ?? $i['qty'] ?? 1));
            $unitP  = (float)($i['price'] ?? $i['unit_price'] ?? 0);
            $pTotal = money_pdf($unitP * $pQty);

            $pdfRows .= '
                <tr style="border-bottom:1px solid #eeeeee;">
                    <td style="padding:10px 12px; font-size:13px; color:#222222;"><b>' . $pName . '</b></td>
                    <td style="padding:10px 12px; font-size:13px; text-align:center; color:#555555;">' . $pQty . '</td>
                    <td style="padding:10px 12px; font-size:13px; text-align:right; color:#222222; font-weight:bold;">' . $pTotal . '</td>
                </tr>';
        }

        $emailRows = '';
        foreach ($items as $i) {
            $pName  = e($i['product_name'] ?? $i['name'] ?? 'Product Item');
            $pQty   = max(1, (int)($i['quantity'] ?? $i['qty'] ?? 1));
            $unitP  = (float)($i['price'] ?? $i['unit_price'] ?? 0);
            $pTotal = money($unitP * $pQty);

            $emailRows .= '
                <tr style="border-bottom:1px solid #eeeeee;">
                    <td style="padding:10px 12px; font-size:13px; color:#222222;"><b>' . $pName . '</b></td>
                    <td style="padding:10px 12px; font-size:13px; text-align:center; color:#555555;">' . $pQty . '</td>
                    <td style="padding:10px 12px; font-size:13px; text-align:right; color:#222222; font-weight:bold;">' . $pTotal . '</td>
                </tr>';
        }

        $invoiceHtml = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice ' . e($order['order_number']) . '</title>
<style>
    @page { margin: 25px 30px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #333; margin: 0; padding: 0; font-size: 13px; }
    .header-table { width: 100%; border-bottom: 2px solid ' . $emailColor . '; padding-bottom: 15px; margin-bottom: 25px; }
    .info-table { width: 100%; margin-bottom: 25px; }
    .info-box { background: #fafafa; padding: 12px; border: 1px solid #f0f0f0; border-radius: 4px; }
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
    .items-table th { background: ' . $emailColor . '; color: #ffffff; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; }
    .items-table td { padding: 10px 12px; border-bottom: 1px solid #eeeeee; font-size: 13px; }
    .totals-table { width: 260px; margin-left: auto; border-collapse: collapse; }
    .totals-table td { padding: 5px 8px; font-size: 13px; }
    .footer { margin-top: 35px; border-top: 1px solid #eeeeee; padding-top: 15px; text-align: center; color: #777; font-size: 12px; }
</style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="vertical-align:top;">
                <h2 style="color:' . $emailColor . '; margin:0; font-size:24px;">' . e($storeName) . '</h2>
                <p style="margin:4px 0 0 0; color:#666; font-size:12px;">' . e(url()) . '</p>
            </td>
            <td style="vertical-align:top; text-align:right;">
                <h1 style="margin:0; font-size:22px; color:' . $emailColor . ';">INVOICE</h1>
                <p style="margin:4px 0 0 0; font-weight:bold; font-size:14px;">' . e($order['order_number']) . '</p>
                <p style="margin:2px 0 0 0; color:#777; font-size:12px;">Date: ' . date('d M, Y', strtotime($order['created_at'])) . '</p>
            </td>
        </tr>
    </table>

    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:48%; vertical-align:top;">
                <div class="info-box">
                    <b style="color:' . $emailColor . '; text-transform:uppercase; font-size:11px; display:block; margin-bottom:6px;">Billed To:</b>
                    <strong>' . e($order['customer_name']) . '</strong><br>
                    <span style="color:#555;">' . e($order['customer_email']) . '</span><br>
                    <span style="color:#555;">' . e($order['customer_phone']) . '</span>
                </div>
            </td>
            <td style="width:4%;"></td>
            <td style="width:48%; vertical-align:top;">
                <div class="info-box">
                    <b style="color:' . $emailColor . '; text-transform:uppercase; font-size:11px; display:block; margin-bottom:6px;">Shipping Address:</b>
                    <span style="color:#333;">' . nl2br(e($order['address'])) . '</span><br>
                    <strong style="color:' . $emailColor . ';">' . e($order['city']) . '</strong>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Item Description</th>
                <th style="text-align:center; width:50px;">Qty</th>
                <th style="text-align:right; width:100px;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            ' . $pdfRows . '
        </tbody>
    </table>

    <table style="width:100%;">
        <tr>
            <td style="vertical-align:top;">
                <b>Payment Method:</b>
                <p style="margin:4px 0 0 0; font-weight:bold; color:' . $emailColor . ';">Cash on Delivery (COD)</p>
            </td>
            <td style="vertical-align:top; text-align:right;">
                <table class="totals-table">
                    <tr>
                        <td style="text-align:left;">Subtotal:</td>
                        <td style="text-align:right;">' . money_pdf($order['subtotal']) . '</td>
                    </tr>
                    <tr>
                        <td style="text-align:left;">Discount:</td>
                        <td style="text-align:right; color:#c0392b;">-' . money_pdf($order['discount_amount']) . '</td>
                    </tr>
                    <tr>
                        <td style="text-align:left;">Delivery Fee:</td>
                        <td style="text-align:right;">' . money_pdf($order['shipping_amount']) . '</td>
                    </tr>
                    <tr style="border-top:1px solid #ddd; font-weight:bold; font-size:15px;">
                        <td style="text-align:left; padding-top:6px; color:' . $emailColor . ';">Total Due:</td>
                        <td style="text-align:right; padding-top:6px; color:' . $emailColor . ';">' . money_pdf($order['total']) . '</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="footer">
        <p style="margin:0;">' . e($invoiceNote) . '</p>
    </div>
</body>
</html>';

        $pdfBinary = generate_order_pdf_invoice($invoiceHtml);

        $attachmentData = $pdfBinary !== '' ? $pdfBinary : $invoiceHtml;
        $attachmentFile = 'invoice-' . $order['order_number'] . ($pdfBinary !== '' ? '.pdf' : '.html');
        $attachmentMime = $pdfBinary !== '' ? 'application/pdf' : 'text/html';

        $bodyHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 25px; border: 1px solid #e5e5de; border-radius: 10px; background-color: #ffffff;">
            <div style="border-bottom: 2px solid ' . $emailColor . '; padding-bottom: 15px; margin-bottom: 20px;">
                ' . $logoHtml . '
                <h3 style="margin: 8px 0 0 0; color: ' . $emailColor . '; font-size: 20px;">Order Confirmed #' . e($order['order_number']) . '</h3>
            </div>
            
            <p style="font-size:15px; color:#333;">Hello <b>' . e($order['customer_name']) . '</b>,</p>
            <p style="font-size:14px; color:#555; line-height:1.5;">Great news! Your order has been officially <b>confirmed</b> and is now being packaged for dispatch. Your official PDF invoice is attached to this email.</p>
            
            <table style="width:100%; border-collapse:collapse; margin: 20px 0;" cellpadding="0" cellspacing="0">
                <thead>
                    <tr style="background:' . $emailColor . '; color:#ffffff;">
                        <th style="text-align:left; padding:10px; font-size:13px; text-transform:uppercase;">Item Description</th>
                        <th style="text-align:center; padding:10px; font-size:13px; text-transform:uppercase;">Qty</th>
                        <th style="text-align:right; padding:10px; font-size:13px; text-transform:uppercase;">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $emailRows . '
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
            $attachmentData,
            $attachmentFile,
            $attachmentMime
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

if (!function_exists('has_permission')) {
    function has_permission(string $module): bool
    {
        if (is_super_admin()) return true;

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
        $htpasswdPath = dirname(__DIR__) . '/manage_portal_x92/.htpasswd';
        $username = trim($username);

        if (!file_exists($htpasswdPath)) {
            @touch($htpasswdPath);
        }

        $hash = '{SHA}' . base64_encode(sha1($plainPassword, true));
        $newLine = $username . ':' . $hash;

        $rawContent = file_exists($htpasswdPath) ? file_get_contents($htpasswdPath) : '';
        $rawContent = str_replace("\r\n", "\n", $rawContent);
        $lines = array_filter(array_map('trim', explode("\n", $rawContent)), fn($l) => $l !== '');

        $updatedLines = [];
        $userFound = false;

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;
            [$existingUser,] = explode(':', $line, 2);
            $existingUser = trim($existingUser);

            if (strcasecmp($existingUser, $username) === 0) {
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

    $isLoggedInAdmin = !empty($_SESSION['admin_id']);
    $isAdminPreview  = isset($_GET['preview']) || isset($_GET['admin_test']);

    if (!$isAdminRoute && (!$isLoggedInAdmin || !$isAdminPreview)) {
        http_response_code(503);

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

/**
 * Site Analytics & Visitor Tracking
 * Production-hardened storefront visitor tracking (compatible with InfinityFree & Cloudflare)
 */
if (!function_exists('track_storefront_visitor')) {
    function track_storefront_visitor(): void
    {
        // Don't track CLI, cron, or admin panel routes
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (php_sapi_name() === 'cli' || str_contains($uri, '/admin') || str_contains($uri, 'manage_portal')) {
            return;
        }

        // 1. Extract Real Client IP behind InfinityFree / Cloudflare reverse proxies
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }

        $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $referrer = !empty($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 255) : null;
        $pageUrl  = parse_url($uri, PHP_URL_PATH) ?: '/';

        // 2. Device Detection
        $device = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|mobile|iphone)/i', $ua)) {
            $device = 'Mobile';
        }

        // 3. Browser Engine Detection
        $browser = 'Other';
        if (preg_match('/Edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Opera|OPR/i', $ua)) {
            $browser = 'Opera';
        }

        // 4. OS Detection
        $os = 'Other';
        if (preg_match('/windows|win32/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        }

        // 5. Store using PHP's exact localized datetime (Fixes InfinityFree UTC-7 timezone lag)
        $currentAppTime = date('Y-m-d H:i:s');

        try {
            $pdo = db();

            // Session check prevents duplicate spam within 30 seconds without failing on shared proxy IPs
            $sessionHitKey = 'hit_' . md5($pageUrl);
            if (!empty($_SESSION[$sessionHitKey]) && (time() - (int)$_SESSION[$sessionHitKey]) < 30) {
                return;
            }
            $_SESSION[$sessionHitKey] = time();

            $stmt = $pdo->prepare("
                INSERT INTO visitor_logs (ip_address, page_url, user_agent, referrer_url, device_type, browser, os, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ip,
                $pageUrl,
                $ua,
                $referrer,
                $device,
                $browser,
                $os,
                $currentAppTime
            ]);
        } catch (Throwable $e) {
            error_log('Visitor Tracking Log Error: ' . $e->getMessage());
        }
    }
}

// Auto-run on every storefront page load
track_storefront_visitor();

/**
 * Log administrative activity to admin_activity_logs
 */
if (!function_exists('log_admin_activity')) {
    function log_admin_activity(string $module, string $action, string $description): void
    {
        try {
            if (!function_exists('db')) {
                return;
            }

            $adminId = $_SESSION['admin_id'] ?? null;
            $adminName = $_SESSION['admin_name'] ?? null;
            $adminEmail = $_SESSION['admin_email'] ?? null;

            if (!$adminName && $adminId) {
                $stmt = db()->prepare('SELECT name, email FROM admins WHERE id = ?');
                $stmt->execute([$adminId]);
                $u = $stmt->fetch();
                if ($u) {
                    $adminName = $u['name'];
                    $adminEmail = $u['email'];
                }
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $stmt = db()->prepare('
                INSERT INTO admin_activity_logs (admin_id, admin_name, admin_email, module, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $adminId,
                $adminName ?: 'System',
                $adminEmail ?: 'system@internal',
                $module,
                $action,
                $description,
                $ip,
                $ua
            ]);
        } catch (Throwable $e) {
            // Fail silently
        }
    }
}

/**
 * Post an automated balanced double-entry journal to the General Ledger
 */
if (!function_exists('post_journal_entry')) {
    function post_journal_entry(
        string $refType,
        ?int $refId,
        string $entryDate,
        string $memo,
        array $lines,
        ?int $adminId = null
    ): int {
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $l) {
            $totalDebit += (float)($l['debit'] ?? 0);
            $totalCredit += (float)($l['credit'] ?? 0);
        }

        // Enforce strict double-entry balance check (Debits must equal Credits)
        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new RuntimeException("Unbalanced journal entry: Debits (" . money($totalDebit) . ") do not equal Credits (" . money($totalCredit) . ")");
        }

        $pdo = db();
        $inTrans = $pdo->inTransaction();
        if (!$inTrans) $pdo->beginTransaction();

        try {
            $entryNo = 'JRN-' . date('ymd', strtotime($entryDate)) . '-' . strtoupper(bin2hex(random_bytes(3)));

            $jStmt = $pdo->prepare('
                INSERT INTO accounting_journals (entry_number, entry_date, reference_type, reference_id, memo, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $jStmt->execute([$entryNo, $entryDate, $refType, $refId, $memo, $adminId]);
            $journalId = (int)$pdo->lastInsertId();

            $lStmt = $pdo->prepare('
                INSERT INTO accounting_journal_lines (journal_id, account_code, debit, credit, description)
                VALUES (?, ?, ?, ?, ?)
            ');

            foreach ($lines as $l) {
                $lStmt->execute([
                    $journalId,
                    $l['account_code'],
                    max(0.0, (float)($l['debit'] ?? 0)),
                    max(0.0, (float)($l['credit'] ?? 0)),
                    $l['description'] ?? $memo
                ]);
            }

            if (!$inTrans) $pdo->commit();
            return $journalId;
        } catch (Throwable $e) {
            if (!$inTrans) $pdo->rollBack();
            error_log('Accounting Journal Posting Error: ' . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * Automatically post double-entry journals for delivered/fulfilled customer orders
 */
if (!function_exists('post_order_delivery_journals')) {
    function post_order_delivery_journals(int $orderId): void
    {
        try {
            $pdo = db();
            // Verify if already journaled to prevent duplicate ledger postings
            $chk = $pdo->prepare("SELECT id FROM accounting_journals WHERE reference_type = 'order_sale' AND reference_id = ?");
            $chk->execute([$orderId]);
            if ($chk->fetch()) return;

            $s = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $s->execute([$orderId]);
            $order = $s->fetch(PDO::FETCH_ASSOC);
            if (!$order) return;

            // Fetch order items & cost
            $itemsStmt = $pdo->prepare('SELECT quantity, cost_price FROM order_items WHERE order_id = ?');
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $orderCogs = 0.0;
            foreach ($items as $it) {
                $orderCogs += ((float)$it['cost_price'] * (int)$it['quantity']);
            }

            $orderDate  = date('Y-m-d', strtotime($order['created_at']));
            $total      = (float)$order['total'];
            $shipping   = (float)$order['shipping_amount'];
            $prodRev    = max(0.0, $total - $shipping);

            // 1. Revenue Journal: Debit Courier Receivable, Credit Sales & Shipping
            $revLines = [
                ['account_code' => '1030', 'debit' => $total, 'credit' => 0.0, 'description' => "COD Receivable for Order #{$order['order_number']}"],
                ['account_code' => '4010', 'debit' => 0.0, 'credit' => $prodRev, 'description' => "Net Product Sales #{$order['order_number']}"],
            ];
            if ($shipping > 0) {
                $revLines[] = ['account_code' => '4020', 'debit' => 0.0, 'credit' => $shipping, 'description' => "Delivery Fee Collected #{$order['order_number']}"];
            }
            post_journal_entry('order_sale', $orderId, $orderDate, "Customer Order #{$order['order_number']}", $revLines);

            // 2. COGS Journal: Debit COGS Expense, Credit Inventory Asset
            if ($orderCogs > 0) {
                $cogsLines = [
                    ['account_code' => '5010', 'debit' => $orderCogs, 'credit' => 0.0, 'description' => "COGS for Order #{$order['order_number']}"],
                    ['account_code' => '1040', 'debit' => 0.0, 'credit' => $orderCogs, 'description' => "Inventory Relief #{$order['order_number']}"]
                ];
                post_journal_entry('cogs', $orderId, $orderDate, "COGS Recognition #{$order['order_number']}", $cogsLines);
            }
        } catch (Throwable $e) {
            error_log('Automated Order Journal Error: ' . $e->getMessage());
        }
    }
}
