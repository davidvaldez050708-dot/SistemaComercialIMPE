#!/usr/bin/env python3
"""Publicador importador Meta: pruebas sin red, SQL ni acceso a producción."""
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from unittest.mock import patch
import importlib.util
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publicador_importacion',str(ROOT/'tools/publicar_prospectos_importador_meta.py'))
pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)

def run(site,backup,mode):
    output=StringIO()
    args=['publicar_prospectos_importador_meta.py','--site',str(site),'--backup-root',str(backup),mode]
    with patch('sys.argv',args),patch.object(pub,'check_extensions',return_value=None):
        with patch.object(pub,'fetch',side_effect=lambda name,sha:(ROOT/name).read_bytes().replace(b'\r\n',b'\n')):
            with redirect_stdout(output):pub.main()
    return output.getvalue()

with tempfile.TemporaryDirectory(prefix='prospectos-import-meta-test-') as temp:
    site=Path(temp)/'site';backups=Path(temp)/'backups';site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    for dep in ['config/db_connection.php','app/services/ProspectoCentralSchema.php',
                'public/javascript/prospectos_fase1.js','public/javascript/prospectos_central.js',
                'app/services/ProspectoCentralRepository.php','app/views/prospectos/partials/ficha_panel.php']:
        file=site/dep;file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes((ROOT/dep).read_bytes())
    new=[]
    for relative,(known,desired) in pub.FILES.items():
        if not known or (None in known and relative=='app/services/ProspectoMetaImportador.php'):
            new.append(relative);continue
        file=site/relative;file.parent.mkdir(parents=True,exist_ok=True)
        # Recuperar la versión revisada del PR anterior usando Git (CI tiene historial).
        import subprocess
        old=subprocess.check_output(['git','show','4c7182877f0781a8d62d9ea6cc97757efac616d9:'+relative],cwd=ROOT)
        assert pub.git_hash(old) in known,relative
        file.write_bytes(old)
        assert pub.git_hash((ROOT/relative).read_bytes().replace(b'\r\n',b'\n'))==desired,relative
    before={str(p):p.read_bytes() for p in site.rglob('*') if p.is_file()}
    assert 'CHECK OK' in run(site,backups,'--check')
    assert all(f.read_bytes()==before[str(f)] for f in site.rglob('*') if f.is_file())
    assert 'APLICADO' in run(site,backups,'--apply')
    assert all((site/name).is_file() for name in new)
    assert 'Sin cambios' in run(site,backups,'--apply')
    manifest=list(backups.glob('*/respaldo.json'))
    assert len(manifest)==1
    args=['publicar_prospectos_importador_meta.py','--site',str(site),
          '--backup-root',str(backups),'--restore',str(manifest[0].parent)]
    with patch('sys.argv',args),redirect_stdout(StringIO()):pub.main()
    assert all(not (site/name).exists() for name in new)
    assert all(p.read_bytes()==before[str(p)] for p in site.rglob('*') if p.is_file())
    # También acepta la instalación de Meta que quedó publicada antes del cambio de permisos.
    legacy=site/'app/services/ProspectoMetaImportador.php'
    import subprocess
    old_service=subprocess.check_output(['git','show',
        '13f0cae3da684f43149e9f51c6093598bd2e02fb:app/services/ProspectoMetaImportador.php'],cwd=ROOT)
    assert pub.git_hash(old_service) in pub.FILES['app/services/ProspectoMetaImportador.php'][0]
    legacy.write_bytes(old_service)
    assert 'COMPATIBLE: app/services/ProspectoMetaImportador.php' in run(site,backups,'--check')
    legacy.unlink()
    bad=site/'app/controllers/ProspectoController.php'
    bad.write_bytes(bad.read_bytes()+b'\n// personalizado')
    snapshot=bad.read_bytes()
    try:run(site,backups,'--apply');raise AssertionError('No se bloqueó personalización desconocida')
    except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
    assert bad.read_bytes()==snapshot
print('PASS: instalación selectiva reversible, hashes, idempotencia y protección contra cambios concurrentes, sin SQL.')
