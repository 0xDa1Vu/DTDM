<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
use App\Services\CapExperimentService;
App\Core\MongoDatabase::requireDemo();
$service = new CapExperimentService();
$action = $argv[1] ?? 'status';
if ($action === 'status') $result = $service->status();
elseif ($action === 'record-status') {
    $result = ['type' => 'status', 'run_id' => $argv[2] ?? 'manual', 'phase' => $argv[3] ?? 'manual', 'status' => $service->status()];
    $service->record($result);
} else {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $result = $service->probe($input);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
