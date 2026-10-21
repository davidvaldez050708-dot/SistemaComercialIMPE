"""Publicador real sobre réplicas temporales; compatible con Python 3.6."""
from pathlib import Path
import os, sys, subprocess, tempfile, json, re
ROOT = Path(__file__).resolve().parents[1]
M = json.loads((ROOT/'tools/aliados_convocatorias/manifest.json').read_text(encoding='utf8'))
BASH = os.environ.get('IMPE_TEST_BASH', 'bash')
def sp(p):
    s = str(p).replace('\\', '/')
    return '/' + s[0].lower() + s[2:] if re.match(r'^[A-Za-z]:/', s) else s
def snap(root):
    return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def run(kind):
    with tempfile.TemporaryDirectory(prefix='impe-handly-') as tmp:
        private=Path(tmp); live=private/'site'; live.mkdir()
        # El publicador aísla la configuración Git; usar un clon propiedad del usuario de prueba.
        prepared_source=private/'source'
        subprocess.check_call(['git','clone','--quiet','--shared','--no-checkout',str(ROOT),str(prepared_source)],cwd=ROOT)
        (live/'index.php').write_text('<?php // fixture',encoding='utf8')
        (live/'personalizacion.txt').write_text('CONSERVAR',encoding='utf8')
        for f in M['files']:
            ref='aa1f9fafeeca8572e78098bef501bc81e0460c12' if kind=='pr114' else M['base']
            source=subprocess.run(['git','show',ref+':'+f['path']],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if source.returncode: continue
            p=live/f['path'];p.parent.mkdir(parents=True,exist_ok=True)
            p.write_bytes(source.stdout.replace(b'\r\n',b'\n'))
            if kind=='personalizado' and f['path']==M['files'][0]['path']: p.write_bytes(b'<?php /* PERSONALIZACION HOSTINGER */ ?>\n'+p.read_bytes())
            if kind=='crlf': p.write_bytes(p.read_bytes().replace(b'\n',b'\r\n'))
        first=live/M['files'][0]['path']
        if kind=='vista-conflicto':
            p=live/'app/views/convocatorias/index.php';p.write_text(p.read_text(encoding='utf8').replace('Nueva convocatoria','Convocatoria personalizada'),encoding='utf8')
        if kind=='marcadores':
            p=live/'app/views/layout/sidebar.php';p.write_bytes(p.read_bytes()+b'\n<<<<<<< HEAD\n')
        if kind=='conflicto': first.write_text('<?php // variante incompatible',encoding='utf8')
        if kind=='nuevo-existente':
            f=next(f for f in M['files'] if f['new']);p=live/f['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php // archivo ajeno',encoding='utf8')
        env=dict(os.environ,TMPDIR=sp(private),IMPE_ALIADOS_CONVOCATORIAS_SOURCE=sp(prepared_source),IMPE_ALIADOS_CONVOCATORIAS_PUBLIC=sp(live),IMPE_ALIADOS_CONVOCATORIAS_BACKUPS=sp(private/'backups'))
        def command(mode,backup=None):
            r=subprocess.run([BASH,sp(ROOT/'tools/publicar_aliados_convocatorias_hostinger.sh'),mode]+([sp(backup)] if backup else []),cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
            return r.returncode,r.stdout
        before=snap(live);code,out=command('--check')
        assert snap(live)==before,out
        if kind in ['conflicto','nuevo-existente','vista-conflicto','marcadores']:
            assert code!=0,out
            code,out=command('--apply');assert code!=0 and snap(live)==before,out
            print('OK: '+kind+' bloqueado sin escrituras');return
        assert code==0,out
        code,out=command('--apply');assert code==0,out
        backup=list((private/'backups').iterdir());assert len(backup)==1
        if kind=='personalizado': assert b'PERSONALIZACION HOSTINGER' in first.read_bytes()
        assert (live/'personalizacion.txt').read_text()=='CONSERVAR'
        installed=snap(live);code,out=command('--apply');assert code==0 and snap(live)==installed,out
        code,out=command('--restore',backup[0]);assert code==0 and snap(live)==before,out
        print('OK: '+kind+' check/apply/idempotencia/restore exacto')
if __name__=='__main__':
    for kind in (sys.argv[1:] or ['base','pr114','personalizado','crlf','conflicto','nuevo-existente','vista-conflicto','marcadores']):run(kind)
