#!/usr/bin/env python3
"""El publicador de diseño anterior nunca debe sobrescribir una fase más nueva."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publicar_prospectos_central_compacto as publisher

with tempfile.TemporaryDirectory(prefix='prospectos-compacto-obsoleto-') as temp:
    site=Path(temp)/'site'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture, sin base de datos',encoding='utf-8')
    for relative in publisher.FILES:
        file=site/relative
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes((ROOT/relative).read_bytes())
    files_before={relative:(site/relative).read_bytes() for relative in publisher.FILES}
    assert any(publisher.digest(content)!=publisher.FILES[relative][1] for relative,content in files_before.items()), 'Esta prueba requiere código posterior al publicador antiguo.'
    script=ROOT/'tools/publicar_prospectos_central_compacto.py'
    for action in ['--check','--apply']:
        result=subprocess.run([sys.executable,str(script),'--site',str(site),action],
                              stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert result.returncode!=0,'El publicador viejo debe bloquear código más nuevo'
        assert b'VERSI' in result.stderr
        assert all((site/relative).read_bytes()==content for relative,content in files_before.items()),'El código no debe modificarse'
print('PASS: el paquete visual obsoleto bloquea reemplazos de código nuevo, sin red ni SQL.')
