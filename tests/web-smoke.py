"""HTTP smoke tests against the fixed local demo, no payment requests."""
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8010'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(path, data=None):
    encoded = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    with client.open(BASE + path, encoded, timeout=15) as res:
        body = res.read().decode()
        assert 'Fatal error' not in body and 'Warning:' not in body, path
        return body, res.geturl()

for path in ['/', '/products', '/products?q=ao', '/products?sort=price_asc', '/products?size[]=M', '/cart', '/login']:
    body, _ = request(path)
    assert '<html' in body.lower(), path
    print('PASS:', path)
token = re.search(r'name="_csrf" value="([^"]+)"', body).group(1)
body, url = request('/login', {'_csrf': token, 'email': 'admin@shop.test', 'password': 'admin123'})
assert url.endswith('/admin'), url
for path in ['/admin', '/admin/products', '/admin/products/create', '/admin/categories', '/admin/orders', '/admin/reviews', '/admin/users', '/admin/stylings']:
    body, url = request(path)
    assert not url.endswith('/login'), path
    print('PASS:', path)
products, _ = request('/products')
slug = re.search(r'href="(/product/[^"?#]+)"', products).group(1)
body, _ = request(slug)
variant = re.search(r'data-id="(\d+)"', body)
assert '₫' in body
print('PASS: product detail', slug)
body, _ = request('/admin/products')
edit = re.search(r'href="(/admin/products/edit/\d+)"', body).group(1)
form, _ = request(edit)
assert 'catalog_version' in form and 'v_original_stock' in form
print('PASS: stable variant edit form')
try:
    request('/admin/products/store', {'name': 'must-not-create'})
    raise AssertionError('CSRF missing was accepted')
except urllib.error.HTTPError as e:
    assert e.code == 403
print('PASS: CSRF rejected')
