<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli('127.0.0.1','root','','',33319);
$name='impe_horarios_test_'.bin2hex(random_bytes(5));
$server->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db=new mysqli('127.0.0.1','root','',$name,33319);$db->set_charset('utf8mb4');putenv('HORARIOS_TEST_DATABASE='.$name);$GLOBALS['horarios_test_db']=$db;
$root=getenv('HORARIOS_TEST_PROJECT');$source=getenv('HORARIOS_TEST_SOURCE');
define('BASE_URL','https://example.test/');define('ROOT_PATH',$root);
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function hidden($object,$method,...$args){$m=new ReflectionMethod($object,$method);$m->setAccessible(true);return $m->invoke($object,...$args);}
function seed(string $table,array $data):void{
 global $db;
 foreach($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $col){
  $f=$col['Field'];if(array_key_exists($f,$data)||$col['Null']==='YES'||$col['Default']!==null||str_contains($col['Extra'],'auto_increment'))continue;
  if(str_starts_with($col['Type'],'enum(')){preg_match("/enum\\('([^']*)'/",$col['Type'],$m);$data[$f]=$m[1];}
  elseif(preg_match('/int|decimal|float|double/',$col['Type']))$data[$f]=1;
  elseif(str_starts_with($col['Type'],'date'))$data[$f]='2026-10-27';
  else $data[$f]=$f.'_'.($data['id']??uniqid());
 }
 $q=$db->prepare("INSERT INTO `$table` (`".implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
 $v=array_values($data);$q->bind_param(str_repeat('s',count($v)),...$v);$q->execute();
}
try{
 // Importar únicamente estructura del esquema: ningún INSERT con información real.
 preg_match_all('/^CREATE TABLE .*?;/ms',file_get_contents($source.'/database/sistema_comercial_impe.sql'),$ddl);
 foreach($ddl[0] as $sql)$db->query($sql);
 $db->query("SET time_zone='+00:00'");
 $clock=(new DateTimeImmutable('2026-10-27 14:53:34',new DateTimeZone('UTC')))->getTimestamp();
 $db->query('SET timestamp='.$clock);
 seed('roles',['id'=>4,'nombre'=>'Analista de Datos','estado'=>1]);
 seed('usuarios',['id'=>1,'rol_id'=>4,'nombre'=>'Prueba','apellidos'=>'Horaria','usuario'=>'prueba.horaria','correo'=>'prueba@example.test','estado'=>1,'ultimo_acceso'=>'2026-10-27 14:34:00','created_at'=>'2026-10-26 02:15:00','updated_at'=>'2026-10-27 14:35:00']);
 seed('usuarios',['id'=>2,'rol_id'=>4,'nombre'=>'Sin','apellidos'=>'Acceso','usuario'=>'sin.acceso','correo'=>'sin@example.test','estado'=>1,'ultimo_acceso'=>null]);
 $stored=$db->query('SELECT id,ultimo_acceso,UNIX_TIMESTAMP(created_at) creado,UNIX_TIMESTAMP(updated_at) modificado FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 require_once $root.'/app/models/UsuarioModel.php';
 require_once $root.'/app/models/ReporteAdministradorModel.php';
 require_once $root.'/app/helpers/TelefoniaFechaHoraHelper.php';
 $users=new UsuarioModel();
 // El resultado no depende de la zona por defecto de PHP ni de la sesión SQL.
 foreach(['UTC','America/Mexico_City','Asia/Tokyo'] as $phpZone){
  date_default_timezone_set($phpZone);
  foreach(['+00:00','+05:30','-06:00'] as $sqlZone){
   $db->query("SET time_zone='$sqlZone'");
   $u=$users->buscarPorId(1);
   check($u['ultimo_acceso']==='2026-10-27 08:34:00','No convierte último acceso:'.$phpZone.'/'.$sqlZone);
   check($u['ultimo_acceso_utc']==='2026-10-27 14:34:00','Pierde referencia UTC');
   check($u['created_at']==='2026-10-25 20:15:00','Fecha de alta incorrecta:'.$phpZone.'/'.$sqlZone.':'.$u['created_at']);
   check($u['updated_at']==='2026-10-27 08:35:00','Fecha de modificación incorrecta');
   check(UsuarioFechaHoraHelper::normalizar($u)===$u,'Se aplica doble conversión');
   check($users->buscarPorId(2)['ultimo_acceso']===null,'Sin registro deja de ser nulo');
   check($users->buscarPorId(999)===null,'Usuario inexistente cambia contrato');
   $list=$users->listarUsuariosConFiltros(['buscar'=>'prueba.horaria','rol'=>'4','estado'=>'1']);
   check(count($list)===1&&$list[0]['ultimo_acceso']==='2026-10-27 08:34:00','Tabla filtrada usa otra hora');
   $recent=array_column($users->obtenerUsuariosRecientes(),null,'usuario');
   check($recent['prueba.horaria']['ultimo_acceso']==='2026-10-27 08:34:00','Inicio conserva UTC');
   $report=array_column((new ReporteAdministradorModel())->obtenerUsuariosConSeguimiento([4]),null,'id');
   check($report[1]['ultimo_acceso']==='2026-10-27 08:34:00','Reporte conserva UTC');
  }
 }
 check($stored===$db->query('SELECT id,ultimo_acceso,UNIX_TIMESTAMP(created_at) creado,UNIX_TIMESTAMP(updated_at) modificado FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC),'La lectura reescribe fechas');
 // Los próximos accesos se guardan expresamente en UTC, aunque SQL esté en otra zona.
 $db->query("SET time_zone='+05:30'");
 check($users->actualizarUltimoAcceso(1),'No registra acceso');
 check($db->query('SELECT ultimo_acceso FROM usuarios WHERE id=1')->fetch_assoc()['ultimo_acceso']==='2026-10-27 14:53:34','Se guardó hora de sesión SQL en vez de UTC');
 $u=$users->buscarPorId(1);check($u['ultimo_acceso']==='2026-10-27 08:53:34','Acceso nuevo mal convertido');
 $users->actualizarUsuario(1,['nombre'=>'Prueba','apellidos'=>'Horaria','telefono'=>'','foto_perfil'=>'','correo'=>'prueba@example.test','usuario'=>'prueba.horaria','rol_id'=>4,'estado'=>1,'ultimo_acceso'=>'2000-01-01 00:00:00']);
 check($db->query('SELECT ultimo_acceso FROM usuarios WHERE id=1')->fetch_assoc()['ultimo_acceso']==='2026-10-27 14:53:34','Editar usuario cambia el acceso');
 // El historial telefónico conserva su tratamiento por proveedor.
 $sdk=['proveedor_externo'=>'ZADARMA_WEBRTC','fecha_inicio'=>'2026-10-27 14:34:00'];
 $local=['proveedor_externo'=>'ZADARMA','fecha_inicio'=>'2026-10-27 08:34:00'];
 check(TelefoniaFechaHoraHelper::normalizarInteraccion($sdk)['fecha_inicio']==='2026-10-27 08:34:00','Regresión SDK');
 check(TelefoniaFechaHoraHelper::normalizarInteraccion($local)===$local,'Se convirtió llamada local dos veces');
 foreach([null,'','fecha inválida','2026-02-30 12:00:00','0000-00-00 00:00:00'] as $bad)check(UsuarioFechaHoraHelper::aMexico($bad)===$bad,'Se inventó una fecha');
 check(UsuarioFechaHoraHelper::aMexico('2026-10-27 14:34:00.123456')==='2026-10-27 08:34:00','Fracciones de segundo no convertidas');
 check(UsuarioFechaHoraHelper::aMexico('2026-01-01 03:00:00')==='2025-12-31 21:00:00','Cambio de año incorrecto');
 check(UsuarioFechaHoraHelper::aMexico('2026-11-01 02:00:00')==='2026-10-31 20:00:00','Cambio de mes incorrecto');
 // Vistas y serializador reales reciben una única hora local.
 date_default_timezone_set('America/Mexico_City');
 require_once $root.'/app/helpers/PermissionHelper.php';
 $_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>['usuarios.ver','usuarios.editar','usuarios.cambiar_estado','usuarios.crear']];
 $usuarios=$users->listarUsuariosConFiltros();$usuarioActualId=1;$administradoresActivos=1;
 ob_start();require $root.'/app/views/usuarios/tabla.php';$html=ob_get_clean();
 $text=preg_replace('/\s+/u',' ',strip_tags($html));
 check(str_contains($text,'08:53'),'Tabla visible no muestra hora de México');
 check(!str_contains($text,'14:53'),'Tabla visible muestra UTC');
 require_once $root.'/app/controllers/UsuarioController.php';
 $controller=(new ReflectionClass('UsuarioController'))->newInstanceWithoutConstructor();
 $profile=hidden($controller,'serializarMiPerfil',$users->buscarPorId(1));
 check($profile['ultimo_acceso']==='2026-10-27 08:53:34','Mi perfil conserva UTC');
 require_once $root.'/app/services/ReporteAdministradorPdfService.php';
 $pdf=hidden(new ReporteAdministradorPdfService(),'formatearFechaHora',$profile['ultimo_acceso'],'Sin registro');
 check(str_contains($pdf,'08:53'),'PDF conserva UTC');
 // Reproducir el riesgo hallado en consultas de vencimiento (no se modifica en este parche).
 $db->query("SET time_zone='+00:00'");
 $risk=$db->query("SELECT '2026-10-27 10:00:00'<NOW() vencida_segun_sql,'2026-10-27 10:00:00'<'2026-10-27 08:53:34' vencida_segun_mexico")->fetch_assoc();
 check((int)$risk['vencida_segun_sql']===1&&(int)$risk['vencida_segun_mexico']===0,'No se reprodujo el desfase de pendientes');
 $process=proc_open([PHP_BINARY,$source.'/tools/verificar_hora_usuarios.php','--site',$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 check(proc_close($process)===0,'Comprobador CLI falló:'.$error);
 $verified=json_decode($output,true,512,JSON_THROW_ON_ERROR);
 check($verified['ultimo_acceso_mas_reciente_mexico']==='2026-10-27 08:53:34','Comprobador no convierte UTC');
 check(!str_contains($output,'example.test'),'Comprobador imprime datos personales');
 echo "PASS: lectura consistente en Usuarios, Inicio, Perfil y PDF; 9 combinaciones PHP/SQL, accesos futuros en UTC, historial sin cambios y llamadas preservadas.\n";
}finally{$db->close();$server->query("DROP DATABASE `$name`");$server->close();}
