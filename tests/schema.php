<?php
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\MongoDatabase;
use MongoDB\BSON\Decimal128;

MongoDatabase::requireDemo();
$db = MongoDatabase::db();
$base = $db->products->findOne();
if (!$base) throw new RuntimeException('Migrate seed trước.');
$cases = [
    'negative_price' => function ($d) { $d['price'] = new Decimal128('-1'); return $d; },
    'wrong_type' => function ($d) { $d['price'] = '100'; return $d; },
    'duplicate_variant' => function ($d) { $d['variants'] = [
        ['id' => -999, 'size' => 'X', 'color' => 'Y', 'sku' => null],
        ['id' => -999, 'size' => 'X', 'color' => 'Y', 'sku' => null],
    ]; return $d; },
];
foreach ($cases as $name => $change) {
    $session = MongoDatabase::client()->startSession();
    $session->startTransaction();
    try {
        $d = $change($base); $d['_id'] = -999; $d['slug'] = 'schema-probe';
        $db->products->insertOne($d, ['session' => $session]);
        throw new RuntimeException("Validator accepted $name");
    } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
        if ($e->getCode() !== 121) throw $e;
        echo "$name: rejected (121)\n";
    } finally {
        if ($session->isInTransaction()) $session->abortTransaction();
    }
}
