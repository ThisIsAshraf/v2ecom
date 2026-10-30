<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('reviews');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['customer_name'] ?? ''));
        $review = trim((string)($_POST['review_text'] ?? ''));
        $rating = min(5, max(1, (int)($_POST['rating'] ?? 5)));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $existingImage = trim((string)($_POST['existing_image'] ?? ''));

        if ($name === '' || $review === '') {
            flash('error', 'Customer name and review text are required.');
            redirect('admin/marketing/testimonials');
        }

        // Optional Image Upload Handling
        $imageUrl = $existingImage;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $uploadDir = dirname(__DIR__, 2) . '/uploads/reviews/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $filename = 'review_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
                    $imageUrl = 'uploads/reviews/' . $filename;
                }
            }
        }

        if ($id) {
            $s = db()->prepare('UPDATE testimonials SET customer_name=?, review_text=?, image_url=?, rating=?, sort_order=?, active=? WHERE id=?');
            $s->execute([$name, $review, $imageUrl ?: null, $rating, $sortOrder, $active, $id]);

            $statusLabel = $active ? 'Visible' : 'Hidden';
            log_admin_activity(
                'reviews',
                'update_testimonial',
                "Updated testimonial ID #{$id} for '{$name}' (Rating: {$rating}★, Status: {$statusLabel}, Order: {$sortOrder})"
            );
        } else {
            $s = db()->prepare('INSERT INTO testimonials (customer_name, review_text, image_url, rating, sort_order, active) VALUES (?,?,?,?,?,?)');
            $s->execute([$name, $review, $imageUrl ?: null, $rating, $sortOrder, $active]);
            $newId = (int)db()->lastInsertId();

            $statusLabel = $active ? 'Visible' : 'Hidden';
            log_admin_activity(
                'reviews',
                'create_testimonial',
                "Created new testimonial ID #{$newId} for '{$name}' (Rating: {$rating}★, Status: {$statusLabel}, Order: {$sortOrder})"
            );
        }
        flash('success', 'Testimonial saved.');
        redirect('admin/marketing/testimonials');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = db()->prepare('SELECT customer_name, active FROM testimonials WHERE id=?');
        $chk->execute([$id]);
        $rv = $chk->fetch();

        if ($rv) {
            db()->prepare('UPDATE testimonials SET active=1-active WHERE id=?')->execute([$id]);
            $newState = empty($rv['active']) ? 'Active (Visible on homepage)' : 'Hidden';

            log_admin_activity(
                'reviews',
                'toggle_testimonial_visibility',
                "Toggled testimonial for '{$rv['customer_name']}' (ID #{$id}) to {$newState}"
            );

            flash('success', 'Testimonial state updated.');
        }
        redirect('admin/marketing/testimonials');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = db()->prepare('SELECT customer_name, rating FROM testimonials WHERE id=?');
        $chk->execute([$id]);
        $rv = $chk->fetch();

        if ($rv) {
            db()->prepare('DELETE FROM testimonials WHERE id=?')->execute([$id]);

            log_admin_activity(
                'reviews',
                'delete_testimonial',
                "Deleted customer testimonial ID #{$id} from '{$rv['customer_name']}' ({$rv['rating']}★)"
            );

            flash('success', 'Testimonial deleted.');
        }
        redirect('admin/marketing/testimonials');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM testimonials WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

$reviews = db()->query('SELECT * FROM testimonials ORDER BY sort_order ASC, id DESC')->fetchAll();
admin_header('Customer Reviews & Testimonials'); ?>

<div class="panel" style="margin-bottom:22px">
    <h2><?= $edit ? 'Edit Review' : 'Add Customer Review' ?></h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <input type="hidden" name="existing_image" value="<?= e($edit['image_url'] ?? '') ?>">

        <div class="form-grid">
            <div class="field"><label>Customer Name *</label><input name="customer_name" required value="<?= e($edit['customer_name'] ?? '') ?>" placeholder="e.g. Tanvir Ahmed"></div>
            <div class="field">
                <label>Star Rating</label>
                <select name="rating">
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?= $r ?>" <?= ((int)($edit['rating'] ?? 5) === $r) ? 'selected' : '' ?>><?= $r ?> Stars (<?= str_repeat('★', $r) ?>)</option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
        </div>

        <div class="field">
            <label>Customer Fit Photo (Optional - Not Mandatory)</label>
            <input type="file" name="image" accept="image/*">
            <?php if (!empty($edit['image_url'])): ?>
                <div style="margin-top:8px;">
                    <img src="<?= e(url($edit['image_url'])) ?>" style="height:60px; border-radius:6px; border:1px solid #ddd;">
                    <span class="muted small">Current Photo</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="field"><label>Review / Feedback *</label><textarea name="review_text" required><?= e($edit['review_text'] ?? '') ?></textarea></div>
        <label><input style="width:auto" type="checkbox" name="active" value="1" <?= (!$edit || !empty($edit['active'])) ? 'checked' : '' ?>> Visible on homepage</label>
        <p style="margin-top:15px;"><button>Save Review</button> <?php if ($edit): ?><a class="button secondary" href="<?= e(url('admin/marketing/testimonials')) ?>">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="panel">
    <h2>All Reviews</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Photo</th>
                    <th>Rating</th>
                    <th>Review</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $rv): ?>
                    <tr>
                        <td><b><?= e($rv['customer_name']) ?></b></td>
                        <td>
                            <?php if (!empty($rv['image_url'])): ?>
                                <img src="<?= e(url($rv['image_url'])) ?>" style="width:40px; height:40px; object-fit:cover; border-radius:6px;">
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#f39c12;"><?= str_repeat('★', (int)$rv['rating']) ?></td>
                        <td><?= e($rv['review_text']) ?></td>
                        <td><?= !empty($rv['active']) ? 'Active' : 'Hidden' ?></td>
                        <td style="display:flex; gap:6px;">
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('admin/marketing/testimonials?edit=' . $rv['id'])) ?>">Edit</a>
                            <form method="post" style="display:inline; margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $rv['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px;"><?= !empty($rv['active']) ? 'Hide' : 'Show' ?></button></form>
                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete this review?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rv['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px; background:#fde8e8; color:#e74c3c;">Delete</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reviews): ?><tr>
                        <td colspan="6">No testimonials created yet.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_footer(); ?>