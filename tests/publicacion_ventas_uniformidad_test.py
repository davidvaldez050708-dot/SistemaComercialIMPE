"""Publicador real en réplicas; no usa servidores ni base de datos de producción."""
from pathlib import Path
import concurrent.futures, hashlib, json, os, re, subprocess, tempfile

REPO=Path(__file__).resolve().parents[1]
SCRIPT=REPO/'tools/publicar_ventas_uniformidad_hostinger.sh'
MANIFEST=json.loads((REPO/'tools/ventas_uniformidad_20261019/manifest.json').read_text(encoding='utf8'))
BASH=os.environ.get('IMPE_TEST_BASH','bash')
DEP='app/services/TelefoniaMarcacionesVentasService.php'

def sp(p):
    s=str(p).replace('\\','/')
    return '/'+s[0].lower()+s[2:] if re.match(r'^[A-Za-z]:/',s) else s

def blob(ref,p):
    return subprocess.check_output(['git','show',ref+':'+p],cwd=REPO).replace(b'\r\n',b'\n')

def snap(root):
    return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}

def scenario(kind):
    with tempfile.TemporaryDirectory(prefix='impe-uniformidad-',dir=os.environ.get('IMPE_TEST_TMP')) as tmp:
        root=Path(tmp);live=root/'site';live.mkdir();(live/'index.php').write_bytes(b'<?php // no ejecutar CRM')
        for p,data in {DEP:blob(MANIFEST['base'],DEP),'public/css/telefonia_shell.css':(REPO/'tools/ventas_revision_20261019/telefonia_shell_hostinger.css').read_bytes(),'public/css/seguimiento_entrantes_analista.css':blob(MANIFEST['base'],'public/css/seguimiento_entrantes_analista.css')+b'\n/* PERSONALIZADO HOSTINGER */\n','privado.txt':b'PRIVADO'}.items():
            out=live/p;out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(data)
        for f in MANIFEST['files']:
            if f['new'] or (kind=='css-ausente' and f.get('optional_missing')):continue
            p=live/f['path'];p.parent.mkdir(parents=True,exist_ok=True);data=blob(MANIFEST['base'],f['path'])
            if kind=='personalizado' and f['kind']=='php':data+=b'\n// PERSONALIZACION A CONSERVAR\n'
            if kind=='crlf':data=data.replace(b'\n',b'\r\n')
            p.write_bytes(data);p.chmod(0o644)
        bad={'js-desconocido':'public/javascript/telefonia_ventas_shell.js','css-desconocido':'public/css/telefonia_ventas_ui.css','php-conflicto':'app/services/TelefoniaVentasLlamadasService.php','dependencia-antigua':DEP}
        if kind in bad:
            p=live/bad[kind];p.write_bytes(b'<?php // incompatible\n' if kind in ['php-conflicto','dependencia-antigua'] else p.read_bytes()+b'\n/* NO REVISADO */\n')
        env=dict(os.environ,TMPDIR=sp(root),IMPE_VENTAS_UNIFORMIDAD_SOURCE=sp(REPO),IMPE_VENTAS_UNIFORMIDAD_PUBLIC=sp(live),IMPE_VENTAS_UNIFORMIDAD_BACKUPS=sp(root/'backups'))
        def run(mode,backup=None):
            p=subprocess.run([BASH,sp(SCRIPT),mode]+([sp(backup)] if backup else []),cwd=REPO,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
            return p.returncode,p.stdout+p.stderr
        before=snap(live)
        if kind in bad:
            code,out=run('--apply');assert code!=0,out;assert snap(live)==before and not (root/'backups').exists()
            return kind+': rechazado antes de escritura'
        code,out=run('--check');assert code==0,out;assert snap(live)==before and not (root/'backups').exists()
        code,out=run('--apply');assert code==0,out;after=snap(live)
        paths={f['path'] for f in MANIFEST['files']}
        for p,data in before.items():
            if p not in paths:assert after[p]==data,'Personalización ajena alterada: '+p
        for f in MANIFEST['files']:
            data=after[f['path']].replace(b'\r\n',b'\n')
            if kind=='personalizado' and f['kind']=='php' and not f['new']:assert data.endswith(b'// PERSONALIZACION A CONSERVAR\n')
            else:assert hashlib.sha256(data).hexdigest()==f['target_sha256'],f['path']
            if os.name!='nt':assert (live/f['path']).stat().st_mode&0o777==0o644
        backups=list((root/'backups').iterdir());assert len(backups)==1
        code,out=run('--apply');assert code==0,out;assert len(list((root/'backups').iterdir()))==1
        code,out=run('--restore',backups[0]);assert code==0,out;assert snap(live)==before
        return kind+': check intacto, apply, idempotencia, CSS Hostinger conservado y restore exacto'

if __name__=='__main__':
    subprocess.run([BASH,'-n',sp(SCRIPT)],check=True)
    kinds=['github','personalizado','crlf','css-ausente','js-desconocido','css-desconocido','php-conflicto','dependencia-antigua']
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        for result in pool.map(scenario,kinds):print('OK:',result,flush=True)
    print('Publicador uniformidad: 8 escenarios en réplicas aprobados.')
