"""Paquete reproducible de programación de atención; conserva las variantes territoriales."""
import base64,difflib,gzip,hashlib,json,pathlib,re,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
BASE='926c8f0ff15c02c7d6411e6edd7340a84ffa328c'
FILES=['app/services/InteraccionProximaAccion.php','app/services/InteraccionRutaService.php',
 'app/controllers/SeguimientoVinculacionController.php','app/models/SeguimientoVinculacionModel.php',
 'app/services/SeguimientoFlujoService.php','public/javascript/seguimiento_interacciones.js']
def sha(b):return hashlib.sha256(b).hexdigest() if b is not None else None
def pack(b):
 if b is None:return None
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
def blob(p):return subprocess.check_output(['git','show',BASE+':'+p],cwd=str(R))
def replace(s,a,b,n=1):
 assert s.count(a)==n,(a,s.count(a),n)
 return s.replace(a,b)
def adapt(p,old,base,target):
 s=old.decode('utf8')
 if p.endswith('SeguimientoVinculacionModel.php'):
  a="require_once __DIR__ . '/../services/TelefoniaVerificacionEvidenciaService.php';"
  s=replace(s,a,a+"\nrequire_once __DIR__ . '/../services/InteraccionProximaAccion.php';")
  for method in ['consultaSeguimientosBase','consultaDetalleSeguimientoBase']:
   a='    private function '+method+'()\n    {\n'
   s=replace(s,a,a+"        $programada = InteraccionProximaAccion::condicionSql('seguimientos');\n")
  a="WHEN seguimientos.estado_seguimiento = 'DATOS_VERIFICADOS' THEN NULL"
  s=replace(s,a,"WHEN seguimientos.estado_seguimiento = 'DATOS_VERIFICADOS' AND NOT ($programada) THEN NULL",2)
  s=replace(s,'END AS proxima_accion_at,','END AS proxima_accion_at,\n                    ($programada) AS proxima_accion_programada,')
  s=replace(s,'                    seguimientos.*,','                    seguimientos.*,\n                    ($programada) AS proxima_accion_programada,')
  return s.encode()
 if p.endswith('SeguimientoFlujoService.php'):
  a="require_once __DIR__ . '/../../config/db_connection.php';"
  s=replace(s,a,a+"\nrequire_once __DIR__ . '/InteraccionProximaAccion.php';")
  a='        $usuarioId = (int)$usuarioId;'
  s=replace(s,a,a+"\n        $programada = InteraccionProximaAccion::condicionSql('seguimientos');")
  s=replace(s,'                    seguimientos.proxima_accion_at,',"                    seguimientos.proxima_accion_at,\n                    (' . $programada . ') AS proxima_accion_programada,")
  s=replace(s,"if ($estado === 'DATOS_VERIFICADOS') {","if ($estado === 'DATOS_VERIFICADOS' && empty($seguimiento['proxima_accion_programada'])) {")
  return s.encode()
 if p.endswith('.js'):return target # Mismo diseño; conserva la regla de datos vigentes ya corregida.
 if p.endswith('InteraccionRutaService.php'):
  start="            $etapaPersistencia = 'actualizar la fecha de última interacción';"
  end='            $stmtSeguimiento->execute();'
  canonical=base.decode();a=s.index(start);b=s.index(end,a)+len(end)
  c=canonical.index(start);d=canonical.index(end,c)+len(end)
  s=s[:a]+canonical[c:d]+s[b:];old=s.encode()
 patch=''.join(difflib.unified_diff(base.decode().splitlines(True),target.decode().splitlines(True),fromfile='a/'+p,tofile='b/'+p,n=1))
 with tempfile.TemporaryDirectory() as directory:
  dest=pathlib.Path(directory)/p;dest.parent.mkdir(parents=True);dest.write_bytes(old)
  result=subprocess.run(['git','-c','core.autocrlf=false','apply','--unidiff-zero','--whitespace=nowarn','-'],cwd=directory,input=patch.encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  assert result.returncode==0,(p,result.stderr.decode())
  after=dest.read_bytes()
  if p.endswith('InteraccionRutaService.php'):
   text=after.decode();a=text.index(start);b=text.index(end,a)
   block=text[a:b];block=replace(block,'AND analista_id = ?',"AND \" . SeguimientoTerritorialScope::analista('seguimientos_vinculacion') . \"")
   after=(text[:a]+block+text[b:]).encode()
  return after
host=json.loads((R/'tests/fixtures/analista_proxima_accion_hostinger.json').read_text(encoding='utf8'))
rows=[]
for p in FILES:
 target=(R/p).read_bytes().replace(b'\r\n',b'\n');base=None if p==FILES[0] else blob(p);originals=[base]
 if p in host:
  installed=gzip.decompress(base64.b64decode(host[p]['base']));assert sha(installed)==host[p]['sha256'];originals.append(installed)
 variants=[]
 for old in originals:
  if any(v['before']==sha(old) for v in variants):continue
  after=target if old==base else adapt(p,old,base,target)
  variants.append({'before':sha(old),'base':pack(old),'after':sha(after),'target':pack(after)})
 rows.append({'path':p,'variants':variants})
raw=(json.dumps({'schema':1,'package':'analista-proxima-accion','files':rows},indent=2)+'\n').encode()
(R/'tools/analista_proxima_accion_release.json').write_bytes(raw)
s=blob('tools/publicar_analista_cancelacion_inmediata.py').decode()
s=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'","MANIFEST_SHA256 = '"+sha(raw)+"'",s)
a=s.index('PATHS = ');b=s.index('\n\n',a);s=s[:a]+'PATHS = '+repr(FILES)+s[b:]
s=s.replace('analista_cancelacion_inmediata_release.json','analista_proxima_accion_release.json').replace('analista-cancelacion-inmediata','analista-proxima-accion')
s=s.replace('resultado guardado sin bloqueo por vinculación pendiente','próxima acción del Analista')
(R/'tools/publicar_analista_proxima_accion.py').write_bytes(s.encode())
print('Manifest:',sha(raw))
