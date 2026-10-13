<?php
namespace App\Services;

use App\Core\MongoDatabase;

final class CatalogSchema
{
    public static function install(): void
    {
        // Administrative DDL commands do not accept application read concern.
        $db = MongoDatabase::client()->selectDatabase(cfg('mongo.database'), ['readConcern' => new \MongoDB\Driver\ReadConcern()]);
        $integer = ['bsonType' => ['int', 'long']];
        $nullableId = ['bsonType' => ['int', 'long', 'null']];
        $schemas = [
            'products' => [
                'required' => ['_id', 'name', 'slug', 'price', 'effective_price', 'status', 'images', 'variants', 'version'],
                'properties' => [
                    '_id' => $integer, 'name' => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'slug' => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 220],
                    'category_id' => $nullableId, 'brand_id' => ['bsonType' => ['string', 'null']],
                    'price' => ['bsonType' => 'decimal', 'minimum' => 0],
                    'sale_price' => ['bsonType' => ['decimal', 'null'], 'minimum' => 0],
                    'effective_price' => ['bsonType' => 'decimal', 'minimum' => 0],
                    'status' => ['enum' => ['active', 'hidden']], 'is_featured' => ['bsonType' => 'bool'],
                    'version' => $integer, 'created_at' => ['bsonType' => 'date'], 'updated_at' => ['bsonType' => 'date'],
                    'images' => ['bsonType' => 'array', 'maxItems' => 100, 'items' => [
                        'bsonType' => 'object', 'required' => ['id', 'image_url', 'is_primary', 'sort_order'],
                        'properties' => ['id' => $integer, 'image_url' => ['bsonType' => 'string'],
                            'is_primary' => ['bsonType' => 'bool'], 'sort_order' => $integer],
                    ]],
                    'variants' => ['bsonType' => 'array', 'maxItems' => 200, 'items' => [
                        'bsonType' => 'object', 'required' => ['id', 'size', 'color', 'sku'],
                        'properties' => ['id' => $integer, 'size' => ['bsonType' => 'string'],
                            'color' => ['bsonType' => 'string'], 'sku' => ['bsonType' => ['string', 'null']]],
                    ]],
                ],
            ],
            'categories' => ['required' => ['_id', 'name', 'slug', 'parent_id', 'version'], 'properties' => [
                '_id' => $integer, 'name' => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'slug' => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 140], 'parent_id' => $nullableId,
                'version' => $integer,
            ]],
            'brands' => ['required' => ['_id', 'name'], 'properties' => [
                '_id' => ['bsonType' => 'string'], 'name' => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 120],
            ]],
        ];
        $existing = iterator_to_array($db->listCollectionNames());
        foreach ($schemas as $name => $schema) {
            $validator = ['$jsonSchema' => ['bsonType' => 'object'] + $schema];
            if ($name === 'products') {
                $validator = ['$and' => [$validator, ['$expr' => ['$and' => [
                    ['$eq' => [['$size' => '$variants'], ['$size' => ['$setUnion' => ['$variants.id', []]]]]],
                    ['$eq' => [['$size' => '$variants'], ['$size' => ['$setUnion' => [[
                        '$map' => ['input' => '$variants', 'as' => 'v', 'in' => ['size' => '$$v.size', 'color' => '$$v.color']],
                    ], []]]]]],
                    ['$eq' => ['$effective_price', ['$ifNull' => ['$sale_price', '$price']]]],
                ]]]]];
            }
            if (in_array($name, $existing, true)) {
                $db->command(['collMod' => $name, 'validator' => $validator, 'validationLevel' => 'strict', 'validationAction' => 'error']);
            } else {
                $db->createCollection($name, ['validator' => $validator]);
            }
        }
        foreach (['catalog_events', 'counters'] as $name) {
            if (!in_array($name, $existing, true)) $db->createCollection($name);
        }
        foreach (['products', 'categories'] as $name) $db->$name->createIndex(['slug' => 1], ['unique' => true]);
        $db->products->createIndex(['status' => 1, 'category_id' => 1, 'effective_price' => 1]);
        $db->products->createIndex(['status' => 1, 'created_at' => -1, '_id' => -1]);
        $db->products->createIndex(['variants.id' => 1], ['unique' => true]);
        $db->products->createIndex(['variants.size' => 1]);
        $db->products->createIndex(['variants.color' => 1]);
        $db->categories->createIndex(['parent_id' => 1]);
        $db->catalog_events->createIndex(['applied' => 1, 'created_at' => 1]);
    }
}
