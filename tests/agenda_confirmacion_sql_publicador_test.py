"""El parche modifica una única línea del método observado. Sin SQL ni red."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASH='C:/Program Files/Git/bin/bash.exe'
fixture=(ROOT/'tests/fixtures/agenda_confirmar_hostinger.php').read_text(encoding='utf8')
wrong="'UPDATE reuniones_vinculacion SET ' . SeguimientoTerritorialScope::reunionSupervisor('reuniones_vinculacion') . ',zoom_url"
correct="'UPDATE reuniones_vinculacion SET cuenta_clave_id = ?,zoom_url"
assert fixture.count(wrong)==1
for crlf in [False,True]:
 with tempfile.TemporaryDirectory(prefix='confirm-sql-patch-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);live=area/'web';live.mkdir();(live/'index.php').write_text('<?php',encoding='utf8')
    path=live/'app/services/AgendaReunionRepository.php';path.parent.mkdir(parents=True)
    original=fixture+'\n// Otra personalización y otras funciones permanecen intactas.\n'
    fixed=original.replace(wrong,correct)
    if crlf:original=original.replace('\n','\r\n');fixed=fixed.replace('\n','\r\n')
    path.write_bytes(original.encode())
    env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(live),IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH='C:/xampp/php'+os.pathsep+os.environ['PATH'])
    def run(*args,ok=True):
        r=subprocess.run([BASH,str(ROOT/'tools/publicar_confirmacion_sql_hostinger.sh'),*args],env=env,capture_output=True,text=True,encoding='utf8')
        assert (r.returncode==0)==ok,(r.stdout,r.stderr);return r.stdout+r.stderr
    assert '1 archivo(s) pendientes' in run('--check') and path.read_bytes()==original.encode()
    run('--apply');assert path.read_bytes()==fixed.encode()
    assert 'sin cambios pendientes' in run('--apply')
    assert fixed.count("SeguimientoTerritorialScope::reunionSupervisor('reuniones_vinculacion')")==1,'WHERE territorial conservado'
    backup=next((area/'backups').iterdir());run('--restore',str(backup));assert path.read_bytes()==original.encode()
    path.write_bytes(original.replace(wrong,'variante no revisada').encode())
    assert 'SITIO INTACTO' in run('--apply',ok=False)
    assert path.read_bytes()==original.replace(wrong,'variante no revisada').encode()
print('OK: una sola línea, WHERE y bind intactos, LF/CRLF, check inmutable, idempotencia, respaldo/restore y rechazo seguro.')
