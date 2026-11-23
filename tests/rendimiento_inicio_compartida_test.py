import json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root/'tools'))
import publicar_rendimiento_inicio as pub
spec=json.loads((root/'tools/rendimiento_inicio_release.json').read_text(encoding='utf-8'))
def original(name):return subprocess.check_output(['git','show','a4e7ac7:'+name],cwd=str(root))
with tempfile.TemporaryDirectory(prefix='perf-shared-compat-') as tmp:
 tmp=Path(tmp);modified={}
 for row in spec['files']:
  name=row['path'];old=original(name).decode()
  if name.endswith('LoginController.php'):
   old=old.replace('        $this->usuarioModel = new UsuarioModel();','        $conexion = Database::compartida();\n        $this->usuarioModel = new UsuarioModel($conexion);',1).replace('        $this->rolModel = new RolModel();','        $this->rolModel = new RolModel($conexion);',1)
   start=old.index('    public function procesarRecuperacion()');end=old.index('    private function ',start)
   # Delegación instalada; el resto del servicio no forma parte del parche.
   recovery="""    public function procesarRecuperacion()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $dato = trim((string)($_POST['usuario'] ?? ''));
            if ($dato === '') { $_SESSION['error_recuperacion'] = 'Ingrese su usuario o correo.'; }
            else {
                require_once __DIR__ . '/../services/CorreoRecuperacionService.php';
                (new CorreoRecuperacionService($this->usuarioModel))->solicitar($dato, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            }
        }
        exit;
    }

"""
   old=old[:start]+recovery+old[end:]
  elif name.endswith('TelefoniaController.php'):
   start=old.index('    public function disponibilidad()');end=old.index('    public function completarProgramacionVenta()',start)
   installed=(root/'tests/fixtures/telefonia_disponibilidad_instalada_20261123.txt').read_text(encoding='utf-8')
   old=old[:start]+installed+old[end:]
  new=pub.candidate(old.encode(),row);assert pub.candidate(new,row)==new
  assert pub.candidate(old.replace('\n','\r\n').encode(),row).replace(b'\r\n',b'\n')==new
  pub.lint(new,name);target=tmp/Path(name).name;target.write_bytes(new);modified[name]=target
  if name.endswith('LoginController.php'):
   assert b'new UsuarioModel($conexion)' in new and b'new RolModel($conexion)' in new and b'CorreoRecuperacionService($this->usuarioModel)' in new
  elif name.endswith('TelefoniaController.php'):
   # Sólo se añade el cierre de sesión: se conserva el sondeo instalado completo.
   patched=new.decode();start=patched.index('    public function disponibilidad()');end=patched.index('    public function completarProgramacionVenta()',start)
   expected=patched[start:end].replace("        // El sondeo no modifica datos de la sesión; guardar permisos y liberar navegación.\n        if (($metodo === 'GET' || in_array(($_POST['accion'] ?? ''), ['PRESENCIA','POLL'], true)) && session_status() === PHP_SESSION_ACTIVE) session_write_close();\n",'')
   assert expected==installed
 env=dict(os.environ,PYTHONIOENCODING='utf-8',IMPE_PERF_LOGIN_SOURCE=str(modified['app/controllers/LoginController.php']),IMPE_PERF_PHONE_SOURCE=str(modified['app/controllers/TelefoniaController.php']),IMPE_PERF_EXPECT_SHARED='1')
 p=subprocess.run([sys.executable,str(root/'tests/rendimiento_sesion_test.py')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
 print(p.stdout.decode(errors='replace'))
 site=tmp/'site';private=tmp/'private';site.mkdir();private.mkdir();(site/'index.php').write_text('<?php')
 for row in spec['files']:
  name=row['path'];patched=modified[name].read_bytes()
  # Reconstruir las entradas exactas usadas antes de la publicación.
  current=patched.decode()
  for hunk in reversed(row['hunks']):
   choices=[hunk]+hunk.get('variants',[]);applied=[c for c in choices if current.count(c['after'])==1];assert len(applied)==1
   c=applied[0];current=current.replace(c['after'],c['before'],1)
  target=site/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_text(current,encoding='utf-8')
 service=site/'app/services/TelefoniaDisponibilidadService.php';service.parent.mkdir(parents=True,exist_ok=True);service.write_text('<?php /* servicio moderno ya instalado */')
 command=[sys.executable,str(root/'tools/publicar_rendimiento_inicio.py'),'--site',str(site),'--backup-root',str(private)]
 for mode in ['--check','--apply','--apply']:
  r=subprocess.run(command+[mode],stdout=subprocess.PIPE,stderr=subprocess.PIPE);assert r.returncode==0,(r.stdout+r.stderr).decode(errors='replace')
 for name,target in modified.items():assert (site/name).read_bytes().replace(b'\r\n',b'\n')==target.read_bytes()
 assert service.read_text()=='<?php /* servicio moderno ya instalado */'
 print('PASS compatibilidad: conexión compartida, recuperación delegada, sondear(), callback de origen y manejo de fallos temporales conservados; sesiones y validaciones verificadas con la variante instalada.')
