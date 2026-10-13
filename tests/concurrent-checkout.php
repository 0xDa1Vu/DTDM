<?php
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Database;
use App\Core\MongoDatabase;
use App\Models\Order;
use App\Services\CatalogService;
use App\Repositories\MongoCatalogRepository;

MongoDatabase::requireDemo();
if (!MongoDatabase::enabled()) throw new RuntimeException('Requires Mongo catalog.');
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    while (microtime(true) < $input['start']) usleep(10000);
    try {
        $id = (new Order())->placeOrder($input['info'], [$input['item']]);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}
$suffix = bin2hex(random_bytes(5));
$service = new CatalogService();
$id = $service->saveProduct(null, ['name' => 'Concurrency ' . $suffix, 'slug' => 'concurrent-' . $suffix,
    'price' => '10000.00', 'sale_price' => '', 'category_id' => 1, 'brand' => 'ATELIER'], [['size' => 'M', 'color' => 'Test', 'stock' => 1]]);
$sql = Database::conn();
try {
    $variant = (new MongoCatalogRepository())->variants($id)[0];
    $input = ['start' => microtime(true) + 1, 'info' => ['user_id' => null, 'payment_method' => 'cod',
        'shipping_address' => 'Demo', 'phone' => '0000000000', 'customer_name' => 'concurrent-' . $suffix],
        'item' => $variant + ['variant_id' => $variant['id'], 'product_name' => 'Concurrency ' . $suffix,
            'price' => 10000, 'quantity' => 1, 'available' => true, 'catalog_version' => 1]];
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($input))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException($error);
    }
    $stock = (new MongoCatalogRepository())->variants($id)[0]['stock'];
    if (count(array_filter($results, fn($r) => $r['ok'])) !== 1 || $stock !== 0) throw new RuntimeException('Concurrent inventory assertion failed');
    echo json_encode(['ok' => true, 'workers' => $results, 'remaining_stock' => $stock], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $sql->prepare('DELETE FROM orders WHERE customer_name = ?')->execute(['concurrent-' . $suffix]);
    $sql->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    $sql->prepare("DELETE FROM catalog_projection_versions WHERE kind='products' AND entity_id=?")->execute([$id]);
    MongoDatabase::db()->catalog_events->deleteMany(['kind' => 'products', 'document._id' => $id]);
    MongoDatabase::db()->products->deleteOne(['_id' => $id]);
}
