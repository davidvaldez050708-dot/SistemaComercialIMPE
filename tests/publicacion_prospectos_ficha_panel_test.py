#!/usr/bin/env python3
"""Prueba offline del publicador de la ficha lateral: validación, bloqueo, aplicación y restauración."""
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from unittest.mock import patch
import importlib.util
import base64
import gzip
import json
import tempfile

ROOT=Path(__file__).resolve().parents[1]
module_path=ROOT/'tools/publicar_prospectos_ficha_panel.py'
spec=importlib.util.spec_from_file_location('publicar_prospectos_ficha_panel',str(module_path))
publisher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


def original_publicado(name,expected):
    data=(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
    if publisher.git_hash(data)==expected:
        return data
    # El paquete anterior sigue anclado a su entrega original. La actualización
    # visual conserva esos dos originales íntegros en su manifiesto offline.
    manifest=json.loads((ROOT/'tools/prospectos_panel_visual_release.json').read_text(encoding='utf-8'))
    row=next(row for row in manifest['files'] if row['path']==name)
    data=gzip.decompress(base64.b64decode(row['base']))
    assert publisher.git_hash(data)==expected
    return data

def run(site,backup,mode):
    args=['publicar_prospectos_ficha_panel.py','--site',str(site),'--backup-root',str(backup),mode]
    output=StringIO()
    with patch('sys.argv',args),patch.object(publisher,'fetch',side_effect=original_publicado):
        with redirect_stdout(output):
            publisher.main()
    return output.getvalue()

with tempfile.TemporaryDirectory(prefix='prospectos-panel-offline-') as tmp:
    site=Path(tmp)/'public_html'
    backup=Path(tmp)/'private_backups'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture aislado',encoding='utf-8')
    for dep in ['config/db_connection.php','app/services/ProspectoCentralSchema.php',
                'public/javascript/prospectos_fase1.js','public/javascript/prospectos_central.js']:
        file=site/dep
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_text('<?php //fixture' if dep.endswith('.php') else '//fixture',encoding='utf-8')
    # Las vistas existentes ya coinciden; los cuatro archivos nuevos aún no existen.
    nuevos=[]
    for relative,(known,sha) in publisher.FILES.items():
        if not known:
            nuevos.append(relative)
            continue
        file=site/relative
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(original_publicado(relative,sha))
        assert publisher.git_hash(file.read_bytes())==sha
    original={str(p):p.read_bytes() for p in site.rglob('*') if p.is_file()}
    assert 'CHECK OK' in run(site,backup,'--check')
    assert all(str(p) in original for p in site.rglob('*') if p.is_file())
    assert 'APLICADO' in run(site,backup,'--apply')
    assert all((site/path).is_file() for path in nuevos)
    assert 'Sin cambios' in run(site,backup,'--apply')
    backup_paths=list(backup.glob('*/respaldo.json'))
    assert len(backup_paths)==1
    script_args=['publicar_prospectos_ficha_panel.py','--site',str(site),'--backup-root',str(backup),
                 '--restore',str(backup_paths[0].parent)]
    with patch('sys.argv',script_args),redirect_stdout(StringIO()):
        publisher.main()
    assert all(not (site/path).exists() for path in nuevos)
    assert all(p.read_bytes()==original[str(p)] for p in site.rglob('*') if p.is_file())
    path=site/'app/controllers/ProspectoController.php'
    path.write_bytes(path.read_bytes()+b'\n// personalizacion desconocida\n')
    before=path.read_bytes()
    try:
        run(site,backup,'--check')
        raise AssertionError('Una versión no reconocida debe bloquear el despliegue')
    except RuntimeError as e:
        assert 'VERSIÓN DIFERENTE' in str(e)
    assert path.read_bytes()==before
print('PASS: paquete selectivo offline, comprobación sin escrituras, idempotencia, restauración y bloqueo seguro.')
