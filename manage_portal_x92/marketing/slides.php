<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('slides');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $subtitle = trim((string)($_POST['subtitle'] ?? ''));
        $eyebrow = trim((string)($_POST['eyebrow'] ?? ''));
        $buttonText = trim((string)($_POST['button_text'] ?? 'Explore Collection'));
        $buttonUrl = trim((string)($_POST['button_url'] ?? 'index.php'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $img = trim((string)($_POST['image_url'] ?? ''));

        $imageUploaded = false;

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['image_file']['tmp_name'];
            $fileName = $_FILES['image_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $uploadDir = dirname(__DIR__, 2) . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newFileName = 'hero_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $newFileName)) {
                    $img = 'uploads/' . $newFileName;
                    $imageUploaded = true;
                }
            }
        }

        if ($title === '') {
            flash('error', 'Slide title is required.');
            redirect('admin/marketing/slides');
        }

        $statusLabel = $active ? 'Visible' : 'Hidden';
        $uploadNote = $imageUploaded ? ' (New image uploaded)' : '';

        if ($id) {
            $s = db()->prepare('UPDATE hero_slides SET title=?, subtitle=?, eyebrow=?, button_text=?, button_url=?, image_url=?, sort_order=?, active=? WHERE id=?');
            $s->execute([$title, $subtitle, $eyebrow, $buttonText, $buttonUrl, $img, $sortOrder, $active, $id]);

            // Audit Logging: Slide Updated
            log_admin_activity(
                'slides',
                'update_slide',
                "Updated hero slide ID #{$id} ('{$title}'): Status: {$statusLabel}, Order: {$sortOrder}, Button: '{$buttonText}' -> '{$buttonUrl}'{$uploadNote}"
            );
        } else {
            $s = db()->prepare('INSERT INTO hero_slides (title, subtitle, eyebrow, button_text, button_url, image_url, sort_order, active) VALUES (?,?,?,?,?,?,?,?)');
            $s->execute([$title, $subtitle, $eyebrow, $buttonText, $buttonUrl, $img, $sortOrder, $active]);
            $newId = (int)db()->lastInsertId();

            // Audit Logging: Slide Created
            log_admin_activity(
                'slides',
                'create_slide',
                "Created new hero slide ID #{$newId} ('{$title}'): Status: {$statusLabel}, Order: {$sortOrder}, Button: '{$buttonText}' -> '{$buttonUrl}'{$uploadNote}"
            );
        }
        flash('success', 'Hero slide saved.');
        redirect('admin/marketing/slides');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = db()->prepare('SELECT title, active FROM hero_slides WHERE id=?');
        $chk->execute([$id]);
        $sl = $chk->fetch();

        if ($sl) {
            db()->prepare('UPDATE hero_slides SET active=1-active WHERE id=?')->execute([$id]);
            $newState = empty($sl['active']) ? 'Active (Visible on homepage)' : 'Hidden';

            // Audit Logging: Slide Status Toggled
            log_admin_activity(
                'slides',
                'toggle_slide_visibility',
                "Toggled hero slide '{$sl['title']}' (ID #{$id}) status to {$newState}"
            );

            flash('success', 'Slide state updated.');
        }
        redirect('admin/marketing/slides');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = db()->prepare('SELECT title, image_url FROM hero_slides WHERE id=?');
        $chk->execute([$id]);
        $sl = $chk->fetch();

        if ($sl) {
            // Delete associated upload if stored locally
            if (!empty($sl['image_url']) && str_starts_with($sl['image_url'], 'uploads/')) {
                $filePath = dirname(__DIR__, 2) . '/' . $sl['image_url'];
                if (is_file($filePath)) @unlink($filePath);
            }

            db()->prepare('DELETE FROM hero_slides WHERE id=?')->execute([$id]);

            // Audit Logging: Slide Deleted
            log_admin_activity(
                'slides',
                'delete_slide',
                "Deleted hero banner slide ID #{$id} ('{$sl['title']}')"
            );

            flash('success', 'Slide deleted.');
        }
        redirect('admin/marketing/slides');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM hero_slides WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

$slides = db()->query('SELECT * FROM hero_slides ORDER BY sort_order ASC, id DESC')->fetchAll();
admin_header('Hero Banner Slides'); ?>

<div class="panel" style="margin-bottom:22px">
    <h2><?= $edit ? 'Edit Slide' : 'Add Hero Banner Slide' ?></h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field"><label>Eyebrow Badge</label><input name="eyebrow" value="<?= e($edit['eyebrow'] ?? '') ?>" placeholder="e.g. SUMMER SALE"></div>
            <div class="field"><label>Slide Title *</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>" placeholder="e.g. Wear what feels like you"></div>
            <div class="field"><label>Button Text</label><input name="button_text" value="<?= e($edit['button_text'] ?? 'Explore Collection') ?>"></div>
            <div class="field"><label>Button Link URL</label><input name="button_url" value="<?= e($edit['button_url'] ?? 'index.php') ?>"></div>
            <div class="field"><label>Banner Image Upload</label><input type="file" name="image_file" accept="image/*"></div>
            <div class="field"><label>Or Image URL</label><input name="image_url" value="<?= e($edit['image_url'] ?? '') ?>" placeholder="uploads/hero.jpg"></div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
        </div>
        <div class="field"><label>Subtitle / Description</label><textarea name="subtitle"><?= e($edit['subtitle'] ?? '') ?></textarea></div>
        <label><input style="width:auto" type="checkbox" name="active" value="1" <?= (!$edit || !empty($edit['active'])) ? 'checked' : '' ?>> Active on homepage</label>
        <p style="margin-top:15px;"><button>Save Slide</button> <?php if ($edit): ?><a class="button secondary" href="<?= e(url('admin/marketing/slides')) ?>">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="panel">
    <h2>All Slides</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Preview</th>
                    <th>Title</th>
                    <th>Eyebrow</th>
                    <th>Link</th>
                    <th>Order</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slides as $sl): ?>
                    <tr>
                        <td>
                            <?php if (!empty($sl['image_url'])): ?>
                                <img src="<?= e(url($sl['image_url'])) ?>" style="width:60px; height:40px; object-fit:cover; border-radius:4px;">
                            <?php else: ?><span class="muted small">Text only</span><?php endif; ?>
                        </td>
                        <td><b><?= e($sl['title']) ?></b></td>
                        <td><?= e($sl['eyebrow'] ?: '—') ?></td>
                        <td><code><?= e($sl['button_url']) ?></code></td>
                        <td><?= (int)$sl['sort_order'] ?></td>
                        <td><?= !empty($sl['active']) ? 'Active' : 'Hidden' ?></td>
                        <td style="display:flex; gap:6px;">
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('admin/marketing/slides?edit=' . $sl['id'])) ?>">Edit</a>
                            <form method="post" style="display:inline; margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $sl['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px;"><?= !empty($sl['active']) ? 'Hide' : 'Show' ?></button></form>
                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete this slide?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $sl['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px; background:#fde8e8; color:#e74c3c;">Delete</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$slides): ?><tr>
                        <td colspan="7">No banner slides created yet. Default banner will be shown.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_footer(); ?>