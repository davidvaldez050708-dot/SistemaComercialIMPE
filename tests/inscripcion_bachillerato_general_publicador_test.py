"""Publicación reversible de Bachillerato General en una copia, sin credenciales ni SQL."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUBLISHER=ROOT/'tools/publicar_inscripcion_bachillerato_general_hostinger.py'
BASE='6b7905d8839a4ff21ea770a83c5a269db19b5707'
FILES=[
    'app/services/FormularioInscripcionValidacion.php',
    'app/services/FormularioInscripcionPantalla.php',
    'app/views/formularios/inscripcion_completa.php',
    'app/views/formularios/publico_inscripcion_completa.php',
    'app/resources/inscripcion/terminos-bachillerato-general.txt',
    'app/resources/inscripcion/privacidad-bachillerato-general.txt'
]

def from_git(name):
    p=subprocess.run(['git','show',BASE+':'+name],cwd=str(ROOT),
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert p.returncode==0,p.stderr.decode('utf-8','replace')
    return p.stdout

with tempfile.TemporaryDirectory(prefix='impe-bachillerato-publicacion-') as tmp:
    root=Path(tmp)
    site,target,backup=(root/'public_html',root/'fuentes',root/'respaldos-privados')
    site.mkdir()
    (site/'index.php').write_text('<?php // replica',encoding='utf-8')
    before={}
    for i,name in enumerate(FILES):
        path=target/name
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes((ROOT/name).read_bytes())
        live=site/name
        live.parent.mkdir(parents=True,exist_ok=True)
        if i<4:
            old=from_git(name)
            live.write_bytes(old)
            before[name]=old

    def run(*args,ok=True):
        command=[sys.executable,str(PUBLISHER),'--site',str(site),
                 '--target-dir',str(target),'--backup-root',str(backup)]+list(args)
        p=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        text=(p.stdout+p.stderr).decode('utf-8','replace')
        assert (p.returncode==0)==ok,text
        return text

    assert 'CHECK OK' in run('--check')
    assert not backup.exists(),'CHECK debe ser solo lectura'
    for name in FILES:
        dest=site/name
        if name in before:
            assert dest.read_bytes()==before[name]
        else:
            assert not dest.exists()
    assert 'APLICADO' in run('--apply')
    for name in FILES:
        assert (site/name).read_bytes()==(ROOT/name).read_bytes(),name
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    folders=[x for x in backup.iterdir() if x.is_dir()]
    assert len(folders)==1
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    for name in FILES:
        dest=site/name
        if name in before:
            assert dest.read_bytes()==before[name]
        else:
            assert not dest.exists()

    # Edición concurrente en una vista: CHECK y APPLY abortan sin escrituras.
    name=FILES[2]
    altered=before[name]+b'\n<!-- cambio local ajeno -->\n'
    (site/name).write_bytes(altered)
    assert 'CONFLICTO' in run('--check',ok=False)
    assert 'CONFLICTO' in run('--apply',ok=False)
    assert (site/name).read_bytes()==altered
    assert len([x for x in backup.iterdir() if x.is_dir()])==1

    # El RESTORE tampoco destruye modificaciones posteriores.
    (site/name).write_bytes(before[name])
    assert 'APLICADO' in run('--apply')
    next_folder=[x for x in backup.iterdir() if x.is_dir() and x!=folders[0]]
    assert len(next_folder)==1
    (site/name).write_bytes((ROOT/name).read_bytes()+b'\n<!-- cambio posterior -->\n')
    assert 'CONFLICTO' in run('--restore',str(next_folder[0]),ok=False)
    assert (site/name).read_bytes().endswith(b'cambio posterior -->\n')
    (site/name).write_bytes((ROOT/name).read_bytes())
    assert 'RESTAURADO' in run('--restore',str(next_folder[0]))
    for name in FILES:
        if name in before:
            assert (site/name).read_bytes()==before[name]
        else:
            assert not (site/name).exists()

print('PASS: Bachillerato sin perfil; CHECK, APPLY, idempotencia, respaldos, RESTORE y conflictos')
