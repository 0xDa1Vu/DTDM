<?php
/**
 * Front controller — mọi request đi qua đây.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

/** @var \App\Core\Router $router */
$router = require dirname(__DIR__) . '/app/routes.php';

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (\Throwable $e) {
    error_log((string) $e);
    http_response_code($e instanceof \InvalidArgumentException ? 422 : 503);
    $message = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Dịch vụ tạm thời chưa sẵn sàng hoặc dữ liệu đang thay đổi. Vui lòng tải lại trang và kiểm tra trước khi thử lại.';
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Thông báo</title><p>' . e($message) . '</p><a href="/">Về trang chủ</a></html>';
    }
}
