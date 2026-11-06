"""Publicador Ejecutivas: solo lectura, aplicación, idempotencia y restore."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_ejecutivas_piloto_hostinger.py'
BASE_SHA = '0c01b0c822b4971086da7e6082146cfb8bcf819b'
FILES = [
    'app/controllers/FormularioController.php',
    'app/views/formularios/inscripcion_programas.php',
    'app/services/FormularioEjecutivasPerfiles.php',
    'app/services/FormularioEjecutivasValidacion.php',
    'app/views/formularios/inscripcion_ejecutivas_previa.php',
    'public/css/formularios_ejecutivas_piloto.css',
    'public/javascript/formularios_ejecutivas_piloto.js'
]

def git_base(name):
    p = subprocess.run(['git','show',BASE_SHA+':'+name],cwd=str(ROOT),
                       stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert p.returncode == 0, p.stderr.decode('utf-8','replace')
    return p.stdout

with tempfile.TemporaryDirectory(prefix='impe-ejecutivas-publicador-') as temp:
    root = Path(temp)
    site, base, target, backup = (root/'public_html',root/'base',root/'target',root/'respaldo-privado')
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    olds = {}
    for i,name in enumerate(FILES):
        (target/name).parent.mkdir(parents=True,exist_ok=True)
        (target/name).write_bytes(subprocess.check_output(['git','show','8b9630b5:'+name],cwd=str(ROOT)))
        (site/name).parent.mkdir(parents=True,exist_ok=True)
        if i < 2:
            raw=git_base(name)
            (base/name).parent.mkdir(parents=True,exist_ok=True)
            (base/name).write_bytes(raw)
            (site/name).write_bytes(raw)
            olds[name]=raw

    def run(*arguments, ok=True):
        cmd=[sys.executable,str(PUBLISHER),'--site',str(site),
             '--base-dir',str(base),'--target-dir',str(target),
             '--backup-root',str(backup)]+list(arguments)
        p=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        output=(p.stdout+p.stderr).decode('utf-8','replace')
        assert (p.returncode==0)==ok,output
        return output

    assert 'CHECK OK' in run('--check')
    assert not backup.exists()
    for name in olds:
        assert (site/name).read_bytes()==olds[name]
    assert 'APLICADO' in run('--apply')
    for name in FILES:
        assert (site/name).read_bytes()==(target/name).read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    folders=list(backup.iterdir())
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    for name in FILES:
        assert (site/name).exists()==(name in olds)
        if name in olds:
            assert (site/name).read_bytes()==olds[name]

    # Hostinger puede contener comentarios y modificaciones independientes.
    control=FILES[0]
    view=FILES[1]
    custom=olds[control].replace(b'<?php',b'<?php\n// PERSONALIZADO LOCAL\n',1)
    (site/control).write_bytes(custom)
    (site/view).write_bytes(olds[view]+b'\n<!-- bloque local independiente -->\n')
    assert 'CHECK OK' in run('--check')
    assert 'APLICADO' in run('--apply')
    assert b'PERSONALIZADO LOCAL' in (site/control).read_bytes()
    assert b'bloque local independiente' in (site/view).read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    new_folders=[x for x in backup.iterdir() if x!=folders[0]]
    assert len(new_folders)==1
    assert 'RESTAURADO' in run('--restore',str(new_folders[0]))
    assert (site/control).read_bytes()==custom

    # Una firma alterada bloquea la instalación y no destruye nada.
    altered=custom.replace(b'    public function generarEnlaceInscripcion()\n',
                           b'    public function generarEnlaceInscripcionPersonalizado()\n',1)
    assert altered!=custom
    (site/control).write_bytes(altered)
    assert 'CONFLICTO' in run('--check',ok=False)
    assert 'CONFLICTO' in run('--apply',ok=False)
    assert (site/control).read_bytes()==altered

print('PASS Ejecutivas: CHECK, APPLY, idempotencia, personalizaciones y RESTORE')
