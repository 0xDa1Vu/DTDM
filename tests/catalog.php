<?php
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Database;
use App\Core\MongoDatabase;
use App\Services\CatalogService;
use App\Services\CatalogProjectionService;
use App\Repositories\MongoCatalogRepository;
use App\Models\Cart;
use App\Models\Order;

MongoDatabase::requireDemo();
if (!MongoDatabase::enabled()) throw new RuntimeException('Set CATALOG_DRIVER=mongodb.');
function check(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    echo "PASS: $name\n";
}
$service = new CatalogService(); $repo = new MongoCatalogRepository();
$sql = Database::conn(); $mongo = MongoDatabase::db();
$suffix = bin2hex(random_bytes(5));
$id = $cartId = $orderId = null;
$data = ['name' => 'Test catalog ' . $suffix, 'slug' => 'test-' . $suffix, 'price' => '100000.00', 'sale_price' => '',
    'category_id' => 1, 'brand' => 'ATELIER', 'status' => 'active', 'is_featured' => true];
try {
    $id = $service->saveProduct(null, $data, [['size' => 'M', 'color' => 'Test', 'stock' => 5]]);
    $p = $repo->find('products', $id); $variant = $repo->variants($id)[0]; $vid = $variant['id'];
    check($p['price'] === '100000.00' && $variant['stock'] === 5, 'create product and initial stock');
    check($repo->browse(['q' => $suffix])['total'] === 1, 'literal substring search');
    check($repo->browse(['q' => '.*' . $suffix])['total'] === 0, 'regex metacharacters escaped');
    $imageModel = new \App\Models\ProductImage();
    $image1 = $imageModel->add($id, '/assets/test-one.webp', true);
    $image2 = $imageModel->add($id, '/assets/test-two.webp');
    $imageModel->setPrimary($image2, $id);
    $imageModel->reorder([$image2, $image1]);
    check($repo->find('products', $id)['image'] === '/assets/test-two.webp' && $repo->images($id)[0]['id'] === $image2, 'embedded images primary and order');
    $imageModel->deleteOne($image1);
    check(count($repo->images($id)) === 1, 'embedded image deletion');
    $p = $repo->find('products', $id);
    $cart = (new Cart())->current(null, 'test-' . $suffix); $cartId = (int) $cart['id'];
    (new Cart())->addItem($cartId, $vid, 6);
    $info = ['user_id' => null, 'payment_method' => 'cod', 'shipping_address' => 'Demo test', 'phone' => '0000000000', 'customer_name' => 'Test'];
    $before = (int) $sql->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    try { (new Order())->placeOrder($info, (new Cart())->items($cartId)); throw new LogicException('Oversell was accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'không đủ hàng'), 'insufficient stock rejected'); }
    check((int) $sql->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $before && $repo->variants($id)[0]['stock'] === 5, 'order and inventory rollback together');
    $cartItemId = (int) (new Cart())->items($cartId)[0]['cart_item_id'];
    (new Cart())->updateItem($cartItemId, 2, $cartId);
    (new Cart())->updateItem($cartItemId, 100, $cartId + 9999);
    check((int) (new Cart())->items($cartId)[0]['quantity'] === 2, 'cart ownership enforced');
    $orderId = (new Order())->placeOrder($info, (new Cart())->items($cartId));
    check($repo->variants($id)[0]['stock'] === 3, 'checkout decrements SQL inventory');
    $data['price'] = '120000.00'; $data['version'] = $p['version'];
    $service->saveProduct($id, $data, [['id' => $vid, 'size' => 'M', 'color' => 'Test', 'stock' => 5, 'original_stock' => 5]]);
    check($repo->variants($id)[0]['stock'] === 3 && $repo->variants($id)[0]['id'] === $vid, 'catalog edit preserves concurrent sale and variant ID');
    check((string) (new Order())->items($orderId)[0]['price'] === '100000.00', 'historical order price remains unchanged');
    $event = $mongo->catalog_events->findOne(['kind' => 'products', 'document._id' => $id], ['sort' => ['version' => -1]]);
    $mongo->catalog_events->updateOne(['_id' => $event['_id']], ['$set' => ['applied' => false]]);
    CatalogProjectionService::sync();
    check($repo->variants($id)[0]['stock'] === 3, 'projection replay is idempotent');
    try { $service->saveProduct($id, $data); throw new LogicException('Stale version accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'đã thay đổi'), 'stale admin version rejected'); }
    $service->delete('products', $id);
    check($repo->variant($vid) === null && (new Cart())->items($cartId)[0]['available'] === false, 'retired product cannot be purchased; cart can still display/remove it');
} finally {
    // Only records created by this run, in guarded local demo, are removed.
    if ($orderId) $sql->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    if ($cartId) $sql->prepare('DELETE FROM carts WHERE id = ?')->execute([$cartId]);
    if ($id) {
        $sql->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        $sql->prepare("DELETE FROM catalog_projection_versions WHERE kind='products' AND entity_id=?")->execute([$id]);
        $mongo->catalog_events->deleteMany(['kind' => 'products', 'document._id' => $id]);
        $mongo->products->deleteOne(['_id' => $id]);
    }
}
