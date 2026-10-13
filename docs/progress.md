# Tiến độ đồ án — dừng để hướng dẫn setup

| Nhiệm vụ | Trạng thái | Kiểm tra đã chạy |
|---|---|---|
| Repo DTDM và mốc source ban đầu | Xong, đã push | HEAD local/remote khớp |
| Docker demo riêng, backup, baseline | Xong, đã push | Compose, HTTP baseline, restore backup vào DB thử |
| MongoDB Replica Set và migration | Xong, đã push | 3 vai trò node, migration chạy lại, đối soát, validator |
| Tích hợp catalog và giữ giao dịch SQL | Xong, đã push | 14 catalog checks, HTTP smoke, 2 checkout cạnh tranh |
| CAP Dashboard và probe | Xong | HTTP auth/CSRF, 3 node status, majority write/read, log/export, guard local |
| Script partition/recovery | Đã viết, chưa nghiệm thu runtime | Cú pháp Python, inspect firewall chỉ đọc; chưa cắt mạng |
| Hướng dẫn người dùng setup | Đang bàn giao | docs/setup.md |
| Bốn kịch bản CAP và bằng chứng | Chưa thực hiện | Chờ người dùng setup xong |
| Báo cáo 5 chương, biểu đồ, hướng dẫn bảo vệ | Chưa hoàn tất | Đã có tài liệu nền/mô hình; thiếu thực nghiệm thật |

Theo yêu cầu mới nhất, dừng sau nhiệm vụ Dashboard, không tự chạy partition.
Smoke CAP ngày 2026-10-13: ghi majority thành công; cả mongo1/mongo2/mongo3 đọc
đúng operation_id. Anonymous bị chuyển login; target không hợp lệ và kết hợp
linearizable/secondary bị từ chối 422; thiếu CSRF bị từ chối 403; log/export qua.
CLI bị chặn khi CAP_DEMO_ENABLED=0 hoặc APP_ENV=production. Những kiểm tra này
không được trình bày thành kết quả CAP khi có phân vùng mạng.
