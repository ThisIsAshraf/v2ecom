<?php
require_once __DIR__ . '/app/bootstrap.php';

// Helper to resolve cart items regardless of session storage format
function get_detailed_cart_items(): array
{
    $cart = $_SESSION['cart'] ?? [];
    if (!is_array($cart) || empty($cart)) {
        return [];
    }

    $productIds = [];
    $rawItems = [];

    foreach ($cart as $key => $item) {
        $pId = 0;
        $qty = 0;
        $size = '';

        if (is_array($item)) {
            $pId = (int)($item['product_id'] ?? 0);
            $qty = max(0, (int)($item['qty'] ?? 0));
            $size = trim((string)($item['size'] ?? ''));
        } else {
            // Legacy format fallback: key is product_id, value is qty
            $pId = (int)$key;
            $qty = max(0, (int)$item);
        }

        if ($pId > 0 && $qty > 0) {
            $productIds[] = $pId;
            $rawItems[] = [
                'cart_key'   => (string)$key,
                'product_id' => $pId,
                'qty'        => $qty,
                'size'       => $size
            ];
        }
    }

    if (empty($productIds)) {
        return [];
    }

    $productIds = array_unique($productIds);
    $inMarks = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare("
        SELECT p.*, COALESCE(pi.image_url, p.image_url) AS cover_image 
        FROM products p 
        LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1 
        WHERE p.id IN ($inMarks)
    ");
    $stmt->execute(array_values($productIds));
    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $prodMap = [];
    foreach ($prods as $p) {
        $prodMap[$p['id']] = $p;
    }

    $items = [];
    foreach ($rawItems as $r) {
        $pId = $r['product_id'];
        if (!isset($prodMap[$pId])) {
            continue;
        }

        $p = $prodMap[$pId];
        $price = (float)$p['price'];
        $qty = $r['qty'];
        $cover = $p['cover_image'] ?? $p['image_url'];

        $imgUrl = $cover
            ? (str_starts_with($cover, 'http') ? $cover : url($cover))
            : 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=800';

        $items[] = [
            'cart_key'   => $r['cart_key'],
            'id'         => $p['id'],
            'name'       => $p['name'],
            'slug'       => $p['slug'],
            'price'      => $price,
            'qty'        => $qty,
            'stock'      => (int)$p['stock'],
            'size'       => $r['size'],
            'image_url'  => $imgUrl,
            'line_total' => $price * $qty
        ];
    }

    return $items;
}

// Handle Cart Actions (Quantity Updates)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update') {
        foreach (($_POST['qty'] ?? []) as $key => $qty) {
            $key = (string)$key;
            $qty = (int)$qty;

            if ($qty <= 0) {
                unset($_SESSION['cart'][$key]);
            } else {
                if (isset($_SESSION['cart'][$key])) {
                    if (is_array($_SESSION['cart'][$key])) {
                        $pId = (int)($_SESSION['cart'][$key]['product_id'] ?? 0);
                        $s = db()->prepare('SELECT stock FROM products WHERE id = ?');
                        $s->execute([$pId]);
                        $stock = (int)$s->fetchColumn();

                        if ($stock > 0) {
                            $_SESSION['cart'][$key]['qty'] = min($stock, $qty);
                        } else {
                            unset($_SESSION['cart'][$key]);
                        }
                    } else {
                        $pId = (int)$key;
                        $s = db()->prepare('SELECT stock FROM products WHERE id = ?');
                        $s->execute([$pId]);
                        $stock = (int)$s->fetchColumn();

                        if ($stock > 0) {
                            $_SESSION['cart'][$key] = min($stock, $qty);
                        } else {
                            unset($_SESSION['cart'][$key]);
                        }
                    }
                }
            }
        }
        flash('success', 'Bag updated.');
    }
    redirect('cart.php');
}

$items = get_detailed_cart_items();
$subtotal = 0;
foreach ($items as $it) {
    $subtotal += $it['line_total'];
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
                                    <div>
                                        <a href="<?= e(url('products.php?slug=' . urlencode($i['slug']))) ?>" style="text-decoration:none; font-weight:bold; color:inherit; display:block;">
                                            <?= e($i['name']) ?>
                                        </a>
                                        <?php if (!empty($i['size'])): ?>
                                            <span style="display:inline-block; margin-top:4px; background:#0f172a; color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">
                                                Size: <?= e($i['size']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= money($i['price']) ?></td>
                            <td>
                                <input style="width:80px; padding:6px; border-radius:4px; border:1px solid #ccc;" type="number" min="0" max="<?= max(1, (int)$i['stock']) ?>" name="qty[<?= e($i['cart_key']) ?>]" value="<?= (int)$i['qty'] ?>">
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