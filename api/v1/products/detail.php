<?php
require_once __DIR__ . '/../middleware.php';

// Dynamically permit products:read, catalog, or master scopes
$allowedScopes = ['products:read', 'admin', '*'];
$authed = false;

foreach ($allowedScopes as $scope) {
    try {
        authenticate_api_request($scope);
        $authed = true;
        break;
    } catch (Throwable $e) {
        // Fall through
    }
}

if (!$authed) {
    authenticate_api_request('products:read');
}

$id   = (int)($_GET['id'] ?? 0);
$slug = trim((string)($_GET['slug'] ?? ''));

if ($id <= 0 && $slug === '') {
    api_response(400, [
        'status'  => 'error',
        'message' => 'Bad Request: Please provide either id or slug query parameter.'
    ]);
}

try {
    $db = db();

    $where  = $id > 0 ? 'p.id = ?' : 'p.slug = ?';
    $param  = $id > 0 ? $id : $slug;

    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.category_id,
            c.name AS category_name,
            c.slug AS category_slug,
            p.name,
            p.slug,
            p.sku,
            p.short_description,
            p.description,
            p.price,
            p.compare_price,
            p.stock,
            p.sizes,
            p.status,
            p.created_at,
            COALESCE(pi.image_url, p.image_url) AS cover_image
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
        WHERE {$where}
        LIMIT 1
    ");
    $stmt->execute([$param]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        api_response(404, [
            'status'  => 'error',
            'message' => 'Product not found.'
        ]);
    }

    $productId = (int)$product['id'];

    // Gallery images
    $galStmt = $db->prepare('SELECT id, image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
    $galStmt->execute([$productId]);
    $gallery = [];
    foreach ($galStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $img = $g['image_url'];
        $gallery[] = [
            'id'         => (int)$g['id'],
            'image_url'  => !empty($img) ? (str_starts_with($img, 'http') ? $img : url($img)) : null,
            'is_primary' => (bool)$g['is_primary']
        ];
    }

    // Granular variants by size and SKU
    $varStmt = $db->prepare('SELECT id, sku, size, color, additional_price, stock FROM product_variants WHERE product_id = ? ORDER BY id ASC');
    $varStmt->execute([$productId]);
    $variants = [];
    foreach ($varStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vStock = (int)$v['stock'];
        $variants[] = [
            'variant_id'       => (int)$v['id'],
            'sku'              => $v['sku'],
            'size'             => $v['size'],
            'color'            => $v['color'],
            'additional_price' => (float)$v['additional_price'],
            'stock'            => $vStock,
            'in_stock'         => $vStock > 0
        ];
    }

    // Parse sizes string into an array
    $rawSizes = array_filter(array_map('trim', explode(',', (string)($product['sizes'] ?? ''))));

    $cover = $product['cover_image'];
    $data = [
        'id'                => $productId,
        'name'              => $product['name'],
        'slug'              => $product['slug'],
        'sku'               => $product['sku'],
        'category'          => [
            'id'   => (int)$product['category_id'],
            'name' => $product['category_name'],
            'slug' => $product['category_slug']
        ],
        'price'             => (float)$product['price'],
        'compare_price'     => $product['compare_price'] !== null ? (float)$product['compare_price'] : null,
        'short_description' => $product['short_description'] ?? '',
        'description'       => $product['description'] ?? '',
        'cover_image'       => !empty($cover) ? (str_starts_with($cover, 'http') ? $cover : url($cover)) : null,
        'stock'             => (int)$product['stock'],
        'in_stock'          => (int)$product['stock'] > 0,
        'sizes'             => array_values($rawSizes),
        'gallery'           => $gallery,
        'variants'          => $variants,
        'product_url'       => url('product.php?slug=' . urlencode($product['slug']))
    ];

    api_response(200, [
        'status' => 'success',
        'data'   => $data
    ]);
} catch (Throwable $e) {
    api_response(500, [
        'status'  => 'error',
        'message' => 'Database error loading product: ' . $e->getMessage()
    ]);
}
