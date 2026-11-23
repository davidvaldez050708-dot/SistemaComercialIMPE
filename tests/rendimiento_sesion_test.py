"""Controladores reales con servicios simulados; sesiones PHP de archivos reales."""
import json,os,subprocess,tempfile,time
from pathlib import Path
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-perf-session-') as folder:
 folder=Path(folder);fixture=folder/'fixture.php'
 fixture.write_text('''<?php
$root=$argv[1];$mode=$argv[2];$dir=$argv[3];$id=$argv[4];
session_save_path($dir);session_id($id);
$start=microtime(true);session_start();
if($mode==='read'){echo json_encode(['ms'=>(microtime(true)-$start)*1000,'permisos'=>$_SESSION['permisos']??[]]);session_write_close();exit;}
$_SESSION=['usuario_id'=>9,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['old'],'csrf_token'=>'fixture-only'];
$_SERVER['REQUEST_METHOD']=in_array($mode,['post','poll','csrf'],true)?'POST':'GET';
require $root.'/app/helpers/PermissionHelper.php';
define('ROOT_PATH',$root);define('BASE_URL','https://fixture.test/');
$GLOBALS['created']=['usuarios'=>0,'roles'=>0];
class UsuarioModel {
 public function __construct(){$GLOBALS['created']['usuarios']++;}
 public function buscarPorUsuarioOCorreo($dato){if($dato==='desconocido')return null;return ['id'=>9,'nombre'=>'Prueba','apellidos'=>'Local','usuario'=>'fixture','foto_perfil'=>'','estado'=>1,'rol_estado'=>1,'rol_id'=>4,'rol'=>'Analista de Datos','requiere_cambio_password'=>0,'password'=>password_hash('fixture-password-only',PASSWORD_DEFAULT)];}
 public function actualizarUltimoAcceso($id){return true;}
}
class RolModel {
 public function __construct(){$GLOBALS['created']['roles']++;}
 public function inicializarPermisosSistema(){}
 public function obtenerCodigosPermisosPorRol($id){return str_starts_with($GLOBALS['mode'],'login')||$GLOBALS['mode']==='denied'?[]:['telefonia.usar','telefonia.recibir'];}
}
function slowFixture(){file_put_contents($GLOBALS['dir'].'/started','1');usleep(1200000);}
class TelefoniaExtensionService {
 public function resolverParaUsuario($id){slowFixture();return ['extension'=>'101','permite_entrantes'=>1,'permite_salientes'=>1];}
}
class TelefoniaDisponibilidadService {
 public function __construct(){slowFixture();}
 public function obtener($u,$id){return null;}
 public function entrante($u){return null;}
 public function devoluciones($u){return [];}
 public function presencia($u,$available){}
 public function accion(...$args){return null;}
}
$GLOBALS['mode']=$mode;$GLOBALS['dir']=$dir;
function loadFixture($file){$src=file_get_contents($file);$src=preg_replace('/require_once\\s+[^;]+;/','',$src);eval(substr($src,strpos($src,'<?php')+5));}
if(str_starts_with($mode,'login')){
 $_SESSION=[];loadFixture($root.'/app/controllers/LoginController.php');$c=new LoginController();
 if($mode==='login-models'){
  if($GLOBALS['created']!==['usuarios'=>0,'roles'=>0])exit(3);
  $method=new ReflectionMethod(LoginController::class,'prepararModelos');$method->invoke($c);$method->invoke($c);
  echo json_encode($GLOBALS['created']);exit;
 }
 register_shutdown_function(function()use($dir){file_put_contents($dir.'/login-state',json_encode(['autenticado'=>!empty($_SESSION['usuario_id']),'error'=>!empty($_SESSION['error_login']),'recuperacion'=>!empty($_SESSION['mensaje_recuperacion']),'created'=>$GLOBALS['created']]));});
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['usuario'=>$mode==='login-recovery'?'desconocido':'fixture','password'=>$mode==='login-ok'?'fixture-password-only':'wrong'];
 if($mode==='login-recovery')$c->procesarRecuperacion();else $c->iniciarSesion();exit;
}
loadFixture($root.'/app/controllers/TelefoniaController.php');$c=new TelefoniaController();
if($mode==='state'||$mode==='denied'){$c->estadoUsuario();exit;}
$_GET=['id'=>0];$_POST=['accion'=>$mode==='post'?'ACEPTAR':'POLL','csrf_token'=>$mode==='csrf'?'wrong':'fixture-only'];$c->disponibilidad();
''',encoding='utf-8')
 def args(mode,sid):return ['php',str(fixture),str(root),mode,str(folder),sid]
 def run(mode,sid):
  p=subprocess.run(args(mode,sid),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
  return p.stdout.decode()
 for mode in ['state','availability','poll','post']:
  sid='perf-fixture-'+mode;signal=folder/'started'
  if signal.exists():signal.unlink()
  child=subprocess.Popen(args(mode,sid),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  until=time.monotonic()+8
  while not signal.exists() and time.monotonic()<until:time.sleep(.02)
  assert signal.exists(),'no inició el servicio simulado'
  read=json.loads(run('read',sid));out,err=child.communicate(timeout=10);assert child.returncode==0,(out+err).decode()
  assert json.loads(out)['ok'] is True
  if mode=='post':assert read['ms']>700,'se liberó una sesión de modificación'
  else:assert read['ms']<350,'una consulta de lectura bloquea la navegación'
  assert read['permisos']==['telefonia.usar','telefonia.recibir'],'no se guardaron los permisos vigentes'
  print(mode.upper()+'_ESPERA_SESION_MS='+str(round(read['ms'])))
 signal.unlink();assert json.loads(run('denied','perf-fixture-denied'))['ok'] is False;assert not signal.exists()
 assert json.loads(run('csrf','perf-fixture-csrf'))['ok'] is False;assert not signal.exists()
 assert json.loads(run('login-models','perf-fixture-models'))==dict(usuarios=1,roles=1)
 for mode in ['login-ok','login-bad','login-recovery']:
  run(mode,'perf-fixture-'+mode);state=json.loads((folder/'login-state').read_text())
  assert state['autenticado']==(mode=='login-ok'),mode
  if mode=='login-bad':assert state['error']
  assert state['created']==dict(usuarios=1,roles=1)
 print('PASS: lecturas sin bloqueo, modificaciones conservan sesión, permisos guardados, acceso denegado, acceso válido, contraseña incorrecta, recuperación sin correo real y modelos creados una sola vez bajo demanda.')
