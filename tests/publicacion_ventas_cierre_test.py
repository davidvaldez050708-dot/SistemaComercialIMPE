"""Publicador real, variantes revisadas PR107/109 y réplicas desechables."""
from pathlib import Path
import os,subprocess,tempfile,hashlib,json,re,concurrent.futures
REPO=Path(__file__).resolve().parents[1];SCRIPT=REPO/'tools/publicar_ventas_cierre_etapa1_hostinger.sh'
M=json.loads((REPO/'tools/ventas_cierre_etapa1/manifest.json').read_text(encoding='utf8'));BASH=os.environ.get('IMPE_TEST_BASH','bash')
def sp(p):
 s=str(p).replace('\\','/');return '/'+s[0].lower()+s[2:] if re.match(r'^[A-Za-z]:/',s) else s
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=REPO).replace(b'\r\n',b'\n')
def snap(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def scenario(kind):
 with tempfile.TemporaryDirectory(prefix='impe-bandejas-',dir=os.environ.get('IMPE_TEST_TMP')) as tmp:
  root=Path(tmp);live=root/'site';live.mkdir();(live/'index.php').write_bytes(b'<?php // fixture, no ejecutar CRM')
  for p,data in {'app/services/TelefoniaMarcacionesVentasService.php':blob(M['base'],'app/services/TelefoniaMarcacionesVentasService.php'),'public/css/telefonia_shell.css':(REPO/'tools/ventas_revision_20261019/telefonia_shell_hostinger.css').read_bytes(),'public/css/seguimiento_entrantes_analista.css':blob(M['base'],'public/css/seguimiento_entrantes_analista.css')+b'\n/* HOSTINGER PERSONALIZADO */\n','privado.txt':b'CONSERVAR'}.items():
   out=live/p;out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(data)
  for f in M['files']:
   if f['new']:continue
   data=blob('a5a61f5eb27ff1e1143f0e267fd7b6426f29eebb' if kind=='pr110' else M['base'],f['path'])
   if kind.startswith('hostinger') or kind=='edicion-posterior':
    phase='after' if kind=='hostinger-after' else 'before';p=REPO/('tools/ventas_pr108_hostinger/'+phase+'/'+f['path'])
    if not p.exists():p=REPO/('tools/ventas_pr108_hostinger/before/'+f['path'])
    if p.exists():data=p.read_bytes().replace(b'\r\n',b'\n')
    elif f['path'] in ['public/javascript/telefonia_ventas_shell.js','app/services/TelefoniaVentasLlamadasService.php']:data=blob('3c180f9455ebe2b798fde73fe1541a8a6abc0deb',f['path'])
   if kind=='personalizado' and f['kind']=='php' and not f.get('strict'):data+=b'\n// PERSONALIZACION COMPATIBLE\n'
   if kind=='crlf':data=data.replace(b'\n',b'\r\n')
   p=live/f['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);p.chmod(0o644)
  bad={'js-desconocido':'public/javascript/telefonia_ventas_resultados.js'}
  if kind in bad:p=live/bad[kind];p.write_bytes(p.read_bytes()+b'\n/* NO REVISADO */\n')
  prepared=root/'source';subprocess.check_call(['git','clone','--quiet','--shared','--no-checkout',str(REPO),str(prepared)])
  env=dict(os.environ,TMPDIR=sp(root),IMPE_VENTAS_CIERRE_SOURCE=sp(prepared),IMPE_VENTAS_CIERRE_PUBLIC=sp(live),IMPE_VENTAS_CIERRE_BACKUPS=sp(root/'backups'))
  def run(mode,backup=None):
   p=subprocess.run([BASH,sp(SCRIPT),mode]+([sp(backup)] if backup else []),cwd=REPO,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True);return p.returncode,p.stdout+p.stderr
  before=snap(live)
  if kind in bad:
   code,out=run('--apply');assert code!=0,out;assert snap(live)==before and not (root/'backups').exists();return kind+': detenido, sitio intacto'
  code,out=run('--check');assert code==0,out;assert snap(live)==before and not (root/'backups').exists()
  code,out=run('--apply');assert code==0,out;after=snap(live)
  changed={f['path'] for f in M['files']}
  for p,data in before.items():
   if p not in changed:assert after[p]==data,'Archivo ajeno alterado: '+p
  for f in M['files']:
   data=after[f['path']].replace(b'\r\n',b'\n')
   if kind=='personalizado' and f['kind']=='php' and not f['new'] and not f.get('strict'):assert data.endswith(b'// PERSONALIZACION COMPATIBLE\n')
   else:assert hashlib.sha256(data).hexdigest()==f['target_sha256'],f['path']
   if os.name!='nt':assert (live/f['path']).stat().st_mode&0o777==0o644
  backups=list((root/'backups').iterdir());assert len(backups)==1
  code,out=run('--apply');assert code==0,out;assert len(list((root/'backups').iterdir()))==1
  if kind=='edicion-posterior':
   p=live/'app/views/telefonia/resultado_ventas_form.php';installed=p.read_bytes();p.write_bytes(installed+b'\n<!-- EDICION POSTERIOR -->');edited=snap(live)
   code,out=run('--restore',backups[0]);assert code!=0,out;assert snap(live)==edited;p.write_bytes(installed)
  code,out=run('--restore',backups[0]);assert code==0,out;assert snap(live)==before
  return kind+': check, publicación, idempotencia, personalizaciones y rollback exacto'
if __name__=='__main__':
 subprocess.run([BASH,'-n',sp(SCRIPT)],check=True)
 kinds=__import__('sys').argv[1:] or ['main','pr110','personalizado','crlf','js-desconocido','edicion-posterior']
 with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
  for result in pool.map(scenario,kinds):print('OK:',result,flush=True)
 print('Publicador cierre: escenarios seleccionados aprobados.')
