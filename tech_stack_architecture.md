# DiverseBD — Technical Architecture & Stack Documentation

## 1. Executive Summary
**DiverseBD** is a modern, modular PHP-based eCommerce web application tailored for the Bangladeshi online retail ecosystem. It features a lightweight storefront, a secured administrative back-office portal, and a RESTful API layer for integrating third-party logistics services (e.g., Pathao, Steadfast), CRMs, and mobile applications.

---

## 2. Technology Stack

### 🖥️ Frontend
- **Templating Engine:** Native PHP with modular layout components (`header.php`, `footer.php`, `nav.php`).
- **Styling:** Tailwind CSS (Utility-first framework via CDN / CLI compilation).
- **Interactivity:** Vanilla JavaScript (ES6+ async `fetch` API for dynamic order updates, cart interactions, and Postman API payload testing).
- **Icons & Visuals:** FontAwesome / Heroicons SVG icons.

### ⚙️ Backend Core
- **Runtime Environment:** PHP 8.2+
- **Architecture Pattern:** Custom Lightweight MVC / Modular Procedural Architecture.
- **Database Access:** PHP Data Objects (PDO) with prepared statements to prevent SQL Injection.
- **Environment Management:** Custom zero-dependency `.env` file parser.
- **Routing:** Apache `.htaccess` rewrite rules (clean URLs for storefront and REST API).

### 🗄️ Database & Storage
- **Database Engine:** MySQL 8.0+ / MariaDB 10.4+.
- **Database Charset:** `utf8mb4_unicode_ci` (full support for Bengali script and international characters).
- **Media Storage:** Local file system storage (`/uploads/products/`) with URL path mapping in database.

### 🔌 API & Integration Layer
- **Format:** RESTful JSON (`Content-Type: application/json`).
- **Authentication:** Custom Header-based API Key (`X-API-KEY`) + Secret Token (`X-API-SECRET`) verification.
- **Access Control:** Fine-grained Scope-based authorization (e.g., `products:read`, `orders:read`, `courier:sync`).

### ☁️ Hosting & Production Environment
- **Web Server:** Apache 2.4 / Nginx (via reverse proxy).
- **Live Domain:** `https://diverse.rf.gd` (InfinityFree / Custom Hosting).
- **Timezone:** `Asia/Dhaka` (`GMT+6`).

---

## 3. High-Level System Architecture

```
                                 ┌───────────────────────────────┐
                                 │     Clients / Consumers       │
                                 └──────────────┬────────────────┘
                                                │
                 ┌──────────────────────────────┼──────────────────────────────┐
                 │                              │                              │
                 ▼                              ▼                              ▼
    ┌──────────────────────────┐  ┌──────────────────────────┐  ┌──────────────────────────┐
    │    Public Storefront     │  │     Admin Portal         │  │   Third-Party API Clients│
    │   (https://diverse.rf.gd) │  │  (/manage_portal_x92/)   │  │    (Pathao/CRMs/Postman) │
    └────────────┬─────────────┘  └────────────┬─────────────┘  └────────────┬─────────────┘
                 │                             │                             │
                 │ HTTP (Sessions)             │ HTTP Basic Auth + Sessions  │ Bearer / X-API-KEY
                 ▼                             ▼                             ▼
    ┌──────────────────────────────────────────────────────────────────────────────────────┐
    │                                  Apache Web Server                                   │
    │                              (.htaccess Clean Rewrites)                              │
    └──────────────────────────────────────────┬───────────────────────────────────────────┘
                                               │
                                               ▼
    ┌──────────────────────────────────────────────────────────────────────────────────────┐
    │                                  Application Layer                                   │
    │                                                                                      │
    │    ┌─────────────────────────┐  ┌───────────────────────┐  ┌───────────────────────┐  │
    │    │   app/bootstrap.php     │  │   api/v1/middleware   │  │   Database Wrapper    │  │
    │    │   (Env & Error Handler) │  │  (Auth & Scope Check) │  │       (PDO/db())      │  │
    │    └─────────────────────────┘  └───────────────────────┘  └───────────────────────┘  │
    └──────────────────────────────────────────┬───────────────────────────────────────────┘
                                               │
                                               ▼
    ┌──────────────────────────────────────────────────────────────────────────────────────┐
    │                                MySQL Database Layer                                  │
    │               (users, products, categories, orders, order_items, api_keys)           │
    └──────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Directory & File Structure

```text
htdocs/
├── .htaccess                     # Root rewrite rules, HTTPS forcing, clean API routing
├── .env                          # Production environment variables (Git-ignored)
├── index.php                     # Storefront home page
├── app/
│   ├── bootstrap.php             # Core initialization, .env loader, error hardening
│   └── database.php              # PDO database connection handler
├── api/
│   └── v1/
│       ├── middleware.php        # API key verification & scope authentication handler
│       ├── auth/
│       │   └── login.php         # Customer SSO authentication endpoint
│       ├── courier/
│       │   └── sync.php          # Logistics tracking ID & status sync endpoint
│       ├── customers/
│       │   └── sync.php          # CRM profile lookup endpoint
│       ├── orders/
│       │   └── track.php         # Public/Client order lookup endpoint
│       └── products/
│           └── list.php          # Live product catalog & inventory feed endpoint
├── manage_portal_x92/            # Secured Admin Panel
│   ├── .htaccess                 # HTTP Basic Password Protection & file security
│   ├── index.php                 # Admin dashboard overview
│   ├── orders.php                # Order management & status update interface
│   └── products.php              # Product catalog editor
└── uploads/
    └── products/                 # Uploaded catalog media assets
```

---

## 5. Core Database Schema Overview

### 1. `users` Table
Stores customer accounts, admin staff, and shipping addresses.
- **Fields:** `id`, `name`, `email`, `password_hash`, `phone`, `address`, `city`, `role` (`customer`/`admin`), `status`, `created_at`.

### 2. `categories` Table
Organizes products into logical catalog sections.
- **Fields:** `id`, `name`, `slug`, `created_at`.

### 3. `products` Table
Inventory items available for purchase.
- **Fields:** `id`, `category_id`, `name`, `slug`, `sku`, `description`, `price`, `compare_price`, `image_url`, `stock`, `status`, `created_at`.

### 4. `orders` Table
Master transaction records generated at checkout.
- **Fields:** `id`, `order_number`, `user_id`, `customer_name`, `customer_email`, `customer_phone`, `shipping_address`, `city`, `payment_method`, `payment_status`, `status`, `tracking_number`, `total`, `created_at`.

### 5. `order_items` Table
Line items associated with each order.
- **Fields:** `id`, `order_id`, `product_id`, `product_name`, `price`, `quantity`, `subtotal`.

### 6. `api_keys` Table
Security table managing client integration credentials.
- **Fields:** `id`, `key_name`, `api_key`, `api_secret`, `scopes`, `status`, `created_at`.

---

## 6. Security Architecture & Best Practices

1. **Prepared Statements:** All raw user inputs passed into database queries utilize PDO bound parameters (`$stmt->execute([$param])`), eliminating SQL Injection risks.
2. **Obfuscated Admin Path:** The administrative interface is assigned a randomized directory string (`manage_portal_x92`) and protected by dual-layer security (HTTP Basic Auth via `.htpasswd` + session checks).
3. **API Scope Restrictions:** Requests are checked against explicit scopes defined in `api_keys.scopes` (e.g., `orders:read`, `courier:sync`), preventing key privilege escalation.
4. **Environment Isolation:** Sensitive credentials (database passwords, API secrets) reside in `.env`, which is blocked from HTTP access via `.htaccess`.
5. **Production Error Hardening:** `display_errors` is disabled in `app/bootstrap.php` to prevent sensitive code execution path leaks in production responses.