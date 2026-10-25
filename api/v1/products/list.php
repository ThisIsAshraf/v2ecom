<?php
require_once __DIR__ . '/../middleware.php';

// Requires 'products:read' scope
authenticate_api_request('products:read');

$limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$catId  = (int)($_GET['category_id'] ?? 0);

try {
    $db = db();

    $where  = ['p.active = 1'];
    $params = [];

    if ($catId > 0) {
        $where[]  = 'p.category_id = ?';
        $params[] = $catId;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Total Count Query for proper pagination metadata
    $countSql = "SELECT COUNT(*) FROM products p {$whereSql}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages   = max(1, (int)ceil($totalRecords / $limit));

    // Select product records with cover photo from product_images (is_primary = 1) fallback
    $sql = "SELECT 
                p.id, 
                p.category_id, 
                c.name AS category_name, 
                p.name, 
                p.slug, 
                p.sku, 
                p.description, 
                p.price, 
                p.compare_price, 
                p.colors,
                p.sizes,
                COALESCE(pi.image_url, p.image_url) AS image_url, 
                p.stock, 
                p.created_at 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
            {$whereSql} 
            ORDER BY p.id DESC LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);

    $i = 1;
    foreach ($params as $param) {
        $stmt->bindValue($i++, $param, PDO::PARAM_INT);
    }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach normalized gallery images, formatted size/color arrays, & variants
    if ($products) {
        $productIds = array_column($products, 'id');
        $inMarks = implode(',', array_fill(0, count($productIds), '?'));

        // Fetch gallery photos
        $galStmt = $db->prepare("SELECT product_id, image_url FROM product_images WHERE product_id IN ($inMarks) ORDER BY is_primary DESC, id ASC");
        $galStmt->execute($productIds);
        $galleryMap = [];
        foreach ($galStmt->fetchAll(PDO::FETCH_ASSOC) as $gRow) {
            $rawImg = $gRow['image_url'];
            $fullUrl = (str_starts_with($rawImg, 'http://') || str_starts_with($rawImg, 'https://')) ? $rawImg : url($rawImg);
            $galleryMap[$gRow['product_id']][] = $fullUrl;
        }

        // Fetch product variants with Color and Size details
        $varStmt = $db->prepare("SELECT product_id, sku, color, size, additional_price, stock FROM product_variants WHERE product_id IN ($inMarks) ORDER BY id ASC");
        $varStmt->execute($productIds);
        $variantMap = [];
        foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $vRow) {
            $variantMap[$vRow['product_id']][] = [
                'sku'              => $vRow['sku'],
                'color'            => $vRow['color'],
                'size'             => $vRow['size'],
                'additional_price' => (float)$vRow['additional_price'],
                'stock'            => (int)$vRow['stock'],
                'in_stock'         => (int)$vRow['stock'] > 0
            ];
        }

        // Format each product object
        foreach ($products as &$pItem) {
            // Full image URL
            if (!empty($pItem['image_url'])) {
                $rawCover = $pItem['image_url'];
                $pItem['image_url'] = (str_starts_with($rawCover, 'http://') || str_starts_with($rawCover, 'https://')) ? $rawCover : url($rawCover);
            }

            // Numeric casting
            $basePrice = (float)$pItem['price'];
            $pItem['price']         = $basePrice;
            $pItem['compare_price'] = $pItem['compare_price'] !== null ? (float)$pItem['compare_price'] : null;
            $pItem['stock']         = (int)$pItem['stock'];
            $pItem['in_stock']      = $pItem['stock'] > 0;

            // Direct frontend link
            $pItem['product_url'] = url('product.php?slug=' . urlencode($pItem['slug']));

            // Parsed raw lists
            $rawColors = !empty($pItem['colors'])
                ? array_values(array_filter(array_map('trim', explode(',', (string)$pItem['colors']))))
                : [];
            $rawSizes  = !empty($pItem['sizes'])
                ? array_values(array_filter(array_map('trim', explode(',', (string)$pItem['sizes']))))
                : [];

            $variantsList = $variantMap[$pItem['id']] ?? [];

            // Extract unique colors and sizes from active variant matrix
            $matrixColors = [];
            $matrixSizes  = [];
            $maxVariantPrice = $basePrice;
            $hasPriceDelta = false;

            foreach ($variantsList as $varItem) {
                if (!empty($varItem['color']) && !in_array($varItem['color'], $matrixColors, true)) {
                    $matrixColors[] = $varItem['color'];
                }
                if (!empty($varItem['size']) && !in_array($varItem['size'], $matrixSizes, true)) {
                    $matrixSizes[] = $varItem['size'];
                }
                if ($varItem['additional_price'] > 0) {
                    $hasPriceDelta = true;
                    $calcPrice = $basePrice + $varItem['additional_price'];
                    if ($calcPrice > $maxVariantPrice) {
                        $maxVariantPrice = $calcPrice;
                    }
                }
            }

            // Merge matrix values with base strings
            $pItem['colors_list'] = !empty($matrixColors) ? $matrixColors : $rawColors;
            $pItem['sizes_list']  = !empty($matrixSizes) ? $matrixSizes : $rawSizes;

            // Price range indicators
            $pItem['has_variant_pricing'] = $hasPriceDelta;
            $pItem['min_price']           = $basePrice;
            $pItem['max_price']           = $maxVariantPrice;

            $pItem['gallery_images'] = $galleryMap[$pItem['id']] ?? [];
            $pItem['variants']       = $variantsList;
        }
        unset($pItem);
    }

    api_response(200, [
        'status' => 'success',
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $totalRecords,
            'total_pages' => $totalPages,
            'count'       => count($products)
        ],
        'data' => $products
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
