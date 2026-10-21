"""QA local de las muestras ficticias contra Word. No forma parte del CRM."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

import numpy as np
import pdfplumber
from PIL import Image, ImageDraw


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directorio', type=Path, default=Path('output/pdf/oficios-redmex'))
    parser.add_argument('--pdftoppm', required=True)
    args = parser.parse_args()
    base = args.directorio.resolve()
    report = {'manual_review_required': True, 'samples': []}
    for name in ['general', 'personalizado', 'preparado']:
        rendered = []
        for suffix in ['-word', '']:
            pdf = base / (name + suffix + '.pdf')
            subprocess.run([args.pdftoppm, '-scale-to', '1300', '-png', str(pdf), str(base / (name + suffix))],
                           check=True, capture_output=True)
            with pdfplumber.open(pdf) as document:
                rendered.append({'pages': len(document.pages),
                                 'sizes': [[p.width, p.height] for p in document.pages],
                                 'text': ''.join(p.extract_text() or '' for p in document.pages),
                                 'fonts': sorted({re.sub(r'^[A-Z]{6}\+', '', c['fontname']) for p in document.pages for c in p.chars}),
                                 'sha256': hashlib.sha256(pdf.read_bytes()).hexdigest()})
        reference, candidate = rendered
        assert reference['pages'] == candidate['pages'] == 1, name + ': unexpected page count'
        assert candidate['sizes'] == reference['sizes'] == [[612, 792]], name + ': not letter'
        assert re.sub(r'\s', '', reference['text']) == re.sub(r'\s', '', candidate['text']), name + ': missing or substituted text'
        assert candidate['fonts'] == reference['fonts'], name + ': font substitution'
        if name == 'preparado':
            assert 'EDICIÓN MANUAL CONSERVADA' in candidate['text'] and 'NO DEBE' not in candidate['text']
        a = Image.open(base / (name + '-word-1.png')).convert('RGB')
        b = Image.open(base / (name + '-1.png')).convert('RGB')
        assert a.size == b.size
        error = np.abs(np.asarray(a, dtype=np.int16) - np.asarray(b, dtype=np.int16))
        canvas = Image.new('RGB', (a.width * 2, a.height + 30), 'white')
        canvas.paste(a, (0, 30))
        canvas.paste(b, (a.width, 30))
        draw = ImageDraw.Draw(canvas)
        draw.text((10, 8), 'MICROSOFT WORD - DATOS FICTICIOS', fill='black')
        draw.text((a.width + 10, 8), 'RENDER LOCAL DOMPDF - MISMO DOCX', fill='black')
        canvas.save(base / (name + '-comparacion.png'))
        for item in rendered:
            del item['text']
        report['samples'].append({'name': name, 'reference': reference, 'candidate': candidate,
                                  'text_equal_ignoring_whitespace': True,
                                  'mean_absolute_pixel_error_0_255': round(float(error.mean()), 5),
                                  'pixels_difference_gt16_percent': round(float(np.any(error > 16, axis=2).mean()*100), 5)})
    with pdfplumber.open(base / 'paginacion.pdf') as document:
        text = ''.join(p.extract_text() or '' for p in document.pages)
        assert len(document.pages) > 1
        assert all('PÁRRAFO EXTENSO ' + str(i) + ':' in text for i in range(1, 66))
        assert all(len(p.images) >= 3 for p in document.pages), 'missing header/footer'
        for page in document.pages:
            assert [page.width, page.height] == [612, 792]
            assert all(c['top'] >= 100 and c['bottom'] <= 762 for c in page.chars if c['text'].strip()), 'text overlaps institutional resources'
        report['long_document'] = {'pages': len(document.pages), 'paragraphs_preserved': 65, 'header_footer_each_page': True}
    (base / 'comparacion.json').write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
    print('OK: carta, páginas, fuentes, texto completo, edición manual y paginación. Revisar también los tres PNG comparativos.')


if __name__ == '__main__':
    main()
