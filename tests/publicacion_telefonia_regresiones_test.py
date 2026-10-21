"""Réplicas locales: publicación incremental, personalizaciones y restauración."""
from pathlib import Path
import os, sys, json, re, subprocess, tempfile, concurrent.futures
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'tools/telefonia_regresiones/manifest.json').read_text(encoding='utf8'))
BASH=os.environ.get('IMPE_TEST_BASH','bash')
SCRIPT=ROOT/'tools/publicar_telefonia_regresiones_hostinger.sh'
def sp(path):
    s=str(path).replace('\\','/')
    return '/'+s[0].lower()+s[2:] if re.match(r'^[A-Za-z]:/',s) else s
def snapshot(root):
    return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def scenario(kind):
    with tempfile.TemporaryDirectory(prefix='impe-estabilizacion-') as directory:
        private=Path(directory);live=private/'site';live.mkdir();source=private/'source'
        subprocess.check_call(['git','clone','--quiet','--shared','--no-checkout',str(ROOT),str(source)])
        (live/'index.php').write_text('<?php // replica',encoding='utf8')
        (live/'personalizacion.txt').write_text('CONSERVAR',encoding='utf8')
        personalized=set()
        for row in M['files']:
            if row['new']:continue
            data=(ROOT/row['production_fixture']).read_bytes() if kind=='hostinger' and row.get('production_fixture') else subprocess.check_output(['git','show',M['base']+':'+row['path']],cwd=ROOT)
            data=data.replace(b'\r\n',b'\n')
            # La antigua envoltura de Ventas se sustituye por el componente
            # compartido; una modificación de esa envoltura exige revisión.
            if row['kind']=='php' and row['path']!='app/views/telefonia/prospecto_venta.php':
                data+=b'\n// PERSONALIZACION HOSTINGER FUERA DEL PARCHE\n';personalized.add(row['path'])
            if kind=='hostinger':data=data.replace(b'\n',b'\r\n')
            file=live/row['path'];file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(data)
        env=dict(os.environ,TMPDIR=sp(private),IMPE_TELEFONIA_REGRESIONES_SOURCE=sp(source),IMPE_TELEFONIA_REGRESIONES_PUBLIC=sp(live),IMPE_TELEFONIA_REGRESIONES_BACKUPS=sp(private/'backups'))
        def run(mode,backup=None):
            result=subprocess.run([BASH,sp(SCRIPT),mode]+([sp(backup)] if backup else []),cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
            return result.returncode,result.stdout
        before=snapshot(live)
        # Una variante JS ajena debe detener toda la instalación, incluso PHP compatible.
        file=live/'public/javascript/telefonia_disponibilidad.js';original=file.read_bytes();file.write_bytes(original+b'\n// PERSONALIZACION NO REVISADA\n');custom=snapshot(live)
        code,out=run('--apply');assert code!=0 and snapshot(live)==custom,out
        file.write_bytes(original)
        code,out=run('--check');assert code==0 and snapshot(live)==before,out
        code,out=run('--apply');assert code==0,out
        installed=snapshot(live)
        for row in M['files']:
            if row['path'] in personalized:assert b'PERSONALIZACION HOSTINGER FUERA DEL PARCHE' in installed[row['path']]
        assert installed['personalizacion.txt']==before['personalizacion.txt']
        code,out=run('--apply');assert code==0 and snapshot(live)==installed,out
        backups=list((private/'backups').iterdir());assert len(backups)==1
        file=live/M['files'][0]['path'];saved=file.read_bytes();file.write_bytes(saved+b'\n// EDICION POSTERIOR\n');edited=snapshot(live)
        code,out=run('--restore',backups[0]);assert code!=0 and snapshot(live)==edited,out
        file.write_bytes(saved)
        code,out=run('--restore',backups[0]);assert code==0 and snapshot(live)==before,out
        return kind+': check sin escrituras, apply, idempotencia, PHP personalizado, bloqueo JS/edicion posterior y restore exacto'
if __name__=='__main__':
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        for result in pool.map(scenario,sys.argv[1:] or ['base','hostinger']):print('OK:',result,flush=True)
