"""Sitios ficticios: parche selectivo, aislamiento, respaldo y reversión."""
from pathlib import Path
import difflib,hashlib,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_widgets_marketing_hostinger.sh'
norm=lambda b:b.replace(b'\r\n',b'\n')
source=lambda rev,f:norm(subprocess.check_output(['git','show',rev+':'+f],cwd=root))
current=lambda f:norm((root/f).read_bytes())
patches=['public/css/convocatorias.css','app/views/dashboard/marketing.php','app/controllers/HomeController.php']
helpers=['app/models/MarketingWidgetsModel.php','app/views/dashboard/marketing_widgets.php','app/helpers/MapaEstadoHelper.php']
files=helpers+patches
# Sólo el bloque de la tabla cambia; Indicadores Clave y los demás widgets son idénticos.
old=source('a5fd2478',patches[1]);new=current(patches[1]);start=old.index(b'<section class="dashboard-panel marketing-recent-publications mt-4">');end=old.index(b'</section>',start)+len(b'</section>')
assert new==old[:start]+b"<?php require __DIR__ . '/marketing_widgets.php'; ?>"+old[end:]
# Ninguna declaración CSS existente ajena a la tabla retirada se altera.
css=current(patches[0]);prior=source('a5fd2478',patches[0]);a=css.index(b'/* Tres widgets operativos:');b=css.index(b'/* =========================================================\n   PUBLICACIONES DEL D',a)
c=prior.index(b'/* Publicaciones recientes en el dashboard principal de Marketing. */');d=prior.index(b'/* =========================================================\n   PUBLICACIONES DEL D',c)
for line in [b'    .marketing-recent-publications .marketing-recent-heading,\n',b'    .marketing-recent-publications .marketing-recent-heading > *,\n',b'    .marketing-recent-publications .marketing-recent-link,\n']:prior=prior.replace(line,b'')
# Los índices anteriores están antes de las líneas móviles retiradas.
assert css[:a]+css[b:]==prior[:c]+prior[d:]
home=current(patches[2]);added=b"->obtenerRecientesDashboard(4);\n                    require_once __DIR__ . '/../models/MarketingWidgetsModel.php';\n                    $widgetsMarketing = (new MarketingWidgetsModel())->obtener();"
assert home.replace(added,b'->obtenerRecientesDashboard(3);')==source('a5fd2478',patches[2]),'Otros roles y lógica Home intactos'
changed=subprocess.check_output(['git','diff','--name-only','a5fd2478','--','app','public','config'],cwd=root,text=True).splitlines()
assert set(changed)<=set(files),changed

digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else 'MISSING'
for rev,helper_instalado in [('a5fd2478',False),('a5fd2478',True)]:
 with tempfile.TemporaryDirectory(prefix='marketing-widgets-publish-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();(live/'tools').mkdir();(live/'app/services').mkdir(parents=True);(live/'app/helpers').mkdir(parents=True)
  for f in ['index.php','app/models/ConvocatoriaModel.php','config/mail_central.local.php','config/password_reset_mail.local.php','app/services/CorreoHostingerApiService.php','public/javascript/mi_perfil.js','app/views/dashboard/marketing_indicadores.php','app/views/dashboard/analista.php','app/views/layout/sidebar.php']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php // PROTEGIDO FICTICIO',encoding='utf-8')
  protected={p:digest(p) for p in live.rglob('*') if p.is_file()};expected={}
  for f in patches:
   prior=source(rev,f);p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
   if f.endswith('.php'):prior=prior.replace(b'<?php',b'<?php\n// comentario compatible conservado',1).replace(b'\n',b'\r\n')
   p.write_bytes(prior)
   delta=''.join(difflib.unified_diff(source('a5fd2478',f).decode().splitlines(keepends=True),current(f).decode().splitlines(keepends=True),fromfile='a/'+f,tofile='b/'+f))
   stage=base/'expected';target=stage/f;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(norm(prior));patch=base/'delta.patch';patch.write_text(delta,encoding='utf-8',newline='\n')
   env=os.environ.copy();env['GIT_CEILING_DIRECTORIES']=str(base)
   subprocess.run(['git','-c','core.autocrlf=false','apply',str(patch)],cwd=stage,env=env,capture_output=True,check=True)
   expected[f]=target.read_bytes()
  if helper_instalado:
   (live/helpers[2]).write_bytes(current(helpers[2]));protected[live/helpers[2]]=digest(live/helpers[2])
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
  edited=live/patches[2];saved=edited.read_bytes();edited.write_bytes(saved+b'\n// cambio posterior\n');run('--restore',backup,ok=False);edited.write_bytes(saved)
  run('--restore',backup);assert before=={f:digest(live/f) for f in files};assert all(digest(p)==h for p,h in protected.items())
  incompatible=live/patches[0];incompatible.write_bytes(incompatible.read_bytes()+b'/* personalizacion */');same={f:digest(live/f) for f in files}
  run('--check',ok=False);assert same=={f:digest(live/f) for f in files}
print('OK: sólo widgets Marketing; Indicadores Clave, otros roles, CSS ajeno, correo y recuperación intactos. Publicador selectivo, LF/CRLF/comentarios, check sin escritura, apply/restore/idempotencia y rechazo de incompatibles. Sin producción.')
