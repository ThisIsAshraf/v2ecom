<?php
require_once __DIR__ . '/app/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    redirect('index.php');
}

// Fetch main product details with category JOIN
$s = db()->prepare('
    SELECT 
        p.*, 
        c.name category_name, 
        c.slug category_slug,
        COALESCE(pi.image_url, p.image_url) AS cover_image
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
    WHERE p.slug = ? AND p.active = 1
');
$s->execute([$slug]);
$product = $s->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found';
    require_once __DIR__ . '/app/header.php';
    echo '<div class="empty"><h2>Product not found</h2><p>The product you are looking for does not exist or has been removed.</p><a class="button" href="' . e(url('index.php')) . '">Return to shop</a></div>';
    require_once __DIR__ . '/app/footer.php';
    exit;
}

// Fetch additional gallery images
$gs = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
$gs->execute([$product['id']]);
$gallery = $gs->fetchAll();

// Fetch size variants with individual stock numbers
$vStmt = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC');
$vStmt->execute([$product['id']]);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

// Map variant stock by size name
$variantMap = [];
foreach ($variants as $v) {
    if (!empty($v['size'])) {
        $variantMap[$v['size']] = [
            'id'    => (int)$v['id'],
            'stock' => (int)$v['stock'],
            'sku'   => $v['sku']
        ];
    }
}

// Resolve available sizes (variants take priority, fallback to product comma-string)
$productSizes = !empty($variantMap)
    ? array_keys($variantMap)
    : array_filter(array_map('trim', explode(',', (string)($product['sizes'] ?? ''))));

// Handle Add to Bag POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    verify_csrf();
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $selectedSize = trim((string)($_POST['size'] ?? ''));

    // Validation: enforce size selection if sizes exist
    if (!empty($productSizes) && $selectedSize === '') {
        flash('error', 'Please choose a size before adding this product to your bag.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    // Determine available stock for the selected size
    if (!empty($variantMap) && isset($variantMap[$selectedSize])) {
        $availableStock = (int)$variantMap[$selectedSize]['stock'];
    } else {
        $availableStock = (int)$product['stock'];
    }

    if ($availableStock <= 0) {
        flash('error', 'Size ' . $selectedSize . ' is currently out of stock.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    if ($qty > $availableStock) {
        flash('error', 'Only ' . $availableStock . ' unit(s) available in stock for Size ' . $selectedSize . '.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // Compound cart key: e.g. "12_M" or "12"
    $cartKey = $selectedSize !== '' ? ($product['id'] . '_' . $selectedSize) : (string)$product['id'];

    if (isset($_SESSION['cart'][$cartKey])) {
        if (is_array($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'product_id' => (int)$product['id'],
                'qty'        => ((int)$_SESSION['cart'][$cartKey]) + $qty,
                'size'       => $selectedSize,
            ];
        }
    } else {
        $_SESSION['cart'][$cartKey] = [
            'product_id' => (int)$product['id'],
            'qty'        => $qty,
            'size'       => $selectedSize,
        ];
    }

    $sizeNote = $selectedSize !== '' ? ' (Size: ' . $selectedSize . ')' : '';
    flash('success', $product['name'] . $sizeNote . ' added to your bag.');
    redirect('cart.php');
}

$pageTitle       = $product['name'] . ' | ' . store_name();
$metaDescription = substr(strip_tags($product['description'] ?? ''), 0, 150);
$ogImage         = $product['cover_image'] ?? $product['image_url'];

require_once __DIR__ . '/app/header.php';

$mainImg = !empty($product['cover_image'])
    ? (str_starts_with($product['cover_image'], 'http') ? $product['cover_image'] : url($product['cover_image']))
    : 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=800';
?>

<div class="product-detail-layout" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px; margin-bottom: 40px;">

    <!-- Left: Gallery -->
    <div class="product-gallery">
        <div class="main-image-wrap" style="background:#fff; border:1px solid var(--line, #eee); border-radius:8px; overflow:hidden; margin-bottom:12px;">
            <img id="mainProductImg" src="<?= e($mainImg) ?>" alt="<?= e($product['name']) ?>" style="width:100%; height:auto; display:block; object-fit:cover; aspect-ratio:1/1;">
        </div>

        <?php if ($gallery): ?>
            <div class="thumbnails" style="display:flex; gap:10px; flex-wrap:wrap;">
                <?php foreach ($gallery as $idx => $g):
                    $gUrl = str_starts_with($g['image_url'], 'http') ? $g['image_url'] : url($g['image_url']);
                ?>
                    <img class="thumb-img <?= $idx === 0 ? 'active' : '' ?>" src="<?= e($gUrl) ?>" onclick="switchImage(this)" style="width:70px; height:70px; object-fit:cover; border-radius:4px; border:<?= $idx === 0 ? '2px solid var(--accent, #333)' : '1px solid #ddd' ?>; cursor:pointer;">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: Information & Ordering -->
    <div class="product-info">
        <?php if ($product['category_name']): ?>
            <div class="eyebrow"><a href="<?= e(url('index.php?category=' . urlencode($product['category_slug']))) ?>" style="text-decoration:none; color:inherit;"><?= e($product['category_name']) ?></a></div>
        <?php endif; ?>

        <h1 style="margin: 6px 0 12px 0; font-size: 32px;"><?= e($product['name']) ?></h1>
        <p class="muted small" style="margin-bottom: 12px;">SKU: <span id="displaySku"><?= e($product['sku']) ?></span></p>

        <div class="price" style="font-size:24px; font-weight:bold; margin-bottom:20px;">
            <span id="displayPrice"><?= money($product['price']) ?></span>
            <?php if (!empty($product['compare_price']) && $product['compare_price'] > $product['price']): ?>
                <span class="old-price" style="text-decoration:line-through; color:#888; font-size:18px; margin-left:8px;"><?= money($product['compare_price']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ((int)$product['stock'] > 0): ?>
            <form method="post" style="margin-bottom:24px; max-width:360px;" id="addToBagForm">
                <?= csrf_field() ?>

                <?php if (!empty($productSizes)): ?>
                    <div class="field" style="margin-bottom:18px;">
                        <label style="font-weight:bold; font-size:13px; display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Select Size <span style="color:#e11d48;">*</span></span>
                            <span id="selectedSizeLabel" style="font-weight:normal; color:#64748b;">Choose a size</span>
                        </label>
                        <input type="hidden" name="size" id="selectedSizeInput" value="" required>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($productSizes as $sz):
                                $szStock = isset($variantMap[$sz]) ? (int)$variantMap[$sz]['stock'] : (int)$product['stock'];
                                $isSoldOut = ($szStock <= 0);
                            ?>
                                <button type="button"
                                    class="size-pill-btn <?= $isSoldOut ? 'disabled-size' : '' ?>"
                                    data-size="<?= e($sz) ?>"
                                    data-stock="<?= $szStock ?>"
                                    <?= $isSoldOut ? 'disabled' : '' ?>
                                    onclick="selectProductSize(this, '<?= e(addslashes($sz)) ?>', <?= $szStock ?>)"
                                    style="background:<?= $isSoldOut ? '#f1f5f9' : '#fff' ?>; color:<?= $isSoldOut ? '#94a3b8' : '#000' ?>; border:1px solid <?= $isSoldOut ? '#e2e8f0' : '#cbd5e1' ?>; border-radius:6px; padding:8px 16px; font-weight:600; font-size:13px; cursor:<?= $isSoldOut ? 'not-allowed' : 'pointer' ?>; min-width:44px; text-align:center; position:relative; text-decoration:<?= $isSoldOut ? 'line-through' : 'none' ?>;">
                                    <?= e($sz) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field" style="margin-bottom:15px;">
                    <label style="font-weight:bold; font-size:13px; display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span>Quantity</span>
                        <span id="stockLimitHint" style="font-weight:normal; font-size:12px; color:#64748b;">In stock: <?= (int)$product['stock'] ?></span>
                    </label>
                    <input type="number" id="qtyInput" name="qty" value="1" min="1" max="<?= (int)$product['stock'] ?>" style="padding:8px; width:100%; border-radius:6px; border:1px solid #ccc;">
                </div>

                <button class="button" name="add_to_cart" value="1" id="addToBagBtn" style="width:100%; padding:12px; font-weight:bold;">Add to Bag</button>
            </form>
        <?php else: ?>
            <div class="notice error" style="margin-bottom:24px;">Out of Stock</div>
        <?php endif; ?>

        <div class="description" style="line-height:1.6; color:#444; border-top:1px solid var(--line, #eee); padding-top:20px;">
            <h3>Product Overview</h3>
            <p style="white-space:pre-wrap;"><?= e($product['description'] ?: 'No description available for this item.') ?></p>
        </div>
    </div>
</div>

<script>
    function switchImage(thumbEl) {
        document.getElementById('mainProductImg').src = thumbEl.src;
        document.querySelectorAll('.thumb-img').forEach(el => {
            el.style.border = '1px solid #ddd';
        });
        thumbEl.style.border = '2px solid var(--accent, #333)';
    }

    function selectProductSize(btnEl, size, stock) {
        document.getElementById('selectedSizeInput').value = size;
        document.getElementById('selectedSizeLabel').textContent = 'Selected: ' + size + ' (' + stock + ' in stock)';

        // Dynamically adjust maximum quantity input based on this size's stock
        const qty = document.getElementById('qtyInput');
        qty.max = stock;
        if (parseInt(qty.value) > stock) {
            qty.value = stock;
        }
        document.getElementById('stockLimitHint').textContent = stock + ' units available in ' + size;

        // Reset and highlight active button
        document.querySelectorAll('.size-pill-btn:not(.disabled-size)').forEach(b => {
            b.style.background = '#fff';
            b.style.color = '#000';
            b.style.borderColor = '#cbd5e1';
        });
        btnEl.style.background = '#0f172a';
        btnEl.style.color = '#ffffff';
        btnEl.style.borderColor = '#0f172a';
    }
</script>

<?php require_once __DIR__ . '/app/footer.php'; ?>