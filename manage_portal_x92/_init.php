<?php require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

function admin_header(string $title): void
{ ?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= e($title) ?> · <?= e(store_name()) ?> Admin</title>
        <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
    </head>

    <body class="admin-body">
        <div class="admin-layout">
            <aside class="admin-sidebar">
                <a class="brand" href="<?= e(url('admin/dashboard')) ?>"><?= brand_html() ?></a>

                <a href="<?= e(url('admin/dashboard')) ?>">Dashboard</a>

                <!-- Catalog & Inventory -->
                <a href="<?= e(url('admin/catalog/products')) ?>">Products & inventory</a>
                <a href="<?= e(url('admin/catalog/categories')) ?>">Categories</a>
                <a href="<?= e(url('admin/catalog/stock-logs')) ?>">Stock logs</a>

                <!-- Sales & Customers -->
                <a href="<?= e(url('admin/sales/orders')) ?>">Order management</a>
                <a href="<?= e(url('admin/sales/coupons')) ?>">Discounts & promotions</a>
                <a href="<?= e(url('admin/sales/customers')) ?>">Customers</a>
                <a href="<?= e(url('admin/sales/users')) ?>">Customer Account Management</a>
                <a href="<?= e(url('admin/sales/cities')) ?>">Delivery Cities & Rates</a>

                <!-- Marketing & Content -->
                <a href="<?= e(url('admin/marketing/popup')) ?>">Manage PopUp Banner</a>
                <a href="<?= e(url('admin/marketing/slides')) ?>">Hero Banner Slides</a>
                <a href="<?= e(url('admin/marketing/social')) ?>">Social Media Feed</a>
                <a href="<?= e(url('admin/marketing/testimonials')) ?>">Customer Reviews</a>

                <!-- System, Security & Analytics -->
                <a href="<?= e(url('admin/system/analytics')) ?>">Analytics & Traffic</a>
                <a href="<?= e(url('admin/system/reports')) ?>">Sales reports</a>
                <a href="<?= e(url('admin/system/contacts')) ?>">Contact messages</a>
                <a href="<?= e(url('admin/system/settings')) ?>">Store branding</a>
                <a href="<?= e(url('admin/system/theme')) ?>">Storefront Theme</a>
                <a href="<?= e(url('admin/system/api-keys')) ?>">API Integrations</a>
                <a href="<?= e(url('admin/system/maintenance')) ?>" class="admin-nav-item">Maintenance Mode</a>
                <a href="<?= e(url('admin/system/env')) ?>">ENV Configuration</a>
                <a href="<?= e(url('admin/system/security')) ?>">Admin Security & Sessions</a>

                <!-- Footer External Actions -->
                <a href="<?= e(url('index.php')) ?>" target="_blank">View storefront ↗</a>
                <a href="<?= e(url('admin/logout')) ?>">Sign out</a>
            </aside>

            <section class="admin-main">
                <div class="admin-top">
                    <div>
                        <div class="eyebrow">Store administration</div>
                        <h1 class="page-title" style="font-size:29px"><?= e($title) ?></h1>
                    </div>
                    <span class="small">Signed in as <?= e((string)($_SESSION['admin_name'] ?? 'Admin')) ?></span>
                </div>
                <?php if ($m = flash('success')): ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
                <?php if ($m = flash('error')): ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>
            <?php }

        function admin_footer(): void
        { ?>
            </section>
        </div>
    </body>

    </html>
<?php } ?>