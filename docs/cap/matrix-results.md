# Kết quả ma trận CAP trên Docker demo

Đã chạy thực tế ngày 15/10/2026, 12:17:21–12:17:50 giờ Việt Nam, MongoDB 8.0.32.
Bài kiểm thử hoàn tất **43 probe**, trong đó 21 lượt đọc phục vụ đối soát 7 key trên 3 node.
Có 4 thao tác báo lỗi trong các tình huống được thử; toàn bộ bài kiểm thử PASS.

- [Bằng chứng đầy đủ](evidence/matrix-20261015T051721Z-b25cec.json)
- [Cấu hình và log rollback](evidence/matrix-20261015T051721Z-b25cec-environment.json)
- [Lần chạy đầu chưa hoàn tất](evidence/matrix-20261015T051633Z-e52a6a.json)

## Thiết lập và cách chạy

Bài thử tái sử dụng `CapExperimentService`, `scripts/cap/network.py` và helper của
`tests/cap-network.py`. Chỉ ghi vào `cap_demo.probes`; không ghi catalog, tồn kho,
đơn hàng hay thanh toán. Replica set có 3 thành viên dữ liệu, mỗi thành viên 1 vote,
priority=1, electionTimeoutMillis=10000, writeConcernMajorityJournalDefault=true.

```bash
bash scripts/demo.sh ps
python3 tests/cap-matrix.py
```

Không chạy đồng thời với thay đổi catalog hoặc thực nghiệm cluster khác. Runner
kiểm tra stack/network demo và từ chối partition có sẵn. Nó tự tìm Primary,
cô lập hai chiều bằng firewall rồi recovery trong `finally`. Nếu tiến trình bị
kill cưỡng bức hoặc Docker mất kết nối, khi Docker hoạt động lại chạy:

```bash
python3 scripts/cap/network.py recover
```

Log mới nằm trong `storage/cap/matrix-<UTC>-<suffix>.json` và
`storage/cap/experiments.jsonl`; Dashboard hiển thị các probe CLI trong log chung.
JSON lưu SHA256 của các script đã chạy và base commit. Các key chứa run_id riêng
để không ghi đè lần chạy trước. Key `shared` được cập nhật có chủ đích để so sánh cũ/mới.

## Bốn giai đoạn

1. Bình thường: ghi baseline bằng majority, đọc xác nhận cùng operation_id trên cả 3 node.
2. Partition: cô lập Primary mongo3, thử ghi ngay trong giai đoạn chuyển tiếp; quan sát mongo1 trở thành Primary.
3. Sau election: chờ ghi majority trên Primary mới thành công, chạy ma trận bên cô lập/bên đa số và kết nối replica set.
4. Recovery: khôi phục mạng, chờ cluster khỏe, đọc majority từng key trên từng node; so sánh operation_id, catalog, firewall và tiến trình.

Phép thử TCP xác nhận 4 hướng qua biên partition bị timeout và 2 hướng giữa
hai thành viên phía đa số vẫn thông. Web là observer truy cập được cả hai phía.
Các probe direct cố định node mô phỏng truy cập vào từng phía, không mô phỏng
một ứng dụng thực sự bị giới hạn toàn bộ đường mạng. Probe `replica` có thể chọn
node ở cả hai phía và ghi nhận `server` thực tế; không thể xem nó là client chỉ ở phía đa số.

## Kết quả từng probe trước recovery

Đơn vị thời gian là ms đo bên trong PHP; không bao gồm chi phí khởi chạy Docker/PHP CLI.
Đọc dùng maxTimeMS=2000; ghi wtimeout=2000; server selection=2000, socket timeout=5000.
Retry tự động của driver tắt. Runner có thể gửi lệnh ghi mới khi chờ Primary mới sẵn sàng;
mỗi lần có operation_id riêng và được giữ trong log (lần PASS chỉ cần một lần).

| Giai đoạn | Probe | Node trả lời | Kết quả | Thời gian ms |
|---|---|---|---|---:|
| normal | seed-shared | mongo3 | OK | 3.522 |
| normal | replication-mongo1 | mongo1 | OK | 1.536 |
| normal | replication-mongo2 | mongo2 | OK | 1.415 |
| normal | replication-mongo3 | mongo3 | OK | 1.399 |
| transition | isolated-write-1 | mongo3 | OK | 1.836 |
| transition | isolated-write-majority | mongo3 | ERROR 64: waiting for replication timed out | 2016.524 |
| settling | update-shared | mongo1 | OK | 3.659 |
| partition | mongo3-write-1 | mongo3 | ERROR 10107: not primary | 1.352 |
| partition | mongo3-write-majority | mongo3 | ERROR 10107: not primary | 1.407 |
| partition | mongo3-secondaryPreferred-local | mongo3 | OK / stale | 2.777 |
| partition | mongo3-secondaryPreferred-majority | mongo3 | OK / stale | 4.334 |
| partition | mongo3-primary-linearizable | không ghi nhận | ERROR 10107: cannot satisfy linearizable read concern on non-primary node | 2.528 |
| partition | mongo1-write-1 | mongo1 | OK | 2.214 |
| partition | mongo1-write-majority | mongo1 | OK | 3.975 |
| partition | mongo1-secondaryPreferred-local | mongo1 | OK / latest | 1.415 |
| partition | mongo1-secondaryPreferred-majority | mongo1 | OK / latest | 1.364 |
| partition | mongo1-primary-linearizable | mongo1 | OK / latest | 15.211 |
| partition | replica-primary | mongo1 | OK / latest | 2.126 |
| partition | replica-secondary | mongo3 | OK / stale | 3.150 |
| partition | replica-primaryPreferred | mongo1 | OK / latest | 1.841 |
| partition | replica-secondaryPreferred | mongo2 | OK / latest | 1.880 |
| partition | replica-nearest | mongo1 | OK / latest | 1.637 |

`latest` nghĩa là operation_id khớp lệnh update-shared đã hoàn tất trước
khi bắt đầu đọc; `stale` là baseline trước partition. Trong lần chạy này, kết nối
replica với preference `secondary` chọn mongo3 và đọc dữ liệu cũ; các preference
khác chọn node phía đa số. Đó là quan sát một lần chạy, không phải quy tắc chọn node cố định.

## Đối soát sau recovery

| Lệnh ghi | Đã được xác nhận | Kết quả cuối |
|---|---|---|
| seed-shared | Có | superseded |
| isolated-write-1 | Có | absent-after-recovery |
| isolated-write-majority | Không | absent-after-recovery |
| update-shared | Có | present-on-all-nodes |
| mongo3-write-1 | Không | absent-after-recovery |
| mongo3-write-majority | Không | absent-after-recovery |
| mongo1-write-1 | Có | present-on-all-nodes |
| mongo1-write-majority | Có | present-on-all-nodes |

Cả 3 node thống nhất operation_id hoặc cùng không có bản ghi cho mọi key.
Baseline `seed-shared` bị bản cập nhật sau thay thế có chủ đích (`superseded`),
không phải mất dữ liệu. Lệnh `isolated-write-1` từng được xác nhận nhưng không
còn tồn tại sau recovery; log mongo3 ghi nhận rollback. Các lệnh majority được
xác nhận và không bị ghi đè vẫn tồn tại trên cả 3 node.

Catalog trước/sau khớp số lượng và SHA256: 13 sản phẩm, 6 danh mục, 2 thương hiệu.
Firewall khôi phục đúng ban đầu; cả 3 container giữ nguyên ID, thời điểm khởi động
và số lần restart. Cluster kết thúc với mongo1 Primary, mongo2/mongo3 Secondary.

## Lần chạy đầu và sửa bài kiểm thử

Lần `matrix-20261015T051633Z-e52a6a` không hoàn tất. Primary mới nhận lệnh
`update-shared` nhưng trả lỗi write concern 64 sau 2014,980 ms; sau recovery
operation_id của lệnh này xuất hiện trên cả 3 node. Điều đó cho thấy timeout
không đủ để kết luận lệnh chưa ghi dữ liệu. Nguyên nhân cụ thể khiến replication
chưa đủ xác nhận trong 2 giây không được xác định từ log probe này.

Bài kiểm thử ban đầu dừng ngay khi gặp timeout đó và chỉ coi một bản ghi đã
bị thay thế nếu lệnh sau được xác nhận thành công. Vì vậy nó báo nhầm
`Acknowledged majority write lost` cho baseline. Đây là lỗi assertion, không phải
bằng chứng MongoDB mất ghi majority. Runner đã sửa: chờ khả năng ghi majority
trước ma trận ổn định và xác định ghi đè từ operation_id thực tế sau recovery.
Giữ nguyên JSON lần lỗi, kể cả trường kết luận sai, để truy nguyên; không dùng
`write_outcomes` chưa hoàn tất của lần đó làm kết luận hệ thống.

## Diễn giải CAP và giới hạn

CAP xét consistency theo nghĩa atomic/linearizable và availability của các thao
tác tại node không lỗi trong điều kiện partition. Một phản hồi lỗi nhanh không
đồng nghĩa thao tác đọc/ghi vẫn khả dụng theo nghĩa này. Demo hữu hạn minh họa
đánh đổi, không phải chứng minh định lý hay bảo đảm cho mọi lịch sử thực thi.
[Gilbert và Lynch (2002)](https://www.comp.nus.edu.sg/~gilbert/pubs/BrewersConjecture-SigAct.pdf).

Phía đa số tiếp tục nhận ghi; phía cô lập mất khả năng ghi sau khi hạ vai trò.
Đọc local/majority tại node cô lập vẫn trả lời nhưng trả giá trị cũ sau một ghi
majority đã hoàn tất ở phía đa số. Vì thế majority read không tự bảo đảm đọc
mới nhất. Linearizable read trên node đã xuống Secondary bị từ chối; kết hợp
ghi majority và đọc linearizable trên một document là cấu hình có bảo đảm mạnh
hơn được MongoDB mô tả. [Read concern linearizable](https://www.mongodb.com/docs/manual/reference/read-concern-linearizable/).

Ghi w:1 trong cửa sổ trước stepdown có thể được xác nhận rồi rollback;
wtimeout chỉ giới hạn chờ xác nhận, không tự hoàn tác thay đổi. Vì vậy cần
đối soát operation_id sau sự cố, không tự retry thanh toán chỉ dựa vào timeout.
[Write concern](https://www.mongodb.com/docs/manual/reference/write-concern/).

Không gán MongoDB nhãn luôn CP hay luôn AP: cần chỉ rõ thao tác, read/write
concern, cách định tuyến và thời điểm trong partition. w:1 không biến replica
set thành multi-primary; đọc được dữ liệu cũ không có nghĩa mọi ghi đều khả dụng.

Đây là một lần chạy hoàn chỉnh, cộng một lần chưa hoàn tất được lưu riêng.
Chưa đo phân phối độ trễ, tải lớn, crash/restart, partition bất đối xứng, causal
sessions, sharding hay mọi tổ hợp concern/preference. Ma trận đại diện đáp ứng
phạm vi demo, không phải benchmark hoặc kiểm chứng linearizability tổng quát.
Các probe không phải giao dịch SePay; thanh toán và đơn hàng vẫn thuộc MySQL.
