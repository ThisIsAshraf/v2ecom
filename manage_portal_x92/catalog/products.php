<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('products');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

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
            flash('success', 'Gallery image deleted.');
        }
        redirect('admin/catalog/products?edit=' . $prodId);
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        $slug = $slug ?: strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

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
            }
        }

        $price = (float)($_POST['price'] ?? 0);
        $compare = (float)($_POST['compare_price'] ?? 0);
        $stock = max(0, (int)($_POST['stock'] ?? 0));
        $active = isset($_POST['active']) ? 1 : 0;
        $img = trim((string)($_POST['image_url'] ?? ''));

        // Upload Primary Main Cover Image
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
        }

        if ($name === '' || $sku === '' || $price < 0) {
            flash('error', 'Name, SKU and a valid price are required.');
            redirect('admin/catalog/products');
        }

        try {
            if ($id) {
                $s = db()->prepare('UPDATE products SET category_id=?,name=?,slug=?,sku=?,description=?,price=?,compare_price=?,image_url=?,stock=?,active=? WHERE id=?');
                $s->execute([$cat, $name, $slug, $sku, $desc, $price, $compare ?: null, $img, $stock, $active, $id]);
                $productId = $id;
            } else {
                $s = db()->prepare('INSERT INTO products(category_id,name,slug,sku,description,price,compare_price,image_url,stock,active) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$cat, $name, $slug, $sku, $desc, $price, $compare ?: null, $img, $stock, $active]);
                $productId = (int)db()->lastInsertId();
            }

            // Upload Multiple Gallery Images
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
                                db()->prepare('INSERT INTO product_images (product_id, image_url) VALUES (?, ?)')
                                    ->execute([$productId, 'uploads/' . $gName]);
                            }
                        }
                    }
                }
            }

            flash('success', 'Product saved.');
        } catch (Throwable $e) {
            error_log($e->getMessage());
            flash('error', 'Could not save product.');
        }
        redirect('admin/catalog/products');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE products SET active=1-active WHERE id=?')->execute([$id]);
        flash('success', 'Product visibility updated.');
        redirect('admin/catalog/products');
    }
}

$edit = null;
$galleryImages = [];
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM products WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
    if ($edit) {
        $gs = db()->prepare('SELECT * FROM product_images WHERE product_id=? ORDER BY id ASC');
        $gs->execute([$edit['id']]);
        $galleryImages = $gs->fetchAll();
    }
}

$cats = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$products = db()->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC')->fetchAll();
admin_header('Products & inventory'); ?>

<div class="panel" style="margin-bottom:22px">
    <h2><?= $edit ? 'Edit product' : 'Add a product' ?></h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field"><label>Product name *</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
            <div class="field"><label>Slug (URL)</label><input name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto-generated if blank"></div>
            <div class="field"><label>SKU *</label><input name="sku" required value="<?= e($edit['sku'] ?? '') ?>"></div>
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
            <div class="field"><label>Price *</label><input type="number" min="0" step="0.01" name="price" required value="<?= e((string)($edit['price'] ?? '')) ?>"></div>
            <div class="field"><label>Compare-at price</label><input type="number" min="0" step="0.01" name="compare_price" value="<?= e((string)($edit['compare_price'] ?? '')) ?>"></div>
            <div class="field"><label>Stock quantity</label><input type="number" min="0" name="stock" value="<?= e((string)($edit['stock'] ?? 0)) ?>"></div>
            <div class="field"><label>Main Cover Image</label><input type="file" name="image_file" accept="image/*"></div>
            <div class="field"><label>Or Cover Image URL</label><input type="text" name="image_url" value="<?= e($edit['image_url'] ?? '') ?>"></div>
        </div>

        <div class="field" style="margin-top: 15px;">
            <label><b>Add Gallery Photos (Select multiple)</b></label>
            <input type="file" name="gallery_files[]" multiple accept="image/*">
        </div>

        <?php if ($galleryImages): ?>
            <div style="margin: 15px 0;">
                <label><b>Current Gallery Photos:</b></label>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
                    <?php foreach ($galleryImages as $gi): ?>
                        <div style="position:relative; display:inline-block; border:1px solid #ddd; padding:4px; border-radius:4px; background:#fff;">
                            <img src="<?= e(url($gi['image_url'])) ?>" style="width:70px; height:70px; object-fit:cover; border-radius:3px;">
                            <form method="post" style="position:absolute; top:-6px; right:-6px; margin:0;" onsubmit="return confirm('Delete this gallery photo?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_gallery_image">
                                <input type="hidden" name="image_id" value="<?= $gi['id'] ?>">
                                <input type="hidden" name="product_id" value="<?= $edit['id'] ?>">
                                <button style="background:#e74c3c; color:#fff; border:none; border-radius:50%; width:20px; height:20px; line-height:18px; font-size:12px; cursor:pointer; padding:0; text-align:center;">×</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="field"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
        <label><input style="width:auto" type="checkbox" name="active" value="1" <?= (!$edit || !empty($edit['active'])) ? 'checked' : '' ?>> Visible on storefront</label>
        <p><button>Save product</button> <?php if ($edit): ?><a class="button secondary" href="<?= e(url('admin/catalog/products')) ?>">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="panel">
    <h2>Inventory</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product / SKU</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
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
                        <td><?= money($p['price']) ?></td>
                        <td><span class="<?= ((int)$p['stock'] <= 5) ? 'notice error' : 'status' ?>"><?= (int)$p['stock'] ?></span></td>
                        <td><?= !empty($p['active']) ? 'Active' : 'Hidden' ?></td>
                        <td>
                            <a href="<?= e(url('admin/catalog/products?edit=' . $p['id'])) ?>">Edit</a>
                            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="button secondary" style="padding:6px 8px"><?= !empty($p['active']) ? 'Hide' : 'Show' ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>