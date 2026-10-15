# Câu hỏi bảo vệ và gợi ý trả lời

Các câu trả lời bám theo implementation và bằng chứng của DTDM. Tài liệu lý thuyết dẫn ở [báo cáo Chương 2](bao-cao.md); số liệu đầy đủ ở [matrix-results.md](../cap/matrix-results.md).

## 1. Đồ án có phải chuyển toàn bộ website sang MongoDB?

Không. Phạm vi là catalog sản phẩm. MongoDB quản lý sản phẩm, ảnh/biến thể mô tả, danh mục, thương hiệu và event đồng bộ. MySQL vẫn quản lý tồn kho, người dùng, giỏ hàng, đơn hàng, thanh toán. Phương án này tái sử dụng website và tránh mở rộng phạm vi ngoài đề tài.

## 2. Vì sao vẫn có bảng products trong MySQL?

Đó là bản sao tương thích phục vụ khóa ngoại và nghiệp vụ SQL. Nguồn catalog là MongoDB khi bật driver mongodb. Version và event giúp đồng bộ; checkout bị chặn nếu phiên bản chưa khớp. Không có atomic commit xuyên hai DB nên phải công khai giới hạn này.

## 3. Tại sao ảnh/biến thể được embedded nhưng brand/category dùng reference?

Ảnh và thông tin biến thể thường được đọc cùng sản phẩm, có giới hạn mảng. Category/brand được dùng chung. Stock thay đổi theo checkout và giữ nguồn ghi ở SQL nên không nhúng. Reviews có thể tăng không giới hạn và liên hệ người dùng nên tiếp tục ở SQL.

## 4. NoSQL có nghĩa không cần schema hoặc transaction?

Không. Project có validator cho products/categories/brands và transaction MongoDB để ghi catalog cùng event. Counters/events hiện chưa có JSON Schema riêng. Schema linh hoạt không loại bỏ nhu cầu kiểm tra dữ liệu.

## 5. Vì sao giữ ID số thay vì đổi hết sang ObjectId?

Giữ URL, form và liên kết dữ liệu cũ. MongoDB cho phép định danh khác ObjectId; project dùng counters. Đây là quyết định tương thích, không phải tuyên bố counters phù hợp mọi tải lớn.

## 6. MongoDB có nhanh hơn MySQL trong bài này?

Chưa có benchmark để kết luận. Embedding giảm nhu cầu ghép dữ liệu cho trang sản phẩm, nhưng số liệu hiện chỉ chứng minh chức năng và các hành vi CAP. Regex substring và best seller ghép SQL còn giới hạn khi dữ liệu lớn.

## 7. CAP khác ACID ở điểm nào?

CAP xét consistency/availability khi có phân vùng trong hệ phân tán; consistency trong CAP thường được hiểu là linearizability. Consistency của ACID nói về bảo toàn quy tắc dữ liệu trong transaction. Transaction tồn kho SQL không tự chứng minh tính nhất quán xuyên MongoDB và MySQL.

## 8. MongoDB là CP hay AP?

Phải nói rõ cấu hình và thao tác. Bài thử ghi majority tiếp tục ở phía đa số; node cô lập hạ vai trò và từ chối ghi. Đọc local/majority ở node cô lập vẫn có thể trả dữ liệu cũ. Không dùng một nhãn duy nhất để thay cho các quan sát đó.

## 9. Majority read có bảo đảm mới nhất?

Không tự bảo đảm. Trong lần chạy đã lưu, sau một cập nhật majority thành công ở phía đa số, node cô lập vẫn trả baseline với read concern majority. Operation_id chứng minh dữ liệu cũ. Đọc linearizable trên một document xác định duy nhất có điều kiện và bảo đảm khác.

## 10. Ghi w:1 có cho phép ghi vào Secondary?

Không. Lần w:1 thành công diễn ra ngay sau cắt mạng, trước khi Primary cũ nhận biết và hạ vai trò. Sau stepdown, ghi w:1 cũng bị từ chối. Ghi được xác nhận trong cửa sổ đó đã rollback sau recovery.

## 11. Timeout ghi có nghĩa thất bại hoàn toàn?

Không. Lần chạy đầu có lệnh timeout nhưng operation_id xuất hiện trên cả ba node sau recovery. Vì vậy log gọi kết quả là chưa được xác nhận và phải đối soát; không tự retry một thao tác nghiệp vụ có tác dụng phụ chỉ dựa vào timeout.

## 12. Chứng minh partition thật bằng cách nào?

Dùng iptables DROP hai chiều trong các container demo. Có bốn hướng TCP bị timeout qua biên partition và hai hướng giữa cặp đa số vẫn thông. ID/thời gian start/restart container không đổi, chứng tỏ không thay thế partition bằng việc stop mongod.

## 13. Vì sao node cô lập vẫn hiện reachable trên Dashboard?

Web là observer truy cập cả hai phía; firewall chặn liên lạc giữa MongoDB peers. Reachable từ web không có nghĩa replication vẫn thông. Probe direct cố định node; replica connection có thể chọn node ở cả hai phía. Đây là giới hạn được ghi rõ trong báo cáo.

## 14. Có phải sau recovery mọi ghi đều còn?

Không. Các ghi majority được xác nhận và không bị ghi đè vẫn còn trong lần thử. Ghi w:1 ở Primary cũ không còn và log có rollback. Baseline bị update-shared thay thế có chủ đích không được đếm là mất dữ liệu.

## 15. Vì sao runner PASS dù có lệnh ERROR?

Bài thử cần quan sát từ chối ghi/đọc khi thiếu điều kiện. Lần hoàn chỉnh có 43 probe, 4 lỗi thuộc các tình huống được thử và 21 lượt đối soát sau recovery. PASS nói về assertion của kịch bản, không nói mọi thao tác đều thành công.

## 16. Một lần chạy chứng minh được định lý CAP không?

Không. Định lý là kết quả lý thuyết; thực nghiệm hữu hạn minh họa một lịch sử trong cấu hình cụ thể. Chưa kiểm chứng mọi tổ hợp cấu hình, lịch sử đồng thời hoặc phân phối độ trễ.

## 17. SePay có nằm trong thí nghiệm CAP này không?

Không. Luồng thanh toán được tái sử dụng và giữ ở SQL; probe viết `cap_demo.probes`, không tạo giao dịch ngân hàng. Không có bằng chứng về khả năng chịu partition của SePay và không thử chuyển tiền thật.

## 18. Sao lưu và replication có giống nhau?

Không. Replica có thể sao chép cả thao tác sai. Project đã backup SQL/upload và restore thử trước migration; cần bổ sung chiến lược backup MongoDB sau cutover nếu triển khai thật. Không tuyên bố backup SQL là backup toàn bộ hệ thống hiện tại.

## 19. Ba node trên cùng máy có đạt HA thực tế?

Có thể quan sát election và lỗi đường mạng giữa tiến trình, nhưng không chịu được lỗi toàn host. Muốn đánh giá HA thực tế cần nhiều máy/vùng lỗi, cấu hình bảo mật và đo kiểm riêng.

## 20. Điểm chưa hoàn thiện quan trọng nhất?

Không có transaction xuyên MongoDB/MySQL; cần worker retry, idempotency HTTP và xử lý thêm race tham chiếu giữa nhiều admin. Cần backup MongoDB, auth/TLS và vận hành nhiều máy trước production. Đây là hướng phát triển, không phải chức năng đã hoàn thành.
