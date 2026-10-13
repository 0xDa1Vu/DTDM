# Mô hình catalog và tính nhất quán liên hệ thống

## Dữ liệu

MongoDB: products, categories, brands, counters, catalog_events. Numeric ID cũ
được giữ để không đổi URL/form/khóa ngoại SQL. Giá Decimal128, ngày UTCDateTime.
Sản phẩm nhúng images (URL, ảnh chính, thứ tự) và variants (ID, size, màu, SKU).
Giới hạn 100 ảnh, 200 biến thể để tránh document không giới hạn.
Category/brand dùng reference; brand name có bản sao trong product phục vụ tìm
kiếm tên/thương hiệu. Brand được tạo từ tên chính xác, chưa có màn hình đổi tên
thương hiệu hàng loạt. Reviews tiếp tục ở SQL vì thuộc tính người dùng và tăng
không giới hạn theo sản phẩm. File ảnh vẫn ở public/uploads, không lưu binary
trong BSON.

Index và validator được cài bởi app/services/CatalogSchema.php. Không tạo text
index giả định tương đương LIKE: tìm chuỗi con dùng regex escaped, giới hạn ký
tự/thời gian. Tìm không dấu và collation không hoàn toàn giống MySQL; dataset
demo nhỏ. Best seller ghép số lượng order_items SQL với catalog; đây chưa phải
giải pháp tối ưu cho catalog hàng triệu sản phẩm.

## MongoDB → SQL

Mỗi lần sửa ghi document và catalog_events trong một MongoDB transaction,
write concern majority. Sự kiện chứa snapshot và version. Bộ đồng bộ dùng SQL
transaction và catalog_projection_versions để replay không trừ/cộng kho hai lần.
`scripts/sync-catalog.php` là CLI retry. Admin hiển thị số sự kiện còn chờ.

SQL products/categories/product_images là bản sao tương thích cho khóa ngoại.
`variants.stock` chỉ có MySQL là nguồn ghi. Replay catalog không cập nhật stock
của variant đã có. Nhập số tồn mới trên form tạo delta so với số lúc mở form;
delta được áp dụng một lần để bảo toàn các giao dịch bán phát sinh đồng thời.
Không coi delta là thao tác kiểm kê tuyệt đối tại một thời điểm.

Product mới chỉ mua được khi version SQL bằng version catalog đã đọc. Checkout
khóa hàng version và giảm kho có điều kiện trong cùng transaction tạo đơn.
Giá đặt hàng là snapshot catalog lúc lấy giỏ cho request checkout, không phải
cam kết linearizability xuyên hai DB. Nếu version không khớp, yêu cầu tải lại.

Variant bỏ khỏi catalog được giữ trong SQL cho lịch sử và giỏ cũ. ID không bị
đổi khi sửa giá/nội dung. Đổi size/màu cần thêm variant mới. Xoá product là ẩn
và đánh dấu deleted; chi tiết đơn giữ snapshot. Category đang có reference phải
được chuyển reference trước khi xoá. Đây là lựa chọn bảo toàn dữ liệu chủ động,
khác với cascade delete của schema cũ.

## Lỗi và giới hạn

Không có atomic commit giữa MongoDB và MySQL. Nếu MongoDB commit thành công rồi
SQL lỗi, event còn chờ và checkout bị chặn cho phiên bản đó. Không tự fallback
sang nội dung SQL cũ khi MongoDB mất kết nối. Khi client nhận timeout ghi, phải
kiểm tra danh sách/event trước khi tạo lại (chưa có idempotency key cấp HTTP).
Delta tồn kho làm tồn âm bị từ chối; cần đối soát trước khi retry event, không
tự bỏ sự kiện. CLI đồng bộ cần chạy khi có event pending; demo chưa có daemon
retry tự động. Admin update có optimistic version; transaction category và
product riêng chưa giải quyết mọi race thay đổi tham chiếu giữa nhiều admin.

## Kiểm thử

```bash
bash scripts/demo.sh exec -T web php scripts/sync-catalog.php
bash scripts/demo.sh exec -T web php tests/catalog.php
bash scripts/demo.sh exec -T web php tests/concurrent-checkout.php
python3 tests/web-smoke.py
```

Test tự tạo dữ liệu có ID riêng và dọn đúng dữ liệu đó, chỉ trong demo. SQL
orders/history ban đầu không bị xoá. Counter/auto-increment có thể có khoảng
trống sau test, đó là hành vi hợp lệ.
