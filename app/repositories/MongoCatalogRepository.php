<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\MongoDatabase;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

final class MongoCatalogRepository
{
    public function document(string $collection, int $id): ?array
    {
        return MongoDatabase::db()->selectCollection($collection)->findOne(['_id' => $id]);
    }

    public static function row(array $doc): array
    {
        $doc['id'] = $doc['_id'];
        unset($doc['_id'], $doc['_migration_hash']);
        foreach ($doc as $key => $value) {
            if ($value instanceof Decimal128) $doc[$key] = (string) $value;
            if ($value instanceof UTCDateTime) $doc[$key] = $value->toDateTime()->format('Y-m-d H:i:s');
        }
        if (isset($doc['images'])) {
            $images = $doc['images'];
            usort($images, fn($a, $b) => [(int)!$a['is_primary'], $a['id']] <=> [(int)!$b['is_primary'], $b['id']]);
            $doc['image'] = $images[0]['image_url'] ?? null;
        }
        return $doc;
    }

    public function find(string $collection, int $id): ?array
    {
        $doc = $this->document($collection, $id);
        return $doc ? self::row($doc) : null;
    }

    public function bySlug(string $collection, string $slug): ?array
    {
        $doc = MongoDatabase::db()->selectCollection($collection)->findOne(['slug' => $slug]);
        return $doc ? self::row($doc) : null;
    }

    public function all(string $collection, array $filter = [], array $sort = ['_id' => -1], int $limit = 0): array
    {
        return array_map(self::row(...), MongoDatabase::db()->selectCollection($collection)->find($filter, ['sort' => $sort, 'limit' => $limit])->toArray());
    }

    public function browse(array $f): array
    {
        $filter = ['status' => 'active'];
        if (!empty($f['category_id'])) $filter['category_id'] = (int) $f['category_id'];
        if (!empty($f['q']) && is_string($f['q'])) {
            $regex = new Regex(preg_quote(substr($f['q'], 0, 200), '/'), 'i');
            $filter['$or'] = [['name' => $regex], ['brand' => $regex]];
        }
        foreach (['min_price' => '$gte', 'max_price' => '$lte'] as $input => $op) {
            if (isset($f[$input]) && $f[$input] !== '' && is_numeric($f[$input])) {
                $filter['effective_price'][$op] = new Decimal128((string) $f[$input]);
            }
        }
        // Preserve the existing independent EXISTS semantics of size/color filters.
        foreach (['size', 'color'] as $field) {
            $values = array_values(array_filter((array) ($f[$field] ?? []), 'is_string'));
            if ($values) $filter['variants.' . $field] = ['$in' => array_slice($values, 0, 30)];
        }
        $sort = match ($f['sort'] ?? '') {
            'price_asc' => ['effective_price' => 1, '_id' => 1],
            'price_desc' => ['effective_price' => -1, '_id' => 1],
            'name' => ['name' => 1, '_id' => 1],
            default => ['created_at' => -1, '_id' => -1],
        };
        $per = min(100, max(1, (int) ($f['per_page'] ?? 12)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $collection = MongoDatabase::db()->products;
        $total = $collection->countDocuments($filter);
        $rows = $collection->find($filter, ['sort' => $sort, 'skip' => ($page - 1) * $per, 'limit' => $per, 'maxTimeMS' => 3000])->toArray();
        return ['items' => array_map(self::row(...), $rows), 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per)];
    }

    public function withCategory(int $id): ?array
    {
        $product = $this->find('products', $id);
        if (!$product) return null;
        $category = empty($product['category_id']) ? null : $this->find('categories', $product['category_id']);
        return $product + ['category_name' => $category['name'] ?? null, 'category_slug' => $category['slug'] ?? null];
    }

    public function bestSellers(int $limit, bool $onlySold = false): array
    {
        $sales = Database::conn()->query('SELECT v.product_id, SUM(oi.quantity) AS sold FROM variants v JOIN order_items oi ON oi.variant_id = v.id GROUP BY v.product_id')->fetchAll();
        $counts = array_column($sales, 'sold', 'product_id');
        $products = $this->all('products', ['status' => 'active']);
        foreach ($products as &$p) $p['sold'] = (int) ($counts[$p['id']] ?? 0);
        unset($p);
        if ($onlySold) $products = array_values(array_filter($products, fn($p) => $p['sold'] > 0));
        usort($products, fn($a, $b) => [$b['sold'], $b['created_at'], $b['id']] <=> [$a['sold'], $a['created_at'], $a['id']]);
        return array_slice($products, 0, $limit);
    }

    public function images(int $productId): array
    {
        $doc = $this->document('products', $productId);
        $images = $doc['images'] ?? [];
        usort($images, fn($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);
        return array_map(fn($i) => $i + ['product_id' => $productId], $images);
    }

    public function image(int $id): ?array
    {
        $doc = MongoDatabase::db()->products->findOne(['images.id' => $id]);
        foreach ($doc['images'] ?? [] as $image) if ($image['id'] === $id) return $image + ['product_id' => $doc['_id']];
        return null;
    }

    public function variants(int $productId): array
    {
        $doc = $this->document('products', $productId);
        $stmt = Database::conn()->prepare('SELECT id, stock FROM variants WHERE product_id = ?');
        $stmt->execute([$productId]);
        $stock = array_column($stmt->fetchAll(), 'stock', 'id');
        $rows = array_map(fn($v) => $v + ['product_id' => $productId, 'stock' => (int) ($stock[$v['id']] ?? 0)], $doc['variants'] ?? []);
        usort($rows, fn($a, $b) => [$a['size'], $a['color']] <=> [$b['size'], $b['color']]);
        return $rows;
    }

    public function variant(int $id): ?array
    {
        $doc = MongoDatabase::db()->products->findOne(['variants.id' => $id, 'status' => 'active']);
        if (!$doc) return null;
        $row = self::row($doc);
        foreach ($this->variants($row['id']) as $variant) {
            if ($variant['id'] === $id) return $variant + [
                'product_name' => $row['name'], 'product_slug' => $row['slug'],
                'price' => $row['price'], 'sale_price' => $row['sale_price'], 'image' => $row['image'], 'catalog_version' => $row['version'],
            ];
        }
        return null;
    }
}
