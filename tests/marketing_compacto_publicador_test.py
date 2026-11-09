"""Sitios ficticios: parche selectivo, aislamiento, respaldo y reversión."""
from pathlib import Path
import difflib,hashlib,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1];bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_marketing_compacto_hostinger.sh'
norm=lambda b:b.replace(b'\r\n',b'\n')
source=lambda rev,f:norm(subprocess.check_output(['git','show',rev+':'+f],cwd=root))
current=lambda f:norm((root/f).read_bytes())
patches=['public/css/convocatorias.css','app/views/dashboard/marketing.php'];files=patches
# Sólo los dos paneles y el contenedor de su vecino cambian; consultas intactas.
old=source('b0acb38c',patches[1]);new=current(patches[1]);expected=old
for marker in [b'<section class="dashboard-panel marketing-territory-coverage mt-4">',b'<section class="dashboard-panel marketing-publications-by-type">']:
 a=expected.index(marker);b=expected.index(b'</section>',a)+len(b'</section>');expected=expected[:a]+expected[b:]
expected=expected.replace(b'<div class="marketing-bottom-grid mt-4">',b'<div class="mt-4">')
assert expected==new
css=current(patches[0]);a=css.index(b'/* Resumen mensual compacto:');b=css.index(b'/* =========================================================\n   PUBLICACIONES DEL D',a)
assert css[:a]+css[b:]==source('b0acb38c',patches[0]),'Sólo se añaden selectores del resumen mensual'
changed=subprocess.check_output(['git','diff','--name-only','b0acb38c','--','app','public','config'],cwd=root,text=True).splitlines()
assert set(changed)<=set(files),changed

digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else 'MISSING'
for rev,crlf in [('b0acb38c',False),('b0acb38c',True)]:
 with tempfile.TemporaryDirectory(prefix='marketing-compacto-publish-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();(live/'tools').mkdir();(live/'app/services').mkdir(parents=True);(live/'app/helpers').mkdir(parents=True)
  for f in ['index.php','app/models/ConvocatoriaModel.php','config/mail_central.local.php','config/password_reset_mail.local.php','app/services/CorreoHostingerApiService.php','public/javascript/mi_perfil.js','app/views/dashboard/marketing_indicadores.php','app/views/dashboard/analista.php','app/views/layout/sidebar.php','app/controllers/HomeController.php','app/models/MarketingWidgetsModel.php','app/models/MarketingIndicadoresModel.php','app/views/dashboard/marketing_widgets.php']:
   p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php // PROTEGIDO FICTICIO',encoding='utf-8')
  protected={p:digest(p) for p in live.rglob('*') if p.is_file()};expected={}
  for f in patches:
   prior=source(rev,f);p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
   if f.endswith('.php'):prior=prior.replace(b'<?php',b'<?php\n// comentario compatible conservado',1)
   if crlf:prior=prior.replace(b'\n',b'\r\n')
   p.write_bytes(prior)
   delta=''.join(difflib.unified_diff(source('b0acb38c',f).decode().splitlines(keepends=True),current(f).decode().splitlines(keepends=True),fromfile='a/'+f,tofile='b/'+f))
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
print('OK: dos archivos visuales; modelos, consultas, Indicadores Clave y otros módulos intactos. LF/CRLF, check/apply/restore, idempotencia y rechazo de personalizaciones. Sin producción.')
