"""Publicación PR108: huellas de Hostinger y rollback en réplicas desechables."""
from pathlib import Path
import os, subprocess, tempfile, hashlib

REPO=Path(__file__).resolve().parents[1]
SCRIPT=REPO/'tools/publicar_ventas_pr108_hostinger.sh'
TARGET='6035e328676d9c5a80a46d09cd3010910fbcdfd6'
BASE=TARGET+'^1'
FILES={
    "app/views/telefonia/ventas_llamadas.php": "85dffacee855fb1550041cf823dfc9af6287a2f9e0ecbfcc2ea058c3b7096c9a",
    "app/views/telefonia/ventas_devoluciones.php": "a75f72e312b40b3046b6dbaf44f05362beadcc43d3ae2c03b825acd89f142244",
    "app/views/telefonia/ventas_programadas.php": "8e6b41b90d33cfc345b5f2e572d4771f17697ff75160a2c5b68b2fc376048173",
    "public/css/telefonia_ventas_ui.css": "43d34778afd47a5bb2c1e89759af1a577a9f086c1f78545faf24870c9db0dcf2",
    "app/services/TelefoniaNotificacionVentasService.php": "3b557e8894f94d8b46dec3a23428792f273f9199c76c689c447e937bd0dc4113",
    "public/javascript/telefonia_ventas_auto_filtros.js": "MISSING"
}
def gitblob(ref,file):return subprocess.check_output(['git','show',ref+':'+file],cwd=REPO)
def sha(data):return hashlib.sha256(data).hexdigest()
def snapshot(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file() and not p.is_symlink()}
def check_case(mode):
    with tempfile.TemporaryDirectory(prefix='impe-pr108-',dir=os.environ.get('IMPE_TEST_TMP')) as dn:
        tmp=Path(dn);live=tmp/'site';live.mkdir()
        (live/'index.php').write_text('<?php');(live/'otro-modulo.txt').write_text('No tocar')
        for file,h in FILES.items():
            if h=='MISSING':continue
            content=gitblob(BASE,file)
            assert sha(content)==h,(file,'la huella del repositorio no coincide con Hostinger',sha(content),h)
            p=live/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
        if mode=='unknown':
            p=live/'public/css/telefonia_ventas_ui.css';p.write_bytes(p.read_bytes()+b'\\n/* hostinger extra */')
        if mode=='unexpected-new':
            p=live/'public/javascript/telefonia_ventas_auto_filtros.js';p.parent.mkdir(parents=True,exist_ok=True);p.write_text('unreviewed')
        if mode=='missing':
            (live/'app/views/telefonia/ventas_llamadas.php').unlink()
        if mode=='symlink':
            p=live/'public/css/telefonia_ventas_ui.css';p.unlink();p.symlink_to(tmp/'outside')
        if mode=='applied':
            for file in FILES:
                p=live/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(gitblob(TARGET,file))
        env={**os.environ,'IMPE_VENTAS_PR108_SOURCE':str(REPO),'IMPE_VENTAS_PR108_PUBLIC':str(live),'IMPE_VENTAS_PR108_BACKUPS':str(tmp/'backup'),'TMPDIR':str(tmp)}
        def run(*args):
            p=subprocess.run(['bash',str(SCRIPT),*map(str,args)],cwd=REPO,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            return p.returncode,p.stdout
        before=snapshot(live)
        rc,out=run('--check')
        if mode in ('unknown','unexpected-new','missing','symlink'):
            assert rc!=0,(mode,out)
            assert snapshot(live)==before
            rc,out=run('--apply')
            assert rc!=0,(mode,out)
            assert snapshot(live)==before
            return mode
        assert rc==0,(mode,out)
        assert snapshot(live)==before
        rc,out=run('--apply')
        assert rc==0,(mode,out)
        after=snapshot(live)
        for file in FILES:assert after[file]==gitblob(TARGET,file),(mode,file)
        assert after['otro-modulo.txt']==before['otro-modulo.txt']
        backups=list((tmp/'backup').iterdir()) if (tmp/'backup').exists() else []
        if mode=='applied':
            assert not backups
            return mode
        assert len(backups)==1
        rc,out=run('--check')
        assert rc==0 and 'Ningún archivo reemplazado' in out,(mode,out)
        rc,out=run('--restore',backups[0])
        assert rc==0,(mode,out)
        assert snapshot(live)==before,(mode,'rollback')
        return mode
if __name__=='__main__':
    subprocess.run(['bash','-n',str(SCRIPT)],check=True)
    for mode in ['normal','applied','unknown','unexpected-new','missing','symlink']:
        print('OK:',check_case(mode),flush=True)
    print('PR108: 6 escenarios de despliegue y restauración seguros.')
