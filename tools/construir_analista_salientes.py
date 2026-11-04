"""Genera sólo el registro de salientes, conservando las variantes comprobadas de Hostinger."""
from pathlib import Path
import base64,difflib,gzip,hashlib,json,re,subprocess,tempfile
R=Path(__file__).resolve().parents[1]
BASE='0cd5c72291997a700fa25e2155cabe18a7712c6b'
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=str(R))
def sha(b):return None if b is None else hashlib.sha256(b).hexdigest()
def pack(b):
 if b is None:return None
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
paths=['app/services/TelefoniaSalienteAnalistaEstado.php','app/services/TelefoniaEntrantesAnalistaService.php','app/controllers/SeguimientoVinculacionController.php','app/views/seguimiento_vinculacion/llamadas_entrantes.php','public/javascript/seguimiento_entrantes_analista.js','public/javascript/telefonia_host.js','public/javascript/telefonia_shell.js','public/javascript/telefonia_analista_historial_pasivo.js','public/javascript/seguimiento_llamada_registro_obligatorio.js','public/javascript/seguimiento_llamada_zadarma.js']
refs={
'app/views/seguimiento_vinculacion/llamadas_entrantes.php':'8b79f611028c402de0728b77975c28b4d439d8fc',
'public/javascript/seguimiento_entrantes_analista.js':'7858bc2cde0b597b31c997b27629ee11b3d3e495',
'public/javascript/telefonia_host.js':'e511b05c086edff977cd84bdcbe36b00b858e527',
'public/javascript/telefonia_shell.js':'a1e150b584467c419725e18f04e6ed75ba91a2da'}
rows=[]
for p in paths:
 target=(R/p).read_bytes().replace(b'\r\n',b'\n')
 if p==paths[0]:originals=[None]
 else:
  originals=[blob(BASE,p)]
  if p.endswith(('seguimiento_llamada_registro_obligatorio.js','seguimiento_llamada_zadarma.js')):originals=[blob('6db736ac1c88cef50b5ffabdebf1b6708b6878cc',p),blob(BASE,p)]
  if p in refs:originals.append(blob(refs[p],p))
  if p.endswith('SeguimientoVinculacionController.php'):
   old=json.loads(blob(BASE,'tools/verificacion_vigente_release.json'))
   row=next(r for r in old['files'] if r['path']==p)
   originals.append(gzip.decompress(base64.b64decode(row['variants'][1]['target'])))
  if p.endswith('telefonia_analista_historial_pasivo.js'):
   originals.append(gzip.decompress(base64.b64decode((R/'tests/fixtures/analista_salientes_pasivo_hostinger.b64').read_text())))
 variants=[]
 for original in originals:
  if any(v['before']==sha(original) for v in variants):continue
  data=target
  if original==target:
   data=target
  elif original is not None and original!=originals[0] and p.endswith('telefonia_shell.js'):
   text=original.decode()
   a="pending:Boolean(callbackPending||window.IMPE_CALL_CLOSURE?.hasPending()"
   b="pending:Boolean(window.IMPE_ANALYST_OUTGOING_RESULT?.hasPending()||crm?.contentDocument?.body?.classList.contains('impe-call-registration-pending')||callbackPending||window.IMPE_CALL_CLOSURE?.hasPending()"
   assert text.count(a)==1
   text=text.replace(a,b)
   a='        openPendingResult: function () {'
   insert=(R/p).read_text(encoding='utf8').split(a,1)[1].split('            if(window.IMPE_SALES_ATTENTION',1)[0]
   assert text.count(a)==1
   data=text.replace(a,a+insert.rstrip('\n')).encode()
  elif original is not None and original!=originals[0]:
   patch=''.join(difflib.unified_diff(originals[0].decode().splitlines(True),target.decode().splitlines(True),fromfile='a/'+p,tofile='b/'+p))
   with tempfile.TemporaryDirectory(prefix='impe-saliente-variante-') as d:
    dest=Path(d)/p;dest.parent.mkdir(parents=True);dest.write_bytes(original)
    proc=subprocess.run(['git','-c','core.autocrlf=false','apply','--whitespace=nowarn','-'],input=patch.encode(),cwd=d,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if proc.returncode:raise RuntimeError(p+': '+proc.stderr.decode())
    data=dest.read_bytes()
  variants.append({'before':sha(original),'base':pack(original),'after':sha(data),'target':pack(data)})
 rows.append({'path':p,'variants':variants})
deps=[]
for p,ref in [('app/views/telefonia/resultado_analista_form.php','2c087d8168bcd16d91e38b2057ccb9b24e491649'),('app/services/TelefoniaAnalistaWebrtcService.php','0495f77d59ee0b5c08b10dca6b94fc8db5fea19e'),('prueba_telefonia/api/zadarma_estado_llamada.php','0139325b61ea8c3aa754d738eee881d623c2a3ed')]:
 data=blob(ref,p);deps.append({'path':p,'sha256':sha(data),'base':pack(data)})
raw=(json.dumps({'schema':1,'package':'analista-salientes-formulario','files':rows,'dependencies':deps},indent=2)+'\n').encode()
(R/'tools/analista_salientes_release.json').write_bytes(raw)
s=blob(BASE,'tools/publicar_analista_registro_obligatorio.py').decode()
s=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'","MANIFEST_SHA256 = '"+sha(raw)+"'",s)
a=s.index('PATHS = ');b=s.index('\n\n',a)
s=s[:a]+'PATHS = '+repr(paths)+'\nDEPENDENCIES = '+repr({d['path']:d['sha256'] for d in deps})+s[b:]
s=s.replace('analista_registro_obligatorio_release.json','analista_salientes_release.json').replace('analista-registro-obligatorio','analista-salientes-formulario').replace('registro obligatorio del Analista','formulario compartido de salientes del Analista')
s=s.replace('def prepare(site, package):\n    rows = []',"def prepare(site, package):\n    for name, expected in DEPENDENCIES.items():\n        if digest(read(safe(site, name))) != expected:\n            raise RuntimeError('DEPENDENCIA DIFERENTE: '+name+'. Sitio intacto.')\n    rows = []")
(R/'tools/publicar_analista_salientes.py').write_text(s,encoding='utf8',newline='\n')
print('Manifest:',sha(raw))
