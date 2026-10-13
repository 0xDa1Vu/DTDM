(() => {
  const form = document.getElementById('capProbe');
  if (!form) return;
  const message = document.getElementById('capMessage');
  let refreshing = false;
  const text = (tag, value, className) => {
    const el = document.createElement(tag); el.textContent = value;
    if (className) el.className = className;
    return el;
  };
  async function get(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(20000) });
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${await response.text()}`);
    if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
    return response.json();
  }
  async function refresh() {
    if (refreshing || document.hidden) return;
    refreshing = true;
    try {
      const [status, logs] = await Promise.all([get('/admin/cap/status'), get('/admin/cap/logs')]);
      const nodes = document.getElementById('capNodes'); nodes.replaceChildren();
      for (const node of status.nodes) {
        const card = text('article', '', 'cap-node');
        card.append(text('h3', node.node), text('span', node.role, 'cap-role ' + node.role.toLowerCase()));
        card.append(text('p', `Primary theo node này: ${node.reported_primary || 'chưa xác định'} · ${node.elapsed_ms} ms`));
        if (node.error) card.append(text('p', node.error));
        const detail = document.createElement('details'); detail.append(text('summary', 'Góc nhìn replication'));
        detail.append(text('pre', JSON.stringify(node.members_from_this_node || [], null, 2))); card.append(detail); nodes.append(card);
      }
      document.getElementById('capObserved').textContent = `Quan sát lúc ${status.observed_at} · Client web có thể tiếp cận cả hai phía phân vùng.`;
      document.getElementById('capPartition').textContent = status.network ? `Đã áp dụng luật mạng cô lập ${status.network.isolated}. Cần recovery sau thí nghiệm.` : 'Chưa có manifest partition. Xem trạng thái node để đánh giá kết nối thực tế.';
      const primary = status.nodes.find(n => n.role === 'PRIMARY');
      document.getElementById('capPartitionCommand').textContent = primary ? `python3 scripts/cap/network.py partition ${primary.node}` : 'Chưa xác định Primary; đợi cluster ổn định trước khi phân vùng.';
      const table = document.getElementById('capLogs'); table.replaceChildren();
      for (const log of logs) {
        const row = document.createElement('tr');
        const values = [log.started_at || log.status?.observed_at || '', log.target || log.phase || '', log.action || log.type,
          log.type === 'probe' ? (log.ok ? 'Thành công' : 'Lỗi / chưa xác nhận') : 'Quan sát', log.elapsed_ms ?? '',
          log.error?.message || JSON.stringify(log.result ?? log.status ?? {})];
        for (const value of values) row.append(text('td', String(value))); table.append(row);
      }
      message.className = ''; message.textContent = 'Đã cập nhật trạng thái và nhật ký.';
    } catch (e) { message.className = 'error'; message.textContent = e.message; }
    finally { refreshing = false; }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault(); const button = document.getElementById('capSubmit'); button.disabled = true;
    document.getElementById('capResult').textContent = 'Đang thực hiện…';
    try {
      const result = await get('/admin/cap/probe', { method: 'POST', body: new FormData(form) });
      document.getElementById('capResult').textContent = JSON.stringify(result, null, 2);
      await refresh();
    } catch (e) { document.getElementById('capResult').textContent = e.message; }
    finally { button.disabled = false; }
  });
  document.getElementById('capRefresh').addEventListener('click', refresh);
  refresh(); setInterval(refresh, 5000);
})();
