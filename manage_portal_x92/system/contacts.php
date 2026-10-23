<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('contacts');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'update_status');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'send_reply') {
        $replyMessage = trim((string)($_POST['reply_message'] ?? ''));

        $s = db()->prepare('SELECT * FROM contacts WHERE id=?');
        $s->execute([$id]);
        $msg = $s->fetch();

        if (!$msg || $replyMessage === '') {
            flash('error', 'Reply message cannot be empty.');
            redirect('admin/system/contacts' . ($id ? '?id=' . $id : ''));
        }

        $subject = 'Re: ' . $msg['subject'];
        $emailHtml = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;">
                <p>' . nl2br(e($replyMessage)) . '</p>
                <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                <p style="color: #666; font-size: 13px;"><b>Original Message:</b></p>
                <blockquote style="margin: 0; padding-left: 10px; border-left: 3px solid #ccc; color: #555;">
                    <p><b>From:</b> ' . e($msg['name']) . ' (' . e($msg['email']) . ')<br>
                    <b>Date:</b> ' . e($msg['created_at']) . '</p>
                    <p>' . nl2br(e($msg['message'])) . '</p>
                </blockquote>
            </div>
        ';

        if (send_store_email($msg['email'], $msg['name'], $subject, $emailHtml)) {
            db()->prepare("UPDATE contacts SET status='replied' WHERE id=?")->execute([$id]);
            flash('success', 'Reply sent successfully to ' . $msg['email']);
        } else {
            flash('error', 'Could not send email. Please verify SMTP settings in your .env file.');
        }

        redirect('admin/system/contacts?id=' . $id);
    }

    if ($action === 'update_status') {
        $status = (string)($_POST['status'] ?? 'read');
        if (in_array($status, ['new', 'read', 'replied', 'closed'], true)) {
            db()->prepare('UPDATE contacts SET status=? WHERE id=?')->execute([$status, $id]);
            flash('success', 'Message status updated.');
        }
        redirect('admin/system/contacts' . ($id ? '?id=' . $id : ''));
    }
}

$detail = null;
if (isset($_GET['id'])) {
    $s = db()->prepare('SELECT * FROM contacts WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    $detail = $s->fetch();
    if ($detail && $detail['status'] === 'new') {
        db()->prepare("UPDATE contacts SET status='read' WHERE id=?")->execute([$detail['id']]);
    }
}

$contacts = db()->query('SELECT * FROM contacts ORDER BY id DESC LIMIT 250')->fetchAll();
admin_header('Contact messages'); ?>

<?php if ($detail): ?>
    <div class="panel" style="margin-bottom:20px">
        <div class="section-heading">
            <h2><?= e($detail['subject']) ?></h2>
            <a href="<?= e(url('admin/system/contacts')) ?>">← Back</a>
        </div>
        <p><b><?= e($detail['name']) ?></b> · <a href="mailto:<?= e($detail['email']) ?>"><?= e($detail['email']) ?></a> · <?= e($detail['created_at']) ?></p>

        <div style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 15px 0; border: 1px solid #eee;">
            <p style="white-space:pre-wrap; margin: 0;"><?= e($detail['message']) ?></p>
        </div>

        <form method="post" style="margin-bottom:20px;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $detail['id'] ?>">
            <label>Message status:</label>
            <div style="display:flex; gap:10px; align-items:center; margin-top:5px;">
                <select name="status">
                    <?php foreach (['new', 'read', 'replied', 'closed'] as $st): ?>
                        <option value="<?= $st ?>" <?= $detail['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button secondary">Update status</button>
            </div>
        </form>

        <hr style="border: none; border-top: 1px solid var(--line); margin: 20px 0;">

        <h3>Send direct email reply</h3>
        <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="send_reply">
            <input type="hidden" name="id" value="<?= $detail['id'] ?>">
            <div class="field">
                <label>Reply message</label>
                <textarea name="reply_message" rows="5" required placeholder="Type your reply to <?= e($detail['name']) ?> here..."></textarea>
            </div>
            <button>Send email reply</button>
        </form>
    </div>
<?php endif; ?>

<div class="panel">
    <h2>Inbox</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Received</th>
                    <th>From</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td><?= e($c['created_at']) ?></td>
                        <td><?= e($c['name']) ?><br><span class="muted small"><?= e($c['email']) ?></span></td>
                        <td><?= e($c['subject']) ?></td>
                        <td><span class="status"><?= e($c['status']) ?></span></td>
                        <td><a href="<?= e(url('admin/system/contacts?id=' . $c['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$contacts): ?>
                    <tr>
                        <td colspan="5">No messages yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>