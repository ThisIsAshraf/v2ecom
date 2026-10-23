<?php
require_once __DIR__ . '/app/bootstrap.php';

// Handle Cart Actions (Add to Bag & Quantity Updates)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $id  = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, min(10, (int)($_POST['quantity'] ?? 1)));

        $s = db()->prepare('SELECT stock, active FROM products WHERE id = ?');
        $s->execute([$id]);
        $p = $s->fetch();

        if ($p && $p['active'] && (int)$p['stock'] > 0) {
            $_SESSION['cart'][$id] = min((int)$p['stock'], (int)($_SESSION['cart'][$id] ?? 0) + $qty);
            flash('success', 'Added to your bag.');
        } else {
            flash('error', 'This product is unavailable.');
        }
    } elseif ($action === 'update') {
        foreach (($_POST['qty'] ?? []) as $id => $qty) {
            $id  = (int)$id;
            $qty = (int)$qty;

            if ($qty <= 0) {
                unset($_SESSION['cart'][$id]);
            } else {
                $s = db()->prepare('SELECT stock FROM products WHERE id = ?');
                $s->execute([$id]);
                $stock = (int)$s->fetchColumn();

                if ($stock > 0) {
                    $_SESSION['cart'][$id] = min($stock, $qty);
                } else {
                    unset($_SESSION['cart'][$id]);
                }
            }
        }
        flash('success', 'Bag updated.');
    }
    redirect('cart.php');
}

$items    = cart_items();
$subtotal = cart_subtotal($items);

// Fetch Normalized Cover Images for Cart Items
if ($items) {
    $itemIds = array_column($items, 'id');
    $inMarks = implode(',', array_fill(0, count($itemIds), '?'));

    $imgStmt = db()->prepare("
        SELECT p.id, COALESCE(pi.image_url, p.image_url) AS cover_image 
        FROM products p 
        LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1 
        WHERE p.id IN ($inMarks)
    ");
    $imgStmt->execute($itemIds);
    $imgMap = $imgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($items as &$item) {
        $cover = $imgMap[$item['id']] ?? $item['image_url'];
        if ($cover) {
            $item['image_url'] = str_starts_with($cover, 'http') ? $cover : url($cover);
        } else {
            $item['image_url'] = 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=800';
        }
    }
    unset($item);
}

$pageTitle = 'Your bag';
require_once __DIR__ . '/app/header.php';
?>

<h1 class="page-title">Your bag</h1>
<p class="muted">Review your pieces before checkout.</p>

<?php if (!$items): ?>
    <div class="empty">
        <h2>Your bag is waiting for something good.</h2>
        <a class="button" href="<?= e(url('index.php')) ?>">Continue shopping</a>
    </div>
<?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:12px">
                                    <img src="<?= e($i['image_url']) ?>" alt="<?= e($i['name']) ?>" style="width:65px; height:75px; object-fit:cover; border-radius:4px; border:1px solid #eee;">
                                    <a href="<?= e(url('product.php?slug=' . urlencode($i['slug']))) ?>" style="text-decoration:none; font-weight:bold; color:inherit;">
                                        <?= e($i['name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td><?= money($i['price']) ?></td>
                            <td>
                                <input style="width:80px; padding:6px; border-radius:4px; border:1px solid #ccc;" type="number" min="0" max="<?= max(1, (int)$i['stock']) ?>" name="qty[<?= (int)$i['id'] ?>]" value="<?= (int)$i['qty'] ?>">
                            </td>
                            <td><b><?= money($i['line_total']) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:25px; gap:20px; flex-wrap:wrap">
            <button class="button secondary">Update bag</button>
            <div style="text-align:right">
                <p style="font-size:16px;">Subtotal: <b><?= money($subtotal) ?></b></p>
                <p class="muted small" style="margin-bottom:12px;">Delivery and discounts are calculated at checkout.</p>
                <a class="button" href="<?= e(url('checkout.php')) ?>" style="text-decoration:none;">Continue to checkout →</a>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/app/footer.php'; ?>