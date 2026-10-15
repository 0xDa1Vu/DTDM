"""Build offline HTML, DOCX and HTML slides from reviewed Markdown.
Requires local pandoc, graphviz (dot), and python-docx; never connects to DB.
If Google Chrome is installed at the standard macOS path, also builds PDF.
"""
import base64
import pathlib
import re
import subprocess
import tempfile
from docx import Document
from docx.shared import Pt, Cm

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPORT = ROOT / 'docs/report'
OUTPUT = REPORT / 'output'
REPO = 'https://github.com/0xDa1Vu/DTDM/blob/main/'


def run(args):
    subprocess.run(args, cwd=ROOT, check=True)


def export_source(path, diagrams=False):
    text = path.read_text()
    if diagrams:
        images = iter(['architecture', 'data-model'])
        text = re.sub(r'```mermaid\n.*?```', lambda _: '![' + {'architecture': 'Sơ đồ kiến trúc hệ thống', 'data-model': 'Mô hình document sản phẩm'}[(name := next(images))] + '](assets/' + name + '.png)', text, flags=re.S)
    def link(m):
        target = m.group(2)
        if target.startswith(('http:', 'https:', '#')):
            return m.group(0)
        if m.group(1).startswith('!'):
            data = base64.b64encode((path.parent / target).read_bytes()).decode()
            return m.group(1) + '(data:image/png;base64,' + data + ')'
        local = (path.parent / target).resolve()
        if not local.exists():
            raise ValueError('Missing reference: ' + str(local))
        return m.group(1) + '(' + REPO + local.relative_to(ROOT).as_posix() + ')'
    return re.sub(r'(!?\[[^\]]*\])\(([^)]+)\)', link, text)


STYLE = '''<style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#172b40;max-width:1050px;margin:32px auto;padding:0 25px}
h1,h2,h3{line-height:1.25;color:#183d5b}h2{margin-top:2em;border-bottom:2px solid #dbe6ef;padding-bottom:.3em}
table{border-collapse:collapse;width:100%;font-size:.92em;margin:1em 0}td,th{border:1px solid #ccd7df;padding:8px;text-align:left}th{background:#eef4fa}
pre{white-space:pre-wrap;background:#f3f6f9;padding:12px}code{font-size:.9em}img{max-width:100%;height:auto}a{color:#14608e}
@media print{body{max-width:none;margin:0;padding:0;font-size:11pt}h2{break-before:page}h2,h3{break-after:avoid}img,tr{break-inside:avoid}a{color:inherit}nav{display:none}}
@page{size:A4;margin:20mm}
</style>'''
SLIDES = '''<style>
body{max-width:1200px}.level1{display:none;padding:20px 30px}.level1.active{display:block}h1{font-size:2.2em}li{font-size:1.3em;margin:.5em 0}.level1 img{max-height:40vh;display:block;margin:auto}.level1 table{font-size:1.15em}
#controls{position:fixed;bottom:0;left:0;right:0;background:#183d5b;color:white;padding:10px;text-align:center}button{padding:6px 20px;margin:0 15px;cursor:pointer}body{padding-bottom:65px}
@media print{.level1,.level1.active{display:block;break-after:page;padding:0}.level1:last-of-type{break-after:auto}#controls{display:none}.level1 img{max-height:65mm}.level1 figure{margin:3mm 0}.level1 figcaption{display:none}h1{font-size:24pt;margin:0 0 4mm}li{font-size:14pt;margin:2mm 0}.level1 p{margin:2mm 0}.level1 ul,.level1 ol{margin:2mm 0}h2{break-before:auto}body{padding:0}.level1 table{font-size:13pt}}
@media print{.level1:has(figure){display:grid;grid-template-columns:1fr 1.15fr;gap:5mm}.level1:has(figure) h1{grid-column:1/-1}.level1:has(figure) figure{grid-column:2;grid-row:2;margin:0}.level1:has(figure) ul{grid-column:1;grid-row:2}.level1:has(figure) img{max-height:130mm;max-width:100%}hr{display:none}}
@page{size:A4 landscape;margin:15mm}
</style>
<div id="controls"><button id="prev">← Trước</button><span id="count"></span><button id="next">Sau →</button> Phím ← → • Ctrl/Cmd+P để in PDF</div>
<script>
const pages=[...document.querySelectorAll('section.level1')];let current=0;
function show(n){current=Math.max(0,Math.min(pages.length-1,n));pages.forEach((p,i)=>p.classList.toggle('active',i===current));document.getElementById('count').textContent=(current+1)+' / '+pages.length;window.scrollTo(0,0)}
document.getElementById('prev').onclick=()=>show(current-1);document.getElementById('next').onclick=()=>show(current+1);
document.addEventListener('keydown',e=>{if(['ArrowRight','PageDown',' '].includes(e.key)){e.preventDefault();show(current+1)}if(['ArrowLeft','PageUp'].includes(e.key)){e.preventDefault();show(current-1)}});show(0);
</script>'''


def main():
    OUTPUT.mkdir(exist_ok=True)
    for name in ('architecture', 'data-model'):
        run(['dot', '-Tpng', '-Gdpi=160', str(REPORT / 'assets' / (name + '.dot')), '-o', str(REPORT / 'assets' / (name + '.png'))])
    with tempfile.TemporaryDirectory(prefix='dtdm-report-') as temp:
        temp = pathlib.Path(temp)
        for name in ('bao-cao', 'demo-guide', 'defense-qa', 'slides'):
            source = temp / (name + '.md')
            source.write_text(export_source(REPORT / (name + '.md'), diagrams=name == 'bao-cao'))
            html = OUTPUT / (name + '.html')
            run(['pandoc', str(source), '-f', 'markdown', '-t', 'html5', '--standalone', '--section-divs',
                 '--metadata', 'lang=vi', '--metadata', 'pagetitle=DTDM — ' + name, '-o', str(html)])
            body = html.read_text().replace('</head>', STYLE + '\n</head>')
            if name == 'slides':
                body = body.replace('</body>', SLIDES + '\n</body>')
            html.write_text(body)
            if name == 'bao-cao':
                docx = OUTPUT / 'bao-cao.docx'
                run(['pandoc', str(source), '--metadata', 'lang=vi', '--toc', '-o', str(docx)])
                doc = Document(docx)
                for section in doc.sections:
                    section.page_width, section.page_height = Cm(21), Cm(29.7)
                    section.top_margin = section.bottom_margin = Cm(2)
                    section.left_margin, section.right_margin = Cm(3), Cm(2)
                normal = doc.styles['Normal']
                normal.font.name, normal.font.size = 'Times New Roman', Pt(12)
                normal.paragraph_format.line_spacing = 1.3
                for table in doc.tables:
                    for row in table.rows:
                        for cell in row.cells:
                            for para in cell.paragraphs:
                                for r in para.runs:
                                    r.font.size = Pt(10)
                doc.save(docx)
    chrome = pathlib.Path('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
    if chrome.exists():
        with tempfile.TemporaryDirectory(prefix='dtdm-print-') as profile:
            for name in ('bao-cao', 'slides'):
                subprocess.run([str(chrome), '--headless', '--disable-gpu', '--no-pdf-header-footer',
                                '--user-data-dir=' + profile, '--print-to-pdf=' + str(OUTPUT / (name + '.pdf')),
                                (OUTPUT / (name + '.html')).as_uri()], check=True, timeout=60,
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    print('Built report DOCX, standalone HTML, and PDF when Chrome is available under docs/report/output')


if __name__ == '__main__':
    main()
