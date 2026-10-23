"""Sólo sitio ficticio: publicación/reversión de una vista, sin BD ni proveedor."""
from pathlib import Path
import hashlib,os,re,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
bash,phpdir=sys.argv[1:3]
script=root/'tools/publicar_retirar_cobertura_aliados_hostinger.sh'
file='app/views/aliados/index.php'
old=subprocess.check_output(['git','show','12d6755c:'+file],cwd=root).replace(b'\r\n',b'\n')
new=(root/file).read_bytes().replace(b'\r\n',b'\n')
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
# La única modificación de producción es la vista Aliados.
changed=subprocess.check_output(['git','diff','--name-only','12d6755c','--','app','public','config'],cwd=root,text=True).splitlines()
assert changed==[file],changed
# Sólo se retira el bloque visual; el resto de la vista y su JS son idénticos.
a=old.index(b"<?php if ((int)($_SESSION['rol_id'] ?? 0) === 1): ?>");b=old.index(b'<?php endif; ?>',a)+len(b'<?php endif; ?>')
assert new==old[:a]+old[b:]
for eol in [b'\n',b'\r\n']:
 with tempfile.TemporaryDirectory(prefix='impe-aliados-cobertura-test-') as td:
  base=Path(td);live=base/'public_html';live.mkdir();target=live/file;target.parent.mkdir(parents=True)
  original=old.replace(b'\n',eol);expected=new.replace(b'\n',eol);target.write_bytes(original)
  (live/'index.php').write_text('<?php // sitio ficticio',encoding='utf-8')
  secrets={}
  for name in ['config/mail_central.local.php','config/password_reset_mail.local.php','app/services/CorreoHostingerApiService.php','public/javascript/correos_marketing.js','public/css/convocatorias.css','app/views/layout/sidebar.php','app/views/dashboard/marketing_indicadores.php','app/views/dashboard/analista.php','app/controllers/AliadoController.php','app/models/AliadoModel.php','app/views/aliados/estado.php','app/views/aliados/internacionales.php']:
   p=live/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('SECRETO FICTICIO INTACTO',encoding='utf-8');secrets[p]=digest(p)
  env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix();env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix()
  def run(*args,ok=True):
   r=subprocess.run([bash,script.as_posix(),*args],cwd=root,env=env,capture_output=True,text=True,timeout=30)
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout
  run('--check');assert target.read_bytes()==original;assert not (base/'backups').exists()
  result=run('--apply');backup=re.search(r'^Respaldo: (.+)$',result,re.M)[1]
  assert target.read_bytes()==expected
  assert 'ya aplicado' in run('--apply');assert len(list((base/'backups').iterdir()))==1
  target.write_bytes(expected+b'/* personalizacion posterior */');run('--restore',backup,ok=False)
  assert target.read_bytes().endswith(b'/* personalizacion posterior */');target.write_bytes(expected)
  run('--restore',backup);assert target.read_bytes()==original;assert 'ya restaurado' in run('--restore',backup)
  # Respaldos fuera de la carpeta prevista e incompatibles no se aplican.
  run('--restore',str(base),ok=False)
  target.write_bytes(original+b'/* personalizacion anterior */');run('--check',ok=False)
  assert target.read_bytes().endswith(b'/* personalizacion anterior */')
  assert all(digest(p)==h for p,h in secrets.items())
  assert sorted(p.relative_to(live).as_posix() for p in live.rglob('*') if p.is_file())==sorted([file,'index.php']+[p.relative_to(live).as_posix() for p in secrets])
print('OK: check sin escritura, apply sólo vista Aliados, restore exacto, LF/CRLF, idempotencia y rechazo de vistas personalizadas; secretos y servicios ficticios intactos.')
