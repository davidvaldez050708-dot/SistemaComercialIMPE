"""Comparación local de ensayo. No envía archivos ni aprueba una integración."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

import numpy as np
from PIL import Image, ImageDraw
from pypdf import PdfReader


def pdf_data(path):
    content = path.read_bytes()
    if len(content) > 16 * 1024 * 1024 or not content.startswith(b'%PDF-') or b'%%EOF' not in content[-2048:]:
        raise ValueError('Firma, tamaño o final PDF inválido.')
    reader = PdfReader(path, strict=True)
    if reader.is_encrypted or len(reader.pages) != 1:
        raise ValueError('El ensayo debe ser un PDF legible de una página.')
    page = reader.pages[0]
    size = [float(page.mediabox.width), float(page.mediabox.height)]
    if size != [612.0, 792.0]:
        raise ValueError('El ensayo no conserva tamaño carta.')
    if reader.trailer['/Root'].get('/OpenAction') or reader.trailer['/Root'].get('/AA'):
        raise ValueError('El PDF contiene acciones automáticas.')
    fonts = []
    font_map = page['/Resources'].get('/Font', {})
    if hasattr(font_map, 'get_object'):
        font_map = font_map.get_object()
    for font in font_map.values():
        item = font.get_object()
        name = str(item.get('/BaseFont', ''))
        fonts.append(name.split('+')[-1].lstrip('/'))
    return {
        'bytes': len(content), 'sha256': hashlib.sha256(content).hexdigest(),
        'pages': len(reader.pages), 'size_points': size, 'fonts': sorted(set(fonts)),
        'text': ' '.join(page.extract_text().split()),
    }


def compare(reference, candidate, poppler, output):
    output.mkdir(parents=True, exist_ok=True)
    ref, actual = pdf_data(reference), pdf_data(candidate)
    expected = ['PRUEBA/0000/20-10/26', 'PERSONA DESTINATARIA DE PRUEBA', 'INSTITUCIÓN FICTICIA DE PRUEBA', '0000000000']
    for text in expected:
        if text not in ref['text'] or text not in actual['text']:
            raise ValueError('El PDF no corresponde a los datos ficticios del ensayo.')
    images = []
    render_warnings = []
    for path, name in [(reference, 'referencia-word'), (candidate, 'cloudconvert-web')]:
        result = subprocess.run([str(poppler), '-scale-to', '1300', '-singlefile', '-png',
                                 str(path.resolve()), str((output / name).resolve())],
                                capture_output=True, timeout=30)
        if result.returncode:
            raise ValueError('No se pudo representar una página PDF.')
        render_warnings.append(result.stderr.decode('utf-8', errors='replace').strip())
        images.append(Image.open(output / f'{name}.png').convert('RGB'))
    if images[0].size != images[1].size:
        raise ValueError('Las páginas renderizadas no tienen la misma dimensión.')
    before, after = [np.asarray(image, dtype=np.int16) for image in images]
    difference = np.abs(before - after)
    scores = {}
    height = before.shape[0]
    for name, lo, hi in [('pagina', 0, height), ('encabezado', 0, 145),
                         ('cuerpo', 145, 960), ('sello_y_contacto', 960, 1240), ('pie', 1240, height)]:
        region = difference[lo:hi]
        scores[name] = {'mean_absolute_error_0_255': round(float(region.mean()), 5),
                        'pixels_with_channel_difference_gt_16_percent': round(float(np.any(region > 16, axis=2).mean() * 100), 5)}
    width, height = images[0].size
    canvas = Image.new('RGB', (width * 2 + 40, height + 60), 'white')
    canvas.paste(images[0], (10, 45))
    canvas.paste(images[1], (width + 30, 45))
    draw = ImageDraw.Draw(canvas)
    draw.text((15, 15), 'REFERENCIA MICROSOFT WORD', fill='black')
    draw.text((width + 35, 15), 'CLOUDCONVERT WEB - PRUEBA FICTICIA', fill='black')
    canvas.save(output / 'comparacion.png')
    ref_text, actual_text = ref.pop('text'), actual.pop('text')
    same_text = ref_text == actual_text
    report = {'reference': ref, 'candidate': actual, 'normalized_text_equal': same_text,
              'text_equal_ignoring_whitespace': ''.join(ref_text.split()) == ''.join(actual_text.split()),
              'render_size_pixels': [width, height], 'pixel_comparison': scores,
              'render_warnings': render_warnings,
              'manual_visual_review_required': True,
              'api_authenticated_test': 'Pendiente: este resultado se obtuvo mediante la web pública.',
              'production_integration_authorized': False}
    (output / 'comparacion.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps(report, ensure_ascii=True))
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('referencia', type=Path)
    parser.add_argument('candidato', type=Path)
    parser.add_argument('--pdftoppm', required=True, type=Path)
    parser.add_argument('--salida', required=True, type=Path)
    args = parser.parse_args()
    compare(args.referencia, args.candidato, args.pdftoppm, args.salida)
