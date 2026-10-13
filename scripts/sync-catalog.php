<?php
require dirname(__DIR__) . '/app/bootstrap.php';
App\Core\MongoDatabase::requireDemo();
App\Services\CatalogProjectionService::install();
echo json_encode(['applied_events' => App\Services\CatalogProjectionService::sync()]) . PHP_EOL;
