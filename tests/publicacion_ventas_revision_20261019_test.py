"""Publicación post-E de Ventas: 9 archivos de Hostinger, sólo en réplicas desechables."""
from pathlib import Path
import hashlib, os, re, subprocess, sys, tempfile

REPO=Path(__file__).resolve().parents[1]
SCRIPT=REPO/'tools/publicar_ventas_revision_20261019_hostinger.sh'
TARGET='9692b73077bc093c3a0a07d3f1c920e63e9328df'
BASE=TARGET+'^1'
FILES={
    'app/services/TelefoniaMarcacionesVentasService.php':'61bf714d243bd1742e8a2ea00b883e2b7c10c61da562622bfec63251f236bb2e',
    'app/services/TelefoniaVentasLlamadasService.php':'9e8295ad5340895f7acb3cdc8611ca28e040bb3c88ab2891f45039cd7bf0a161',
    'app/views/telefonia/marcador.php':'3114a95de36ee952413a47565f970a90beca884b3e72f05e91c7646a0ffae36e',
    'app/views/telefonia/ventas_llamadas.php':'9d4ffacca65722fcc6b8aa2d0cdd595830b549530fb1859bb87dadaf17ba2b05',
    'public/javascript/telefonia_marcador.js':'aece60de5f4a7b994d0639313b9034e154b6c2bd62be78a3c6b5918ce079972e',
    'public/javascript/telefonia_ventas_shell.js':'92d884534ab2c7b42f4ae9368289d7b7a010d3de29e34a59960caa5c52f96e1e',
    'public/css/telefonia_shell.css':'5298d0505cde80425b4710b39d2b270f5d6ecbdb686f854bc1d97698d7bdd272',
    'public/css/telefonia_marcador.css':'43ab353c2a5df461eecc2493710ddc104a8dd814e6b38c5634cbffe9c9b43cdc',
    'public/css/telefonia_ventas_ui.css':'MISSING'
}
def git_blob(ref,file):
    return subprocess.check_output(['git','show',ref+':'+file],cwd=REPO)
def digest(data):
    return hashlib.sha256(data).hexdigest()
def snapshot(root):
    return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file() and not p.is_symlink()}
def run_scenario(kind):
    with tempfile.TemporaryDirectory(prefix='impe-ventas-revision-',dir=os.environ.get('IMPE_TEST_TMP')) as dirname:
        tmp=Path(dirname);live=tmp/'site';live.mkdir()
        (live/'index.php').write_text('<?php // fixture',encoding='utf-8')
        (live/'privado.txt').write_text('No modificar otras áreas',encoding='utf-8')
        for file,sha in FILES.items():
            if sha=='MISSING':continue
            p=live/file;p.parent.mkdir(parents=True,exist_ok=True)
            blob=git_blob(BASE,file)
            assert digest(blob)==sha,('Huellas Hostinger no coinciden con versión esperada',file,digest(blob),sha)
            p.write_bytes(blob);p.chmod(0o644)
        if kind=='applied':
            for file in FILES:
                p=live/file;p.parent.mkdir(parents=True,exist_ok=True)
                p.write_bytes(git_blob(TARGET,file))
        if kind=='unknown-js':
            p=live/'public/javascript/telefonia_marcador.js';p.write_bytes(p.read_bytes()+b'\\n// NUEVA PERSONALIZACION\\n')
        if kind=='unknown-css':
            p=live/'public/css/telefonia_marcador.css';p.write_bytes(p.read_bytes()+b'\\n/* VERSION DIFERENTE */\\n')
        if kind=='unknown-php':
            p=live/'app/services/TelefoniaMarcacionesVentasService.php';p.write_bytes(b'<?php // variante inesperada')
        if kind=='unexpected-new':
            p=live/'public/css/telefonia_ventas_ui.css';p.write_text('/* desconocida */',encoding='utf-8')
        if kind=='missing-old':
            (live/'app/views/telefonia/marcador.php').unlink()
        if kind=='symlink':
            p=live/'public/css/telefonia_shell.css';p.unlink();p.symlink_to(tmp/'private-source.css')
        env={**os.environ,'IMPE_VENTAS_REVISION_SOURCE':str(REPO),'IMPE_VENTAS_REVISION_PUBLIC':str(live),'IMPE_VENTAS_REVISION_BACKUPS':str(tmp/'backups'),'TMPDIR':str(tmp)}
        def shell(*args):
            p=subprocess.run(['bash',str(SCRIPT),*map(str,args)],cwd=REPO,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
            return p.returncode,p.stdout
        before=snapshot(live)
        rc,out=shell('--check')
        if kind.startswith('unknown-') or kind in ('unexpected-new','missing-old','symlink'):
            assert rc!=0,(kind,out)
            assert snapshot(live)==before,('check modified production replica',kind)
            rc,out=shell('--apply')
            assert rc!=0,(kind,out)
            assert snapshot(live)==before,('apply modified unexpected source',kind)
            assert not (tmp/'backups').exists()
            return kind+': conflicto rechazado, sitio intacto'
        assert rc==0,(kind,out)
        assert snapshot(live)==before,('check changed files',kind)
        rc,out=shell('--apply')
        assert rc==0,(kind,out)
        updated=snapshot(live)
        for file in FILES:
            assert updated[file]==git_blob(TARGET,file),('Wrong deployed bytes',kind,file)
        assert updated['privado.txt']==before['privado.txt']
        backups=list((tmp/'backups').iterdir()) if (tmp/'backups').exists() else []
        if kind=='applied':
            assert backups==[],('Idempotent apply unexpectedly backed up',backups)
            return 'applied: comprobación idempotente'
        assert len(backups)==1,(kind,backups)
        rc,out=shell('--check')
        assert rc==0 and 'Ningún archivo reemplazado' in out,(kind,out)
        if kind=='tampered-after-apply':
            p=live/'public/javascript/telefonia_marcador.js';p.write_bytes(p.read_bytes()+b'\\n// cambiado luego\\n')
            modified=snapshot(live)
            rc,out=shell('--restore',backups[0])
            assert rc!=0,(kind,out)
            assert snapshot(live)==modified,'Restore sobrescribió un archivo editado'
            return kind+': restauración insegura rechazada'
        rc,out=shell('--restore',backups[0])
        assert rc==0,(kind,out)
        assert snapshot(live)==before,'Rollback no restituyó exactamente la réplica'
        return kind+': check, apply, idempotencia, backup y rollback exactos'

if __name__=='__main__':
    subprocess.run(['bash','-n',str(SCRIPT)],check=True)
    for k in ('hostinger','applied','unknown-js','unknown-css','unknown-php','unexpected-new','missing-old','symlink','tampered-after-apply'):
        print('OK:',run_scenario(k),flush=True)
    print('Ventas post-E: 9 escenarios en réplicas desechables, sin tocar Hostinger.')
