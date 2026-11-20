"""Prueba el paquete contra un sitio ficticio; jamás conecta a producción."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = json.loads((ROOT/'tools/seguimientos_propios_release.json').read_text(encoding='utf-8'))
BASE = '1c0db311'

for crlf in [False, True]:
    with tempfile.TemporaryDirectory(prefix='propios-deploy-') as directory:
        private = Path(directory)
        site = private/'public_html'
        site.mkdir()
        (site/'index.php').write_text('<?php', encoding='utf-8')
        original, expected = {}, {}
        for row in SPEC['files']:
            old = subprocess.check_output(['git','show',BASE+':'+row['path']],cwd=ROOT).replace(b'\r\n',b'\n')
            new = (ROOT/row['path']).read_bytes().replace(b'\r\n',b'\n')
            assert hashlib.sha256(old).hexdigest() == row['before_sha']
            assert hashlib.sha256(new).hexdigest() == row['after_sha']
            # Personalización ajena al cambio: debe conservarse al publicar.
            for data, collection in [(old, original), (new, expected)]:
                data = data.replace(b'<?php\n',b'<?php\n// Personalizacion conservada\n',1)
                collection[row['path']] = data.replace(b'\n',b'\r\n') if crlf else data
            path = site/row['path']
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(original[row['path']])
        sentinel = site/'config/db_connection.php'
        sentinel.parent.mkdir()
        sentinel.write_bytes(b'<?php // NO CARGAR NI MODIFICAR')
        command = [sys.executable,str(ROOT/'tools/publicar_seguimientos_propios.py'),
                   '--site',str(site),'--backup-root',str(private/'backups')]

        def run(*args, ok=True):
            result = subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf-8',env=dict(os.environ,PYTHONIOENCODING='utf-8'))
            assert (result.returncode==0)==ok,result.stdout+result.stderr
            return result.stdout+result.stderr

        def matches(collection):
            assert all((site/name).read_bytes()==value for name,value in collection.items())
            assert sentinel.read_bytes()==b'<?php // NO CARGAR NI MODIFICAR'

        assert 'COMPROBACION_OK' in run('--check')
        matches(original)
        # Si un bloque es distinto, no se escribe ningún archivo del paquete.
        changed = site/'app/helpers/SeguimientoTerritorialScope.php'
        changed.write_bytes(changed.read_bytes().replace(b'public static function analista',b'public static function analistaPersonalizado',1))
        snapshot={name:(site/name).read_bytes() for name in original}
        assert 'BLOQUEADO' in run('--apply',ok=False)
        matches(snapshot)
        changed.write_bytes(original['app/helpers/SeguimientoTerritorialScope.php'])
        output=run('--apply')
        assert 'PUBLICACION_OK' in output
        backup=next(line.removeprefix('RESPALDO: ') for line in output.splitlines() if line.startswith('RESPALDO: '))
        matches(expected)
        assert 'SIN_CAMBIOS' in run('--apply')
        # Restauración de un respaldo conocido, conservando personalizaciones.
        assert 'RESTAURACION_OK' in run('--restore',backup)
        matches(original)
        assert 'SIN_CAMBIOS' in run('--restore',backup)
        # Reanudación segura cuando el helper ya fue publicado.
        changed.write_bytes(expected['app/helpers/SeguimientoTerritorialScope.php'])
        run('--apply')
        matches(expected)
print('PASS: preflight, aplicación, personalizaciones, CRLF/LF, idempotencia, bloqueo y restauración.')
