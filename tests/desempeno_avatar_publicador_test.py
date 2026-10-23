"""Sitios ficticios: parche selectivo, aislamiento, respaldo y reversión."""
from pathlib import Path
import difflib,hashlib,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_avatar_desempeno_hostinger.sh'
norm=lambda b:b.replace(b'\r\n',b'\n')
source=lambda rev,f:norm(subprocess.check_output(['git','show',rev+':'+f],cwd=root))
current=lambda f:norm((root/f).read_bytes())
patches=['public/css/desempeno.css','app/views/desempeno/index.php'];files=patches
# Sólo se añaden reglas específicas del avatar personal; no se cambia CSS previo.
assert current(patches[0]).startswith(source('59716bbf',patches[0]))
changed=subprocess.check_output(['git','diff','--name-only','59716bbf','--','app','public','config'],cwd=root,text=True).splitlines()
assert set(changed)<=set(files),changed

digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else 'MISSING'
for rev,crlf in [('59716bbf',False),('59716bbf',True)]:
 with tempfile.TemporaryDirectory(prefix='desempeno-avatar-publish-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();(live/'tools').mkdir();(live/'app/services').mkdir(parents=True);(live/'app/helpers').mkdir(parents=True)
  for f in ['index.php','app/helpers/AvatarHelper.php','config/mail_central.local.php','config/password_reset_mail.local.php','app/services/CorreoHostingerApiService.php','public/javascript/mi_perfil.js','app/views/dashboard/marketing_indicadores.php','app/views/dashboard/analista.php','app/views/layout/sidebar.php','app/controllers/HomeController.php','app/models/MarketingWidgetsModel.php','app/models/MarketingIndicadoresModel.php','app/views/dashboard/marketing_widgets.php','app/controllers/DesempenoController.php','app/models/DesempenoModel.php','app/services/DesempenoService.php','app/views/layout/dashboard_footer.php','public/css/global.css']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php // PROTEGIDO FICTICIO',encoding='utf-8')
  protected={p:digest(p) for p in live.rglob('*') if p.is_file()};expected={}
  for f in patches:
   prior=source(rev,f);p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
   if f.endswith('.php'):prior=prior.replace(b'$fotoUrl = static function',b'// comentario compatible conservado\n$fotoUrl = static function',1)
   if crlf:prior=prior.replace(b'\n',b'\r\n')
   p.write_bytes(prior)
   delta=''.join(line if line.endswith('\n') else line+'\n\\ No newline at end of file\n' for line in difflib.unified_diff(source('59716bbf',f).decode().splitlines(keepends=True),current(f).decode().splitlines(keepends=True),fromfile='a/'+f,tofile='b/'+f))
   stage=base/'expected';target=stage/f;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(norm(prior));patch=base/'delta.patch';patch.write_text(delta,encoding='utf-8',newline='\n')
   env=os.environ.copy();env['GIT_CEILING_DIRECTORIES']=str(base)
   subprocess.run(['git','-c','core.autocrlf=false','apply',str(patch)],cwd=stage,env=env,capture_output=True,check=True)
   expected[f]=target.read_bytes()
  before={f:digest(live/f) for f in files}
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,text=True,capture_output=True,timeout=60)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  run('--check');assert before=={f:digest(live/f) for f in files};assert not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1]
  for f in files:assert norm((live/f).read_bytes())==(expected[f] if f in patches else current(f)),f
  assert all(digest(p)==h for p,h in protected.items());assert 'ya aplicado' in run('--apply')
  edited=live/patches[1];saved=edited.read_bytes();edited.write_bytes(saved+b'\n// cambio posterior\n');run('--restore',backup,ok=False);edited.write_bytes(saved)
  run('--restore',backup);assert before=={f:digest(live/f) for f in files};assert all(digest(p)==h for p,h in protected.items())
  incompatible=live/patches[0];incompatible.write_bytes(incompatible.read_bytes()+b'/* personalizacion */');same={f:digest(live/f) for f in files}
  run('--check',ok=False);assert same=={f:digest(live/f) for f in files}
print('OK: sólo vista/CSS de Desempeño; helper, footer, CSS global, modelos, consultas y otros módulos intactos. LF/CRLF, check/apply/restore, idempotencia y rechazo de personalizaciones. Sin producción.')
