# CAP Dashboard — phạm vi nhiệm vụ hiện tại

Mở http://localhost:8010/admin/cap sau khi đăng nhập admin. Trang chỉ hoạt động
khi CAP_DEMO_ENABLED=1, APP_ENV=local và cấu hình database đúng Docker demo.
Không có endpoint chạy shell hoặc Docker socket trong website.

## Đã triển khai

- Quan sát hello/replSetGetStatus riêng từng node, hiển thị vai trò, optime,
  Primary mà node đó nhận biết và thời gian phản hồi; UNKNOWN nếu không liên lạc.
- Probe read/write database `cap_demo.probes`, tách khỏi catalog bán hàng.
- Target allowlist mongo1/2/3 hoặc Replica Set, read preference/concern và write
  concern có allowlist. Linearizable chỉ cho primary. Không cho người dùng nhập
  MongoDB URI hoặc namespace tuỳ ý.
- Log JSONL ngoài cluster: UTC, operation ID, run ID, cấu hình, server thực thi
  nếu xác định được, kết quả/lỗi và elapsed đo bằng hrtime.
- Session lock được giải phóng trước probe dài để polling không bị chặn.
- CLI network.py dành riêng dtdm_demo; chặn IP peers hai chiều bằng chain
  DTDM_CAP. Web vẫn nhìn được cả hai phía nên là observer riêng, không đại diện
  một client ứng dụng bị kẹt trong partition. Kết nối direct không tự failover.

## Kiểm tra trước buổi demo

```bash
python3 tests/cap-smoke.py
bash scripts/demo.sh exec -T web php scripts/cap/probe.php status
python3 scripts/cap/network.py inspect
```

Smoke chỉ kiểm tra Dashboard, quyền truy cập, ghi majority và đọc bản sao trong
điều kiện mạng bình thường. Log có run_id `smoke-*`; không gộp vào số liệu CAP.

## Lệnh chuẩn bị cho thí nghiệm sau

Các lệnh đã được kiểm chứng trên Docker demo ngày 2026-10-15; xem
[kết quả và cách chạy lại](network-validation.md). Chỉ chạy khi không có người
khác đang thay đổi cluster hoặc chạy thực nghiệm.

```bash
# Xem Primary hiện tại trước; thay mongo1 nếu vai trò đã đổi.
python3 scripts/cap/network.py partition mongo1
# Thử các probe trên Dashboard và ghi lại timeline.
python3 scripts/cap/network.py recover
```

Script không dừng tiến trình mongod. Recovery chỉ xoá chain DTDM_CAP, không flush
firewall chung. Các kiểm tra container label/network hạn chế tác động vào stack
khác. Đã kiểm thử partition hai chiều, election và recovery. Timeout TCP đã
được kiểm tra; ma trận timeout/thành công của thao tác đọc/ghi còn ở nhiệm vụ sau.

## Cách diễn giải

Read concern majority không bảo đảm luôn mới nhất và không tương đương
linearizable. w:1 không cho Secondary nhận ghi tuỳ ý. Timeout ghi không chứng
minh không có ghi; dùng operation ID và đọc lại sau recovery. Các probe hiện ghi
đè cùng key để quan sát giá trị cũ/mới; đối soát lịch sử nhiều ghi cần key riêng
hoặc runner theo dõi mọi operation ID ở giai đoạn thực nghiệm tiếp theo.

Tham khảo: https://www.mongodb.com/docs/manual/core/replica-set-elections/
và https://www.mongodb.com/docs/manual/reference/read-concern-linearizable/
và https://www.mongodb.com/docs/manual/reference/write-concern/
