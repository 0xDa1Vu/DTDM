# Hướng dẫn demo và bảo vệ

Thời lượng đề xuất: 12–15 phút. Dùng Docker demo trên máy hiện tại, không import lại database. Mục tiêu là chứng minh website dùng catalog MongoDB, quan sát partition thật và giải thích kết quả bằng operation_id.

## 1. Chuẩn bị trước buổi trình bày

Mở Docker Desktop. Chạy tại thư mục repository DTDM:

```bash
bash scripts/demo.sh up -d
bash scripts/demo.sh ps
bash scripts/demo.sh exec -T web php scripts/cap/probe.php status
python3 scripts/cap/network.py inspect
```

Cần thấy ba node reachable, một Primary và hai Secondary; `network` là null; firewall không có chain DTDM_CAP. Nếu còn partition từ lần thử của chính mình, chạy recovery rồi kiểm tra lại. Không chạy đồng thời với người khác đang thử cluster.

Website: http://localhost:8010. Admin demo: `admin@shop.test` / `admin123`.
Mở sẵn trang sản phẩm, `/admin/cap`, [bảng kết quả đã lưu](../cap/matrix-results.md) và [báo cáo](bao-cao.md). Có thể chạy kiểm tra trước buổi demo:

```bash
python3 tests/web-smoke.py
python3 tests/cap-smoke.py
```

CAP smoke tạo probe riêng nhưng không partition. Đừng dùng `docker compose up` không chỉ định file: `docker-compose.yml` là cấu hình cũ ở port 8000; `scripts/demo.sh` chọn đúng demo 8010. Không dùng `down -v`, không chuyển tiền theo QR demo.

## 2. Kịch bản trình bày 12–15 phút

| Thời gian | Thao tác | Ý cần nói |
|---|---|---|
| 0–2 phút | Trang sản phẩm, tìm kiếm/lọc, admin sản phẩm | Tái sử dụng website PHP; catalog MongoDB, giao dịch SQL |
| 2–4 phút | Mô hình document và sơ đồ kiến trúc | Ảnh/biến thể nhúng; category/brand reference; stock ở MySQL |
| 4–5 phút | Dashboard, ba vai trò node | Primary là vai trò động; web observer nhìn được cả hai phía |
| 5–10 phút | Partition, ghi/đọc, recovery theo phần 3 | So sánh kết quả và operation_id, không chỉ nhìn HTTP status |
| 10–12 phút | JSON và log rollback từ lần thực nghiệm đã lưu | w:1 có thể rollback; timeout chưa xác định kết quả cuối |
| 12–15 phút | Kết luận, giới hạn và câu hỏi | Một lần demo hữu hạn; không gán MongoDB luôn CP/AP |

Để xem document trực tiếp, dùng URI replica set để không phụ thuộc mongo1 đang giữ vai trò gì:

```bash
bash scripts/demo.sh exec mongo1 mongosh 'mongodb://mongo1:27017,mongo2:27017,mongo3:27017/catalog_demo?replicaSet=rsCatalog'
```

Trong mongosh chạy `db.products.findOne()` và `db.products.getIndexes()`, sau đó `exit`. Chỉ xem dữ liệu; không sửa/xóa bằng tay.

## 3. Demo thao tác từng bước trên Dashboard

### Bước A — Bình thường

1. Ghi lại tên Primary hiện tại, gọi là **P cũ**. Dùng run_id mới, ví dụ `defense-20261015-01` (đổi suffix mỗi lần).
2. Trong form chọn Replica Set, Ghi, write concern majority, key `defense-20261015-01-shared`, value `before`.
3. Ghi lại operation_id của lệnh ghi, gọi là **ID trước**.
4. Đọc cùng key lần lượt ở mongo1, mongo2, mongo3 bằng secondaryPreferred + majority. Chỉ chuyển bước khi cả ba đọc được ID trước; nếu chưa đồng bộ, đọc lại.

Read preference/read concern chỉ áp dụng cho đọc; write concern chỉ áp dụng cho ghi. Giá trị probe là chuỗi thử nghiệm, không làm thay đổi giá sản phẩm thật.

### Bước B — Partition và election

Chạy lệnh Dashboard gợi ý cho **P cũ**. Ví dụ dưới chỉ đúng khi P cũ đang là mongo1:

```bash
python3 scripts/cap/network.py partition mongo1
```

Theo dõi Dashboard đến khi phía hai node có Primary mới (**P mới**) và P cũ không còn Primary. Đợi theo trạng thái thực, không đếm đúng 10 giây rồi mặc định election xong. Nếu sau khoảng 90 giây chưa ổn định, recovery, lưu lỗi và chuyển sang bằng chứng đã có.

Script chặn liên lạc MongoDB hai chiều bằng firewall; không dừng container. Web vẫn nhìn thấy node cô lập. Reachable từ web không có nghĩa node đó liên lạc được với các peer.

### Bước C — So sánh hai phía

| Target | Thao tác/cấu hình | Cách quan sát |
|---|---|---|
| P mới | Ghi majority, cùng key shared, value `after` | Chờ OK và ghi lại **ID sau** |
| P mới | Đọc primary + linearizable | Đối chiếu ID sau |
| P cũ | Đọc secondaryPreferred + local | Đối chiếu với ID trước/ID sau |
| P cũ | Đọc secondaryPreferred + majority | Có thể đọc giá trị đã commit trước partition nhưng đã cũ |
| P cũ | Đọc primary + linearizable | Sau stepdown không đáp ứng linearizable read |
| P cũ | Ghi w:1 vào key mới `defense-20261015-01-isolated` | Sau stepdown không được phép ghi |

Phải có một ghi mới được majority xác nhận trước khi kết luận một lần đọc là stale. Nếu lệnh ghi báo timeout, không coi mặc nhiên là chưa ghi; giữ operation_id và đối soát sau recovery. Để thử lại, dùng key riêng hoặc ghi rõ lần ghi đè để tránh báo nhầm mất dữ liệu.

Cửa sổ P cũ còn nhận w:1 sau khi cắt mạng có thể rất ngắn; thao tác tay không bảo đảm bắt được. Không chỉnh timeout/election để ép kết quả khi đang bảo vệ. Dùng runner hoặc bằng chứng đã lưu để trình bày rollback.

### Bước D — Recovery

```bash
python3 scripts/cap/network.py recover
bash scripts/demo.sh exec -T web php scripts/cap/probe.php status
python3 scripts/cap/network.py inspect
```

Chờ một Primary/hai Secondary và network=null. Đọc majority cùng key trên cả ba node, đối chiếu ID sau. Với các key ghi lỗi, kiểm tra có tồn tại operation_id không. Nhấn **Tải log JSONL** trên Dashboard. Không yêu cầu Primary cũ phải giành lại vai trò.

## 4. Chạy tự động để thu bằng chứng mới

Khi không có partition đang bật:

```bash
python3 tests/cap-matrix.py
```

Runner tự chọn Primary, thử trước/trong/sau partition và đối soát bảy key trên ba node. Nó in đường dẫn `storage/cap/matrix-<UTC>-<suffix>.json`. Số probe có thể thay đổi do polling/retry có ghi log; không yêu cầu mọi lần đều đúng 43 probe hoặc cùng node/độ trễ với lần đã lưu.

PASS nghĩa là các assertion và recovery đạt trong lần đó. Một ghi lỗi dự kiến vẫn có thể nằm trong lần PASS. Nếu FAIL, giữ JSON, kiểm tra `error`/`recovery_error`, không xóa bằng chứng hoặc gọi đó là kết quả thành công. `finally` cố gắng recovery; khi terminal bị kill cưỡng bức hoặc Docker mất kết nối, chạy lệnh recovery sau khi Docker hoạt động lại.

## 5. Phương án khi demo trực tiếp gặp sự cố

| Hiện tượng | Xử lý |
|---|---|
| Docker chưa chạy | Mở/Resume Docker Desktop, chạy lại ps; không xóa volume |
| Port 8010 bị chiếm | Kiểm tra tiến trình/stack đang dùng; không tự kill dịch vụ không rõ nguồn |
| Dashboard yêu cầu đăng nhập | Đăng nhập admin demo; không tắt kiểm tra quyền/CSRF |
| UNKNOWN / không có Primary | Ghi lại trạng thái, recovery nếu partition do mình tạo; xem log container |
| Ghi báo timeout | Lưu operation_id, đối soát sau recovery; không tuyên bố chắc chắn mất/không ghi |
| Không bắt được rollback w:1 | Trình bày bằng chứng ngày 15/10/2026, nói rõ đây là lần chạy trước |
| Mất mạng Internet | Dùng tài liệu HTML/DOCX đã xuất và môi trường Docker đã build; không rebuild giữa buổi |

Nếu không chạy được live, nói rõ sự cố và dùng JSON/log đã lưu; không trình bày tài liệu cũ như kết quả đang chạy. Khi kết thúc, luôn kiểm tra recovery và website.

## 6. Danh sách minh chứng cần trình bày

- Trang sản phẩm và document tương ứng: cho thấy website dùng catalog NoSQL.
- Vai trò ba node trước/trong/sau partition.
- ID trước và ID sau để giải thích stale read.
- Ghi w:1 bị rollback trong [JSON hoàn chỉnh](../cap/evidence/matrix-20261015T051721Z-b25cec.json) và [log MongoDB](../cap/evidence/matrix-20261015T051721Z-b25cec-environment.json).
- Lần ghi timeout nhưng sau recovery có dữ liệu trong [lần chạy đầu](../cap/evidence/matrix-20261015T051633Z-e52a6a.json); giải thích lỗi assertion cũ theo [matrix-results.md](../cap/matrix-results.md).

Ảnh chụp trong buổi bảo vệ có thể bổ sung cho dễ xem, nhưng không thay thế JSON và operation_id. Bộ bàn giao hiện không giả lập ảnh chụp màn hình như minh chứng thật.
