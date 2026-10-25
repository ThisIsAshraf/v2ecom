# DiverseBD REST API — Production Deployment & Configuration Guide

This guide details the complete step-by-step process for deploying, configuring, and hardening the **DiverseBD** REST API (`api/v1`) on a production server (Apache/cPanel, VPS, or Nginx).

---

## 📋 Pre-Deployment Checklist

Before taking the API live, verify:

- [ ] Database credentials and encryption keys are isolated in `.env` (never committed to git).
- [ ] Database tables (`api_keys`, `orders`, `product_variants`, `shipping_cities`, `customer_addresses`) reflect the latest schema.
- [ ] Apache/Nginx URL rewrite rules forward clean `/api/v1/...` routes to PHP scripts.
- [ ] Authorization headers (`X-API-KEY`, `Authorization`) are preserved through Apache/Nginx FastCGI.
- [ ] PHP errors are logged privately and suppressed from HTTP responses.

---

## 1. Environment & Server Configuration (`.env`)

Never hardcode database passwords or sensitive credentials inside your source code.

### Step 1.1: Create `.env` in the Production Root

Create `.env` in your root web directory (e.g., `/home/username/public_html/.env` or `/var/www/html/.env`):

```ini
APP_ENV=production
APP_URL=https://diversebd.com
APP_TIMEZONE=Asia/Dhaka

# Production Database Credentials
DB_HOST=localhost
DB_NAME=diversebd_live
DB_USER=diversebd_dbuser
DB_PASS=YourStrongRandomPassword123!

# Session & API Defaults
API_DEFAULT_RATE_LIMIT=5000
```

### Step 1.2: Protect `.env` File Permissions

Set strict file permissions so only the web server process can read the `.env` file:

```bash
chmod 600 /var/www/html/.env
```

---

## 2. Production Database Setup

Run these migrations in phpMyAdmin or the MySQL command line on your production database.

### Step 2.1: Create / Upgrade `api_keys` Table

Ensure the `api_keys` table has the updated columns for hashed secret tokens, scopes, and daily rolling request counters:

```sql
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(100) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `secret_token` VARCHAR(255) DEFAULT NULL,
  `scopes` TEXT NOT NULL,
  `rate_limit` INT NOT NULL DEFAULT 5000,
  `requests_count` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_api_key` (`api_key`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Step 2.2: Ensure Supporting Core Columns Exist

Make sure your orders and catalog tables contain required tracking and variant structures:

```sql
-- Ensure orders table has tracking_number and normalized address references
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `tracking_number` VARCHAR(100) NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `city_id` INT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `shipping_address_id` INT NULL AFTER `city_id`;

-- Ensure order_items tracks selected variant size and product SKUs
ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `size` VARCHAR(50) NULL AFTER `product_name`,
  ADD COLUMN IF NOT EXISTS `sku` VARCHAR(100) NULL AFTER `size`;
```

### Step 2.3: Generate Master Production Key

Insert an initial master administrator key with full wildcard (`*`) access to bootstrap your integrations:

```sql
INSERT INTO `api_keys` (`key_name`, `api_key`, `secret_token`, `scopes`, `rate_limit`, `status`)
VALUES (
  'Production Master Key',
  'db_prod_master_9a8b7c6d5e4f3a2b1c0d',
  '$2y$10$YourHashedSecretTokenPlaceholderOrNull',
  '["*"]',
  10000,
  'active'
);
```

---

## 3. Web Server Configuration & Rewriting

### Step 3.1: Apache Configuration (`.htaccess`)

Place this `.htaccess` file in your project root (e.g., `public_html/.htaccess`). It:

1. Strips `.php` extensions so endpoints accept clean URLs (e.g., `/api/v1/orders/track` -> `/api/v1/orders/track.php`).
2. Preserves HTTP Authorization and API headers through Apache FastCGI/PHP-FPM.
3. Completely blocks public web browser access to sensitive files (`.env`, `.git`, `.sql`).

```apache
# ----------------------------------------------------------------------
# 1. Block Access to Sensitive Files
# ----------------------------------------------------------------------
<FilesMatch "^\.env|\.(ini|log|sql|db|bak|git)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

Options -Indexes
DirectoryIndex index.php

# ----------------------------------------------------------------------
# 2. Preserve API Authorization Headers across FastCGI / PHP-FPM
# ----------------------------------------------------------------------
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Pass Authorization & Custom API Key Headers
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteRule .* - [E=HTTP_X_API_KEY:%{HTTP:X-API-KEY}]

    # Force HTTPS Redirect
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP:X-Forwarded-Proto} !https
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Clean API URL Routing (Supports /api/v1/products/list and /api/v1/orders/track)
    # 2-segment routing (e.g., /api/v1/products/list -> api/v1/products/list.php)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{DOCUMENT_ROOT}/api/v1/$1/$2.php -f
    RewriteRule ^api/v1/([^/]+)/([^/]+)/?$ api/v1/$1/$2.php [L,QSA]

    # 1-segment routing (e.g., /api/v1/middleware -> api/v1/middleware.php)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{DOCUMENT_ROOT}/api/v1/$1.php -f
    RewriteRule ^api/v1/([^/]+)/?$ api/v1/$1.php [L,QSA]
</IfModule>

# Set Default Server Timezone
<IfModule mod_php.c>
    php_value date.timezone Asia/Dhaka
</IfModule>
```

---

### Step 3.2: Nginx Configuration (If using VPS / LEMP Stack)

If running on Nginx instead of Apache, add these directives to your server block:

```nginx
server {
    listen 443 ssl http2;
    server_name diversebd.com;
    root /var/www/diversebd/public_html;
    index index.php index.html;

    # Protect .env and dotfiles
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Clean API URL Routing
    location /api/v1/ {
        try_files $uri $uri/ $uri.php$is_args$args;
    }

    # Standard PHP-FPM Processing
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Pass API headers explicitly to FastCGI
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_API_KEY $http_x_api_key;
    }
}
```

---

## 4. Production Hardening in `app/bootstrap.php`

Ensure unhandled PHP errors are logged to disk rather than leaked into JSON responses:

```php
<?php
declare(strict_types=1);

// Disable error output in HTTP response bodies
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Ensure private file logging for errors
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php_errors.log');

// Set Application Timezone
date_default_timezone_set('Asia/Dhaka');

// Tiny .env reader
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === "'" && str_ends_with($value, "'")) || ($value[0] === '"' && str_ends_with($value, '"')))) {
            $value = substr($value, 1, -1);
        }
        $_ENV[trim($key)] = $value;
    }
}
```

---

## 5. Webhook Setup for Courier Partners

When integrating couriers like **Steadfast**, **Pathao**, or **RedX**:

1. **Callback URL:** Configure the courier webhook URL to:
   ```text
   https://diversebd.com/api/v1/courier/sync.php
   ```
   _(Or clean URL `https://diversebd.com/api/v1/courier/sync` if URL rewrite is active)._
2. **Authentication Method:**
   - If the courier dashboard supports custom headers, add:
     - Header Name: `X-API-KEY`
     - Header Value: `[Your Generated API Key]`
   - If the courier dashboard only accepts a webhook URL, append the token parameter:
     ```text
     https://diversebd.com/api/v1/courier/sync.php?api_key=db_prod_9a8b7c6d5e4f...
     ```
3. **Automated Return Restocking:** The endpoint automatically detects if the incoming courier status is `returned` or `cancelled`. If so, it restores variant-level inventory in `product_variants` and product-level inventory in `products` automatically.

---

## 6. Hosting Restrictions Notice (InfinityFree / Free Tier rf.gd)

If you are using free hosts such as InfinityFree (`rf.gd`):

- InfinityFree enforces an anti-bot challenge (`aes.js`) that requires a browser engine to generate a cookie (`__test`).
- Non-browser HTTP clients (cURL, Postman, mobile apps, courier webhooks) will receive an HTML/JavaScript challenge instead of JSON responses.
- **Production Solution:** For commercial integrations (mobile apps, POS systems, delivery webhooks), host the application on standard shared hosting (e.g., Hostinger, Namecheap cPanel) or a cloud VPS (e.g., DigitalOcean, Hetzner, AWS) where custom headers and raw HTTP POST requests are unrestricted.

---

## 7. Verifying the Production Deployment

Run this cURL test in your terminal to verify that authentication, routing, and database responses are functioning properly:

```bash
curl -X GET "https://diversebd.com/api/v1/orders/track.php?order_number=DVBD261025EBE936" \
     -H "X-API-KEY: db_prod_master_9a8b7c6d5e4f3a2b1c0d" \
     -H "Accept: application/json"
```

A successful setup returns an HTTP `200 OK` response with the order data, variant sizes, and timeline history.
