"""Dashboard smoke only: no partition/recovery commands are run."""
import datetime
import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8010'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def get(path, data=None):
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data is not None else None,
                                 headers={'Accept': 'application/json' if '/cap/' in path else 'text/html'})
    with client.open(req, timeout=15) as response:
        return response.read().decode(), response.geturl()

_, url = get('/admin/cap/status')
assert url.endswith('/login'), url
print('PASS: anonymous user redirected to login')
body, _ = get('/login')
token = re.search(r'name="_csrf" value="([^"]+)"', body).group(1)
_, url = get('/login', {'email': 'admin@shop.test', 'password': 'admin123', '_csrf': token})
assert url.endswith('/admin')
body, _ = get('/admin/cap')
assert 'capProbe' in body and 'network.py recover' in body
token = re.search(r'name="_csrf" value="([^"]+)"', body).group(1)
status = json.loads(get('/admin/cap/status')[0])
assert sum(n['role'] == 'PRIMARY' for n in status['nodes']) == 1
assert sum(n['role'] == 'SECONDARY' for n in status['nodes']) == 2
assert status['network'] is None, 'Do not run smoke during a partition'
print('PASS: dashboard and 3 live node statuses')
run = 'smoke-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
params = {'_csrf': token, 'run_id': run, 'key': run, 'target': 'replica', 'action': 'write', 'value': '100000',
          'write_concern': 'majority', 'read_preference': 'primary', 'read_concern': 'majority'}
written = json.loads(get('/admin/cap/probe', params)[0])
assert written['ok'], written
print('PASS: majority write probe, operation_id:', written['operation_id'])
for node in ('mongo1', 'mongo2', 'mongo3'):
    read = None
    for attempt in range(10):
        read = json.loads(get('/admin/cap/probe', params | {'action': 'read', 'target': node, 'read_preference': 'secondaryPreferred'})[0])
        if read['ok'] and read['result'] and read['result']['operation_id'] == written['operation_id']:
            break
        time.sleep(0.2)
    assert read['ok'] and read['result']['operation_id'] == written['operation_id'], read
    print('PASS: replicated document on', node)
for override, expected in [({'target': 'external.example'}, 422), ({'_csrf': ''}, 403),
                           ({'action': 'read', 'read_concern': 'linearizable', 'read_preference': 'secondary'}, 422)]:
    try:
        get('/admin/cap/probe', params | override)
        raise AssertionError('Invalid probe accepted')
    except urllib.error.HTTPError as e:
        assert e.code == expected, e.code
print('PASS: target allowlist, CSRF, invalid concern combination rejected')
logs = json.loads(get('/admin/cap/logs')[0])
assert any(row.get('operation_id') == written['operation_id'] for row in logs)
export = get('/admin/cap/export')[0]
assert written['operation_id'] in export
for line in export.splitlines(): json.loads(line)
print('PASS: persisted log and JSONL export')
