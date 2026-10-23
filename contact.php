<?php
require_once __DIR__ . '/app/bootstrap.php';

$errors = [];

// Handle Contact Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name    = trim((string)($_POST['name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? 'General enquiry'));
    $message = trim((string)($_POST['message'] ?? ''));

    if (mb_strlen($name) < 2) $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (mb_strlen($message) < 10) $errors[] = 'Please add more detail to your message.';

    if (!$errors) {
        $s = db()->prepare('INSERT INTO contacts (name, email, subject, message, status) VALUES (?, ?, ?, ?, ?)');
        $s->execute([$name, $email, substr($subject, 0, 150), $message, 'new']);

        // Determine Admin Notification Recipient
        $adminEmail = trim(site_setting('admin_notification_email', envv('ADMIN_NOTIFICATION_EMAIL', '')));

        if ($adminEmail !== '') {
            $emailBody = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;">
                    <h3 style="color:#2c3e50; margin-top:0;">New Contact Message Received</h3>
                    <p><b>From:</b> ' . e($name) . ' (' . e($email) . ')</p>
                    <p><b>Subject:</b> ' . e($subject) . '</p>
                    <div style="background:#f9f9f9; padding:12px; border-radius:4px; border:1px solid #ddd; margin-top:10px;">
                        ' . nl2br(e($message)) . '
                    </div>
                </div>
            ';
            send_store_email($adminEmail, 'Store Admin', 'New Contact Message: ' . $subject, $emailBody);
        }

        flash('success', 'Thanks for contacting us. Your message has been received.');
        redirect('contact.php');
    }
}

$pageTitle = 'Contact us';
require_once __DIR__ . '/app/header.php';
?>

<h1 class="page-title">We’re here to help.</h1>
<p class="muted">Questions about sizing, delivery, or an order? Send us a message.</p>

<?php if ($errors): ?>
    <div class="notice error"><?= e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="post" class="panel" style="max-width:700px; margin-top:25px;">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field">
            <label>Your name *</label>
            <input name="name" required value="<?= e((string)($_POST['name'] ?? '')) ?>">
        </div>
        <div class="field">
            <label>Email *</label>
            <input type="email" name="email" required value="<?= e((string)($_POST['email'] ?? '')) ?>">
        </div>
    </div>
    <div class="field">
        <label>Subject</label>
        <input name="subject" value="<?= e((string)($_POST['subject'] ?? '')) ?>" placeholder="General enquiry">
    </div>
    <div class="field">
        <label>Message *</label>
        <textarea name="message" required rows="5" placeholder="Type your message here..."><?= e((string)($_POST['message'] ?? '')) ?></textarea>
    </div>
    <button class="button">Send message</button>
</form>

<?php require_once __DIR__ . '/app/footer.php'; ?>