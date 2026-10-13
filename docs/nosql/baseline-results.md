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
