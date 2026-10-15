# Setup trên máy đang làm đồ án

MongoDB đã được cài **bên trong Docker**, không cần cài thêm MongoDB Community
Server bằng Homebrew. Ba container mongo1/mongo2/mongo3 là ba tiến trình MongoDB
thật. MongoDB Compass chỉ là GUI tuỳ chọn, không phải database server.

## Trên máy hiện tại

1. Mở Docker Desktop, đảm bảo trạng thái Running (Resume nếu đang Paused).
2. Mở Terminal trong thư mục DTDM:

```bash
cd /Users/mac/Documents/Edu/nam_ba/DienToanDamMay/DTDM
bash scripts/demo.sh up -d
bash scripts/demo.sh ps
```

3. Mở http://localhost:8010 để xem website.
4. Đăng nhập `admin@shop.test` / `admin123`, mở http://localhost:8010/admin/cap.
5. Dashboard phải hiển thị một PRIMARY và hai SECONDARY. Đây là trạng thái mạng
   bình thường; chưa cần chạy lệnh partition.

Database đã được seed/migrate trên máy hiện tại. **Không import lại SQL hoặc xoá
volume để setup lại.** Source gốc và database LTW/XAMPP không bị thay đổi.

## Port và cấu hình môi trường

Cấu hình `.env` và `.env.example` của DTDM dùng APP_URL `http://localhost:8010`,
DB_HOST `db`, DB_PORT `3306`, DB_NAME `catalog_demo`. Tên `db` và các tên `mongoN`
chỉ được phân giải trong mạng Docker; không chạy PHP trực tiếp trên macOS bằng
cấu hình này. CAP_DEMO_ENABLED=0 trong file để mặc định tắt; Compose demo bật 1.

`APP_URL` là URL ứng dụng, không tự thay đổi port publish của Docker. Mapping
thực tế nằm trong `docker-compose.demo.yml`: `127.0.0.1:8010:80`.
`docker-compose.yml` còn lưu cấu hình LTW cũ tại 8000; dùng `bash scripts/demo.sh`
để luôn chọn đúng stack DTDM. File `.env` riêng tư không được push GitHub.

## Xem dữ liệu MongoDB từ Terminal

```bash
bash scripts/demo.sh exec mongo1 mongosh 'mongodb://mongo1:27017,mongo2:27017,mongo3:27017/catalog_demo?replicaSet=rsCatalog'
```

Trong mongosh:

```javascript
show collections
db.products.findOne()
db.products.countDocuments()
rs.status().members.map(m => ({node: m.name, role: m.stateStr}))
```

Gõ `exit` để thoát. MongoDB chưa publish cổng ra máy host, nên Compass trên macOS
chưa thể kết nối bằng localhost:27017. Có thể cấu hình kết nối GUI riêng ở bước
tiếp theo; không cần việc đó để website và Dashboard chạy.

## Dừng và chạy lại

```bash
bash scripts/demo.sh stop
bash scripts/demo.sh up -d
```

Volume giữ dữ liệu khi stop/start. Không dùng `down -v`.

## Khi clone lên máy mới hoàn toàn

Khác với máy hiện tại, máy mới cần migrate một lần. Xem hướng dẫn đầy đủ trong
[local-demo.md](nosql/local-demo.md): khởi động với driver MySQL để lấy baseline,
backup, migration, verify, sync, sau đó bật MongoDB. Không sao chép `.env` có
thông tin thanh toán thật vào demo. Compose dùng thông số database demo riêng.

## Phạm vi đã kiểm tra

Đã chạy HTTP smoke, catalog CRUD, checkout cạnh tranh hai process, replication
đọc/ghi trên mạng bình thường và [partition/election/recovery](cap/network-validation.md).
Chưa chạy đầy đủ ma trận đọc/ghi khi phân vùng để kết luận CAP. QR demo dùng số giả; không chuyển tiền thật.
