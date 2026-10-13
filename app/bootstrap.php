<?php
/**
 * Bootstrap: autoloader PSR-4 đơn giản + khởi tạo session.
 * Namespace App\  ->  thư mục app/
 */
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    // Namespace App\\Core maps to app/core on case-sensitive Linux filesystems.
    $parts[0] = strtolower($parts[0]);
    $file = __DIR__ . '/' . implode('/', $parts) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Session;

Session::start();

// Cấu hình toàn cục (có thể require ở nơi cần)
$GLOBALS['config'] = require dirname(__DIR__) . '/config/config.php';

/** Helper escape HTML (chống XSS) — dùng khắp view */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Helper format tiền VND */
function vnd($amount): string
{
    return number_format((float) $amount, 0, ',', '.') . '₫';
}

/** Lấy config theo dot-path: cfg('vnpay.url') */
function cfg(string $path, $default = null)
{
    $parts = explode('.', $path);
    $val = $GLOBALS['config'];
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) {
            return $default;
        }
        $val = $val[$p];
    }
    return $val;
}
