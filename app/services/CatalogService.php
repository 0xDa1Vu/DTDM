<?php
namespace App\Services;

use App\Core\MongoDatabase;
use App\Core\Str;
use App\Repositories\MongoCatalogRepository;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;
use MongoDB\Operation\FindOneAndUpdate;

final class CatalogService
{
    public function nextId(string $name): int
    {
        $counter = MongoDatabase::db()->counters->findOneAndUpdate(['_id' => $name], ['$inc' => ['value' => 1]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
        return (int) $counter['value'];
    }

    public function saveProduct(?int $id, array $data, ?array $variants = null): int
    {
        $repo = new MongoCatalogRepository();
        $old = $id ? $repo->document('products', $id) : null;
        if ($id && !$old) throw new \InvalidArgumentException('Không tìm thấy sản phẩm.');
        $doc = $old ?? ['_id' => $this->nextId('products'), 'version' => 0, 'images' => [], 'variants' => [], 'created_at' => new UTCDateTime()];
        if (isset($data['version']) && (int) $data['version'] !== $doc['version']) throw new \RuntimeException('Sản phẩm đã thay đổi. Tải lại trang trước khi lưu.');
        foreach (['name' => 200, 'slug' => 220] as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '' || strlen($value) > $max * 4) throw new \InvalidArgumentException("$field không hợp lệ.");
            $doc[$field] = $value;
        }
        $categoryId = empty($data['category_id']) ? null : (int) $data['category_id'];
        if ($categoryId && !MongoDatabase::db()->categories->findOne(['_id' => $categoryId, 'deleted' => ['$ne' => true]])) throw new \InvalidArgumentException('Danh mục không tồn tại.');
        $doc['category_id'] = $categoryId;
        $doc['description'] = $data['description'] ?? null;
        $doc['price'] = $this->money($data['price'] ?? '');
        $doc['sale_price'] = ($data['sale_price'] ?? '') === '' || $data['sale_price'] === null ? null : $this->money($data['sale_price']);
        if ($doc['sale_price'] !== null && (float) (string) $doc['sale_price'] > (float) (string) $doc['price']) throw new \InvalidArgumentException('Giá sale không được lớn hơn giá gốc.');
        $doc['effective_price'] = $doc['sale_price'] ?? $doc['price'];
        $doc['status'] = ($data['status'] ?? 'active') === 'active' ? 'active' : 'hidden';
        $doc['is_featured'] = !empty($data['is_featured']);
        $doc['brand'] = trim((string) ($data['brand'] ?? '')) ?: null;
        $doc['brand_id'] = $doc['brand'] ? hash('sha256', $doc['brand']) : null;
        if ($doc['brand_id']) MongoDatabase::db()->brands->updateOne(['_id' => $doc['brand_id']], ['$setOnInsert' => ['name' => $doc['brand']]], ['upsert' => true]);
        $initial = $adjustments = [];
        if ($variants !== null) {
            if (count($variants) > 200) throw new \InvalidArgumentException('Tối đa 200 biến thể.');
            $previous = array_column($doc['variants'], null, 'id');
            // Reuse retired IDs by size/color so old orders and SQL uniqueness remain valid.
            $sql = \App\Core\Database::conn()->prepare('SELECT * FROM variants WHERE product_id = ?');
            $sql->execute([$doc['_id']]);
            $retired = [];
            foreach ($sql->fetchAll() as $v) $retired[json_encode([$v['size'], $v['color']])] = $v;
            $doc['variants'] = []; $seen = [];
            foreach ($variants as $input) {
                $size = trim((string) ($input['size'] ?? '')); $color = trim((string) ($input['color'] ?? ''));
                if ($size === '' || $color === '' || strlen($size) > 80 || strlen($color) > 160) throw new \InvalidArgumentException('Size/màu không hợp lệ.');
                $key = json_encode([$size, $color]);
                if (isset($seen[$key])) throw new \InvalidArgumentException('Trùng biến thể size/màu.');
                $seen[$key] = true;
                $vid = (int) ($input['id'] ?? 0);
                if ($vid && !isset($previous[$vid])) throw new \InvalidArgumentException('ID biến thể không thuộc sản phẩm.');
                if ($vid && ($previous[$vid]['size'] !== $size || $previous[$vid]['color'] !== $color)) throw new \InvalidArgumentException('Để đổi size/màu, hãy bỏ dòng cũ và thêm biến thể mới.');
                $existing = $vid ? $previous[$vid] : ($retired[$key] ?? null);
                $vid = $existing ? (int) $existing['id'] : $this->nextId('variants');
                $stock = filter_var($input['stock'] ?? 0, FILTER_VALIDATE_INT);
                if ($stock === false || $stock < 0 || $stock > 1000000) throw new \InvalidArgumentException('Tồn kho không hợp lệ.');
                if (!$existing) $initial[(string) $vid] = $stock;
                elseif (isset($input['original_stock'])) {
                    $base = filter_var($input['original_stock'], FILTER_VALIDATE_INT);
                    if ($base === false || $base < 0) throw new \InvalidArgumentException('Mốc tồn kho không hợp lệ.');
                    $delta = $stock - $base;
                    if ($delta) $adjustments[] = ['id' => $vid, 'delta' => $delta];
                }
                $doc['variants'][] = ['id' => $vid, 'size' => $size, 'color' => $color, 'sku' => $existing['sku'] ?? ('CAT-' . $vid)];
            }
        }
        $this->persist('products', $doc, $initial, $adjustments);
        return $doc['_id'];
    }

    private function money($value): Decimal128
    {
        $value = (string) $value;
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) throw new \InvalidArgumentException('Giá phải là số không âm, tối đa hai chữ số thập phân.');
        return new Decimal128(number_format((float) $value, 2, '.', ''));
    }

    public function saveCategory(?int $id, array $data): int
    {
        $repo = new MongoCatalogRepository();
        $old = $id ? $repo->document('categories', $id) : null;
        if ($id && !$old) throw new \InvalidArgumentException('Danh mục không tồn tại.');
        $doc = $old ?? ['_id' => $this->nextId('categories'), 'version' => 0];
        $doc['name'] = trim((string) ($data['name'] ?? '')); $doc['slug'] = trim((string) ($data['slug'] ?? ''));
        $doc['parent_id'] = empty($data['parent_id']) ? null : (int) $data['parent_id'];
        $seen = [$doc['_id']]; $parent = $doc['parent_id'];
        while ($parent !== null) {
            if (in_array($parent, $seen, true)) throw new \InvalidArgumentException('Danh mục cha tạo thành vòng lặp.');
            $seen[] = $parent;
            $row = $repo->document('categories', $parent);
            if (!$row || !empty($row['deleted'])) throw new \InvalidArgumentException('Danh mục cha không tồn tại.');
            $parent = $row['parent_id'];
        }
        $this->persist('categories', $doc);
        return $doc['_id'];
    }

    public function delete(string $collection, int $id): bool
    {
        $doc = (new MongoCatalogRepository())->document($collection, $id);
        if (!$doc) return false;
        if ($collection === 'categories' && (MongoDatabase::db()->products->countDocuments(['category_id' => $id]) || MongoDatabase::db()->categories->countDocuments(['parent_id' => $id, 'deleted' => ['$ne' => true]]))) {
            throw new \RuntimeException('Chuyển sản phẩm và danh mục con sang danh mục khác trước khi xoá.');
        }
        $doc['deleted'] = true;
        if ($collection === 'products') $doc['status'] = 'hidden';
        $this->persist($collection, $doc);
        return true;
    }

    public function changeImages(int $productId, callable $change): void
    {
        $doc = (new MongoCatalogRepository())->document('products', $productId);
        if (!$doc) throw new \InvalidArgumentException('Sản phẩm không tồn tại.');
        $doc['images'] = array_values($change($doc['images']));
        $this->persist('products', $doc);
    }

    private function persist(string $kind, array $doc, array $initial = [], array $adjustments = []): void
    {
        $db = MongoDatabase::db();
        $previousVersion = $doc['version'];
        $doc['version']++;
        $doc['updated_at'] = new UTCDateTime();
        unset($doc['_migration_hash']);
        $session = MongoDatabase::client()->startSession();
        $session->startTransaction(['readConcern' => new ReadConcern('snapshot'), 'writeConcern' => new WriteConcern('majority', 4000)]);
        try {
            $collection = $db->selectCollection($kind);
            if ($previousVersion === 0) $collection->insertOne($doc, ['session' => $session]);
            else {
                $result = $collection->replaceOne(['_id' => $doc['_id'], 'version' => $previousVersion], $doc, ['session' => $session]);
                if (!$result->getMatchedCount()) throw new \RuntimeException('Catalog đã thay đổi. Tải lại trang.');
            }
            $db->catalog_events->insertOne([
                '_id' => "$kind:{$doc['_id']}:{$doc['version']}", 'kind' => $kind, 'version' => $doc['version'],
                'document' => $doc, 'initial_stock' => (object) $initial, 'stock_adjustments' => $adjustments,
                'created_at' => new UTCDateTime(), 'applied' => false,
            ], ['session' => $session]);
            $session->commitTransaction();
        } catch (\Throwable $e) {
            if ($session->isInTransaction()) $session->abortTransaction();
            throw $e;
        }
        // Mongo commit is durable first. A failed SQL projection remains retryable.
        try { CatalogProjectionService::sync(); }
        catch (\Throwable $e) {
            throw new \RuntimeException('Catalog đã lưu nhưng đang chờ đồng bộ MySQL. Chạy scripts/sync-catalog.php; không gửi lại thao tác tạo mới.', 0, $e);
        }
    }
}
