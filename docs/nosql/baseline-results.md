# Kiểm tra baseline và migration — 2026-10-13

Môi trường: Docker local `dtdm_demo`, source nền 374c420 và thay đổi nhiệm vụ 2.
Không sử dụng database production hoặc volume LTW.

Đã chạy thực tế:

- `/`, `/products`, `/login`, `/cart`: HTTP 200, không có PHP warning/fatal.
- `/products?q=ao`: HTTP 200 nhưng body có PHP fatal (lỗi placeholder SQL từ
  bản cũ). HTTP status đơn lẻ không đủ chứng minh trang hoạt động.
- Replica Set rsCatalog: mongo1 PRIMARY; mongo2/mongo3 SECONDARY ở thời điểm
  kiểm tra. Vai trò có thể đổi sau election.
- Backup `20261013T111953Z`: restore vào database kiểm tra mới trong container
  MySQL demo; đối chiếu số lượng khớp: 13 products, 6 categories, 46 variants,
  28 product_images, 5 users, 36 orders, 41 order_items, 6 payments. Database
  kiểm tra được xoá sau khi đối chiếu; database nguồn không bị ghi đè.
- Migration: 13 products, 6 categories, 2 brands. Verify nội dung chuẩn hóa và
  số lượng: `ok: true`, không có sai lệch. Chạy lại migration thành công.
- PHP lint trên app/scripts: không có lỗi cú pháp.
- Composer audit sau nâng extension/library MongoDB lên 2.5.0: không có advisory.

Đây là baseline/migration, **chưa phải kết quả CAP**. Chưa kiểm chứng thanh toán
thật, chưa chạy stress test hoặc triển khai cloud nhiều máy.

## Sau tích hợp catalog MongoDB

Đã chạy tests/catalog.php: 14 kiểm tra qua, gồm CRUD sản phẩm/ảnh embedded,
escape regex, rollback thiếu kho, quyền sở hữu cart, snapshot giá đơn, bảo toàn
ID biến thể và tồn kho khi sửa catalog, replay event, optimistic version,
ẩn sản phẩm/ngăn mua. tests/concurrent-checkout.php chạy hai process đặt mua
cùng một đơn vị tồn kho: một thành công, một bị từ chối thiếu kho; tồn còn 0.
Đây là phép kiểm tra cạnh tranh hai request, không phải stress benchmark.

tests/web-smoke.py qua: trang khách/tìm kiếm/lọc, đăng nhập admin, các trang
quản trị, chi tiết sản phẩm, form biến thể và từ chối thiếu CSRF. Trong runtime
Apache này status 419 bị truyền ra thành 500; đã đổi lỗi CSRF sang HTTP 403 và
kiểm tra lại thành công. Lỗi placeholder tìm kiếm SQL cũ cũng đã được sửa.
