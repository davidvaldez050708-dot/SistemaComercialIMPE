#!/usr/bin/env python3
"""Empaqueta el hotfix visual sobre la vista V5 mensual exacta de Hostinger.

Sólo modifica app/views/convocatorias/index.php. Las dependencias verifican
que el controlador mensual, helper y componentes V5 permanezcan intactos.
Compatible con Python 3.6; NO ejecuta SQL ni modifica producción.
"""
import argparse
import base64
import gzip
import hashlib
import importlib.util
import io
import json
import subprocess
import tempfile
from pathlib import Path

QR_VIEW_SHA = '80cc94ac0a3a5ceddda6b92e7ddf0b57c426e8a6b1e7c0f6e8c59969591b0269'
ORIGINAL_CONTROLLER_SHA = 'bbf084c48239494d6e1a0d701d06187c00376dea04ebfe2bae1eea59aed4c1d5'
RELEASE = 'convocatorias-v5-mes-selector-visual-20261023'


def digest(value):
    return hashlib.sha256(value).hexdigest()


def encode(value):
    output = io.BytesIO()
    with gzip.GzipFile(fileobj=output, mode='wb', filename='', mtime=0) as stream:
        stream.write(value)
    return base64.b64encode(output.getvalue()).decode('ascii')


def decode(value):
    return gzip.decompress(base64.b64decode(value))


def load_core(path):
    spec = importlib.util.spec_from_file_location('core_mes_estilos',str(path))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    args = parser.parse_args()
    root = args.directory.resolve()
    core = load_core(root/'publicar_aliados_registro.py')
    qr = json.loads((root/'convocatorias_gestion_v5_qr_release.json').read_text(encoding='utf-8'))
    v5 = json.loads((root/'convocatorias_gestion_v5_release.json').read_text(encoding='utf-8'))
    origin = next(item for item in qr['files'] if item['path']=='app/views/convocatorias/index.php')
    reviewed = decode(origin['variants'][0]['target'])
    if origin['variants'][0]['sha256']!=QR_VIEW_SHA or digest(reviewed)!=QR_VIEW_SHA:
        raise RuntimeError('CONFLICTO: variante V5 QR no confirmada')
    v5before = (root/'view-v5-before.php').read_bytes()
    month = (root/'view-monthly-before.php').read_bytes()
    new = (root/'view-monthly-after.php').read_bytes()
    if month == new or b'data-convocatoria-month-dropdown' not in new:
        raise RuntimeError('La vista modificada no incluye el selector homogéneo')
    if b'La ventana avanza mes a mes; las publicaciones antiguas se conservan en el archivo.' in new:
        raise RuntimeError('La descripción auxiliar permanece en el código')
    # Reconstruir el contenido exacto que se publicó previamente en Hostinger.
    with tempfile.TemporaryDirectory() as tmp:
        current = core.merge(reviewed,v5before,month,Path(tmp))
    if b'filtro_convocatoria_periodo' not in current or b'sincronizarPeriodoEnUrl' not in current:
        raise RuntimeError('La vista mensual revisada está incompleta')
    # Aplicar exclusivamente el nuevo diff visual sobre la personalización.
    with tempfile.TemporaryDirectory() as tmp:
        desired = core.merge(current,month,new,Path(tmp))
    if desired == current:
        raise RuntimeError('No existen cambios visuales')
    must = (b'data-convocatoria-month-dropdown',b'data-convocatoria-month-value',
            b'convocatoria-filter-dropdown-trigger',b'sincronizarPeriodoEnUrl')
    if any(key not in desired for key in must):
        raise RuntimeError('La fusión perdió controles del selector o filtros anteriores')
    if b'La ventana avanza mes a mes; las publicaciones antiguas se conservan en el archivo.' in desired:
        raise RuntimeError('El texto auxiliar no fue eliminado de la variante productiva')
    # El CSS de V5 no fue modificado por la ventana mensual: reutilizar
    # exactamente la variante QR vigente y aplicar sólo el layout del hotfix.
    css_entry = next(row for row in qr['files'] if row['path']=='public/css/convocatorias_gestion_v5.css')
    css_current = decode(css_entry['target'])
    css_new = (root/'convocatorias_gestion_v5_after.css').read_bytes()
    if digest(css_current)!=css_entry['sha256'] or b'convocatoria-mes-simplificado' not in css_new:
        raise RuntimeError('CONFLICTO: CSS V5 anterior no verificado o falta layout mensual')
    if css_new == css_current:
        raise RuntimeError('No existen cambios CSS en la etapa visual')
    # Validación independiente de sintaxis del archivo conciliado.
    with tempfile.NamedTemporaryFile(suffix='.php') as output:
        output.write(desired)
        output.flush()
        lint = subprocess.run(['php','-l',output.name],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if lint.returncode:
            raise RuntimeError('PHP inválido: '+lint.stderr.decode('utf-8','replace'))

    original_controller = next(item for item in v5['files'] if item['path']=='app/controllers/ConvocatoriaController.php')
    reviewed_controller = decode(original_controller['variants'][0]['target'])
    if digest(reviewed_controller)!=ORIGINAL_CONTROLLER_SHA:
        raise RuntimeError('CONFLICTO: controlador V5 no reconocido')
    with tempfile.TemporaryDirectory() as tmp:
        controller = core.merge(reviewed_controller,
            (root/'controller-v5-before.php').read_bytes(),
            (root/'controller-monthly-after.php').read_bytes(),Path(tmp))
    helper = (root/'ConvocatoriaVentanaMensual.php').read_bytes()
    if b'final class ConvocatoriaVentanaMensual' not in helper:
        raise RuntimeError('Falta helper mensual verificado')

    dependencies = list(qr['dependencies'])
    for name, content in [
        ('app/controllers/ConvocatoriaController.php',controller),
        ('app/helpers/ConvocatoriaVentanaMensual.php',helper),
    ]:
        dependencies.append({'path':name,'sha256':digest(core.lf(content)), 'content':encode(content)})
    manifest = {
        'source': RELEASE,
        'files':[
            {'path':'app/views/convocatorias/index.php',
             'base':encode(current),'target':encode(desired),'sha256':digest(desired)},
            {'path':'public/css/convocatorias_gestion_v5.css',
             'base':encode(css_current),'target':encode(css_new),'sha256':digest(css_new)}
        ],
        'dependencies': dependencies
    }
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(manifest,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    print('REVISADO: Vista Hostinger '+digest(current))
    print('CANDIDATO: Selector homogéneo '+digest(desired))
    print('DEPENDENCIAS: controlador mensual, helper y 2 componentes V5')
    print('ENTREGA VISUAL: 2 archivos. SIN SQL. '+str(args.output))


if __name__=='__main__':
    main()
