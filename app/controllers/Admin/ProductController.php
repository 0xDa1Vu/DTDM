<?php
namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Str;
use App\Models\Product;
use App\Models\Category;
use App\Models\Variant;
use App\Models\ProductImage;
use App\Core\MongoDatabase;
use App\Services\CatalogService;

class ProductController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $this->view('admin/products/index', [
            'title'    => 'Quản lý sản phẩm',
            'products' => (new Product())->all(),
            'pendingSync' => MongoDatabase::enabled() ? MongoDatabase::db()->catalog_events->countDocuments(['applied' => false]) : 0,
        ], 'admin');
    }

    public function create(): void
    {
        Auth::requireRole('admin');
        $this->view('admin/products/form', [
            'title'      => 'Thêm sản phẩm',
            'backUrl'    => '/admin/products',
            'product'    => null,
            'variants'   => [],
            'images'     => [],
            'categories' => (new Category())->allOrdered(),
        ], 'admin');
    }

    public function store(): void
    {
        Auth::requireRole('admin');
        Csrf::verify();
        if (MongoDatabase::enabled()) {
            $this->saveMongo(null);
            return;
        }
        $id = (new Product())->create($this->payload());
        $this->saveVariants($id);
        $this->saveImages($id);
        Session::flash('success', 'Đã thêm sản phẩm.');
        $this->redirect('/admin/products');
    }

    public function edit(string $id): void
    {
        Auth::requireRole('admin');
        $product = (new Product())->find((int) $id);
        if (!$product) { $this->redirect('/admin/products'); }
        $this->view('admin/products/form', [
            'title'      => 'Sửa sản phẩm',
            'backUrl'    => '/admin/products',
            'product'    => $product,
            'variants'   => (new Variant())->forProduct((int) $id),
            'images'     => (new ProductImage())->forProduct((int) $id),
            'categories' => (new Category())->allOrdered(),
        ], 'admin');
    }

    public function update(string $id): void
    {
        Auth::requireRole('admin');
        Csrf::verify();
        if (MongoDatabase::enabled()) {
            $this->saveMongo((int) $id);
            return;
        }
        (new Product())->modify((int) $id, $this->payload());
        // Thay toàn bộ variant (đơn giản cho đồ án)
        (new Variant())->deleteForProduct((int) $id);
        $this->saveVariants((int) $id);
        $this->saveImages((int) $id); // thêm ảnh mới nếu có upload
        Session::flash('success', 'Đã cập nhật sản phẩm.');
        $this->redirect('/admin/products/edit/' . (int) $id);
    }

    public function destroy(string $id): void
    {
        Auth::requireRole('admin');
        Csrf::verify();
        (new Product())->delete((int) $id);
        Session::flash('success', 'Đã xoá sản phẩm.');
        $this->redirect('/admin/products');
    }

    public function deleteImage(string $id): void
    {
        Auth::requireRole('admin');
        Csrf::verify();
        $imageModel = new ProductImage();
        $img = $imageModel->find((int) $id);
        if ($img) {
            $imageModel->deleteOne((int) $id);
            $file = dirname(__DIR__, 3) . '/public' . $img['image_url'];
            if (str_starts_with($img['image_url'], '/uploads/') && is_file($file)) {
                @unlink($file);
            }
        }
        $productId = $img['product_id'] ?? 0;
        Session::flash('success', 'Đã xoá ảnh.');
        $this->redirect('/admin/products/edit/' . $productId);
    }

    public function setPrimaryImage(string $id): void
    {
        Auth::requireRole('admin');
        Csrf::verify();
        $imageModel = new ProductImage();
        $img = $imageModel->find((int) $id);
        if ($img) {
            $imageModel->setPrimary((int) $id, (int) $img['product_id']);
        }
        $this->redirect('/admin/products/edit/' . ($img['product_id'] ?? 0));
    }

    public function reorderImages(): void
    {
        Auth::requireRole('admin');
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input'), true);
        if (!Csrf::check($body['_csrf'] ?? null)) { $this->json(['ok' => false], 403); }
        $ids  = array_filter((array)($body['ids'] ?? []), 'is_numeric');
        if ($ids) {
            (new ProductImage())->reorder(array_values($ids));
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- helpers ----

    private function saveMongo(?int $id): void
    {
        $variants = [];
        foreach ((array) ($_POST['v_size'] ?? []) as $i => $size) {
            if (trim((string) $size) === '' && trim((string) ($_POST['v_color'][$i] ?? '')) === '') continue;
            $row = ['id' => $_POST['v_id'][$i] ?? 0, 'size' => $size,
                'color' => $_POST['v_color'][$i] ?? '', 'stock' => $_POST['v_stock'][$i] ?? 0];
            if (isset($_POST['v_original_stock'][$i]) && $_POST['v_original_stock'][$i] !== '') $row['original_stock'] = $_POST['v_original_stock'][$i];
            $variants[] = $row;
        }
        try {
            $payload = $this->payload();
            if ($id) $payload['version'] = (int) ($_POST['catalog_version'] ?? 0);
            $savedId = (new CatalogService())->saveProduct($id, $payload, $variants);
            $this->saveImages($savedId);
            Session::flash('success', 'Đã lưu catalog MongoDB và đồng bộ dữ liệu giao dịch.');
            $this->redirect('/admin/products/edit/' . $savedId);
        } catch (\Throwable $e) {
            error_log((string) $e);
            Session::flash('error', $e instanceof \MongoDB\Driver\Exception\Exception ? 'Không thể lưu MongoDB: kiểm tra slug, dữ liệu hoặc kết nối; tải lại danh sách trước khi thử tiếp.' : $e->getMessage());
            $this->redirect($id ? '/admin/products/edit/' . $id : '/admin/products');
        }
    }

    private function payload(): array
    {
        $name = $this->input('name');
        return [
            'category_id' => $this->input('category_id') ?: null,
            'name'        => $name,
            'slug'        => $this->input('slug') ?: Str::slug($name) . '-' . substr(uniqid(), -4),
            'description' => $this->input('description'),
            'price'       => $this->input('price'),
            'sale_price'  => $this->input('sale_price'),
            'brand'       => $this->input('brand'),
            'status'      => $this->input('status') === 'hidden' ? 'hidden' : 'active',
            'is_featured' => $this->input('is_featured') === '1',
        ];
    }

    private function saveVariants(int $productId): void
    {
        $sizes  = $_POST['v_size']  ?? [];
        $colors = $_POST['v_color'] ?? [];
        $stocks = $_POST['v_stock'] ?? [];
        $variant = new Variant();
        foreach ($sizes as $i => $size) {
            if (trim($size) === '' || trim($colors[$i] ?? '') === '') {
                continue;
            }
            $variant->add($productId, [
                'size'  => trim($size),
                'color' => trim($colors[$i]),
                'stock' => (int) ($stocks[$i] ?? 0),
                'sku'   => $productId . '-' . Str::slug($size . '-' . $colors[$i]),
            ]);
        }
    }

    private function saveImages(int $productId): void
    {
        if (empty($_FILES['images']['name'][0])) {
            return;
        }
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

        $imageModel = new ProductImage();
        $existing = $imageModel->forProduct($productId);
        $hasPrimary = !empty($existing);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) { continue; }
            if ($_FILES['images']['size'][$i] > 5 * 1024 * 1024) { continue; } // 5MB
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed, true)) { continue; }
            $ext = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
            $fname = bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
            if (move_uploaded_file($tmp, $uploadDir . $fname)) {
                $imageModel->add($productId, '/uploads/' . $fname, !$hasPrimary);
                $hasPrimary = true;
            }
        }
    }
}
