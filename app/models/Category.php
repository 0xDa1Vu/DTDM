<?php
namespace App\Models;

use App\Core\Model;
use App\Core\MongoDatabase;
use App\Repositories\MongoCatalogRepository;
use App\Services\CatalogService;

class Category extends Model
{
    protected string $table = 'categories';
    public function find(int $id): ?array
    {
        return MongoDatabase::enabled() ? (new MongoCatalogRepository())->find('categories', $id) : parent::find($id);
    }
    public function delete(int $id): bool
    {
        return MongoDatabase::enabled() ? (new CatalogService())->delete('categories', $id) : parent::delete($id);
    }
    public function all(string $orderBy = 'id DESC'): array
    {
        return MongoDatabase::enabled() ? $this->allOrdered() : parent::all($orderBy);
    }


    public function findBySlug(string $slug): ?array
    {
        if (MongoDatabase::enabled()) { return (new MongoCatalogRepository())->bySlug('categories', $slug); }
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $d): int
    {
        if (MongoDatabase::enabled()) { return (new CatalogService())->saveCategory(null, $d); }
        return $this->insert([
            'name'      => $d['name'],
            'slug'      => $d['slug'],
            'parent_id' => $d['parent_id'] ?: null,
        ]);
    }

    public function modify(int $id, array $d): bool
    {
        if (MongoDatabase::enabled()) { (new CatalogService())->saveCategory($id, $d); return true; }
        return $this->update($id, [
            'name'      => $d['name'],
            'slug'      => $d['slug'],
            'parent_id' => $d['parent_id'] ?: null,
        ]);
    }

    public function allOrdered(): array
    {
        if (MongoDatabase::enabled()) { return (new MongoCatalogRepository())->all('categories', ['deleted' => ['$ne' => true]], ['name' => 1]); }
        return $this->db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    }

    /** Trả về mảng danh mục cha, mỗi cha có key 'children' chứa các con */
    public function allGrouped(): array
    {
        $rows = $this->allOrdered();

        $parents = [];
        $children = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                $row['children'] = [];
                $parents[$row['id']] = $row;
            } else {
                $children[(int)$row['parent_id']][] = $row;
            }
        }
        foreach ($children as $pid => $kids) {
            if (isset($parents[$pid])) {
                $parents[$pid]['children'] = $kids;
            }
        }
        return array_values($parents);
    }
}
