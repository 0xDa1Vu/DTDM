# Tiến độ đồ án — bàn giao

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
| Báo cáo tham khảo | Giữ nguồn Markdown; người dùng tự làm bản nộp | Các bản xuất chỉ giữ local, không theo dõi bằng Git |
| Tài liệu demo và bảo vệ | Chỉ giữ local theo yêu cầu người dùng | Hướng dẫn demo/xử lý sự cố, hỏi đáp và bản xuất đã gỡ khỏi nhánh hiện tại |
| Kiểm tra bàn giao | Xong | Web/CAP smoke chạy lại; kiểm tra liên kết, DOCX/HTML/PDF |
| Cá nhân hóa bản nộp | Người nộp bổ sung | Họ tên/MSSV/lớp/giảng viên và mẫu chính thức của học phần chưa được cung cấp |

Theo yêu cầu mới nhất, đã hoàn thiện các phần còn lại mà không dừng xin duyệt từng nhiệm vụ.
[Danh mục tài liệu](report/README.md) ghi rõ phần giữ trong Git và phần chỉ giữ local.
[Kiểm tra cuối](report/handover-checks.md) chỉ chạy web/CAP smoke, không chạy lại partition.
Bằng chứng trước: [ma trận CAP](cap/matrix-results.md), [kiểm thử mạng](cap/network-validation.md).
Các hướng phát triển production ở Chương 5 không được tính là đã triển khai.

Smoke CAP ngày 2026-10-13: ghi majority thành công; cả mongo1/mongo2/mongo3 đọc
đúng operation_id. Anonymous bị chuyển login; target không hợp lệ và kết hợp
linearizable/secondary bị từ chối 422; thiếu CSRF bị từ chối 403; log/export qua.
CLI bị chặn khi CAP_DEMO_ENABLED=0 hoặc APP_ENV=production. Những kiểm tra này
không được trình bày thành kết quả CAP khi có phân vùng mạng.
