<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('products');

// Standard preset sizes available
$availableSizePresets = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Free Size'];

// Download CSV Sample Import Template
if (isset($_GET['download_template']) && $_GET['download_template'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-import-sample.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'sku', 'category', 'price', 'compare_price', 'stock', 'colors', 'sizes', 'description', 'image_url']);
    fputcsv($out, ['Cotton Oxford Shirt', 'SHIRT-OX-001', 'Men Casual', '1450.00', '1850.00', '25', 'White,Blue,Black', 'S,M,L,XL', 'Premium breathable cotton shirt.', 'https://example.com/shirt.jpg']);
    fputcsv($out, ['Floral Printed Kurti', 'KURTI-FL-002', 'Women Ethnic', '2200.00', '2600.00', '15', 'Red,Green', 'M,L,XL', 'Lightweight 3-piece set with dupatta.', '']);
    fclose($out);
    exit;
}

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'products-export-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    log_admin_activity('products', 'export_csv', 'Exported products inventory catalog to CSV');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product ID', 'Name', 'SKU', 'Category', 'Price', 'Compare-at Price', 'Total Stock', 'Colors', 'Sizes', 'Status', 'Image URL']);

    $exportQuery = 'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.id DESC';
    $exportStmt = db()->query($exportQuery);
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['sku'],
            $row['category_name'] ?? 'Uncategorised',
            $row['price'],
            $row['compare_price'] ?? '0.00',
            $row['stock'],
            $row['colors'] ?? '',
            $row['sizes'] ?? '',
            !empty($row['active']) ? 'Active' : 'Hidden',
            $row['image_url']
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // --- BULK CSV IMPORT HANDLER ---
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid CSV file.');
            redirect('admin/catalog/products');
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            flash('error', 'Invalid file type. Only CSV files (.csv) are supported.');
            redirect('admin/catalog/products');
        }

        $handle = fopen($tmpFile, 'r');
        if (!$handle) {
            flash('error', 'Could not open the uploaded CSV file.');
            redirect('admin/catalog/products');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            flash('error', 'CSV file appears to be empty.');
            redirect('admin/catalog/products');
        }

        $headerMap = [];
        foreach ($headers as $idx => $colName) {
            $key = strtolower(trim($colName));
            $headerMap[$key] = $idx;
        }

        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue;

            $name      = trim((string)($row[$headerMap['name'] ?? 0] ?? ''));
            $sku       = trim((string)($row[$headerMap['sku'] ?? 1] ?? ''));
            $catName   = trim((string)($row[$headerMap['category'] ?? 2] ?? ''));
            $price     = (float)($row[$headerMap['price'] ?? 3] ?? 0);
            $compare   = (float)($row[$headerMap['compare_price'] ?? 4] ?? 0);
            $stock     = max(0, (int)($row[$headerMap['stock'] ?? 5] ?? 0));
            $rowColors = trim((string)($row[$headerMap['colors'] ?? 6] ?? ''));
            $rowSizes  = trim((string)($row[$headerMap['sizes'] ?? 7] ?? ''));
            $desc      = trim((string)($row[$headerMap['description'] ?? 8] ?? ''));
            $imageUrl  = trim((string)($row[$headerMap['image_url'] ?? 9] ?? ''));

            if ($name === '' || $sku === '' || $price < 0) {
                $skipped++;
                continue;
            }

            $catId = null;
            if ($catName !== '') {
                $catSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $catName), '-'));
                $findCat = db()->prepare('SELECT id FROM categories WHERE name = ? OR slug = ?');
                $findCat->execute([$catName, $catSlug]);
                $existingCat = $findCat->fetch();

                if ($existingCat) {
                    $catId = (int)$existingCat['id'];
                } else {
                    $insCat = db()->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
                    $insCat->execute([$catName, $catSlug]);
                    $catId = (int)db()->lastInsertId();
                    log_admin_activity('categories', 'auto_create', "Auto-created category '{$catName}' during bulk CSV import");
                }
            }

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

            $checkProduct = db()->prepare('SELECT id FROM products WHERE sku = ?');
            $checkProduct->execute([$sku]);
            $existingProduct = $checkProduct->fetch();

            if ($existingProduct) {
                $up = db()->prepare('UPDATE products SET category_id=?, name=?, slug=?, description=?, price=?, compare_price=?, image_url=?, stock=?, colors=?, sizes=? WHERE id=?');
                $up->execute([$catId, $name, $slug, $desc, $price, $compare ?: null, $imageUrl, $stock, $rowColors, $rowSizes, $existingProduct['id']]);
                $updated++;
            } else {
                $ins = db()->prepare('INSERT INTO products (category_id, name, slug, sku, description, price, compare_price, image_url, stock, colors, sizes, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                $ins->execute([$catId, $name, $slug, $sku, $desc, $price, $compare ?: null, $imageUrl, $stock, $rowColors, $rowSizes]);
                $imported++;
            }
        }
        fclose($handle);

        log_admin_activity('products', 'import_csv', "Bulk CSV Import: {$imported} new products inserted, {$updated} updated, {$skipped} skipped");
        flash('success', "Bulk Import Complete: $imported new products inserted, $updated existing updated, $skipped skipped.");
        redirect('admin/catalog/products');
    }

    // --- GALLERY IMAGE DELETION (Invoked outside main form) ---
    if ($action === 'delete_gallery_image') {
        $imgId = (int)($_POST['image_id'] ?? 0);
        $prodId = (int)($_POST['product_id'] ?? 0);

        $s = db()->prepare('SELECT image_url FROM product_images WHERE id=?');
        $s->execute([$imgId]);
        $img = $s->fetch();
        if ($img) {
            $filePath = dirname(__DIR__, 2) . '/' . $img['image_url'];
            if (is_file($filePath)) @unlink($filePath);
            db()->prepare('DELETE FROM product_images WHERE id=?')->execute([$imgId]);

            log_admin_activity('products', 'delete_gallery_image', "Deleted gallery image ID #{$imgId} from Product ID #{$prodId}");
            flash('success', 'Gallery image deleted.');
        }
        redirect('admin/catalog/products?edit=' . $prodId);
    }

    // --- SAVE / UPDATE PRODUCT ---
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        $slug = $slug ?: strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        // Handle Color & Size Multi-Variant Matrix
        $rawColorsInput = trim((string)($_POST['colors'] ?? ''));
        $activeColorsList = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawColorsInput)))));
        $colorsString = implode(',', $activeColorsList);

        $enabledVariants = $_POST['enabled_variants'] ?? [];
        $variantStockData = $_POST['v_stock'] ?? [];
        $variantSkuData   = $_POST['v_sku'] ?? [];
        $variantPriceData = $_POST['v_price'] ?? [];

        $variantsToSync = [];
        $activeSizesCollector = [];
        $totalVariantStock = 0;

        if (is_array($enabledVariants)) {
            foreach ($enabledVariants as $vKey) {
                $parts = explode('||', (string)$vKey, 2);
                $vColor = trim($parts[0] ?? '');
                $vSize  = trim($parts[1] ?? '');

                if ($vColor === '' && $vSize === '') continue;

                if ($vSize !== '') {
                    $activeSizesCollector[$vSize] = true;
                }

                $vStock = max(0, (int)($variantStockData[$vKey] ?? 0));
                $vPrice = max(0.0, (float)($variantPriceData[$vKey] ?? 0.0));
                $totalVariantStock += $vStock;

                $vSku = trim((string)($variantSkuData[$vKey] ?? ''));
                if ($vSku === '') {
                    $skuSuffix = '';
                    if ($vColor !== '') $skuSuffix .= '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vColor));
                    if ($vSize !== '')  $skuSuffix .= '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vSize));
                    $vSku = $sku . $skuSuffix;
                }

                $variantsToSync[] = [
                    'key'              => $vKey,
                    'color'            => $vColor !== '' ? $vColor : null,
                    'size'             => $vSize !== '' ? $vSize : null,
                    'stock'            => $vStock,
                    'sku'              => $vSku,
                    'additional_price' => $vPrice
                ];
            }
        }

        $sizesString = implode(',', array_keys($activeSizesCollector));

        // Overall stock calculation
        $manualStock = max(0, (int)($_POST['stock'] ?? 0));
        $stock = !empty($variantsToSync) ? $totalVariantStock : $manualStock;

        $cat = (int)($_POST['category_id'] ?? 0) ?: null;
        $newCatName = trim((string)($_POST['new_category'] ?? ''));
        if ($newCatName !== '') {
            $catSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $newCatName), '-'));
            $findCat = db()->prepare('SELECT id FROM categories WHERE name = ? OR slug = ?');
            $findCat->execute([$newCatName, $catSlug]);
            $existingCat = $findCat->fetch();
            if ($existingCat) {
                $cat = (int)$existingCat['id'];
            } else {
                $insCat = db()->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
                $insCat->execute([$newCatName, $catSlug]);
                $cat = (int)db()->lastInsertId();
                log_admin_activity('categories', 'create_category', "Created category '{$newCatName}'");
            }
        }

        $price = (float)($_POST['price'] ?? 0);
        $compare = (float)($_POST['compare_price'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $img = trim((string)($_POST['image_url'] ?? ''));

        // Upload Cover Image
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['image_file']['tmp_name'];
            $fileName = $_FILES['image_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $uploadDir = dirname(__DIR__, 2) . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newFileName = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $newFileName)) {
                    $img = 'uploads/' . $newFileName;
                }
            }
        } elseif ($id > 0 && $img === '') {
            // Retain existing image if not replacing
            $prevImg = db()->prepare('SELECT image_url FROM products WHERE id = ?');
            $prevImg->execute([$id]);
            $img = (string)$prevImg->fetchColumn();
        }

        if ($name === '' || $sku === '' || $price < 0) {
            flash('error', 'Name, SKU and a valid price are required.');
            redirect($id ? ('admin/catalog/products?edit=' . $id) : 'admin/catalog/products');
        }

        try {
            $pdo = db();
            $pdo->beginTransaction();

            if ($id) {
                $s = $pdo->prepare('UPDATE products SET category_id=?,name=?,slug=?,sku=?,description=?,price=?,compare_price=?,image_url=?,stock=?,colors=?,sizes=?,active=? WHERE id=?');
                $s->execute([$cat, $name, $slug, $sku, $desc, $price, $compare ?: null, $img, $stock, $colorsString, $sizesString, $active, $id]);
                $productId = $id;

                log_admin_activity('products', 'update_product', "Updated product '{$name}' (ID: #{$id}, Stock: {$stock})");
            } else {
                $s = $pdo->prepare('INSERT INTO products(category_id,name,slug,sku,description,price,compare_price,image_url,stock,colors,sizes,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$cat, $name, $slug, $sku, $desc, $price, $compare ?: null, $img, $stock, $colorsString, $sizesString, $active]);
                $productId = (int)$pdo->lastInsertId();

                log_admin_activity('products', 'create_product', "Created product '{$name}' (ID: #{$productId}, Stock: {$stock})");
            }

            // Sync Multi-Variant Records into product_variants table
            if (!empty($variantsToSync)) {
                $keepIds = [];

                $findV = $pdo->prepare('
                    SELECT id FROM product_variants 
                    WHERE product_id = ? 
                      AND (color = ? OR (color IS NULL AND ? IS NULL)) 
                      AND (size = ? OR (size IS NULL AND ? IS NULL))
                    LIMIT 1
                ');
                $upV  = $pdo->prepare('UPDATE product_variants SET stock = ?, sku = ?, additional_price = ? WHERE id = ?');
                $insV = $pdo->prepare('INSERT INTO product_variants (product_id, sku, color, size, stock, additional_price) VALUES (?, ?, ?, ?, ?, ?)');

                foreach ($variantsToSync as $vItem) {
                    $findV->execute([$productId, $vItem['color'], $vItem['color'], $vItem['size'], $vItem['size']]);
                    $existingVariantId = $findV->fetchColumn();

                    if ($existingVariantId) {
                        $upV->execute([$vItem['stock'], $vItem['sku'], $vItem['additional_price'], $existingVariantId]);
                        $keepIds[] = (int)$existingVariantId;
                    } else {
                        $insV->execute([$productId, $vItem['sku'], $vItem['color'], $vItem['size'], $vItem['stock'], $vItem['additional_price']]);
                        $keepIds[] = (int)$pdo->lastInsertId();
                    }
                }

                // Delete removed variants
                if (!empty($keepIds)) {
                    $inMarks = implode(',', array_fill(0, count($keepIds), '?'));
                    $delStmt = $pdo->prepare("DELETE FROM product_variants WHERE product_id = ? AND id NOT IN ($inMarks)");
                    $delStmt->execute(array_merge([$productId], $keepIds));
                }
            } else {
                $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
            }

            // Upload Gallery Images
            if (!empty($_FILES['gallery_files']['name'][0])) {
                $uploadDir = dirname(__DIR__, 2) . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                foreach ($_FILES['gallery_files']['tmp_name'] as $k => $tmp) {
                    if ($_FILES['gallery_files']['error'][$k] === UPLOAD_ERR_OK) {
                        $fName = $_FILES['gallery_files']['name'][$k];
                        $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                            $gName = 'gal_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                            if (move_uploaded_file($tmp, $uploadDir . $gName)) {
                                $pdo->prepare('INSERT INTO product_images (product_id, image_url) VALUES (?, ?)')
                                    ->execute([$productId, 'uploads/' . $gName]);
                            }
                        }
                    }
                }
            }

            $pdo->commit();
            flash('success', 'Product and variant matrix saved successfully.');
            redirect('admin/catalog/products?edit=' . $productId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log($e->getMessage());
            flash('error', 'Could not save product: ' . $e->getMessage());
            redirect($id ? ('admin/catalog/products?edit=' . $id) : 'admin/catalog/products');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $checkStmt = db()->prepare('SELECT name, active FROM products WHERE id=?');
        $checkStmt->execute([$id]);
        $prod = $checkStmt->fetch();

        db()->prepare('UPDATE products SET active=1-active WHERE id=?')->execute([$id]);

        if ($prod) {
            $newStatus = empty($prod['active']) ? 'Active (Visible)' : 'Hidden';
            log_admin_activity('products', 'toggle_visibility', "Toggled visibility for '{$prod['name']}' to {$newStatus}");
        }

        flash('success', 'Product visibility updated.');
        redirect('admin/catalog/products');
    }
}

$edit = null;
$galleryImages = [];
$currentVariants = []; // Map "color||size" => [stock, sku, price]

if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM products WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
    if ($edit) {
        $gs = db()->prepare('SELECT * FROM product_images WHERE product_id=? ORDER BY id ASC');
        $gs->execute([$edit['id']]);
        $galleryImages = $gs->fetchAll();

        // Fetch existing variants
        $vStmt = db()->prepare('SELECT * FROM product_variants WHERE product_id=? ORDER BY id ASC');
        $vStmt->execute([$edit['id']]);
        $rows = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $c = trim((string)($r['color'] ?? ''));
            $sz = trim((string)($r['size'] ?? ''));
            $vKey = $c . '||' . $sz;
            $currentVariants[$vKey] = [
                'color'            => $c,
                'size'             => $sz,
                'stock'            => (int)$r['stock'],
                'sku'              => $r['sku'] ?? '',
                'additional_price' => (float)($r['additional_price'] ?? 0)
            ];
        }
    }
}

$cats = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// --- SEARCH & PAGINATION LOGIC ---
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;

$whereSql = '';
$params   = [];

if ($search !== '') {
    $whereSql = 'WHERE p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ?';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term];
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id=p.category_id $whereSql");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalProducts / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$pStmt = db()->prepare("
    SELECT p.*, c.name AS category_name 
    FROM products p 
    LEFT JOIN categories c ON c.id=p.category_id 
    $whereSql 
    ORDER BY p.id DESC 
    LIMIT $limit OFFSET $offset
");
$pStmt->execute($params);
$products = $pStmt->fetchAll();

admin_header('Products & Inventory'); ?>

<!-- Section: Bulk Import Products via CSV -->
<div class="panel" style="margin-bottom:22px; background:#f0fdf4; border:1px solid #bbf7d0;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0; color:#166534;">📦 Bulk Import Products</h3>
            <p class="muted small" style="margin:4px 0 0 0;">Upload a CSV file to add or update multiple products simultaneously.</p>
        </div>
        <a href="<?= e(url('admin/catalog/products?download_template=csv')) ?>" class="button secondary" style="padding:6px 12px; font-size:12px; background:#fff;">📄 Download CSV Template</a>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:15px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">
        <input type="file" name="csv_file" accept=".csv" required style="font-size:13px;">
        <button type="submit" style="background:#15803d; border-color:#166534; padding:8px 16px;">Upload & Import CSV</button>
    </form>
</div>

<!-- Section: Add / Edit Single Product Form -->
<div class="panel" style="margin-bottom:22px">
    <h2><?= $edit ? ('Edit product #' . $edit['id'] . ': ' . e($edit['name'])) : 'Add a product' ?></h2>
    <form method="post" enctype="multipart/form-data" id="productMainForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field"><label>Product name *</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
            <div class="field"><label>Slug (URL)</label><input name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto-generated if blank"></div>
            <div class="field"><label>Base SKU *</label><input name="sku" id="base_sku_input" required value="<?= e($edit['sku'] ?? '') ?>"></div>
            <div class="field">
                <label>Category</label>
                <select name="category_id">
                    <option value="">Uncategorised</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ((int)($edit['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Or Create Category</label><input name="new_category" placeholder="New category name"></div>
            <div class="field"><label>Base Price (৳) *</label><input type="number" min="0" step="0.01" name="price" required value="<?= e((string)($edit['price'] ?? '')) ?>"></div>
            <div class="field"><label>Compare-at price</label><input type="number" min="0" step="0.01" name="compare_price" value="<?= e((string)($edit['compare_price'] ?? '')) ?>"></div>

            <div class="field">
                <label>Standard / Fallback Stock</label>
                <input type="number" min="0" name="stock" id="main_stock_input" value="<?= e((string)($edit['stock'] ?? 0)) ?>">
                <small class="muted" style="display:block; margin-top:3px;">Note: If Color/Size variants below are enabled, this total is calculated automatically from the matrix.</small>
            </div>

            <div class="field"><label>Main Cover Image</label><input type="file" name="image_file" accept="image/*"></div>
            <div class="field"><label>Or Cover Image URL</label><input type="text" name="image_url" value="<?= e($edit['image_url'] ?? '') ?>"></div>
        </div>

        <!-- MULTI-VARIANT: COLORS & SIZES MATRIX BUILDER -->
        <div class="field" style="margin-top: 22px; padding: 20px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0; font-size:16px; color:#0f172a;">🎨 Available Colors & Sizes (Multi-Variant Matrix)</h3>
                    <p class="muted small" style="margin:2px 0 0 0;">Specify colors and sizes to generate individual inventory stock records per variant.</p>
                </div>
            </div>

            <!-- Colors Input -->
            <div style="margin-bottom:15px; background:#fff; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                <label style="font-weight:700; font-size:13px; color:#334155;">Available Colors (Comma-separated)</label>
                <input type="text" id="colorsInput" name="colors" value="<?= e($edit['colors'] ?? '') ?>" placeholder="e.g. Red, Black, Navy Blue, White" style="margin-top:4px; font-size:13px;">
                <small class="muted">Leave blank if this product does not have color options.</small>
            </div>

            <!-- Sizes Selector -->
            <div style="margin-bottom:15px; background:#fff; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                <label style="font-weight:700; font-size:13px; color:#334155; display:block; margin-bottom:6px;">Available Sizes</label>
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;" id="sizeCheckboxesWrap">
                    <?php
                    $existingSizesArray = array_filter(array_map('trim', explode(',', (string)($edit['sizes'] ?? ''))));
                    $allSizesToDisplay = array_unique(array_merge($availableSizePresets, $existingSizesArray));
                    foreach ($allSizesToDisplay as $preset):
                        $isSzChecked = in_array($preset, $existingSizesArray, true);
                    ?>
                        <label style="font-size:13px; background:#f1f5f9; padding:5px 10px; border-radius:4px; border:1px solid #cbd5e1; cursor:pointer;">
                            <input type="checkbox" class="size-preset-cb" value="<?= e($preset) ?>" <?= $isSzChecked ? 'checked' : '' ?> style="margin-right:4px;">
                            <?= e($preset) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:10px; display:flex; gap:8px; align-items:center;">
                    <input type="text" id="customSizeInput" placeholder="Add custom size (e.g. 32, 34, 40)" style="max-width:220px; font-size:12px; padding:5px 8px;">
                    <button type="button" class="button secondary" onclick="addCustomSizeTag()" style="padding:5px 12px; font-size:12px;">+ Add Size</button>
                    <button type="button" class="button" onclick="regenerateVariantMatrix()" style="margin-left:auto; background:#2563eb; padding:6px 14px; font-size:12px;">⚡ Build / Refresh Matrix</button>
                </div>
            </div>

            <!-- Generated Variant Matrix Table -->
            <div class="table-wrap" style="background:#fff; border-radius:6px; border:1px solid #e2e8f0;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;" id="matrixVariantsTable">
                    <thead>
                        <tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:10px 14px; width:60px;">Enable</th>
                            <th style="padding:10px 14px; width:140px;">Color</th>
                            <th style="padding:10px 14px; width:100px;">Size</th>
                            <th style="padding:10px 14px; width:140px;">Stock in Hand</th>
                            <th style="padding:10px 14px; width:140px;">+Price (৳)</th>
                            <th style="padding:10px 14px;">Variant SKU</th>
                        </tr>
                    </thead>
                    <tbody id="matrixTableBody">
                        <?php if (!empty($currentVariants)): ?>
                            <?php foreach ($currentVariants as $vKey => $v): ?>
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:8px 14px; text-align:center;">
                                        <input type="checkbox" name="enabled_variants[]" value="<?= e($vKey) ?>" checked onchange="toggleMatrixRow(this)" style="width:16px; height:16px; cursor:pointer;">
                                    </td>
                                    <td style="padding:8px 14px; font-weight:600; color:#0f172a;">
                                        <?= e($v['color'] !== '' ? $v['color'] : '— (Standard)') ?>
                                    </td>
                                    <td style="padding:8px 14px; font-weight:700; color:#2563eb;">
                                        <?= e($v['size'] !== '' ? $v['size'] : '—') ?>
                                    </td>
                                    <td style="padding:8px 14px;">
                                        <input type="number" min="0" name="v_stock[<?= e($vKey) ?>]" value="<?= $v['stock'] ?>" class="v-input-stock" style="width:90px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;">
                                    </td>
                                    <td style="padding:8px 14px;">
                                        <input type="number" min="0" step="0.01" name="v_price[<?= e($vKey) ?>]" value="<?= $v['additional_price'] ?>" class="v-input-price" style="width:90px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;" placeholder="0.00">
                                    </td>
                                    <td style="padding:8px 14px;">
                                        <input type="text" name="v_sku[<?= e($vKey) ?>]" value="<?= e($v['sku']) ?>" class="v-input-sku" style="max-width:240px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="emptyMatrixNotice">
                                <td colspan="6" style="text-align:center; padding:15px;" class="muted">
                                    No variants configured. Enter colors and select sizes above, then click <b>⚡ Build / Refresh Matrix</b>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="field" style="margin-top: 15px;">
            <label><b>Add Gallery Photos (Select multiple)</b></label>
            <input type="file" name="gallery_files[]" multiple accept="image/*">
        </div>

        <!-- Current Gallery Photos with NON-NESTED Delete Buttons -->
        <?php if ($galleryImages): ?>
            <div style="margin: 15px 0;">
                <label><b>Current Gallery Photos:</b></label>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
                    <?php foreach ($galleryImages as $gi): ?>
                        <div style="position:relative; display:inline-block; border:1px solid #ddd; padding:4px; border-radius:4px; background:#fff;">
                            <img src="<?= e(url($gi['image_url'])) ?>" style="width:70px; height:70px; object-fit:cover; border-radius:3px;">
                            <button type="button" onclick="deleteGalleryPhoto(<?= (int)$gi['id'] ?>, <?= (int)$edit['id'] ?>)" title="Delete image" style="position:absolute; top:-6px; right:-6px; background:#e74c3c; color:#fff; border:none; border-radius:50%; width:20px; height:20px; line-height:18px; font-size:12px; cursor:pointer; padding:0; text-align:center;">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="field"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
        <label><input style="width:auto" type="checkbox" name="active" value="1" <?= (!$edit || !empty($edit['active'])) ? 'checked' : '' ?>> Visible on storefront</label>
        <p>
            <button type="submit" id="mainSaveBtn" style="padding:10px 22px; font-weight:bold; cursor:pointer;">Save product</button>
            <?php if ($edit): ?><a class="button secondary" href="<?= e(url('admin/catalog/products')) ?>">Cancel</a><?php endif; ?>
        </p>
    </form>
</div>

<!-- Standalone Hidden Form for Gallery Image Deletions (OUTSIDE productMainForm) -->
<form id="standaloneGalleryDeleteForm" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_gallery_image">
    <input type="hidden" name="image_id" id="deleteGalleryImageId" value="">
    <input type="hidden" name="product_id" id="deleteGalleryProductId" value="">
</form>

<!-- Section: Inventory Table & Export -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
        <div>
            <h2 style="margin:0;">Inventory (<?= number_format($totalProducts) ?>)</h2>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="get" style="display:flex; gap:6px; margin:0;">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, SKU, category..." style="padding:6px 10px; font-size:13px; border:1px solid #ccc; border-radius:4px; width:220px;">
                <button type="submit" class="button secondary" style="padding:6px 12px; font-size:13px;">Search</button>
                <?php if ($search !== ''): ?>
                    <a href="<?= e(url('admin/catalog/products')) ?>" class="button secondary" style="padding:6px 10px; font-size:13px;">Reset</a>
                <?php endif; ?>
            </form>
            <a href="<?= e(url('admin/catalog/products?export=csv')) ?>" class="button secondary" style="padding:6px 12px; font-size:13px;">📥 Export CSV</a>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product / SKU</th>
                    <th>Category</th>
                    <th>Variants & Stock Breakdown</th>
                    <th>Price</th>
                    <th>Total Stock</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px;" class="muted">No products found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['image_url'])): ?>
                                    <img src="<?= e(url($p['image_url'])) ?>" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                                <?php else: ?>
                                    <span class="muted small">No image</span>
                                <?php endif; ?>
                            </td>
                            <td><b><?= e($p['name']) ?></b><br><span class="muted small"><?= e($p['sku']) ?></span></td>
                            <td><?= e($p['category_name'] ?? '—') ?></td>
                            <td>
                                <?php
                                $pvList = db()->prepare('SELECT color, size, stock FROM product_variants WHERE product_id = ? ORDER BY id ASC');
                                $pvList->execute([$p['id']]);
                                $varRows = $pvList->fetchAll(PDO::FETCH_ASSOC);

                                if (!empty($varRows)): ?>
                                    <div style="display:flex; gap:4px; flex-wrap:wrap; max-width:280px;">
                                        <?php foreach ($varRows as $vr):
                                            $badgeColor = ((int)$vr['stock'] <= 0) ? '#fee2e2; color:#b91c1c;' : '#e2e8f0; color:#1e293b;';
                                            $labelParts = [];
                                            if (!empty($vr['color'])) $labelParts[] = $vr['color'];
                                            if (!empty($vr['size']))  $labelParts[] = $vr['size'];
                                            $vLabel = implode(' - ', $labelParts);
                                        ?>
                                            <span style="display:inline-block; background:<?= $badgeColor ?>; font-size:11px; font-weight:700; padding:2px 6px; border-radius:4px; margin:1px;">
                                                <?= e($vLabel ?: 'Base') ?>: <?= (int)$vr['stock'] ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif (!empty($p['sizes']) || !empty($p['colors'])): ?>
                                    <span class="muted small">Sizes: <?= e($p['sizes'] ?: '—') ?> | Colors: <?= e($p['colors'] ?: '—') ?></span>
                                <?php else: ?>
                                    <span class="muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= money($p['price']) ?></td>
                            <td><span class="<?= ((int)$p['stock'] <= 5) ? 'notice error' : 'status' ?>"><?= (int)$p['stock'] ?></span></td>
                            <td><?= !empty($p['active']) ? 'Active' : 'Hidden' ?></td>
                            <td>
                                <a href="<?= e(url('admin/catalog/products?edit=' . $p['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="button secondary" style="padding:6px 8px"><?= !empty($p['active']) ? 'Hide' : 'Show' ?></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:12px; border-top:1px solid #eee;">
            <span class="small muted">
                Page <b><?= $page ?></b> of <b><?= $totalPages ?></b>
            </span>
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url('admin/catalog/products?page=' . ($page - 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">← Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(url('admin/catalog/products?page=' . ($page + 1) . ($search ? '&q=' . urlencode($search) : ''))) ?>" class="button secondary" style="padding:4px 10px; font-size:12px;">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function deleteGalleryPhoto(imgId, prodId) {
        if (confirm('Delete this gallery photo?')) {
            document.getElementById('deleteGalleryImageId').value = imgId;
            document.getElementById('deleteGalleryProductId').value = prodId;
            document.getElementById('standaloneGalleryDeleteForm').submit();
        }
    }

    function toggleMatrixRow(checkboxEl) {
        const row = checkboxEl.closest('tr');
        const inputs = row.querySelectorAll('.v-input-stock, .v-input-price, .v-input-sku');
        inputs.forEach(input => {
            input.disabled = !checkboxEl.checked;
            if (checkboxEl.checked && input.classList.contains('v-input-stock') && !input.value) {
                input.value = '10';
            }
        });
    }

    function addCustomSizeTag() {
        const inp = document.getElementById('customSizeInput');
        const val = inp.value.trim().toUpperCase();
        if (!val) return;

        const wrap = document.getElementById('sizeCheckboxesWrap');
        const existing = Array.from(wrap.querySelectorAll('.size-preset-cb')).some(cb => cb.value.toUpperCase() === val);
        if (existing) {
            alert('Size ' + val + ' already exists.');
            return;
        }

        const label = document.createElement('label');
        label.style.cssText = 'font-size:13px; background:#f1f5f9; padding:5px 10px; border-radius:4px; border:1px solid #cbd5e1; cursor:pointer;';
        label.innerHTML = `<input type="checkbox" class="size-preset-cb" value="${val}" checked style="margin-right:4px;"> ${val}`;
        wrap.appendChild(label);
        inp.value = '';
    }

    function regenerateVariantMatrix() {
        const rawColors = document.getElementById('colorsInput').value.split(',').map(s => s.trim()).filter(Boolean);
        const selectedSizes = Array.from(document.querySelectorAll('.size-preset-cb:checked')).map(cb => cb.value.trim()).filter(Boolean);

        const colors = rawColors.length > 0 ? rawColors : [''];
        const sizes = selectedSizes.length > 0 ? selectedSizes : [''];

        if (colors.length === 1 && colors[0] === '' && sizes.length === 1 && sizes[0] === '') {
            alert('Please specify at least one color or select at least one size.');
            return;
        }

        const baseSku = (document.getElementById('base_sku_input').value || 'SKU').trim();
        const tbody = document.getElementById('matrixTableBody');

        // Preserve existing values entered previously
        const previousData = {};
        tbody.querySelectorAll('tr').forEach(tr => {
            const cb = tr.querySelector('input[type="checkbox"]');
            if (cb) {
                const k = cb.value;
                previousData[k] = {
                    checked: cb.checked,
                    stock: tr.querySelector('.v-input-stock')?.value || '10',
                    price: tr.querySelector('.v-input-price')?.value || '0.00',
                    sku: tr.querySelector('.v-input-sku')?.value || ''
                };
            }
        });

        tbody.innerHTML = '';

        colors.forEach(col => {
            sizes.forEach(sz => {
                const vKey = col + '||' + sz;
                const prev = previousData[vKey] || null;

                let defaultSku = baseSku;
                if (col) defaultSku += '-' + col.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
                if (sz) defaultSku += '-' + sz.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();

                const stockVal = prev ? prev.stock : '10';
                const priceVal = prev ? prev.price : '0.00';
                const skuVal = prev ? prev.sku : defaultSku;
                const isChecked = prev ? prev.checked : true;

                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #f1f5f9';
                tr.innerHTML = `
                    <td style="padding:8px 14px; text-align:center;">
                        <input type="checkbox" name="enabled_variants[]" value="${vKey}" ${isChecked ? 'checked' : ''} onchange="toggleMatrixRow(this)" style="width:16px; height:16px; cursor:pointer;">
                    </td>
                    <td style="padding:8px 14px; font-weight:600; color:#0f172a;">
                        ${col ? col : '<span class="muted">—</span>'}
                    </td>
                    <td style="padding:8px 14px; font-weight:700; color:#2563eb;">
                        ${sz ? sz : '<span class="muted">—</span>'}
                    </td>
                    <td style="padding:8px 14px;">
                        <input type="number" min="0" name="v_stock[${vKey}]" value="${stockVal}" class="v-input-stock" ${!isChecked ? 'disabled' : ''} style="width:90px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;">
                    </td>
                    <td style="padding:8px 14px;">
                        <input type="number" min="0" step="0.01" name="v_price[${vKey}]" value="${priceVal}" class="v-input-price" ${!isChecked ? 'disabled' : ''} style="width:90px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;" placeholder="0.00">
                    </td>
                    <td style="padding:8px 14px;">
                        <input type="text" name="v_sku[${vKey}]" value="${skuVal}" class="v-input-sku" ${!isChecked ? 'disabled' : ''} style="max-width:240px; padding:5px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;">
                    </td>
                `;
                tbody.appendChild(tr);
            });
        });
    }
</script>

<?php admin_footer(); ?>