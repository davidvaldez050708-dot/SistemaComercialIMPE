#!/usr/bin/env python3
"""Un publicador antiguo nunca debe sustituir el módulo de importación actual."""
from pathlib import Path
from contextlib import redirect_stdout
from io import StringIO
from unittest.mock import patch
import importlib.util
import tempfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publicador_panel',str(ROOT/'tools/publicar_prospectos_ficha_panel.py'))
old=importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
with tempfile.TemporaryDirectory(prefix='prospectos-publicador-panel-obsoleto-') as temp:
    site=Path(temp)/'site'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    for dep in ['config/db_connection.php','app/services/ProspectoCentralSchema.php',
                'public/javascript/prospectos_fase1.js','public/javascript/prospectos_central.js']:
        path=site/dep
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text('fixture',encoding='utf-8')
    for relative in old.FILES:
        file=site/relative
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes((ROOT/relative).read_bytes())
    before={str(f):f.read_bytes() for f in site.rglob('*') if f.is_file()}
    for mode in ('--check','--apply'):
        args=['publicar_prospectos_ficha_panel.py','--site',str(site),mode]
        try:
            with patch('sys.argv',args),redirect_stdout(StringIO()):
                old.main()
            raise AssertionError('Publicador obsoleto aceptó archivos nuevos')
        except RuntimeError as e:
            assert 'VERSIÓN DIFERENTE' in str(e),str(e)
        assert all(f.read_bytes()==before[str(f)] for f in site.rglob('*') if f.is_file())
print('PASS: publicador de la ficha lateral no sobrescribe cambios del importador.')
