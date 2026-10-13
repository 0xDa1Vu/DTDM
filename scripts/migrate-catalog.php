<?php
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\MongoDatabase;
use App\Services\CatalogSchema;
use App\Services\CatalogSnapshot;

MongoDatabase::requireDemo();
$options = getopt('', ['backup:', 'dry-run']);
$snapshot = CatalogSnapshot::fromSql();
if (isset($options['dry-run'])) {
    echo json_encode(['dry_run' => true, 'counts' => array_map('count', $snapshot)], JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}
$backup = realpath($options['backup'] ?? '');
$root = realpath(dirname(__DIR__) . '/backups');
if (!$backup || !$root || !str_starts_with($backup, $root . DIRECTORY_SEPARATOR) || !is_file($backup . '/SHA256SUMS')) {
    throw new RuntimeException('Chạy backup-demo.sh trước, rồi truyền --backup=backups/<timestamp>.');
}
foreach (file($backup . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$hash, $name] = explode('  ', $line, 2);
    if (basename($name) !== $name || !hash_equals($hash, hash_file('sha256', $backup . '/' . $name))) {
        throw new RuntimeException('Checksum backup không hợp lệ.');
    }
}
if (!is_file($backup . '/mysql.sql') || !is_file($backup . '/uploads.tar.gz')) throw new RuntimeException('Backup thiếu dữ liệu.');
CatalogSchema::install();
$db = MongoDatabase::db();
// Preflight the entire migration BEFORE any data writes. Never overwrite admin changes.
foreach ($snapshot as $collection => $documents) {
    foreach ($documents as $doc) {
        $old = $db->$collection->findOne(['_id' => $doc['_id']]);
        if ($old && CatalogSnapshot::hash($old) !== CatalogSnapshot::hash($doc)) {
            throw new RuntimeException("$collection/{$doc['_id']} đã khác nguồn; dừng để tránh ghi đè.");
        }
    }
}
foreach ($snapshot as $collection => $documents) {
    foreach ($documents as $doc) {
        $doc['_migration_hash'] = CatalogSnapshot::hash($doc);
        $db->$collection->updateOne(['_id' => $doc['_id']], ['$setOnInsert' => $doc], ['upsert' => true]);
    }
}
foreach (['products' => 'products', 'categories' => 'categories', 'variants' => 'variants', 'images' => 'product_images'] as $name => $table) {
    $max = (int) App\Core\Database::conn()->query("SELECT COALESCE(MAX(id),0) FROM $table")->fetchColumn();
    $db->counters->updateOne(['_id' => $name], ['$max' => ['value' => $max]], ['upsert' => true]);
}
echo json_encode(['migrated' => array_map('count', $snapshot), 'backup' => basename($backup)], JSON_PRETTY_PRINT) . PHP_EOL;
