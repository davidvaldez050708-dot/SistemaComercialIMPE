"""Publicador de Inscripción paso 1: pruebas sin Hostinger, SQL ni formularios reales."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_formularios_inscripcion_paso1_hostinger.py'
BASE_REV = 'a90b6aded4a7f606eccc7a27749658e3fed9b9ed'
FILES = [
    'app/controllers/FormularioController.php',
    'app/views/formularios/inscripcion.php',
    'public/css/formularios.css',
    'app/services/FormularioInscripcionProgramas.php',
    'app/views/formularios/inscripcion_programas.php',
]
BASE_COUNT = 3

def read_base(path):
    proc = subprocess.run(
        ['git','show',BASE_REV+':'+path],cwd=str(ROOT),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert proc.returncode == 0, proc.stderr.decode('utf-8','replace')
    return proc.stdout

with tempfile.TemporaryDirectory(prefix='impe-inscripcion-paso1-') as tmp:
    root=Path(tmp)
    site,base,target,backup=(root/'public_html',root/'base',root/'target',root/'backup')
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture')
    olds={}
    for i,path in enumerate(FILES):
        (target/path).parent.mkdir(parents=True,exist_ok=True)
        (target/path).write_bytes((ROOT/path).read_bytes())
        (site/path).parent.mkdir(parents=True,exist_ok=True)
        if i < BASE_COUNT:
            before=read_base(path)
            (base/path).parent.mkdir(parents=True,exist_ok=True)
            (base/path).write_bytes(before)
            (site/path).write_bytes(before)
            olds[path]=before

    def run(*mode, ok=True):
        p=subprocess.run([sys.executable,str(PUBLISHER),'--site',str(site),
           '--base-dir',str(base),'--target-dir',str(target),
           '--backup-root',str(backup)]+list(mode),
           stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        out=(p.stdout+p.stderr).decode('utf-8','replace')
        assert (p.returncode==0) == ok, out
        return out

    assert 'CHECK OK' in run('--check')
    assert not backup.exists(), '--check no debe crear respaldo'
    for path in olds:
        assert (site/path).read_bytes() == olds[path], 'CHECK solo lectura'
    assert 'APLICADO' in run('--apply')
    for path in FILES:
        assert (site/path).read_bytes() == (target/path).read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    folders=[x for x in backup.iterdir() if x.is_dir()]
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    for path in FILES:
        assert (site/path).is_file() == (path in olds)
        if path in olds:
            assert (site/path).read_bytes() == olds[path]

    # Personalizaciones fuera de los bloques cambiados.
    a='app/controllers/FormularioController.php'
    (site/a).write_bytes(olds[a].replace(b'<?php',b'<?php\n// personalización local\n',1))
    b='public/css/formularios.css'
    (site/b).write_bytes(olds[b] + b'\n/* estilo local no relacionado */\n')
    assert 'CHECK OK' in run('--check')
    assert 'APLICADO' in run('--apply')
    assert b'personalizaci\xc3\xb3n local' in (site/a).read_bytes()
    assert b'estilo local no relacionado' in (site/b).read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    latest=[x for x in backup.iterdir() if x.is_dir() and x != folders[0]]
    assert len(latest)==1
    assert 'RESTAURADO' in run('--restore',str(latest[0]))
    assert b'estilo local no relacionado' in (site/b).read_bytes()

    # Cambio contrario a los fragmentos esperados => bloqueo.
    original=(site/a).read_bytes()
    altered=original.replace(
        b'    public function inscripcion()\n    {',
        b'    public function inscripcionPersonalizada()\n    {',1)
    assert altered != original
    (site/a).write_bytes(altered)
    assert 'CONFLICTO' in run('--check',ok=False)
    assert (site/a).read_bytes()==altered
print('PASS inscripción paso 1: check, apply, idempotencia, restore y personalizaciones')
