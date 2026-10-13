<?php
namespace App\Core;

use MongoDB\Client;
use MongoDB\Database as MongoDb;

final class MongoDatabase
{
    private static ?Client $client = null;

    public static function client(): Client
    {
        return self::$client ??= new Client(cfg('mongo.uri'), [
            'serverSelectionTimeoutMS' => 4000,
            'connectTimeoutMS' => 2000,
            'socketTimeoutMS' => 10000,
            'w' => 'majority', 'wTimeoutMS' => 4000,
        ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    public static function db(): MongoDb
    {
        return self::client()->selectDatabase(cfg('mongo.database'), ['readConcern' => new \MongoDB\Driver\ReadConcern('majority')]);
    }

    public static function enabled(): bool
    {
        return cfg('catalog_driver') === 'mongodb';
    }

    public static function requireDemo(): void
    {
        if (!cfg('cap_demo') || cfg('mongo.database') !== 'catalog_demo' || cfg('db.name') !== 'catalog_demo' || cfg('db.host') !== 'db') {
            throw new \RuntimeException('Chỉ cho phép thao tác trong Docker demo catalog_demo.');
        }
    }
}
