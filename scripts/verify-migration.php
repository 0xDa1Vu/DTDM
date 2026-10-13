<?php
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\MongoDatabase;
use App\Services\CatalogSnapshot;

MongoDatabase::requireDemo();
$snapshot = CatalogSnapshot::fromSql();
$errors = [];
foreach ($snapshot as $name => $docs) {
    $collection = MongoDatabase::db()->selectCollection($name);
    if ($collection->countDocuments() !== count($docs)) $errors[] = "$name: count mismatch";
    foreach ($docs as $doc) {
        $stored = $collection->findOne(['_id' => $doc['_id']]);
        if (!$stored || CatalogSnapshot::hash($stored) !== CatalogSnapshot::hash($doc)) $errors[] = "$name/{$doc['_id']}: mismatch";
    }
}
echo json_encode(['ok' => !$errors, 'counts' => array_map('count', $snapshot), 'errors' => $errors], JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors ? 1 : 0);
