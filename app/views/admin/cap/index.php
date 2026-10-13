<?php use App\Core\Csrf; ?>
<link rel="stylesheet" href="/css/cap.css">
<section class="cap-intro">
    <div><span class="cap-label">PHÒNG THỰC NGHIỆM LOCAL</span>
        <h2>Quan sát dữ liệu khi mạng bị phân vùng</h2>
        <p>Replica Set <strong>rsCatalog</strong> · 3 node · Probe dùng database riêng <strong>cap_demo</strong>.</p>
    </div>
    <a href="/admin/cap/export" class="btn btn-outline">Tải log JSONL</a>
</section>
<p id="capMessage" role="status" aria-live="polite">Đang kết nối các node…</p>
<div class="cap-nodes" id="capNodes"></div>
<p class="cap-note" id="capObserved"></p>
<div class="cap-columns">
    <section class="cap-panel">
        <h3>Thử đọc / ghi</h3>
        <form id="capProbe" class="admin-form">
            <?= Csrf::field() ?>
            <div class="form-grid">
                <label>Kết nối<select name="target"><option value="replica">Replica Set — tự chọn node</option><option>mongo1</option><option>mongo2</option><option>mongo3</option></select></label>
                <label>Thao tác<select name="action"><option value="read">Đọc</option><option value="write">Ghi</option></select></label>
                <label>Read preference<select name="read_preference"><option>primary</option><option>primaryPreferred</option><option>secondary</option><option>secondaryPreferred</option><option>nearest</option></select></label>
                <label>Read concern<select name="read_concern"><option>local</option><option>majority</option><option>linearizable</option></select></label>
                <label>Write concern<select name="write_concern"><option>majority</option><option>1</option></select></label>
                <label>Mã lần chạy<input name="run_id" value="manual" pattern="[a-zA-Z0-9_-]{1,64}" required></label>
                <label>Khóa document<input name="key" value="demo-price" pattern="[a-zA-Z0-9_-]{1,80}" required></label>
                <label>Giá trị thử nghiệm<input name="value" value="100000" maxlength="200"></label>
            </div>
            <p class="cap-note">Read concern/preference áp dụng cho đọc; write concern áp dụng cho ghi. Linearizable cần primary. Retry tự động tắt; giới hạn chọn server và chờ majority là 2 giây.</p>
            <button type="submit" class="btn btn-dark" id="capSubmit">Chạy thao tác</button>
        </form>
        <pre id="capResult" class="cap-result" aria-live="polite">Kết quả thực tế sẽ xuất hiện tại đây.</pre>
    </section>
    <section class="cap-panel">
        <h3>Partition và recovery</h3>
        <p id="capPartition">Đang kiểm tra trạng thái mạng…</p>
        <ol>
            <li>Xác định Primary hiện tại từ các thẻ node.</li>
            <li>Trong Terminal tại thư mục DTDM, chạy lệnh phân vùng bên dưới. Script chặn liên lạc hai chiều giữa node được chọn và hai node còn lại.</li>
            <li>Thử probe trực tiếp ở từng phía; quan sát election và dữ liệu trả về.</li>
            <li>Khôi phục mạng, đọc lại cùng khóa và tải log để đối chiếu.</li>
        </ol>
        <p>Phân vùng Primary đang quan sát:</p>
        <pre id="capPartitionCommand">Chờ xác định Primary…</pre>
        <p>Khôi phục kết nối:</p>
        <pre>python3 scripts/cap/network.py recover</pre>
        <p class="cap-note">Website không chạy lệnh Docker. Các lệnh trên chỉ chấp nhận container thuộc project dtdm_demo.</p>
        <details><summary>Cách đọc kết quả CAP</summary>
            <p>Web là điểm quan sát có thể tiếp cận cả hai phía. Chọn mongo1/2/3 để thử từng node bằng directConnection. Kết nối Replica Set có thể tự tìm Primary ở phía đa số.</p>
            <p>Đọc majority không đồng nghĩa đọc tuyến tính. Đọc dữ liệu cũ thành công không chứng minh toàn hệ thống đạt CAP availability. Node không kết nối được được ghi là UNKNOWN.</p>
            <p>Timeout ghi là kết quả chưa được xác nhận: có thể thao tác đã áp dụng. Dùng operation_id trong log để đối chiếu sau recovery. Không giả định w:1 luôn ghi được ở phía thiểu số.</p>
        </details>
    </section>
</div>
<section class="cap-panel cap-history">
    <div class="cap-history-header"><h3>Nhật ký gần nhất</h3><button type="button" class="btn btn-outline" id="capRefresh">Làm mới</button></div>
    <div class="cap-table-wrap"><table class="data-table"><thead><tr><th>Thời điểm UTC</th><th>Kết nối</th><th>Thao tác</th><th>Kết quả</th><th>ms</th><th>Chi tiết</th></tr></thead><tbody id="capLogs"></tbody></table></div>
    <p class="cap-note">Log lưu ngoài Replica Set tại storage/cap. Nhật ký hiển thị tối đa 100 sự kiện gần nhất; file tải xuống chứa log đầy đủ.</p>
</section>
<script src="/js/cap.js" defer></script>
