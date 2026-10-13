<?php
namespace App\Services;

use App\Core\Database;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;

final class CatalogSnapshot
{
    public static function fromSql(): array
    {
        $sql = Database::conn();
        $sql->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $sql->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $result = ['categories' => [], 'brands' => [], 'products' => []];
            foreach ($sql->query('SELECT * FROM categories ORDER BY id')->fetchAll() as $row) {
                $result['categories'][] = ['_id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'],
                    'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'], 'version' => 1];
            }
            $images = $variants = [];
            foreach ($sql->query('SELECT * FROM product_images ORDER BY sort_order, id')->fetchAll() as $row) {
                $images[$row['product_id']][] = ['id' => (int) $row['id'], 'image_url' => $row['image_url'],
                    'is_primary' => (bool) $row['is_primary'], 'sort_order' => (int) $row['sort_order']];
            }
            foreach ($sql->query('SELECT * FROM variants ORDER BY id')->fetchAll() as $row) {
                $variants[$row['product_id']][] = ['id' => (int) $row['id'], 'size' => $row['size'],
                    'color' => $row['color'], 'sku' => $row['sku']];
            }
            foreach ($sql->query('SELECT * FROM products ORDER BY id')->fetchAll() as $row) {
                $brand = trim($row['brand'] ?? '');
                $brandId = $brand === '' ? null : hash('sha256', $brand);
                if ($brandId) $result['brands'][$brandId] = ['_id' => $brandId, 'name' => $brand];
                $result['products'][] = [
                    '_id' => (int) $row['id'], 'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                    'name' => $row['name'], 'slug' => $row['slug'], 'description' => $row['description'],
                    'price' => new Decimal128($row['price']),
                    'sale_price' => $row['sale_price'] === null ? null : new Decimal128($row['sale_price']),
                    'effective_price' => new Decimal128($row['sale_price'] ?? $row['price']),
                    'brand_id' => $brandId, 'brand' => $brand ?: null,
                    'status' => $row['status'], 'is_featured' => (bool) $row['is_featured'],
                    'created_at' => new UTCDateTime(new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC'))),
                    'images' => $images[$row['id']] ?? [], 'variants' => $variants[$row['id']] ?? [], 'version' => 1,
                ];
            }
            $result['brands'] = array_values($result['brands']);
            $sql->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($sql->inTransaction()) $sql->rollBack();
            throw $e;
        }
    }

    public static function hash(array $document): string
    {
        unset($document['_migration_hash']);
        // Canonical field order for BSON objects and nested arrays.
        $normalize = function ($value) use (&$normalize) {
            if (is_array($value)) {
                if (!array_is_list($value)) ksort($value);
                return array_map($normalize, $value);
            }
            if ($value instanceof Decimal128) return ['decimal' => (string) $value];
            if ($value instanceof UTCDateTime) return ['date' => (string) $value];
            return $value;
        };
        return hash('sha256', json_encode($normalize($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
