<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';

function clean_product(): array {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    if ($name === '' || mb_strlen($name) > 100 || $category === '' || mb_strlen($category) > 80 || $price === false || $price < 0) {
        throw new InvalidArgumentException('Enter a name, category, and a non-negative price.');
    }
    return ['name' => $name, 'category' => $category, 'price' => number_format((float)$price, 2, '.', '')];
}

function upload_image(?string $current = null): ?string {
    if (empty($_FILES['image']['name'])) return $current;
    ensure_storage();
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 2 * 1024 * 1024) throw new InvalidArgumentException('Image upload failed or exceeds 2 MB.');
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type] ?? null;
    if ($extension === null) throw new InvalidArgumentException('Use a JPG, PNG, or WebP image.');
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . '/' . $name)) throw new RuntimeException('Could not save the image.');
    if ($current && is_file(UPLOAD_DIR . '/' . $current)) unlink(UPLOAD_DIR . '/' . $current);
    return $name;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $data = clean_product(); $id = (int)($_POST['id'] ?? 0); $old = null;
            if ($id) { $stmt = database()->prepare('SELECT * FROM products WHERE id = ?'); $stmt->execute([$id]); $old = $stmt->fetch(); if (!$old) throw new InvalidArgumentException('Product not found.'); }
            $data['image'] = upload_image($old['image'] ?? null);
            $start = microtime(true);
            if ($id) { $stmt = database()->prepare('UPDATE products SET name=?, category=?, price=?, image=? WHERE id=?'); $stmt->execute([$data['name'], $data['category'], $data['price'], $data['image'], $id]); }
            else { $stmt = database()->prepare('INSERT INTO products (name,category,price,image) VALUES (?,?,?,?)'); $stmt->execute([$data['name'], $data['category'], $data['price'], $data['image']]); $id = (int)database()->lastInsertId(); }
            $sqlMs = (microtime(true) - $start) * 1000;
            $row = database()->prepare('SELECT * FROM products WHERE id=?'); $row->execute([$id]); $product = $row->fetch();
            $start = microtime(true); document_upsert($product); $jsonMs = (microtime(true) - $start) * 1000;
            flash(sprintf('Saved. MySQL: %.2f ms | JSON document store: %.2f ms', $sqlMs, $jsonMs));
        }
        if ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $id === null) throw new InvalidArgumentException('Invalid product selected.');
            $stmt = database()->prepare('SELECT image FROM products WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
            if (!$old) throw new InvalidArgumentException('Product not found.');
            database()->prepare('DELETE FROM products WHERE id=?')->execute([$id]); document_delete($id);
            if ($old['image'] && is_file(UPLOAD_DIR . '/' . $old['image'])) unlink(UPLOAD_DIR . '/' . $old['image']);
            flash('Product deleted from both stores.');
        }
        if (!in_array($action, ['save', 'delete'], true)) throw new InvalidArgumentException('Unknown form action.');
        header('Location: index.php'); exit;
    }
} catch (Throwable $error) { flash($error->getMessage()); header('Location: index.php'); exit; }

$sort = $_GET['sort'] ?? 'id'; $direction = $_GET['dir'] ?? 'desc';
$allowedSort = ['id', 'name', 'category', 'price', 'created_at'];
if (!in_array($sort, $allowedSort, true)) $sort = 'id';
$direction = $direction === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 5;
$total = (int)database()->query('SELECT COUNT(*) FROM products')->fetchColumn(); $pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
$start = microtime(true); $stmt = database()->prepare("SELECT * FROM products ORDER BY $sort $direction LIMIT ? OFFSET ?"); $stmt->bindValue(1, $perPage, PDO::PARAM_INT); $stmt->bindValue(2, ($page - 1) * $perPage, PDO::PARAM_INT); $stmt->execute(); $products = $stmt->fetchAll(); $sqlReadMs = (microtime(true) - $start) * 1000;
$start = microtime(true); $docCount = count(documents()); $jsonReadMs = (microtime(true) - $start) * 1000;
$edit = null; if (isset($_GET['edit'])) { $stmt = database()->prepare('SELECT * FROM products WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch() ?: null; }
function sort_url(string $field): string { global $sort, $direction; return '?sort=' . $field . '&dir=' . (($sort === $field && $direction === 'asc') ? 'desc' : 'asc'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CRUD Database Comparison</title><link rel="stylesheet" href="style.css"></head>
<body><main><header><h1>Product CRUD</h1><p>MySQL relational database + JSON document store comparison</p></header>
<?php if ($message = flash()): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
<section class="metrics"><div><b>MySQL page read</b><span><?= number_format($sqlReadMs, 2) ?> ms</span></div><div><b>Document read</b><span><?= number_format($jsonReadMs, 2) ?> ms</span></div><div><b>Document records</b><span><?= $docCount ?></span></div></section>
<section class="card"><h2><?= $edit ? 'Edit product' : 'Add product' ?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><label>Name<input required maxlength="100" name="name" value="<?= e($edit['name'] ?? '') ?>"></label><label>Category<input required maxlength="80" name="category" value="<?= e($edit['category'] ?? '') ?>"></label><label>Price<input required min="0" step="0.01" type="number" name="price" value="<?= e($edit['price'] ?? '') ?>"></label><label>Image <small>(JPG, PNG or WebP; max 2 MB)</small><input accept="image/jpeg,image/png,image/webp" type="file" name="image"></label><button><?= $edit ? 'Update' : 'Create' ?> product</button><?php if ($edit): ?> <a class="cancel" href="index.php">Cancel</a><?php endif; ?></form></section>
<section class="card"><h2>Products <small><?= $total ?> total</small></h2><div class="table-wrap"><table><thead><tr><th>Image</th><?php foreach (['id'=>'ID','name'=>'Name','category'=>'Category','price'=>'Price','created_at'=>'Created'] as $field=>$label): ?><th><a href="<?= sort_url($field) ?>"><?= $label ?><?= $sort === $field ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' ?></a></th><?php endforeach; ?><th>Actions</th></tr></thead><tbody><?php foreach ($products as $product): ?><tr><td><?php if ($product['image']): ?><img src="uploads/<?= e($product['image']) ?>" alt="Product image"><?php endif; ?></td><td><?= $product['id'] ?></td><td><?= e($product['name']) ?></td><td><?= e($product['category']) ?></td><td>₹<?= e($product['price']) ?></td><td><?= e($product['created_at']) ?></td><td><a href="?edit=<?= $product['id'] ?>">Edit</a><form class="inline" method="post" onsubmit="return confirm('Delete this product?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $product['id'] ?>"><button class="link danger">Delete</button></form></td></tr><?php endforeach; ?><?php if (!$products): ?><tr><td colspan="8">No products yet — add one above.</td></tr><?php endif; ?></tbody></table></div><nav><?php for ($i=1;$i<=$pages;$i++): ?><a class="<?= $i===$page?'active':'' ?>" href="?page=<?= $i ?>&sort=<?= e($sort) ?>&dir=<?= e($direction) ?>"><?= $i ?></a><?php endfor; ?></nav></section></main></body></html>
