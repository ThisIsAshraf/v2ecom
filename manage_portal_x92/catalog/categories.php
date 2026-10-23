<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('categories');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        $slug = $slug ?: strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        if ($name === '') {
            flash('error', 'Category name is required.');
            redirect('admin/catalog/categories');
        }

        try {
            if ($id) {
                $s = db()->prepare('UPDATE categories SET name=?, slug=? WHERE id=?');
                $s->execute([$name, $slug, $id]);
            } else {
                $s = db()->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
                $s->execute([$name, $slug]);
            }
            flash('success', 'Category saved successfully.');
        } catch (Throwable $e) {
            error_log($e->getMessage());
            flash('error', 'Could not save category. Category name or slug must be unique.');
        }
        redirect('admin/catalog/categories');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Unlink products assigned to this category before removing it
        db()->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);

        flash('success', 'Category deleted. Associated products are now marked as uncategorised.');
        redirect('admin/catalog/categories');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

$categories = db()->query('
    SELECT c.*, COUNT(p.id) AS product_count 
    FROM categories c 
    LEFT JOIN products p ON p.category_id = c.id 
    GROUP BY c.id 
    ORDER BY c.name ASC
')->fetchAll();

admin_header('Category Management'); ?>

<!-- Add / Edit Category Panel -->
<div class="panel" style="margin-bottom: 22px;">
    <h2><?= $edit ? 'Edit Category' : 'Create New Category' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field">
                <label>Category Name *</label>
                <input name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Shirts, Outerwear">
            </div>
            <div class="field">
                <label>Category Slug (URL)</label>
                <input name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto-generated if left blank">
            </div>
        </div>

        <p style="margin-top: 15px;">
            <button class="button">Save Category</button>
            <?php if ($edit): ?>
                <a class="button secondary" href="<?= e(url('admin/catalog/categories')) ?>">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>

<!-- Categories Table -->
<div class="panel">
    <h2>All Categories</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th>URL Slug</th>
                    <th>Assigned Products</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><b><?= e($c['name']) ?></b></td>
                        <td><code>/index.php?category=<?= e($c['slug']) ?></code></td>
                        <td><span class="status" style="padding: 2px 8px; border-radius: 4px; font-weight: bold; background: #e8f8f5; color: #27ae60;"><?= (int)$c['product_count'] ?> items</span></td>
                        <td style="display: flex; gap: 8px; align-items: center;">
                            <a class="button secondary" style="padding: 4px 8px; font-size: 12px;" href="<?= e(url('admin/catalog/categories?edit=' . $c['id'])) ?>">Edit</a>

                            <form method="post" onsubmit="return confirm('Delete category <?= e($c['name']) ?>? Products in this category will become uncategorised.');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="button secondary" style="padding: 4px 8px; font-size: 12px; background: #fde8e8; color: #e74c3c; border-color: #fadbd8;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$categories): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 20px;">No categories created yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>