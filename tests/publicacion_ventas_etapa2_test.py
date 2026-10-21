"""Publicadores sobre réplicas. Compatible con Python 3.6; sin credenciales/producción."""
from pathlib import Path
import os,sys,subprocess,json,re,tempfile,hashlib,concurrent.futures
ROOT=Path(__file__).resolve().parents[1];BASH=os.environ.get('IMPE_TEST_BASH','bash')
def sp(p):
 s=str(p).replace('\\','/');return '/'+s[0].lower()+s[2:] if re.match(r'^[A-Za-z]:/',s) else s
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT).replace(b'\r\n',b'\n')
def snap(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def scenario(stage,kind):
 m=json.loads((ROOT/('tools/ventas_etapa2_'+stage+'/manifest.json')).read_text(encoding='utf8'));script=ROOT/('tools/publicar_ventas_etapa2_'+stage+'_hostinger.sh')
 target=re.search(r"TARGET='([a-f0-9]{40})'",script.read_text(encoding='utf8')).group(1)
 with tempfile.TemporaryDirectory(prefix='impe-etapa2-') as tmp:
  private=Path(tmp);live=private/'site';live.mkdir();(live/'index.php').write_text('<?php // replica',encoding='utf8')
  source=private/'source';subprocess.check_call(['git','clone','--quiet','--shared','--no-checkout',str(ROOT),str(source)])
  def put(p,data):
   file=live/p;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(data);file.chmod(0o644)
  put('app/services/TelefoniaMarcacionesVentasService.php',blob(m['base'],'app/services/TelefoniaMarcacionesVentasService.php'))
  for p in ['public/css/telefonia_ventas_ui.css','public/css/telefonia_shell.css','public/css/seguimiento_entrantes_analista.css']:put(p,b'/* PERSONALIZACION HOSTINGER: CONSERVAR */')
  if stage=='b':
   for p in ['app/services/TelefoniaVentasEvidenciaService.php','app/services/TelefoniaProspectoLlamadaService.php']:put(p,blob(target,p))
  for f in m['files']:
   if f['new']:continue
   data=(ROOT/f['production_fixture']).read_bytes() if kind.startswith('hostinger') and f.get('production_fixture') else blob(m['base'],f['path'])
   if kind=='personalizado' and f['kind']=='php':data+=b'\n// PERSONALIZACION FUERA DEL PARCHE\n'
   if kind in ['crlf','hostinger-crlf']:data=data.replace(b'\n',b'\r\n')
   put(f['path'],data)
  if kind=='js-desconocido':
   f=next(f for f in m['files'] if f['kind']=='js' and not f['new']);put(f['path'],(live/f['path']).read_bytes()+b'\n// NO REVISADO')
  if kind=='nuevo-existente':put(next(f['path'] for f in m['files'] if f['new']),b'// ARCHIVO AJENO')
  if kind=='hostinger-desconocido':
   p='public/javascript/telefonia_host.js';put(p,(live/p).read_bytes()+b'\n// CAMBIO NO REVISADO')
  env=dict(os.environ,TMPDIR=sp(private));prefix='IMPE_VENTAS_ETAPA2_'+stage.upper()
  env.update({prefix+'_SOURCE':sp(source),prefix+'_PUBLIC':sp(live),prefix+'_BACKUPS':sp(private/'backups')})
  def run(mode,backup=None):
   r=subprocess.run([BASH,sp(script),mode]+([sp(backup)] if backup else []),cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True);return r.returncode,r.stdout
  before=snap(live);code,out=run('--check');assert snap(live)==before,out
  if kind in ['js-desconocido','nuevo-existente','hostinger-desconocido']:
   assert code!=0,out;code,out=run('--apply');assert code!=0 and snap(live)==before,out;return stage+'/'+kind+': rechazado, sitio intacto'
  assert code==0,out;code,out=run('--apply');assert code==0,out;after=snap(live)
  for p,data in before.items():
   if p not in [f['path'] for f in m['files']]:assert after[p]==data,p
  for f in m['files']:
   data=after[f['path']].replace(b'\r\n',b'\n')
   if kind=='personalizado' and f['kind']=='php' and not f['new']:assert data.endswith(b'// PERSONALIZACION FUERA DEL PARCHE\n')
   else:
    expected=next((v['after'] for v in f['variants'] if v['label'].startswith('hostinger') and kind.startswith('hostinger')),f['target_sha256']);assert hashlib.sha256(data).hexdigest()==expected,f['path']
  backups=list((private/'backups').iterdir());assert len(backups)==1
  code,out=run('--apply');assert code==0 and snap(live)==after,out
  if kind=='edicion-posterior':
   p=live/next(f['path'] for f in m['files'] if f['kind']=='php');old=p.read_bytes();p.write_bytes(old+b'\n// EDICION POSTERIOR');edited=snap(live)
   code,out=run('--restore',backups[0]);assert code!=0 and snap(live)==edited,out;p.write_bytes(old)
  code,out=run('--restore',backups[0]);assert code==0 and snap(live)==before,out
  return stage+'/'+kind+': check/apply/idempotencia/restore exacto, CSS intacto'
if __name__=='__main__':
 stages=[sys.argv[1]] if len(sys.argv)>1 else ['a','b'];kinds=sys.argv[2:] or ['pr115','hostinger','hostinger-crlf','hostinger-desconocido','personalizado','crlf','js-desconocido','nuevo-existente','edicion-posterior']
 with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
  tasks=[pool.submit(scenario,s,k) for s in stages for k in kinds if not (s=='a' and k=='hostinger-desconocido')]
  for task in tasks:print('OK:',task.result(),flush=True)
