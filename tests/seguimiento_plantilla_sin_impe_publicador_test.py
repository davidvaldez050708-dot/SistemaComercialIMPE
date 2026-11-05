"""Ensaya CHECK, APPLY, RESTORE, idempotencia y conciliación de marca visible."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT/'tools/publicar_plantilla_seguimiento_sin_impe_hostinger.py'
FILES = {
    'app/services/SeguimientoExcelPlantillaService.php': (
        'PLANTILLA DE IMPORTACIÓN DE INSTITUCIONES V3',
        'PLANTILLA OFICIAL IMPE · IMPORTACIÓN DE INSTITUCIONES V3'),
    'app/controllers/SeguimientoVinculacionController.php': (
        'plantilla_instituciones_seguimiento_v3.xlsx',
        'plantilla_instituciones_seguimiento_impe_v3.xlsx'),
    'public/javascript/seguimiento_importacion_excel.js': (
        'plantilla_instituciones_seguimiento_v3.xlsx',
        'plantilla_instituciones_seguimiento_impe_v3.xlsx'),
}

with tempfile.TemporaryDirectory(prefix='test-excel-sin-impe-') as td:
    root = Path(td)
    site, source, backups = root/'public_html', root/'source', root/'backups'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    original = {}
    for name,(new,old) in FILES.items():
        # Este instalador publicado valida su versión sellada, no futuras mejoras del XLSX.
        current = subprocess.check_output(['git','show','3544f8a5:'+name],cwd=ROOT)
        assert current.count(new.encode('utf-8')) == 1
        assert old.encode('utf-8') not in current
        current_name = site/name
        source_name = source/name
        current_name.parent.mkdir(parents=True, exist_ok=True)
        source_name.parent.mkdir(parents=True, exist_ok=True)
        source_name.write_bytes(current)
        legacy = current.replace(new.encode('utf-8'), old.encode('utf-8'), 1)
        if name.endswith('Controller.php'):
            legacy = legacy.replace(b'<?php', b'<?php\n// Personalizacion Hostinger preservada', 1)
        current_name.write_bytes(legacy)
        original[name] = legacy

    def run(*args, ok=True):
        proc = subprocess.run(
            [sys.executable,str(SCRIPT),'--site',str(site),
             '--source-dir',str(source),'--backup-root',str(backups)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        out = (proc.stdout+proc.stderr).decode('utf-8','replace')
        assert (proc.returncode == 0) == ok,out
        return out

    assert 'CHECK OK' in run('--check')
    assert not backups.exists()
    for name in FILES:
        assert (site/name).read_bytes() == original[name]
    assert 'APLICADO' in run('--apply')
    for name,(new,old) in FILES.items():
        data = (site/name).read_bytes()
        assert new.encode('utf-8') in data and old.encode('utf-8') not in data
        if name.endswith('Controller.php'):
            assert b'Personalizacion Hostinger preservada' in data
    assert 'YA ACTUALIZADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    folders = [p for p in backups.iterdir() if p.is_dir()]
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    for name in FILES:
        assert (site/name).read_bytes() == original[name]

    # Si no coincide el texto antiguo ni el aprobado, se detiene sin escribir.
    name='app/services/SeguimientoExcelPlantillaService.php'
    (site/name).write_bytes(original[name].replace(
        FILES[name][1].encode('utf-8'),b'CAMBIO MANUAL DESCONOCIDO',1))
    assert 'CONFLICTO' in run('--check',ok=False)
    assert not (site/name).read_bytes().count(FILES[name][0].encode('utf-8'))
print('PASS plantilla sin IMPE: check, apply, restore, personalizaciones, conflictos')
