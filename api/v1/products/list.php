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

    // Query selecting cover photo from product_images (is_primary = 1) with fallback
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

    // Attach normalized product gallery images & size/color variants
    if ($products) {
        $productIds = array_column($products, 'id');
        $inMarks = implode(',', array_fill(0, count($productIds), '?'));

        // Fetch gallery photos
        $galStmt = $db->prepare("SELECT product_id, image_url FROM product_images WHERE product_id IN ($inMarks)");
        $galStmt->execute($productIds);
        $galleryMap = [];
        foreach ($galStmt->fetchAll(PDO::FETCH_ASSOC) as $gRow) {
            $galleryMap[$gRow['product_id']][] = $gRow['image_url'];
        }

        // Fetch product variants
        $varStmt = $db->prepare("SELECT product_id, sku, size, color, additional_price, stock FROM product_variants WHERE product_id IN ($inMarks)");
        $varStmt->execute($productIds);
        $variantMap = [];
        foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $vRow) {
            $variantMap[$vRow['product_id']][] = [
                'sku'              => $vRow['sku'],
                'size'             => $vRow['size'],
                'color'            => $vRow['color'],
                'additional_price' => (float)$vRow['additional_price'],
                'stock'            => (int)$vRow['stock'],
            ];
        }

        // Map back to products
        foreach ($products as &$pItem) {
            $pItem['gallery_images'] = $galleryMap[$pItem['id']] ?? [];
            $pItem['variants']       = $variantMap[$pItem['id']] ?? [];
        }
        unset($pItem);
    }

    api_response(200, [
        'status' => 'success',
        'pagination' => [
            'page'  => $page,
            'limit' => $limit,
            'count' => count($products)
        ],
        'data' => $products
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
