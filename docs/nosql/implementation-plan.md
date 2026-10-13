# Kế hoạch triển khai NoSQL và CAP

Phạm vi được chốt trong trao đổi: giữ PHP MVC và giao diện ATELIER; MongoDB
quản lý catalog; MySQL giữ giao dịch/tồn kho; không tác động database gốc.
Mỗi nhiệm vụ hoàn thành được kiểm tra, commit và push riêng lên origin/DTDM.

## Nhiệm vụ và kiểm thử

1. Môi trường demo riêng, autoload Linux, backup và baseline. File: bootstrap,
   compose demo, scripts/demo.sh, scripts/backup-demo.sh. Kiểm tra Compose,
   bash syntax, trang chủ/login/products và restore backup vào database thử.
2. MongoDB Replica Set 3 node, driver PHP, schema, index và migration idempotent.
   Kiểm tra vai trò node, validation, đối soát dữ liệu SQL/MongoDB và chạy lại.
3. Tầng catalog MongoDB và bản sao SQL tương thích. Giữ numeric ID, nhúng ảnh/
   thuộc tính biến thể, tồn kho chỉ ghi ở SQL. Kiểm tra CRUD, tìm kiếm, biến thể,
   lỗi đồng bộ, đơn hàng đồng thời và quyền truy cập.
4. Dashboard CAP và script mạng chỉ chạy local. Kiểm tra phân quyền, timeout,
   cô lập hai chiều đúng node, recovery lặp lại và log ngoài cluster.
5. Chạy 4 kịch bản, lưu evidence thực, viết báo cáo 5 chương và demo guide.
   Không điền số liệu dự kiến vào phần kết quả thực nghiệm.

## Thiết kế và ranh giới

- `products`: ID số cũ, giá decimal, ảnh và thuộc tính biến thể embedded;
  `categories`/`brands` reference. Category parent là reference.
- Giữ model trả PHP array để tái sử dụng view. Model SQL khác giữ nguyên PDO.
- MongoDB là nguồn nội dung catalog; SQL là nguồn tồn kho/đơn/thanh toán.
  Không xem hai transaction riêng là một transaction phân tán.
- Sự kiện catalog có version và được áp dụng idempotent sang SQL; không ghi
  đè stock khi replay nội dung. Catalog mới chưa đồng bộ không được checkout.
- CAP dùng dữ liệu probe riêng trên cùng Replica Set, write/read concern rõ ràng,
  client trực tiếp từng node và client Replica Set được phân biệt trong log.
- Ba container trên một máy chỉ là môi trường thực nghiệm, không chứng minh
  chịu lỗi cấp datacenter hoặc hiệu năng cloud production.

## Dữ liệu và rollback

Compose demo có project `dtdm_demo`, volume riêng, web chỉ bind loopback:8010.
`.env` cũ không bị sửa; thông số database demo được truyền bằng environment.
Không chạy `down -v` hoặc import dump vào database đang sử dụng. Migration chỉ
đọc SQL và ghi database MongoDB demo sau backup. Khi catalog đã có ghi mới,
rollback cần đồng bộ và đối soát, không đơn thuần đổi biến cấu hình.

## Trạng thái ban đầu

Commit nền: 8e02433. Docker Desktop ban đầu paused; chưa có baseline runtime.
Phát hiện tĩnh: autoload phân biệt hoa/thường trên Linux; placeholder tìm kiếm
SQL lặp; admin thay toàn bộ variants khi sửa. VNPay có code nhưng thiếu route.
Các điểm này không được coi là kiểm thử đã thất bại nếu chưa chạy.
