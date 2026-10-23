# API Production Deployment & Configuration Guide

This guide details the complete step-by-step process for deploying and configuring the **DiverseBD** REST API (`api/v1`) on a production server (such as `https://diverse.rf.gd` or a dedicated Apache/Nginx host).

---

## 1. Environment & Server Configuration (`.env`)

Never hardcode database passwords or sensitive secrets inside your source code. Use environment variables managed via a `.env` file placed at your project root.

### Step 1.1: Create `.env` in your production root directory
Create a file named `.env` in your root web folder (e.g., `htdocs/.env`) with the following contents:

```ini
APP_ENV=production
APP_URL=https://diverse.rf.gd

# Production Database Credentials
DB_HOST=localhost
DB_NAME=your_production_db_name
DB_USER=your_production_db_user
DB_PASS=your_production_db_password

# Security & API Settings
API_KEY_SECRET=generate_a_random_long_secret_key_here
```

### Step 1.2: Protect `.env` from Public Web Access

Ensure your `.htaccess` denies web access to `.env` (configured in Section 3).

---

## 2. Database Schema Setup

Before launching the API, execute these database queries in phpMyAdmin (or your MySQL console) on the production database.

### Step 2.1: API Keys Table (Authentication)

Creates the storage for client API keys and permissions:

```sql
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(100) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `api_secret` VARCHAR(128) DEFAULT NULL,
  `scopes` TEXT NOT NULL,
  `status` ENUM('active', 'revoked') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Step 2.2: Generate Production API Keys

Insert a production API key record into `api_keys`:

```sql
INSERT INTO `api_keys` (`key_name`, `api_key`, `api_secret`, `scopes`, `status`)
VALUES (
  'Production Admin Integration',
  'db_prod_9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c4d',
  'prod_secret_f87cb1e2c37efc7e86af6289d7f66186cef2d95d7fc9af149a4e8e21f0b4e0bc',
  'products:read,orders:read,orders:write,courier:write,customers:read',
  'active'
);
```

### Step 2.3: Order Tracking Schema Update

Ensure the `orders` table includes the courier `tracking_number` column:

```sql
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `tracking_number` VARCHAR(100) NULL AFTER `status`;
```

---

## 3. Server Configuration & URL Rewriting (`.htaccess`)

### Step 3.1: Root Directory `.htaccess` (`htdocs/.htaccess`)

This file handles HTTPS enforcement, security headers, and clean routing so endpoints work without `.php` extensions (e.g., `/api/v1/orders/track`):

```apache
# Protect .env file and sensitive configuration/log files
<FilesMatch "^\.env|\.(log|ini|db|sql|bak)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Ensure default index page loads properly
DirectoryIndex index.php

# Prevent directory listing
Options -Indexes

# Upgrade HTTP asset requests to HTTPS automatically
<IfModule mod_headers.c>
    Header set Content-Security-Policy "upgrade-insecure-requests;"
</IfModule>

# Force HTTPS redirect & handle API URL rewriting
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # 1. Force HTTPS Redirect
    RewriteCond %{HTTP:X-Forwarded-Proto} !https
    RewriteCond %{HTTPS} !=on
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # 2. Clean API Routing for /api/v1/
    # Routes requests like https://diverse.rf.gd/api/v1/orders/track -> api/v1/orders/track.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^api/v1/([^/]+)/?$ api/v1/$1.php [L,QSA]
</IfModule>

# Timezone Configuration
php_value date.timezone Asia/Dhaka
```

### Step 3.2: Admin Portal `.htaccess` (`htdocs/manage_portal_x92/.htaccess`)

```apache
# 1. HTTP Basic Password Authentication
AuthType Basic
AuthName "Restricted Management Area"
AuthUserFile "/home/vol9_3/epizy.com/epiz_32728908/diverse.rf.gd/htdocs/manage_portal_x92/.htpasswd"
Require valid-user

# 2. Prevent Directory Listing
Options -Indexes

# 3. Ensure index.php loads automatically
DirectoryIndex index.php

# 4. Protect core setup & configuration files
<FilesMatch "^(bootstrap|config|database|header|footer)\.php$">
    Order allow,deny
    Deny from all
</FilesMatch>

# 5. Protect .htpasswd file explicitly
<Files .htpasswd>
    Order allow,deny
    Deny from all
</Files>
```

---

## 4. Error Hardening in PHP (`app/bootstrap.php`)

To prevent PHP stack traces or database errors from leaking to API clients in production, update `app/bootstrap.php`:

```php
<?php
declare(strict_types=1);

// Prevent outputting PHP errors to response body in production
ini_set('display_errors', '0');
error_reporting(E_ALL);

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

## 5. Hosting Platform Notes (InfinityFree / rf.gd Restrictions)

Free hosts like InfinityFree (`rf.gd`) enforce an **AES Cookie Security system** (`__test` cookie via `aes.js`).

- Browser requests complete this JavaScript challenge automatically.
- Non-browser API clients (Postman, cURL, webhooks, mobile apps) will receive HTML containing `aes.js` instead of JSON unless the `__test` cookie header is passed.
- **Production Recommendation:** For external third-party service integration or public API use, host the project on a standard PHP host (e.g., Namecheap, Hostinger, Render, or a VPS) that does not inject anti-bot JavaScript onto API routes.