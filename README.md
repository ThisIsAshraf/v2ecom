🛍️ DiverseBD — E-Commerce Storefront & Admin Portal

A full-featured, lightweight PHP & MySQL e-commerce management platform built with custom Role-Based Access Control (RBAC), multi-session management, dynamic theme/typography controls, and order tracking.
🌟 Key Features
🔐 1. Advanced Admin Security & RBAC (manage_portal_x92)

    Central Super Admin Control: Super Admin accounts (is_super_admin = 1) possess unrestricted system privileges and are protected from accidental deletion or modification by secondary staff.

    Granular Staff Permissions: Assign secondary admin access to specific operational modules (products, orders, categories, reports, coupons, customers, contacts, settings, theme, slides, social, reviews).

    Session Limiting & Device Monitoring: Real-time signed-in device dashboard with configurable concurrent session caps (persisted in site_settings).

    Automatic Apache .htpasswd Sync: Programmatically updates HTTP Basic Authentication credentials when admin user accounts or passwords are changed.

    Brute-Force Protection: IP-based failure logging that locks out suspicious login attempts after 5 consecutive failures for 15 minutes.

🎨 2. Dynamic Theme & Sectional Typography Engine

    Section-by-Section Typography: Customize font families and font sizes independently for:

        Header Navigation & Announcement Bar

        Hero Banner & Main Headings

        Trust Badges & Featured Categories

        Product Catalog Area

        Social Media Feed Section

        Footer Section & Contact Details

    Google Fonts Integration: Native support for pre-loaded web font presets (Inter, Roboto, Poppins, Montserrat, Playfair Display, System Native).

    One-Click Color Presets: Fast theme switching between Classic Navy, Monochrome Minimal, Midnight Dark, and Warm Terracotta.

📦 3. Storefront & Customer Features

    Catalog & Inventory: Organized product categories, stock tracking, and sale tags.

    Order Tracking & Management: Public order status tracking by order ID and email.

    Responsive Layout: Mobile-ready storefront built with custom CSS variables.

📁 Repository Structure
Plaintext

├── app/
│ ├── bootstrap.php # Core application initialization & helpers
│ ├── db.php # PDO Database connection handler
│ └── header.php # Storefront header layout & dynamic CSS engine
├── assets/
│ └── style.css # Main storefront stylesheet
├── manage_portal_x92/ # Secret Administrative Portal
│ ├── .htaccess # Apache directory security
│ ├── .htpasswd # HTTP basic authentication passwords
│ ├── \_init.php # Admin authentication & RBAC check
│ ├── login.php # Secure admin authentication & rate limiting
│ ├── security.php # Session manager, RBAC & admin user creation
│ ├── theme.php # Theme colors & sectional typography control
│ ├── products.php # Inventory management (RBAC guarded)
│ ├── orders.php # Order processing (RBAC guarded)
│ └── settings.php # Global store configuration
├── index.php # Storefront homepage
├── cart.php # Shopping bag & checkout
├── track-order.php # Public order tracking page
└── README.md # Project documentation

🛠️ Database Setup

Import the following schema structure into your MySQL database:
From DBSchema Folder import the .sql file

🚀 Installation & Local Development

    Clone the Repository:
    Bash

    git clone https://github.com/your-username/diversebd.git
    cd diversebd

    Configure Database Connection:
    Update your database credentials in app/db.php:
    PHP

    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'diversebd');
    define('DB_USER', 'root');
    define('DB_PASS', '');

    Set Up Initial Super Admin:
    Execute a query to insert your primary administrator:
    SQL

    INSERT INTO `admins` (`name`, `email`, `password_hash`, `role`, `is_super_admin`)
    VALUES ('Super Admin', 'admin@store.com', '$2y$10$YourHashedPasswordHere', 'Central Administrator', 1);

    Verify Apache File Permissions:
    Ensure manage_portal_x92/.htpasswd is writable by PHP process (chmod 664 manage_portal_x92/.htpasswd).

⚙️ Security Guidelines

    Portal Renaming: Rename manage_portal_x92 to a unique directory name prior to live deployment.

    HTTPS Enforcement: Always run under SSL/TLS in production to secure session cookies and HTTP Basic Auth headers.

    Session Persistence: Modify max_admin_sessions directly in security.php or site_settings table to enforce concurrency caps across your team.

## 🔌 REST API Documentation (`/api/v1`)

The platform features a secure, scoped RESTful API for third-party integrations (mobile apps, courier partners, external CRMs, and Single Sign-On).

### Authentication & Headers

All requests must include a valid API key via the `X-API-KEY` header or a `Bearer` token in the `Authorization` header:

```http
X-API-KEY: db_your_generated_api_key_here
```
