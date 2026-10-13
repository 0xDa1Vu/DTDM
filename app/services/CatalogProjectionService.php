<?php
namespace App\Services;

use App\Core\Database;
use App\Core\MongoDatabase;

final class CatalogProjectionService
{
    public static function install(): void
    {
        MongoDatabase::requireDemo();
        Database::conn()->exec('CREATE TABLE IF NOT EXISTS catalog_projection_versions (
            kind VARCHAR(20) NOT NULL, entity_id INT NOT NULL, version INT NOT NULL,
            PRIMARY KEY(kind, entity_id)
        ) ENGINE=InnoDB');
        $stmt = Database::conn()->prepare('INSERT IGNORE INTO catalog_projection_versions VALUES (?, ?, ?)');
        foreach (['categories', 'products'] as $name) {
            foreach (MongoDatabase::db()->selectCollection($name)->find(['version' => 1, '_migration_hash' => ['$exists' => true]]) as $doc) {
                $stmt->execute([$name, $doc['_id'], 1]);
            }
        }
    }

    public static function version(string $kind, int $id, bool $lock = false): int
    {
        $s = Database::conn()->prepare('SELECT version FROM catalog_projection_versions WHERE kind = ? AND entity_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$kind, $id]);
        return (int) $s->fetchColumn();
    }

    public static function sync(): int
    {
        $sql = Database::conn();
        if ((int) $sql->query("SELECT GET_LOCK('dtdm_catalog_projection', 5)")->fetchColumn() !== 1) {
            throw new \RuntimeException('Đồng bộ catalog đang bận.');
        }
        $count = 0;
        try {
            foreach (MongoDatabase::db()->catalog_events->find(['applied' => false], ['sort' => ['created_at' => 1, 'version' => 1]]) as $event) {
                self::apply($event);
                MongoDatabase::db()->catalog_events->updateOne(['_id' => $event['_id']], ['$set' => ['applied' => true]]);
                $count++;
            }
        } finally {
            $sql->query("SELECT RELEASE_LOCK('dtdm_catalog_projection')");
        }
        return $count;
    }

    private static function apply(array $event): void
    {
        $sql = Database::conn();
        $doc = $event['document'];
        $kind = $event['kind'];
        $sql->beginTransaction();
        try {
            $sql->prepare('INSERT IGNORE INTO catalog_projection_versions VALUES (?, ?, 0)')->execute([$kind, $doc['_id']]);
            $version = self::version($kind, $doc['_id'], true);
            if ($version < $doc['version']) {
                if ($version + 1 !== $doc['version']) throw new \RuntimeException('Thiếu sự kiện đồng bộ catalog.');
                if ($kind === 'categories') {
                    if (!empty($doc['deleted'])) {
                        $sql->prepare('DELETE FROM categories WHERE id = ?')->execute([$doc['_id']]);
                    } else {
                        $sql->prepare('INSERT INTO categories (id,name,slug,parent_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), slug=VALUES(slug), parent_id=VALUES(parent_id)')
                            ->execute([$doc['_id'], $doc['name'], $doc['slug'], $doc['parent_id']]);
                    }
                } else {
                    $sql->prepare('INSERT INTO products (id,category_id,name,slug,description,price,sale_price,brand,status,is_featured,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), name=VALUES(name), slug=VALUES(slug), description=VALUES(description), price=VALUES(price), sale_price=VALUES(sale_price), brand=VALUES(brand), status=VALUES(status), is_featured=VALUES(is_featured)')
                        ->execute([$doc['_id'], $doc['category_id'], $doc['name'], $doc['slug'], $doc['description'],
                            (string) $doc['price'], $doc['sale_price'] === null ? null : (string) $doc['sale_price'],
                            $doc['brand'], $doc['status'], (int) $doc['is_featured'], $doc['created_at']->toDateTime()->format('Y-m-d H:i:s')]);
                    // Removed variants remain in SQL to preserve order and cart references.
                    // Stock is written only for new variants or explicit inventory adjustment events.
                    foreach ($doc['variants'] as $variant) {
                        $stock = (int) ($event['initial_stock'][(string) $variant['id']] ?? 0);
                        $sql->prepare('INSERT INTO variants (id,product_id,size,color,sku,stock) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE size=VALUES(size), color=VALUES(color), sku=VALUES(sku)')
                            ->execute([$variant['id'], $doc['_id'], $variant['size'], $variant['color'], $variant['sku'], $stock]);
                    }
                    foreach ($event['stock_adjustments'] ?? [] as $adjustment) {
                        // Delta is applied once with the projection version in the same SQL transaction.
                        $s = $sql->prepare('UPDATE variants SET stock = stock + ? WHERE id = ? AND stock + ? >= 0');
                        $s->execute([$adjustment['delta'], $adjustment['id'], $adjustment['delta']]);
                        if ($s->rowCount() !== 1) throw new \RuntimeException('Điều chỉnh tồn kho không hợp lệ; cần đối soát.');
                    }
                    $sql->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$doc['_id']]);
                    $imageStmt = $sql->prepare('INSERT INTO product_images (id,product_id,image_url,is_primary,sort_order) VALUES (?,?,?,?,?)');
                    foreach ($doc['images'] as $image) $imageStmt->execute([$image['id'], $doc['_id'], $image['image_url'], (int) $image['is_primary'], $image['sort_order']]);
                }
                $sql->prepare('UPDATE catalog_projection_versions SET version = ? WHERE kind = ? AND entity_id = ?')->execute([$doc['version'], $kind, $doc['_id']]);
            }
            $sql->commit();
        } catch (\Throwable $e) {
            if ($sql->inTransaction()) $sql->rollBack();
            throw $e;
        }
    }
}
