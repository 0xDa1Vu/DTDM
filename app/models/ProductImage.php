<?php
namespace App\Models;

use App\Core\Model;
use App\Core\MongoDatabase;
use App\Repositories\MongoCatalogRepository;
use App\Services\CatalogService;

class ProductImage extends Model
{
    protected string $table = 'product_images';

    public function find(int $id): ?array
    {
        return MongoDatabase::enabled() ? (new MongoCatalogRepository())->image($id) : parent::find($id);
    }

    public function forProduct(int $productId): array
    {
        if (MongoDatabase::enabled()) return (new MongoCatalogRepository())->images($productId);
        $stmt = $this->db->prepare(
            'SELECT * FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function add(int $productId, string $url, bool $primary = false): int
    {
        if (MongoDatabase::enabled()) {
            $service = new CatalogService(); $id = $service->nextId('images');
            $service->changeImages($productId, function ($images) use ($id, $url, $primary) {
                if ($primary) foreach ($images as &$image) $image['is_primary'] = false;
                unset($image);
                $images[] = ['id' => $id, 'image_url' => $url, 'is_primary' => $primary, 'sort_order' => count($images)];
                return $images;
            });
            return $id;
        }
        return $this->insert([
            'product_id' => $productId,
            'image_url'  => $url,
            'is_primary' => $primary ? 1 : 0,
        ]);
    }

    public function deleteForProduct(int $productId): void
    {
        if (MongoDatabase::enabled()) { (new CatalogService())->changeImages($productId, fn($images) => []); return; }
        $stmt = $this->db->prepare('DELETE FROM product_images WHERE product_id = :pid');
        $stmt->execute(['pid' => $productId]);
    }

    public function deleteOne(int $id): ?string
    {
        $row = $this->find($id);
        if (!$row) return null;
        if (MongoDatabase::enabled()) {
            (new CatalogService())->changeImages($row['product_id'], fn($images) => array_filter($images, fn($i) => $i['id'] !== $id));
            return $row['image_url'];
        }
        $this->db->prepare('DELETE FROM product_images WHERE id = :id')->execute(['id' => $id]);
        return $row['image_url'];
    }

    public function reorder(array $ids): void
    {
        if (MongoDatabase::enabled()) {
            $first = $this->find((int) ($ids[0] ?? 0));
            if (!$first) return;
            $order = array_flip(array_map('intval', $ids));
            (new CatalogService())->changeImages($first['product_id'], function ($images) use ($order) {
                foreach ($images as &$image) if (isset($order[$image['id']])) $image['sort_order'] = $order[$image['id']];
                unset($image); return $images;
            });
            return;
        }
        $stmt = $this->db->prepare('UPDATE product_images SET sort_order = :ord WHERE id = :id');
        foreach ($ids as $order => $id) {
            $stmt->execute(['ord' => $order, 'id' => (int) $id]);
        }
    }

    public function setPrimary(int $id, int $productId): void
    {
        if (MongoDatabase::enabled()) {
            (new CatalogService())->changeImages($productId, function ($images) use ($id) {
                foreach ($images as &$image) $image['is_primary'] = $image['id'] === $id;
                unset($image); return $images;
            });
            return;
        }
        $this->db->prepare('UPDATE product_images SET is_primary = false WHERE product_id = :pid')
            ->execute(['pid' => $productId]);
        $this->db->prepare('UPDATE product_images SET is_primary = true WHERE id = :id')
            ->execute(['id' => $id]);
    }
}
