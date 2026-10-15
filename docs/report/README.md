# Bộ tài liệu bàn giao đồ án

## Tài liệu có thể dùng ngay

| Mục đích | File |
|---|---|
| Báo cáo để đọc/in | [bao-cao.pdf](output/bao-cao.pdf) |
| Báo cáo để chỉnh sửa theo mẫu trường | [bao-cao.docx](output/bao-cao.docx) |
| Báo cáo HTML offline | [bao-cao.html](output/bao-cao.html) |
| Slide trình chiếu offline | [slides.html](output/slides.html) — mở bằng trình duyệt, dùng phím trái/phải |
| Slide PDF | [slides.pdf](output/slides.pdf) |
| Hướng dẫn demo 12–15 phút | [demo-guide.md](demo-guide.md) / [HTML](output/demo-guide.html) |
| 20 câu hỏi bảo vệ | [defense-qa.md](defense-qa.md) / [HTML](output/defense-qa.html) |
| Bằng chứng CAP và giới hạn | [matrix-results.md](../cap/matrix-results.md) |
| Nguồn báo cáo/slide | [bao-cao.md](bao-cao.md), [slides.md](slides.md) |

HTML nhúng hình và không cần tải thư viện JavaScript/CSS ngoài để đọc/trình chiếu. Các liên kết nguồn trong bản xuất trỏ tới GitHub, cần mạng/quyền repository để mở. JSON bằng chứng có sẵn trong `docs/cap/evidence` nếu cần xem offline. Trên GitHub, tải file HTML về máy để mở, không dùng trang xem source làm màn trình chiếu.

PDF được in từ HTML; Word được xuất riêng để chỉnh sửa. Phân trang Word có thể khác theo phiên bản Word/font. Mục lục Word là trường tự động: sau khi chỉnh sửa, chọn cập nhật mục lục trong Word. Mẫu A4 hiện tại không được tuyên bố là mẫu chính thức của trường.

## Phần người nộp cần bổ sung

- Họ tên, MSSV, lớp/nhóm, trường/khoa và giảng viên.
- Bìa, logo, quy cách trích dẫn/định dạng bắt buộc theo mẫu học phần nếu có.
- Tên thành viên/phân công thực tế, không tự ghi công việc chưa làm.

Không còn bước code/demo nào bị giữ lại để chờ xác nhận. Các mục production/mở rộng ở Chương 5 là hướng phát triển, không thuộc chức năng đã cam kết triển khai trong demo đại học.

## Xuất lại sau khi sửa nội dung

Môi trường đã dùng: `pandoc`, Graphviz (`dot`), Python với `python-docx`; Google Chrome tại đường dẫn macOS chuẩn để xuất PDF. Trên máy khác, cài các công cụ tương ứng trước khi chạy. Không cài chúng vào container ứng dụng vì đây chỉ là công cụ tài liệu.

```bash
python3 scripts/export-report.py
```

Script dựng hai sơ đồ từ `assets/*.dot`, thay Mermaid bằng ảnh khi xuất, tạo DOCX và bốn HTML; nếu có Chrome ở đường dẫn macOS chuẩn thì tạo thêm hai PDF. Nếu không có Chrome, mở HTML và dùng Print → Save as PDF. Script không truy cập database hoặc thay đổi mạng.

Khi thay đổi kiến trúc/mô hình, cập nhật cả sơ đồ Mermaid trong báo cáo và nguồn DOT cho bản xuất để tránh sai lệch. Khi cập nhật số liệu, phải lấy từ một lần chạy có JSON thật và ghi rõ run_id; không sửa số trong JSON để khớp lời trình bày.

## Danh mục bàn giao

- Repository: source website, Compose demo, schema/migration/sync và tests.
- Thiết lập: [setup.md](../setup.md), [quy trình backup/migration](../nosql/local-demo.md).
- Báo cáo: 5 chương, sơ đồ kiến trúc/document, số liệu và nguồn tham khảo.
- Demo: hướng dẫn live, runner tự động, phương án xử lý lỗi, slide và hỏi đáp.
- Minh chứng: network test, matrix PASS, lần matrix lỗi đã giải thích và log rollback.
- Kiểm tra bàn giao: [handover-checks.md](handover-checks.md).

Không đưa `.env`, `vendor`, volume hoặc dump có dữ liệu người dùng vào bộ nộp công khai. Repo chứa cấu hình/tài khoản demo; không dùng chúng như cấu hình production. Stack hiện tại giữ dữ liệu trong Docker volume, không phải toàn bộ dữ liệu runtime nằm trong Git.
