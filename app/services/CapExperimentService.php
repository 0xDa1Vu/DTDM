<?php
namespace App\Services;

use App\Core\MongoDatabase;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;

final class CapExperimentService
{
    public const NODES = ['mongo1', 'mongo2', 'mongo3'];
    public const PREFERENCES = ['primary', 'primaryPreferred', 'secondary', 'secondaryPreferred', 'nearest'];

    private function manager(string $target): Manager
    {
        MongoDatabase::requireDemo();
        if (!in_array($target, [...self::NODES, 'replica'], true)) throw new \InvalidArgumentException('Node không hợp lệ.');
        $uri = $target === 'replica'
            ? 'mongodb://mongo1:27017,mongo2:27017,mongo3:27017/?replicaSet=rsCatalog'
            : "mongodb://$target:27017/?directConnection=true";
        return new Manager($uri, ['serverSelectionTimeoutMS' => 2000, 'connectTimeoutMS' => 1000,
            'socketTimeoutMS' => 5000, 'retryReads' => false, 'retryWrites' => false]);
    }

    public function status(): array
    {
        $rows = [];
        foreach (self::NODES as $node) {
            $start = hrtime(true);
            try {
                $manager = $this->manager($node);
                $options = ['readPreference' => new ReadPreference('secondaryPreferred')];
                $hello = $manager->executeCommand('admin', new Command(['hello' => 1]), $options)->toArray()[0];
                $status = $manager->executeCommand('admin', new Command(['replSetGetStatus' => 1]), $options)->toArray()[0];
                $members = [];
                foreach ($status->members as $member) $members[] = [
                    'name' => $member->name, 'state' => $member->stateStr, 'health' => $member->health,
                    'optime_date' => isset($member->optimeDate) ? $member->optimeDate->toDateTime()->format(DATE_ATOM) : null,
                ];
                $rows[] = ['node' => $node, 'reachable' => true,
                    'role' => !empty($hello->isWritablePrimary) ? 'PRIMARY' : (!empty($hello->secondary) ? 'SECONDARY' : 'OTHER'),
                    'reported_primary' => $hello->primary ?? null, 'term' => $status->term ?? null,
                    'members_from_this_node' => $members, 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2)];
            } catch (\Throwable $e) {
                $rows[] = ['node' => $node, 'reachable' => false, 'role' => 'UNKNOWN', 'error' => $e->getMessage(),
                    'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2)];
            }
        }
        $network = dirname(__DIR__, 2) . '/storage/cap/network.json';
        return ['observed_at' => gmdate(DATE_ATOM), 'observer' => 'web container (can reach both partitions)',
            'nodes' => $rows, 'network' => is_file($network) ? json_decode(file_get_contents($network), true) : null];
    }

    public function probe(array $input): array
    {
        MongoDatabase::requireDemo();
        $target = (string) ($input['target'] ?? 'replica');
        $action = (string) ($input['action'] ?? 'read');
        $preference = (string) ($input['read_preference'] ?? 'primary');
        $concern = (string) ($input['read_concern'] ?? 'local');
        $w = (string) ($input['write_concern'] ?? 'majority');
        $run = (string) ($input['run_id'] ?? 'manual');
        $key = (string) ($input['key'] ?? 'demo-price');
        $value = (string) ($input['value'] ?? '100000');
        if (!in_array($action, ['read', 'write'], true) || !in_array($target, [...self::NODES, 'replica'], true)
            || !in_array($preference, self::PREFERENCES, true) || !in_array($concern, ['local', 'majority', 'linearizable'], true)
            || !in_array($w, ['1', 'majority'], true) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $run)
            || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $key) || strlen($value) > 200) {
            throw new \InvalidArgumentException('Cấu hình probe không hợp lệ.');
        }
        if ($action === 'read' && $concern === 'linearizable' && $preference !== 'primary') throw new \InvalidArgumentException('Linearizable cần read preference primary.');
        $record = ['type' => 'probe', 'run_id' => $run, 'operation_id' => bin2hex(random_bytes(12)),
            'started_at' => gmdate(DATE_ATOM), 'target' => $target, 'connection' => $target === 'replica' ? 'replicaSet' : 'directConnection',
            'observer' => 'web container', 'action' => $action, 'key' => $key, 'value' => $action === 'write' ? $value : null,
            'read_preference' => $preference, 'read_concern' => $concern, 'write_concern' => $w,
            'max_time_ms' => $action === 'read' ? 2000 : null, 'wtimeout_ms' => 2000, 'retry_reads' => false, 'retry_writes' => false];
        $start = hrtime(true);
        try {
            $manager = $this->manager($target);
            if ($action === 'write') {
                $bulk = new BulkWrite();
                $bulk->update(['_id' => $key], ['$set' => ['value' => $value, 'operation_id' => $record['operation_id'], 'run_id' => $run,
                    'written_at' => new \MongoDB\BSON\UTCDateTime()]], ['upsert' => true]);
                $result = $manager->executeBulkWrite('cap_demo.probes', $bulk, ['writeConcern' => new WriteConcern($w === '1' ? 1 : 'majority', 2000)]);
                $record['server'] = $result->getServer()->getHost();
                $record['result'] = ['acknowledged' => $result->isAcknowledged(), 'matched' => $result->getMatchedCount(), 'upserted' => $result->getUpsertedCount()];
            } else {
                $cursor = $manager->executeQuery('cap_demo.probes', new Query(['_id' => $key], ['limit' => 1,
                    'maxTimeMS' => 2000, 'readConcern' => new ReadConcern($concern)]), ['readPreference' => new ReadPreference($preference)]);
                $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
                $record['result'] = $cursor->toArray()[0] ?? null;
                $record['server'] = $cursor->getServer()->getHost();
            }
            $record['ok'] = true;
        } catch (\Throwable $e) {
            $record['ok'] = false;
            $record['error'] = ['type' => get_class($e), 'code' => $e->getCode(), 'message' => $e->getMessage()];
            $record['write_outcome'] = $action === 'write' ? 'unconfirmed; inspect operation_id after recovery' : null;
            if ($e instanceof \MongoDB\Driver\Exception\BulkWriteException && $e->getWriteResult()) {
                $record['server'] = $e->getWriteResult()->getServer()->getHost();
            }
        }
        $record['elapsed_ms'] = round((hrtime(true) - $start) / 1e6, 3);
        $record['finished_at'] = gmdate(DATE_ATOM);
        $this->record($record);
        return $record;
    }

    public function record(array $record): void
    {
        MongoDatabase::requireDemo();
        $dir = dirname(__DIR__, 2) . '/storage/cap';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Không tạo được thư mục log.');
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        if (file_put_contents($dir . '/experiments.jsonl', $line, FILE_APPEND | LOCK_EX) === false) throw new \RuntimeException('Không lưu được bằng chứng thực nghiệm.');
    }

    public function recent(): array
    {
        $path = dirname(__DIR__, 2) . '/storage/cap/experiments.jsonl';
        if (!is_file($path)) return [];
        // Bounded tail read, not the full growing experiment file.
        $handle = fopen($path, 'rb');
        $length = filesize($path); $start = max(0, $length - 262144);
        fseek($handle, $start);
        if ($start > 0) fgets($handle);
        $text = stream_get_contents($handle); fclose($handle);
        $rows = [];
        foreach (explode("\n", trim($text)) as $line) { $row = json_decode($line, true); if ($row) $rows[] = $row; }
        return array_reverse(array_slice($rows, -100));
    }
}
