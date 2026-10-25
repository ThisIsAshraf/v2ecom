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

// Fetch variants with Color & Size matrix details
$vStmt = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC');
$vStmt->execute([$product['id']]);
$variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

// Build structured variant map and gather unique options
$variantMap   = [];
$colorOptions = [];
$sizeOptions  = [];

foreach ($variants as $v) {
    $c  = trim((string)($v['color'] ?? ''));
    $sz = trim((string)($v['size'] ?? ''));
    $key = $c . '||' . $sz;

    if ($c !== '' && !in_array($c, $colorOptions, true)) {
        $colorOptions[] = $c;
    }
    if ($sz !== '' && !in_array($sz, $sizeOptions, true)) {
        $sizeOptions[] = $sz;
    }

    $variantMap[$key] = [
        'id'               => (int)$v['id'],
        'color'            => $c,
        'size'             => $sz,
        'stock'            => (int)$v['stock'],
        'sku'              => $v['sku'] ?: $product['sku'],
        'additional_price' => (float)($v['additional_price'] ?? 0)
    ];
}

// Fallbacks from products table if variants table had no specific colors/sizes defined
if (empty($colorOptions) && !empty($product['colors'])) {
    $colorOptions = array_filter(array_map('trim', explode(',', (string)$product['colors'])));
}
if (empty($sizeOptions) && !empty($product['sizes'])) {
    $sizeOptions = array_filter(array_map('trim', explode(',', (string)$product['sizes'])));
}

// Handle Add to Bag POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    verify_csrf();
    $qty           = max(1, (int)($_POST['qty'] ?? 1));
    $selectedColor = trim((string)($_POST['color'] ?? ''));
    $selectedSize  = trim((string)($_POST['size'] ?? ''));

    // Validation: enforce Color if colors exist
    if (!empty($colorOptions) && $selectedColor === '') {
        flash('error', 'Please select a color option before adding to your bag.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    // Validation: enforce Size if sizes exist
    if (!empty($sizeOptions) && $selectedSize === '') {
        flash('error', 'Please choose a size before adding this product to your bag.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    $variantKey = $selectedColor . '||' . $selectedSize;
    $availableStock = (int)$product['stock'];
    $variantPriceDelta = 0.0;
    $selectedSku = $product['sku'];

    if (!empty($variantMap)) {
        if (isset($variantMap[$variantKey])) {
            $matched = $variantMap[$variantKey];
            $availableStock    = $matched['stock'];
            $variantPriceDelta = $matched['additional_price'];
            $selectedSku       = $matched['sku'];
        } elseif (isset($variantMap['||' . $selectedSize])) {
            $matched = $variantMap['||' . $selectedSize];
            $availableStock    = $matched['stock'];
            $variantPriceDelta = $matched['additional_price'];
            $selectedSku       = $matched['sku'];
        } elseif (isset($variantMap[$selectedColor . '||'])) {
            $matched = $variantMap[$selectedColor . '||'];
            $availableStock    = $matched['stock'];
            $variantPriceDelta = $matched['additional_price'];
            $selectedSku       = $matched['sku'];
        }
    }

    $optionNoteParts = [];
    if ($selectedColor !== '') $optionNoteParts[] = 'Color: ' . $selectedColor;
    if ($selectedSize !== '')  $optionNoteParts[] = 'Size: ' . $selectedSize;
    $optionNote = $optionNoteParts ? ' (' . implode(', ', $optionNoteParts) . ')' : '';

    if ($availableStock <= 0) {
        flash('error', 'The chosen variant' . $optionNote . ' is currently out of stock.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    if ($qty > $availableStock) {
        flash('error', 'Only ' . $availableStock . ' unit(s) available in stock for' . $optionNote . '.');
        redirect('product.php?slug=' . urlencode($slug));
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // Compound cart key: e.g. "12_Red_M", "12_M", or "12"
    $keyParts = [(string)$product['id']];
    if ($selectedColor !== '') $keyParts[] = preg_replace('/[^a-zA-Z0-9]/', '', $selectedColor);
    if ($selectedSize !== '')  $keyParts[] = preg_replace('/[^a-zA-Z0-9]/', '', $selectedSize);
    $cartKey = implode('_', $keyParts);

    $finalUnitPrice = (float)$product['price'] + $variantPriceDelta;

    if (isset($_SESSION['cart'][$cartKey])) {
        if (is_array($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'product_id' => (int)$product['id'],
                'qty'        => ((int)$_SESSION['cart'][$cartKey]) + $qty,
                'color'      => $selectedColor,
                'size'       => $selectedSize,
                'price'      => $finalUnitPrice,
                'sku'        => $selectedSku
            ];
        }
    } else {
        $_SESSION['cart'][$cartKey] = [
            'product_id' => (int)$product['id'],
            'qty'        => $qty,
            'color'      => $selectedColor,
            'size'       => $selectedSize,
            'price'      => $finalUnitPrice,
            'sku'        => $selectedSku
        ];
    }

    flash('success', $product['name'] . $optionNote . ' added to your bag.');
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
            <form method="post" style="margin-bottom:24px; max-width:400px;" id="addToBagForm">
                <?= csrf_field() ?>

                <!-- Color Options -->
                <?php if (!empty($colorOptions)): ?>
                    <div class="field" style="margin-bottom:18px;">
                        <label style="font-weight:bold; font-size:13px; display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Color <span style="color:#e11d48;">*</span></span>
                            <span id="selectedColorLabel" style="font-weight:normal; color:#64748b;">Choose a color</span>
                        </label>
                        <input type="hidden" name="color" id="selectedColorInput" value="" required>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;" id="colorPillsWrap">
                            <?php foreach ($colorOptions as $c): ?>
                                <button type="button"
                                    class="color-pill-btn"
                                    data-color="<?= e($c) ?>"
                                    onclick="selectProductColor(this, '<?= e(addslashes($c)) ?>')"
                                    style="background:#fff; color:#0f172a; border:1px solid #cbd5e1; border-radius:6px; padding:7px 14px; font-weight:600; font-size:13px; cursor:pointer; min-width:48px; text-align:center;">
                                    <?= e($c) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Size Options -->
                <?php if (!empty($sizeOptions)): ?>
                    <div class="field" style="margin-bottom:18px;">
                        <label style="font-weight:bold; font-size:13px; display:flex; justify-content:space-between; margin-bottom:8px;">
                            <span>Select Size <span style="color:#e11d48;">*</span></span>
                            <span id="selectedSizeLabel" style="font-weight:normal; color:#64748b;">Choose a size</span>
                        </label>
                        <input type="hidden" name="size" id="selectedSizeInput" value="" required>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;" id="sizePillsWrap">
                            <?php foreach ($sizeOptions as $sz): ?>
                                <button type="button"
                                    class="size-pill-btn"
                                    data-size="<?= e($sz) ?>"
                                    onclick="selectProductSize(this, '<?= e(addslashes($sz)) ?>')"
                                    style="background:#fff; color:#0f172a; border:1px solid #cbd5e1; border-radius:6px; padding:7px 14px; font-weight:600; font-size:13px; cursor:pointer; min-width:44px; text-align:center;">
                                    <?= e($sz) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field" style="margin-bottom:15px;">
                    <label style="font-weight:bold; font-size:13px; display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span>Quantity</span>
                        <span id="stockLimitHint" style="font-weight:normal; font-size:12px; color:#64748b;">Total in stock: <?= (int)$product['stock'] ?></span>
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
    // Embedded variant data matrix
    const variantMatrix = <?= json_encode($variantMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const basePrice = <?= (float)$product['price'] ?>;
    const baseSku = <?= json_encode($product['sku']) ?>;
    const hasColors = <?= !empty($colorOptions) ? 'true' : 'false' ?>;
    const hasSizes = <?= !empty($sizeOptions) ? 'true' : 'false' ?>;

    let currentColor = '';
    let currentSize = '';

    function switchImage(thumbEl) {
        document.getElementById('mainProductImg').src = thumbEl.src;
        document.querySelectorAll('.thumb-img').forEach(el => {
            el.style.border = '1px solid #ddd';
        });
        thumbEl.style.border = '2px solid var(--accent, #333)';
    }

    function selectProductColor(btnEl, color) {
        currentColor = color;
        document.getElementById('selectedColorInput').value = color;
        document.getElementById('selectedColorLabel').textContent = 'Selected: ' + color;

        // Highlight selected color pill
        document.querySelectorAll('.color-pill-btn').forEach(b => {
            b.style.background = '#fff';
            b.style.color = '#0f172a';
            b.style.borderColor = '#cbd5e1';
        });
        btnEl.style.background = '#0f172a';
        btnEl.style.color = '#fff';
        btnEl.style.borderColor = '#0f172a';

        updateVariantState();
    }

    function selectProductSize(btnEl, size) {
        currentSize = size;
        document.getElementById('selectedSizeInput').value = size;
        document.getElementById('selectedSizeLabel').textContent = 'Selected: ' + size;

        // Highlight selected size pill
        document.querySelectorAll('.size-pill-btn:not(.sold-out)').forEach(b => {
            b.style.background = '#fff';
            b.style.color = '#0f172a';
            b.style.borderColor = '#cbd5e1';
        });
        btnEl.style.background = '#0f172a';
        btnEl.style.color = '#fff';
        btnEl.style.borderColor = '#0f172a';

        updateVariantState();
    }

    function updateVariantState() {
        const vKey = (hasColors ? currentColor : '') + '||' + (hasSizes ? currentSize : '');
        let matched = variantMatrix[vKey] || null;

        // Fallback checks
        if (!matched && currentSize && variantMatrix['||' + currentSize]) {
            matched = variantMatrix['||' + currentSize];
        } else if (!matched && currentColor && variantMatrix[currentColor + '||']) {
            matched = variantMatrix[currentColor + '||'];
        }

        // Cross-update size button availability if color is selected
        if (hasSizes && hasColors && currentColor) {
            document.querySelectorAll('.size-pill-btn').forEach(b => {
                const sz = b.getAttribute('data-size');
                const checkKey = currentColor + '||' + sz;
                const vItem = variantMatrix[checkKey];
                const isOut = vItem && vItem.stock <= 0;

                if (isOut) {
                    b.classList.add('sold-out');
                    b.disabled = true;
                    b.style.background = '#f8fafc';
                    b.style.color = '#94a3b8';
                    b.style.borderColor = '#e2e8f0';
                    b.style.cursor = 'not-allowed';
                    b.style.textDecoration = 'line-through';
                } else {
                    b.classList.remove('sold-out');
                    b.disabled = false;
                    b.style.cursor = 'pointer';
                    b.style.textDecoration = 'none';
                    if (sz !== currentSize) {
                        b.style.background = '#fff';
                        b.style.color = '#0f172a';
                        b.style.borderColor = '#cbd5e1';
                    }
                }
            });
        }

        if (matched) {
            // Update SKU
            document.getElementById('displaySku').textContent = matched.sku || baseSku;

            // Update Price (Base + Additional)
            const finalPrice = basePrice + (parseFloat(matched.additional_price) || 0);
            document.getElementById('displayPrice').textContent = '৳ ' + finalPrice.toFixed(2);

            // Update Stock & Qty Input
            const stock = matched.stock;
            const qty = document.getElementById('qtyInput');
            qty.max = Math.max(1, stock);
            if (parseInt(qty.value) > stock) {
                qty.value = Math.max(1, stock);
            }

            const btn = document.getElementById('addToBagBtn');
            const hint = document.getElementById('stockLimitHint');

            if (stock <= 0) {
                hint.textContent = 'Out of Stock for selected option';
                hint.style.color = '#dc2626';
                btn.disabled = true;
                btn.textContent = 'Variant Sold Out';
                btn.style.opacity = '0.6';
            } else {
                hint.textContent = stock + ' units available in this option';
                hint.style.color = '#64748b';
                btn.disabled = false;
                btn.textContent = 'Add to Bag';
                btn.style.opacity = '1';
            }
        }
    }
</script>

<?php require_once __DIR__ . '/app/footer.php'; ?>