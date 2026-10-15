# Tiến độ đồ án — kiểm tra từng nhiệm vụ

| Nhiệm vụ | Trạng thái | Kiểm tra đã chạy |
|---|---|---|
| Repo DTDM và mốc source ban đầu | Xong, đã push | HEAD local/remote khớp |
| Docker demo riêng, backup, baseline | Xong, đã push | Compose, HTTP baseline, restore backup vào DB thử |
| MongoDB Replica Set và migration | Xong, đã push | 3 vai trò node, migration chạy lại, đối soát, validator |
| Tích hợp catalog và giữ giao dịch SQL | Xong, đã push | 14 catalog checks, HTTP smoke, 2 checkout cạnh tranh |
| CAP Dashboard và probe | Xong | HTTP auth/CSRF, 3 node status, majority write/read, log/export, guard local |
| Script partition/recovery | Đã nghiệm thu runtime | 6 hướng TCP trước/trong/sau partition, election, recovery lặp lại; catalog/firewall/process không đổi |
| Hướng dẫn người dùng setup | Đã có tài liệu, cụm demo đang chạy | docs/setup.md |
| Bốn kịch bản CAP và bằng chứng | Đã chạy ma trận đại diện | 43 probe, stale reads, write timeout, rollback w:1, đối soát 7 key trên 3 node; xem docs/cap/matrix-results.md |
| Báo cáo kỹ thuật 5 chương | Đã tổng hợp bản Markdown | docs/report/bao-cao.md; sơ đồ kiến trúc/document, bảng số liệu và nguồn bằng chứng |
| Hướng dẫn demo/bảo vệ và bản nộp | Chưa hoàn tất | Còn kịch bản trình bày, thông tin sinh viên và định dạng theo mẫu học phần |

Theo yêu cầu mới nhất, hoàn thành một nhiệm vụ rồi dừng để người dùng kiểm tra.
Nhiệm vụ vừa hoàn tất: tổng hợp [báo cáo kỹ thuật 5 chương](report/bao-cao.md).
Chỉ cập nhật tài liệu, không chạy thêm partition hoặc thay đổi dữ liệu. Dừng để người dùng kiểm tra.
Kết quả nhiệm vụ trước: [ma trận CAP và giới hạn](cap/matrix-results.md).
Bài kiểm thử mạng trước đó: [partition/recovery](cap/network-validation.md).

Smoke CAP ngày 2026-10-13: ghi majority thành công; cả mongo1/mongo2/mongo3 đọc
đúng operation_id. Anonymous bị chuyển login; target không hợp lệ và kết hợp
linearizable/secondary bị từ chối 422; thiếu CSRF bị từ chối 403; log/export qua.
CLI bị chặn khi CAP_DEMO_ENABLED=0 hoặc APP_ENV=production. Những kiểm tra này
không được trình bày thành kết quả CAP khi có phân vùng mạng.
