# Môi trường đồ án độc lập

Chạy từ thư mục DTDM:

```bash
bash scripts/demo.sh up -d --build
bash scripts/demo.sh ps
bash scripts/backup-demo.sh
```

Website: http://localhost:8010. Tài khoản seed: admin@shop.test / admin123.
Chỉ dùng dữ liệu giả trong môi trường này. Không chuyển tiền vào QR demo.
Các cấu hình demo ghi đè DB trong `.env`, không đổi file `.env` của bạn.
Stack LTW và volume MySQL cũ không bị thay đổi. ZIP tài liệu ngoài repo không
được tự động đưa lên GitHub.

Backup gồm dump SQL nhất quán của demo, ảnh upload, commit và SHA256SUMS.
File dump có thể chứa thông tin người dùng nên `backups/` không được commit.
Restore phải vào database thử mới; không dùng dump để ghi đè database gốc.
Backup này **không** thay thế backup database production hoặc XAMPP riêng.

Tắt demo và giữ dữ liệu: `bash scripts/demo.sh stop`.
Không dùng `down -v` để khắc phục lỗi hoặc cập nhật phiên bản.

## Migration (trước khi bật catalog MongoDB)

```bash
bash scripts/demo.sh exec -T web php scripts/migrate-catalog.php --dry-run
bash scripts/backup-demo.sh
# Thay timestamp bằng đường dẫn backup vừa được in ra:
bash scripts/demo.sh exec -T web php scripts/migrate-catalog.php --backup=backups/<timestamp>
bash scripts/demo.sh exec -T web php scripts/verify-migration.php
bash scripts/demo.sh exec -T web php tests/schema.php
```

Migration bảo toàn ID, Decimal128 cho tiền, UTCDateTime cho ngày. Không đưa stock
vào document: tồn kho vẫn do MySQL quản lý. Có thể chạy lại nếu nguồn/đích chưa
bị sửa; nếu document đích khác nguồn, script dừng thay vì ghi đè. Verify so sánh
toàn bộ nội dung chuẩn hóa và số lượng, dành cho thời điểm trước cutover.
