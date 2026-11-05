"""Paquete de tres JS: registro obligatorio del auricular, sin tocar Seguimiento."""
import base64,difflib,gzip,hashlib,json,pathlib,re,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
BASE='1f82798e3c4b6ec7362f8858af768a69d483fc61'
FILES=['public/javascript/telefonia_analista_resultado_superpuesto.js','public/javascript/telefonia_analista_historial_pasivo.js','public/javascript/seguimiento_entrantes_analista.js']
def sha(b):return hashlib.sha256(b).hexdigest()
def pack(b):
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
def blob(p):return subprocess.check_output(['git','show',BASE+':'+p],cwd=str(R))
host=json.loads((R/'tests/fixtures/analista_resultado_ui_hostinger.json').read_text(encoding='utf8'))
rows=[]
for p in FILES:
 old=blob(p);target=(R/p).read_bytes().replace(b'\r\n',b'\n');originals=[old]
 if p in host:
  local=gzip.decompress(base64.b64decode(host[p]['base']));assert sha(local)==host[p]['sha256'];originals.append(local)
 variants=[]
 for original in originals:
  if any(v['before']==sha(original) for v in variants):continue
  after=target
  if original!=old:
   if p.endswith('telefonia_analista_historial_pasivo.js'):
    marker='/* Resultado de marcación libre del Analista. Observa el host existente; no llama al SDK. */'.encode()
    assert original.count(marker)==target.count(marker)==1
    after=original.split(marker)[0]+marker+target.split(marker)[1]
   else:
    patch=''.join(difflib.unified_diff(old.decode().splitlines(True),target.decode().splitlines(True),fromfile='a/'+p,tofile='b/'+p))
    with tempfile.TemporaryDirectory() as directory:
     dest=pathlib.Path(directory)/p;dest.parent.mkdir(parents=True);dest.write_bytes(original)
     subprocess.run(['git','-c','core.autocrlf=false','apply','--whitespace=nowarn','-'],cwd=directory,input=patch.encode(),check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
     after=dest.read_bytes()
  variants.append({'before':sha(original),'base':pack(original),'after':sha(after),'target':pack(after)})
 rows.append({'path':p,'variants':variants})
dep='public/javascript/telefonia_shell.js';dep_base=blob(dep);dep_host=gzip.decompress(base64.b64decode(host[dep]['base']))
dependencies={dep:[sha(dep_base),sha(dep_host)]}
spec={'schema':1,'package':'analista-resultado-obligatorio-ui','files':rows,'dependencies':[{'path':dep,'variants':[{'sha256':sha(b),'base':pack(b)} for b in [dep_base,dep_host]]}]}
raw=(json.dumps(spec,indent=2)+'\n').encode()
(R/'tools/analista_resultado_obligatorio_ui_release.json').write_bytes(raw)
publisher=blob('tools/publicar_analista_salientes.py').decode()
publisher=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'","MANIFEST_SHA256 = '"+sha(raw)+"'",publisher)
begin=publisher.index('PATHS = ');end=publisher.index('\n\n',begin)
publisher=publisher[:begin]+'PATHS = '+repr(FILES)+'\nDEPENDENCIES = '+repr(dependencies)+publisher[end:]
publisher=publisher.replace('digest(read(safe(site, name))) != expected','digest(read(safe(site, name))) not in expected')
publisher=publisher.replace('analista_salientes_release.json','analista_resultado_obligatorio_ui_release.json').replace('analista-salientes-formulario','analista-resultado-obligatorio-ui').replace('formulario compartido de salientes del Analista','registro obligatorio del auricular del Analista')
(R/'tools/publicar_analista_resultado_obligatorio_ui.py').write_text(publisher,encoding='utf8',newline='\n')
print('Manifest:',sha(raw))
