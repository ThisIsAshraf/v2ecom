<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('social');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $platform = trim((string)($_POST['platform'] ?? 'Instagram'));
        $title = trim((string)($_POST['title'] ?? ''));
        $postUrl = trim((string)($_POST['post_url'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $img = trim((string)($_POST['image_url'] ?? ''));

        // Handle Image Upload
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['image_file']['tmp_name'];
            $fileName = $_FILES['image_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $uploadDir = dirname(__DIR__, 2) . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newFileName = 'social_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $newFileName)) {
                    $img = 'uploads/' . $newFileName;
                }
            }
        }

        if ($postUrl === '') {
            flash('error', 'Target post or page URL is required.');
            redirect('admin/marketing/social');
        }

        if ($id) {
            $s = db()->prepare('UPDATE social_links SET platform=?, title=?, post_url=?, image_url=?, sort_order=?, active=? WHERE id=?');
            $s->execute([$platform, $title, $postUrl, $img, $sortOrder, $active, $id]);
        } else {
            $s = db()->prepare('INSERT INTO social_links (platform, title, post_url, image_url, sort_order, active) VALUES (?,?,?,?,?,?)');
            $s->execute([$platform, $title, $postUrl, $img, $sortOrder, $active]);
        }
        flash('success', 'Social post saved.');
        redirect('admin/marketing/social');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE social_links SET active=1-active WHERE id=?')->execute([$id]);
        flash('success', 'Post status updated.');
        redirect('admin/marketing/social');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM social_links WHERE id=?')->execute([$id]);
        flash('success', 'Social post deleted.');
        redirect('admin/marketing/social');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM social_links WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

$socialPosts = db()->query('SELECT * FROM social_links ORDER BY sort_order ASC, id DESC')->fetchAll();
admin_header('Social Media Feed'); ?>

<div class="panel" style="margin-bottom:22px">
    <h2><?= $edit ? 'Edit Social Post' : 'Add Social Media Card' ?></h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field">
                <label>Platform *</label>
                <select name="platform">
                    <option value="Instagram" <?= ($edit['platform'] ?? '') === 'Instagram' ? 'selected' : '' ?>>Instagram</option>
                    <option value="Facebook" <?= ($edit['platform'] ?? '') === 'Facebook' ? 'selected' : '' ?>>Facebook</option>
                    <option value="TikTok" <?= ($edit['platform'] ?? '') === 'TikTok' ? 'selected' : '' ?>>TikTok</option>
                    <option value="YouTube" <?= ($edit['platform'] ?? '') === 'YouTube' ? 'selected' : '' ?>>YouTube</option>
                    <option value="WhatsApp" <?= ($edit['platform'] ?? '') === 'WhatsApp' ? 'selected' : '' ?>>WhatsApp</option>
                </select>
            </div>
            <div class="field"><label>Caption / Title (Optional)</label><input name="title" value="<?= e($edit['title'] ?? '') ?>" placeholder="e.g. Summer Drop Highlight"></div>
            <div class="field"><label>Post / Profile URL *</label><input type="url" name="post_url" required value="<?= e($edit['post_url'] ?? '') ?>" placeholder="https://instagram.com/p/..."></div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
            <div class="field"><label>Image Upload</label><input type="file" name="image_file" accept="image/*"></div>
            <div class="field"><label>Or Image URL</label><input name="image_url" value="<?= e($edit['image_url'] ?? '') ?>" placeholder="uploads/social.jpg"></div>
        </div>
        <label><input style="width:auto" type="checkbox" name="active" value="1" <?= (!$edit || !empty($edit['active'])) ? 'checked' : '' ?>> Visible on storefront</label>
        <p style="margin-top:15px;"><button>Save Post</button> <?php if ($edit): ?><a class="button secondary" href="<?= e(url('admin/marketing/social')) ?>">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="panel">
    <h2>Active Social Feed Cards</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Platform</th>
                    <th>Title / Link</th>
                    <th>Order</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($socialPosts as $sp): ?>
                    <tr>
                        <td>
                            <?php if (!empty($sp['image_url'])): ?>
                                <img src="<?= e(url($sp['image_url'])) ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">
                            <?php else: ?><span class="muted small">No Image</span><?php endif; ?>
                        </td>
                        <td><b><?= e($sp['platform']) ?></b></td>
                        <td>
                            <?= e($sp['title'] ?: 'View Post') ?><br>
                            <a href="<?= e($sp['post_url']) ?>" target="_blank" class="muted small"><?= e($sp['post_url']) ?> ↗</a>
                        </td>
                        <td><?= (int)$sp['sort_order'] ?></td>
                        <td><?= !empty($sp['active']) ? 'Active' : 'Hidden' ?></td>
                        <td style="display:flex; gap:6px;">
                            <a class="button secondary" style="padding:4px 8px; font-size:12px;" href="<?= e(url('admin/marketing/social?edit=' . $sp['id'])) ?>">Edit</a>
                            <form method="post" style="display:inline; margin:0;"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $sp['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px;"><?= !empty($sp['active']) ? 'Hide' : 'Show' ?></button></form>
                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Delete this card?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $sp['id'] ?>"><button class="button secondary" style="padding:4px 8px; font-size:12px; background:#fde8e8; color:#e74c3c;">Delete</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$socialPosts): ?><tr>
                        <td colspan="6" style="text-align:center; padding:20px;">No social feed cards added yet.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_footer(); ?>