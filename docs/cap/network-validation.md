# Kiểm thử công cụ partition/recovery

Đã chạy thực tế `python3 tests/cap-network.py` trên stack Docker `dtdm_demo`
ngày 15/10/2026, từ 12:10:38 đến 12:11:00 giờ Việt Nam. Kết quả: PASS.
[Bằng chứng JSON](evidence/network-20261015T051038Z.json) chứa trạng thái node,
timeline, firewall, kết quả TCP, hash catalog và thời gian khởi động container.
`base_commit` là commit trước khi thêm bài kiểm thử; SHA256 ghi nhận đúng nội
dung script mạng và bài kiểm thử được chạy.

| Kiểm tra | Kết quả thực tế |
|---|---|
| Trước partition | mongo1 Primary, mongo2 và mongo3 Secondary; 6/6 hướng TCP thông |
| Cô lập Primary | DROP hai chiều giữa mongo1 và hai node còn lại; 4/4 hướng bị cô lập timeout sau 2 giây, exit code 124 |
| Phía đa số | mongo2 ↔ mongo3 vẫn kết nối được, 2/2 hướng TCP thông |
| Election | mongo1 xuống Secondary, mongo2 lên Primary; quan sát sau 11,331 giây kể từ bắt đầu lệnh partition |
| Quan sát từ web | Vẫn truy cập được cả 3 node ở hai phía partition |
| Recovery | 6/6 hướng TCP thông; 1 Primary và 2 Secondary, mọi thành viên được báo health=1 |
| Tiến trình | ID, thời điểm khởi động và số lần restart của cả 3 container không đổi |
| Catalog | Số lượng và SHA256 trước/sau khớp: 13 products, 6 categories, 2 brands |
| Firewall | Khớp trạng thái ban đầu; chain DTDM_CAP và manifest được gỡ |
| Recovery lần hai | Thành công, không thay đổi firewall |

11,331 giây là thời điểm bài kiểm thử **quan sát được** cả election và Primary
cũ hạ vai trò, có chi phí áp dụng firewall/truy vấn/polling. Đây là một lần chạy,
không phải benchmark hay thời gian election cố định. Sau recovery mongo2 vẫn
là Primary; không yêu cầu mongo1 giành lại vai trò cũ.

## Chạy lại

```bash
bash scripts/demo.sh ps
python3 tests/cap-network.py
python3 scripts/cap/network.py inspect
```

Bài kiểm thử thực sự cắt kết nối giữa các MongoDB node demo trong thời gian
ngắn. Không chạy đồng thời với sửa catalog, thao tác cluster hoặc bài thử khác.
Script từ chối cluster không khỏe/đã có partition và kiểm tra label/network demo.
Kết quả mỗi lần chạy nằm trong `storage/cap/network-<UTC>.json` (Git bỏ qua).
Khối `finally` gọi recovery khi bài kiểm thử đã bắt đầu partition. Nếu terminal
bị kill cưỡng bức hoặc Docker mất kết nối, sau khi Docker hoạt động lại chạy:

```bash
python3 scripts/cap/network.py recover
```

## Phạm vi

Nhiệm vụ này nghiệm thu công cụ tạo partition và recovery; không phát sinh ghi
thử vào catalog. Hash catalog được đọc qua replica set trước/sau, không phải
đối soát lịch sử ghi riêng trên từng node. Chưa chạy ma trận read preference,
read concern, write concern trong partition; chưa kiểm tra rollback của ghi
chưa được đa số xác nhận. Các nội dung đó thuộc nhiệm vụ thực nghiệm CAP tiếp theo.
