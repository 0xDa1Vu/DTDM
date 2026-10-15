# Kiểm tra bàn giao — 15/10/2026

Nhiệm vụ hoàn thiện tài liệu không sửa code nghiệp vụ hoặc cấu hình cluster. Các kiểm tra sau đã thực sự chạy lại sau khi hoàn thiện hướng dẫn:

| Kiểm tra | Kết quả |
|---|---|
| `bash scripts/demo.sh ps` | web đang chạy; MySQL và ba MongoDB healthy |
| `python3 tests/web-smoke.py` | Trang khách, tìm kiếm/lọc, admin, chi tiết, form biến thể và CSRF qua |
| `python3 tests/cap-smoke.py` | Auth/CSRF/allowlist, trạng thái 3 node, ghi majority/đọc bản sao và log/export qua |
| Probe của CAP smoke | Operation ID `23f85197e50b151e6d2dd7f3` đọc khớp trên cả ba node |
| Xuất DOCX | Có đủ 5 chương và 2 ảnh sơ đồ nhúng, kiểm tra qua cấu trúc file Word |
| Xuất HTML | 4 file, hình nhúng vào báo cáo/slide; không tải script/ảnh ngoài |
| Slide HTML | 12 section trình chiếu; điều khiển bằng phím hoặc nút |

CAP smoke chỉ ghi vào vùng probe riêng, không chạy partition và không ghi catalog. Không chạy lại toàn bộ migration/catalog/checkout hoặc ma trận partition vì nhiệm vụ cuối chỉ thay đổi tài liệu. Kết quả các bài đó được dẫn từ những lần đã lưu trước, không gộp thành một lần kiểm thử mới.

Đã xuất PDF từ Chrome headless và kiểm tra cấu trúc/nội dung bằng PDFKit: báo cáo 16 trang, slide 12 trang. Đã xem ảnh render trang có sơ đồ; sửa lỗi phân trang slide ban đầu khiến hình bị đẩy sang trang riêng. Việc kiểm tra file xuất không thay thế việc người nộp đối chiếu quy định định dạng của học phần.
