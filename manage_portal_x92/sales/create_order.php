<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('orders');

$title = 'Create Manual Order (Phone / POS)';

// Fetch cities for delivery fee selection
$cities = db()->query('SELECT * FROM shipping_cities ORDER BY zone_code ASC, city_name ASC')->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active products
$productsQuery = db()->query('
    SELECT id, name, sku, price, cost_price, stock, colors, sizes 
    FROM products 
    WHERE active = 1 
    ORDER BY name ASC
')->fetchAll(PDO::FETCH_ASSOC);

$productsMap = [];
$variantsMap = [];

foreach ($productsQuery as $p) {
    $pId = (int)$p['id'];
    $productsMap[$pId] = [
        'id'         => $pId,
        'name'       => $p['name'],
        'sku'        => $p['sku'],
        'price'      => (float)$p['price'],
        'cost_price' => (float)$p['cost_price'],
        'stock'      => (int)$p['stock']
    ];

    // 1. Base colors & sizes from product record
    $baseColors = array_filter(array_map('trim', explode(',', (string)($p['colors'] ?? ''))));
    $baseSizes  = array_filter(array_map('trim', explode(',', (string)($p['sizes'] ?? ''))));

    // 2. Fetch variants for matrix / pricing additions
    $vStmt = db()->prepare('SELECT color, size, stock, additional_price, sku FROM product_variants WHERE product_id = ?');
    $vStmt->execute([$pId]);
    $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    $matrix = [];
    foreach ($variants as $v) {
        $c = trim((string)($v['color'] ?? ''));
        $s = trim((string)($v['size'] ?? ''));

        if ($c !== '' && !in_array($c, $baseColors, true)) $baseColors[] = $c;
        if ($s !== '' && !in_array($s, $baseSizes, true))  $baseSizes[]  = $s;

        $key = $c . '||' . $s;
        $matrix[$key] = [
            'stock'            => (int)$v['stock'],
            'additional_price' => (float)($v['additional_price'] ?? 0),
            'sku'              => $v['sku'] ?? $p['sku']
        ];
    }

    $variantsMap[$pId] = [
        'colors' => array_values(array_unique($baseColors)),
        'sizes'  => array_values(array_unique($baseSizes)),
        'matrix' => $matrix
    ];
}

// POST HANDLER: Order Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_manual_order') {
    verify_csrf();

    $name     = trim((string)($_POST['customer_name'] ?? ''));
    $email    = trim((string)($_POST['customer_email'] ?? ''));
    $phone    = trim((string)($_POST['customer_phone'] ?? ''));
    $address  = trim((string)($_POST['address'] ?? ''));
    $cityId   = (int)($_POST['city_id'] ?? 0);
    $notes    = trim((string)($_POST['notes'] ?? 'Order placed via phone/manual admin entry'));
    $discount = max(0.0, (float)($_POST['discount_amount'] ?? 0.0));

    $selectedProdIds = $_POST['prod_id'] ?? [];
    $selectedQtys    = $_POST['prod_qty'] ?? [];
    $selectedColors  = $_POST['prod_color'] ?? [];
    $selectedSizes   = $_POST['prod_size'] ?? [];

    $errors = [];
    if (mb_strlen($name) < 2) $errors[] = 'Customer full name is required.';
    if (mb_strlen($phone) < 7) $errors[] = 'A valid phone number is required.';
    if (mb_strlen($address) < 5) $errors[] = 'A valid delivery address is required.';
    if (empty($selectedProdIds)) $errors[] = 'Please select at least one product for the order.';

    // Validate Selected Shipping City
    $cityStmt = db()->prepare('SELECT * FROM shipping_cities WHERE id = ? LIMIT 1');
    $cityStmt->execute([$cityId]);
    $cityObj = $cityStmt->fetch(PDO::FETCH_ASSOC);
    if (!$cityObj) $errors[] = 'Please select a valid delivery city/zone.';

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $subtotal = 0.0;
            $itemsToInsert = [];

            $getProdStmt = $pdo->prepare('SELECT id, name, sku, price, cost_price, stock FROM products WHERE id = ? AND active = 1');
            $getVarStmt  = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? AND (color = ? OR (color IS NULL AND ? = "")) AND (size = ? OR (size IS NULL AND ? = "")) LIMIT 1');

            foreach ($selectedProdIds as $index => $pId) {
                $pId   = (int)$pId;
                $qty   = max(1, (int)($selectedQtys[$index] ?? 1));
                $color = trim((string)($selectedColors[$index] ?? ''));
                $size  = trim((string)($selectedSizes[$index] ?? ''));

                if ($pId <= 0) continue;

                $getProdStmt->execute([$pId]);
                $prod = $getProdStmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new RuntimeException("Product ID #{$pId} is invalid or inactive.");

                $unitPrice = (float)$prod['price'];
                $costPrice = (float)$prod['cost_price'];
                $unitSku   = $prod['sku'];

                // Variant Check
                if ($color !== '' || $size !== '') {
                    $getVarStmt->execute([$pId, $color, $color, $size, $size]);
                    $var = $getVarStmt->fetch(PDO::FETCH_ASSOC);
                    if ($var) {
                        $unitPrice += (float)($var['additional_price'] ?? 0);
                        if (!empty($var['sku'])) $unitSku = $var['sku'];
                    }
                }

                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $itemsToInsert[] = [
                    'product_id'   => $pId,
                    'product_name' => $prod['name'],
                    'color'        => $color !== '' ? $color : null,
                    'size'         => $size !== '' ? $size : null,
                    'sku'          => $unitSku,
                    'qty'          => $qty,
                    'unit_price'   => $unitPrice,
                    'cost_price'   => $costPrice,
                    'line_total'   => $lineTotal
                ];
            }

            $shipping = (float)$cityObj['shipping_cost'];
            $total    = max(0.0, ($subtotal - $discount)) + $shipping;
            $orderNo  = 'DVBD' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
            $tracking = 'TRK-' . strtoupper(bin2hex(random_bytes(4)));

            // 1. Insert Master Order Record with status 'pending'
            $insOrder = $pdo->prepare('
                INSERT INTO orders (
                    user_id, order_number, customer_name, customer_email, customer_phone, 
                    address, city, city_id, notes, payment_method, status, 
                    subtotal, discount_amount, shipping_amount, total, tracking_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insOrder->execute([
                null,
                $orderNo,
                $name,
                $email,
                $phone,
                $address,
                $cityObj['city_name'],
                $cityId,
                $notes,
                'COD',
                'pending',
                $subtotal,
                $discount,
                $shipping,
                $total,
                $tracking
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // 2. Insert Line Items & Deduct Stock
            $insItem     = $pdo->prepare('INSERT INTO order_items (order_id, product_id, color, size, product_name, sku, quantity, unit_price, cost_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stockDeduct = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
            $varDeduct   = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE product_id = ? AND (color = ? OR (color IS NULL AND ? = "")) AND (size = ? OR (size IS NULL AND ? = ""))');

            foreach ($itemsToInsert as $item) {
                $insItem->execute([
                    $orderId,
                    $item['product_id'],
                    $item['color'],
                    $item['size'],
                    $item['product_name'],
                    $item['sku'],
                    $item['qty'],
                    $item['unit_price'],
                    $item['cost_price'],
                    $item['line_total']
                ]);

                $stockDeduct->execute([$item['qty'], $item['product_id']]);
                if ($item['color'] || $item['size']) {
                    $varDeduct->execute([$item['qty'], $item['product_id'], $item['color'], $item['color'], $item['size'], $item['size']]);
                }
            }

            // 3. Log Audit Activity
            $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)')->execute([
                $orderId,
                'pending',
                'Manual draft order created via Admin Panel (Pending Confirmation)',
                $_SESSION['admin_id'] ?? null
            ]);

            $pdo->commit();

            log_admin_activity('orders', 'create_manual_order', "Created pending order #{$orderNo} for customer '{$name}' (Total: " . money($total) . ")");
            flash('success', "Order #{$orderNo} created as Pending. Review and update status to 'Confirmed' to email invoice to customer.");
            redirect('admin/sales/orders?id=' . $orderId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('Manual Order Creation Error: ' . $e->getMessage());
            flash('error', 'Could not create order: ' . $e->getMessage());
        }
    } else {
        foreach ($errors as $err) flash('error', $err);
    }
}

admin_header($title); ?>

<div class="panel" style="max-width:980px; margin:0 auto 25px auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #cbd5e1; padding-bottom:12px;">
        <h2 style="margin:0;">☎️ Create Order (Phone Call / POS Entry)</h2>
        <a href="<?= e(url('admin/sales/orders')) ?>" class="button secondary">← Back to All Orders</a>
    </div>

    <form method="post" id="manualOrderForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_manual_order">

        <!-- Customer & Delivery Information -->
        <h3 style="margin-top:0; color:#2563eb;">1. Customer & Delivery Info</h3>
        <div class="form-grid" style="margin-bottom:20px;">
            <div class="field">
                <label>Customer Full Name *</label>
                <input type="text" name="customer_name" required placeholder="e.g. Tanvir Ahmed">
            </div>
            <div class="field">
                <label>Phone Number *</label>
                <input type="text" name="customer_phone" required placeholder="e.g. 01700000000">
            </div>
            <div class="field">
                <label>Email Address (Optional)</label>
                <input type="email" name="customer_email" placeholder="customer@example.com">
            </div>
            <div class="field">
                <label>Delivery City / Zone *</label>
                <select name="city_id" required style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
                    <?php foreach ($cities as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= e($c['city_name']) ?> (<?= $c['zone_code'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?> — <?= money($c['shipping_cost']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field" style="margin-bottom:20px;">
            <label>Full Delivery Address *</label>
            <textarea name="address" required placeholder="House, Road, Area, Thana..." rows="2"></textarea>
        </div>

        <!-- Product Selector Table -->
        <h3 style="color:#2563eb; margin-top:25px;">2. Order Items Selection</h3>
        <div class="table-wrap" style="margin-bottom:15px; border:1px solid #cbd5e1; border-radius:6px;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;" id="orderItemsTable">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                        <th style="padding:10px;">Product Search / Select</th>
                        <th style="padding:10px; width:130px;">Color Option</th>
                        <th style="padding:10px; width:110px;">Size Option</th>
                        <th style="padding:10px; width:80px;">Qty</th>
                        <th style="padding:10px; width:100px; text-align:right;">Line Total</th>
                        <th style="padding:10px; width:40px; text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="orderItemsBody">
                    <tr class="item-row" style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:8px;">
                            <input type="text" class="prod-search-input" placeholder="🔍 Type name or SKU to search..." onkeyup="filterProductDropdown(this)" style="margin-bottom:4px; font-size:12px; padding:4px 8px; width:100%;">
                            <select name="prod_id[]" class="prod-select" required onchange="handleProductSelect(this)" style="width:100%; padding:6px; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="">-- Choose Product --</option>
                                <?php foreach ($productsMap as $p): ?>
                                    <option value="<?= $p['id'] ?>">
                                        <?= e($p['name']) ?> (SKU: <?= e($p['sku']) ?>) — <?= money($p['price']) ?> [Stock: <?= $p['stock'] ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="padding:8px;">
                            <select name="prod_color[]" class="color-select" onchange="recalculateRow(this)" style="width:100%; padding:6px; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="">N/A</option>
                            </select>
                        </td>
                        <td style="padding:8px;">
                            <select name="prod_size[]" class="size-select" onchange="recalculateRow(this)" style="width:100%; padding:6px; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="">N/A</option>
                            </select>
                        </td>
                        <td style="padding:8px;">
                            <input type="number" name="prod_qty[]" class="qty-input" value="1" min="1" onchange="recalculateRow(this)" onkeyup="recalculateRow(this)" style="width:100%; padding:5px;">
                        </td>
                        <td style="padding:8px; text-align:right; font-weight:bold; color:#16a34a;" class="line-total-val">
                            ৳0.00
                        </td>
                        <td style="padding:8px; text-align:center;">
                            <button type="button" onclick="removeRow(this)" style="background:#dc2626; color:#fff; border:none; border-radius:4px; padding:4px 8px; cursor:pointer;">×</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <button type="button" onclick="addOrderItemRow()" class="button secondary" style="margin-bottom:20px;">+ Add Another Product</button>

        <!-- Order Adjustments & Admin Notes -->
        <h3 style="color:#2563eb; margin-top:15px;">3. Adjustments & Admin Notes</h3>
        <div class="form-grid" style="margin-bottom:20px;">
            <div class="field">
                <label>Custom Discount (৳)</label>
                <input type="number" step="0.01" min="0" name="discount_amount" value="0.00">
            </div>
            <div class="field">
                <label>Internal Order Note</label>
                <input type="text" name="notes" value="Order taken via phone call">
            </div>
        </div>

        <div style="border-top:1px solid #cbd5e1; padding-top:18px; text-align:right;">
            <button type="submit" style="padding:10px 25px; font-weight:bold; font-size:15px; background:#2563eb; border-color:#1d4ed8;">
                📝 Save Pending Order
            </button>
        </div>
    </form>
</div>

<script>
    const productsData = <?= json_encode($productsMap) ?>;
    const variantsData = <?= json_encode($variantsMap) ?>;

    function filterProductDropdown(searchInput) {
        const filter = searchInput.value.toLowerCase();
        const row = searchInput.closest('tr');
        const select = row.querySelector('.prod-select');
        const options = select.options;

        for (let i = 0; i < options.length; i++) {
            const txt = options[i].text.toLowerCase();
            if (i === 0 || txt.includes(filter)) {
                options[i].style.display = "";
            } else {
                options[i].style.display = "none";
            }
        }
    }

    function handleProductSelect(selectEl) {
        const pId = parseInt(selectEl.value, 10);
        const row = selectEl.closest('tr');
        const colorSelect = row.querySelector('.color-select');
        const sizeSelect = row.querySelector('.size-select');

        colorSelect.innerHTML = '<option value="">N/A</option>';
        sizeSelect.innerHTML = '<option value="">N/A</option>';

        if (!pId || !productsData[pId]) {
            recalculateRow(selectEl);
            return;
        }

        const vInfo = variantsData[pId];
        if (vInfo) {
            if (vInfo.colors && vInfo.colors.length > 0) {
                vInfo.colors.forEach(col => {
                    const opt = document.createElement('option');
                    opt.value = col;
                    opt.textContent = col;
                    colorSelect.appendChild(opt);
                });
            }
            if (vInfo.sizes && vInfo.sizes.length > 0) {
                vInfo.sizes.forEach(sz => {
                    const opt = document.createElement('option');
                    opt.value = sz;
                    opt.textContent = sz;
                    sizeSelect.appendChild(opt);
                });
            }
        }

        recalculateRow(selectEl);
    }

    function recalculateRow(elementInRow) {
        const row = elementInRow.closest('tr');
        const pId = parseInt(row.querySelector('.prod-select').value, 10);
        const qty = parseInt(row.querySelector('.qty-input').value, 10) || 1;
        const color = row.querySelector('.color-select').value.trim();
        const size = row.querySelector('.size-select').value.trim();
        const lineTotalCell = row.querySelector('.line-total-val');

        if (!pId || !productsData[pId]) {
            lineTotalCell.textContent = '৳0.00';
            return;
        }

        const baseProduct = productsData[pId];
        let unitPrice = baseProduct.price;

        const vInfo = variantsData[pId];
        if (vInfo && vInfo.matrix) {
            const key = color + '||' + size;
            if (vInfo.matrix[key]) {
                unitPrice += vInfo.matrix[key].additional_price;
            }
        }

        const total = unitPrice * qty;
        lineTotalCell.textContent = '৳' + total.toFixed(2);
    }

    function addOrderItemRow() {
        const tbody = document.getElementById('orderItemsBody');
        const firstRow = tbody.querySelector('.item-row');
        const newRow = firstRow.cloneNode(true);

        newRow.querySelector('.prod-search-input').value = '';
        newRow.querySelector('.prod-select').selectedIndex = 0;
        newRow.querySelector('.color-select').innerHTML = '<option value="">N/A</option>';
        newRow.querySelector('.size-select').innerHTML = '<option value="">N/A</option>';
        newRow.querySelector('.qty-input').value = '1';
        newRow.querySelector('.line-total-val').textContent = '৳0.00';

        Array.from(newRow.querySelector('.prod-select').options).forEach(opt => opt.style.display = "");

        tbody.appendChild(newRow);
    }

    function removeRow(btn) {
        const tbody = document.getElementById('orderItemsBody');
        if (tbody.querySelectorAll('.item-row').length > 1) {
            btn.closest('tr').remove();
        } else {
            alert('At least one product is required in the order.');
        }
    }
</script>

<?php admin_footer(); ?>