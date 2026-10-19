"""Ejecuta el publicador real exclusivamente sobre réplicas desechables."""
from pathlib import Path
import concurrent.futures,hashlib,json,os,re,subprocess,tempfile
REPO=Path(__file__).resolve().parents[1]
SCRIPT=REPO/'tools/publicar_recepcion_vinculacion_hostinger.sh'
BASH=os.environ.get('IMPE_TEST_BASH','bash')
TARGET=re.search(r"^TARGET='([a-f0-9]{40})'",SCRIPT.read_text(encoding='utf8'),re.M)[1]
MANIFEST=json.loads((REPO/'tools/recepcion_vinculacion_20261018/manifest.json').read_text(encoding='utf8'))
def blob(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=REPO).replace(b'\r\n',b'\n')
def sp(path):
 text=str(path).replace('\\','/')
 return '/'+text[0].lower()+text[2:] if re.match(r'^[A-Za-z]:/',text) else text
def snap(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def site(root,production=True,crlf=False):
 live=root/'domains/example.org/public_html';live.mkdir(parents=True)
 (live/'index.php').write_bytes(b'<?php throw new Exception("NO EJECUTAR CRM");')
 for item in MANIFEST['files']:
  if item['new']:continue
  p=live/item['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(blob(MANIFEST['base'],item['path']))
  if production and item.get('production_fixture'):subprocess.run(['git','-c','core.autocrlf=false','apply',str(REPO/item['production_fixture'])],cwd=live,check=True,capture_output=True)
  if crlf:p.write_bytes(p.read_bytes().replace(b'\n',b'\r\n'))
  p.chmod(0o644)
 for name in ['config/database.local.php','storage/grabaciones/audio.mp3','public/javascript/telefonia_persistente.js']:
  p=live/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'PERSONALIZACION PRIVADA A CONSERVAR')
 return live
def run(root,live,mode,backup=None,extra=None):
 env=dict(os.environ,IMPE_INTEGRAL_SOURCE=sp(REPO),IMPE_INTEGRAL_PUBLIC=sp(live),IMPE_INTEGRAL_BACKUPS=sp(root/'backups'),TMPDIR=sp(root))
 env.update(extra or {});args=[BASH,sp(SCRIPT),mode]+([sp(backup)] if backup else [])
 return subprocess.run(args,cwd=REPO,env=env,capture_output=True,text=True,encoding='utf8',errors='replace')
def okay(result):assert result.returncode==0,result.stdout+result.stderr
def publication(root,production=True,crlf=False,custom=False):
 live=site(root,production,crlf)
 if custom:
  p=live/'app/controllers/ReminderController.php';p.write_bytes(p.read_bytes()+b'\n// PERSONALIZACION PHP A CONSERVAR\n')
 before=snap(live);okay(run(root,live,'--check'));assert snap(live)==before and not (root/'backups').exists()
 applied=run(root,live,'--apply');okay(applied)
 after=snap(live)
 for item in MANIFEST['files']:
  p=item['path'];data=after[p].replace(b'\r\n',b'\n')
  if custom and p=='app/controllers/ReminderController.php':assert data.endswith(b'// PERSONALIZACION PHP A CONSERVAR\n');continue
  expected=next((v['after'] for v in item['variants'] if v['label'].startswith('hostinger') and production),item['target_sha256'])
  assert hashlib.sha256(data).hexdigest()==expected,p
  if os.name!='nt':assert (live/p).stat().st_mode&0o777==0o644,p
 for p in before.keys()-{x['path'] for x in MANIFEST['files']}:assert after[p]==before[p]
 backup=list((root/'backups').iterdir());assert len(backup)==1
 okay(run(root,live,'--apply'));assert len(list((root/'backups').iterdir()))==1
 okay(run(root,live,'--restore',backup[0]));assert snap(live)==before
 okay(run(root,live,'--restore',backup[0]));assert snap(live)==before
 return live,before,backup[0]
def hostinger(root):publication(root);return 'Hostinger: variantes preservadas, check intacto, instalación y rollback exactos'
def github(root):publication(root,False);return 'GitHub: instalación selectiva e idempotencia'
def crlf(root):publication(root,crlf=True);return 'LF/CRLF y permisos conservados'
def php_custom(root):publication(root,custom=True);return 'PHP personalizado compatible conservado y validado'
def unknown_js(root):
 live=site(root);p=live/'public/javascript/telefonia_shell.js';p.write_bytes(p.read_bytes()+b'\n// NO REVISADO\n');before=snap(live)
 r=run(root,live,'--apply');assert r.returncode!=0 and 'variante no revisada' in r.stderr,r.stdout+r.stderr
 assert snap(live)==before and not (root/'backups').exists();return 'JS desconocido rechazado sin publicar parcialmente'
def conflict_php(root):
 live=site(root);(live/'app/controllers/ReminderController.php').write_bytes(b'<?php // completamente distinto\n');before=snap(live)
 r=run(root,live,'--apply');assert r.returncode!=0 and 'Parche incompatible' in r.stderr,r.stdout+r.stderr
 assert snap(live)==before;return 'PHP incompatible rechazado sin escrituras'
def locked(root):
 live=site(root);before=snap(live);(live.parent/'.impe-atencion-deploy.lock').mkdir()
 assert run(root,live,'--apply').returncode!=0;assert snap(live)==before;return 'bloqueo compartido'
def interrupted(root):
 live=site(root);before=snap(live);wrapper=root/'interrumpir.bash'
 wrapper.write_text('moves=0\nmv(){ moves=$((moves+1));if [[ "$moves" == 21 ]];then return 23;fi;command mv "$@"; }\n',encoding='utf8',newline='\n')
 r=run(root,live,'--apply',extra={'BASH_ENV':sp(wrapper)});assert r.returncode!=0 and 'se restauraron' in r.stderr,r.stdout+r.stderr
 assert snap(live)==before;assert not (live.parent/'.impe-atencion-deploy.lock').exists();return 'fallo al último archivo restaura los 21 y elimina altas nuevas'
def later_edit(root):
 live=site(root);okay(run(root,live,'--apply'));backup=list((root/'backups').iterdir())[0]
 p=live/'public/javascript/telefonia_shell.js';p.write_bytes(p.read_bytes()+b'\n// EDICION POSTERIOR\n');before=snap(live)
 r=run(root,live,'--restore',backup);assert r.returncode!=0 and 'después de publicar' in r.stderr,r.stdout+r.stderr
 assert snap(live)==before;return 'rollback protege ediciones posteriores'
def corrupt_backup(root):
 live=site(root);okay(run(root,live,'--apply'));backup=list((root/'backups').iterdir())[0];(backup/'app/controllers/ReminderController.php').write_bytes(b'ALTERADO');before=snap(live)
 assert run(root,live,'--restore',backup).returncode!=0;assert snap(live)==before;return 'respaldo corrupto rechazado'
def no_node(root):
 live=site(root);wrapper=root/'no-node.bash';wrapper.write_text('node(){ echo NODE_NO_DISPONIBLE >&2; return 99; }\n',encoding='utf8',newline='\n')
 r=run(root,live,'--apply',extra={'BASH_ENV':sp(wrapper)});okay(r);assert 'NODE_NO_DISPONIBLE' not in r.stderr;return 'Hostinger sin ejecutar Node: huellas JS completas'
def concurrent_edit(root):
 live=site(root);before=snap(live);wrapper=root/'editar.bash'
 wrapper.write_text('git(){ command git "$@" || return $?;if [[ "$*" == *" apply "* && "$*" != *" --check "* && ! -e "$IMPE_INTEGRAL_BACKUPS" ]];then printf "\\n// EDICION CONCURRENTE\\n" >> "$IMPE_INTEGRAL_PUBLIC/public/javascript/telefonia_shell.js";fi; }\n',encoding='utf8',newline='\n')
 r=run(root,live,'--apply',extra={'BASH_ENV':sp(wrapper)});assert r.returncode!=0 and 'Cambió durante' in r.stderr,r.stdout+r.stderr
 now=snap(live);assert {p:b for p,b in now.items() if p!='public/javascript/telefonia_shell.js'}=={p:b for p,b in before.items() if p!='public/javascript/telefonia_shell.js'};assert b'EDICION CONCURRENTE' in now['public/javascript/telefonia_shell.js'];return 'edición concurrente detectada antes de instalar'
def corrupted_stage(root):
 live=site(root);before=snap(live);wrapper=root/'corrupt-stage.bash'
 wrapper.write_text('git(){ command git "$@" || return $?;if [[ "$*" == *" apply "* && "$*" != *" --check "* && -f public/javascript/recordatorios.js ]];then printf "\\n<<<<<<< SIN_VALIDAR\\n" >> public/javascript/recordatorios.js;fi; }\n',encoding='utf8',newline='\n')
 r=run(root,live,'--apply',extra={'BASH_ENV':sp(wrapper)});assert r.returncode!=0 and 'Salida distinta' in r.stderr,r.stdout+r.stderr
 assert snap(live)==before;return 'salida alterada rechazada por huella completa'
def symlink(root):
 if os.name=='nt':return 'enlaces: comprobación real reservada al CI Linux'
 live=site(root);p=live/'public/javascript/telefonia_shell.js';outside=root/'outside.js';p.rename(outside);p.symlink_to(outside);before=outside.read_bytes()
 r=run(root,live,'--apply');assert r.returncode!=0 and 'Enlace no permitido' in r.stderr;assert outside.read_bytes()==before;return 'enlace simbólico rechazado sin tocar destino'
def https(root):
 live=site(root);before=snap(live);config=root/'gitconfig';config.write_text('[url "git@github.com:"]\n insteadOf = https://github.com/\n',encoding='utf8')
 r=run(root,live,'--check',extra={'IMPE_INTEGRAL_SOURCE':sp(root/'source-new'),'GIT_CONFIG_GLOBAL':sp(config),'GIT_SSH_COMMAND':'false'})
 okay(r);assert snap(live)==before;return 'descarga HTTPS pública independiente de redirecciones SSH'
if __name__=='__main__':
 subprocess.run([BASH,'-n',sp(SCRIPT)],check=True)
 for item in MANIFEST['files']:
  assert hashlib.sha256(blob(TARGET,item['path'])).hexdigest()==item['target_sha256'],item['path']
  if item['path'].endswith('.js'):subprocess.run(['node','--check',str(REPO/item['path'])],check=True)
 tests=[hostinger,github,crlf,php_custom,unknown_js,conflict_php,locked,interrupted,later_edit,corrupt_backup,no_node,concurrent_edit,corrupted_stage,symlink]
 if os.environ.get('IMPE_TEST_NETWORK')=='1':tests.append(https)
 with tempfile.TemporaryDirectory(prefix='impe-integral-',dir=os.environ.get('IMPE_TEST_TMP')) as tmp:
  def execute(test):root=Path(tmp)/test.__name__;root.mkdir();return test(root)
  with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
   for future in concurrent.futures.as_completed([pool.submit(execute,t) for t in tests]):print('OK:',future.result(),flush=True)
 print(f'Publicación integral: {len(tests)} escenarios aprobados; sólo réplicas aisladas.')
