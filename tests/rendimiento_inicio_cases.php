<?php
require __DIR__.'/../app/models/RolModel.php';
$process=proc_open(['git','show','a4e7ac7:app/models/RolModel.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
$old=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);waCheck(proc_close($process)===0,'falta base original');
$old=preg_replace('/^require_once .*;\s*$/m','',$old);
$old=str_replace('class RolModel','class RolModelAntesRendimiento',$old);
eval(substr($old,strpos($old,'<?php')+5));
function modeloPerfil(string $class,mysqli $db){$r=new ReflectionClass($class);$m=$r->newInstanceWithoutConstructor();$r->getProperty('connection')->setValue($m,$db);return $m;}
function estadoPerfil(mysqli $db): array {
 return [
  $db->query('SELECT modulo,codigo,nombre,descripcion,estado FROM permisos ORDER BY codigo')->fetch_all(MYSQLI_ASSOC),
  $db->query('SELECT rp.rol_id,p.codigo FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id ORDER BY rp.rol_id,p.codigo')->fetch_all(MYSQLI_ASSOC)
 ];
}
function ejecutadasPerfil(mysqli $db): int{return (int)$db->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_row()[1];}
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>[]];$results=[];
foreach(['RolModelAntesRendimiento','RolModel'] as $class){
 $db->query('TRUNCATE rol_permisos');$db->query('TRUNCATE permisos');$m=modeloPerfil($class,$db);
 $start=microtime(true);$before=ejecutadasPerfil($db);$m->inicializarPermisosSistema();
 $results[$class]=['fresh'=>estadoPerfil($db),'exec'=>ejecutadasPerfil($db)-$before,'ms'=>round((microtime(true)-$start)*1000,1)];
 $db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo='whatsapp.enviar'");
 $m->inicializarPermisosSistema();$results[$class]['warm']=estadoPerfil($db);
 waCheck(!in_array('whatsapp.enviar',$m->obtenerCodigosPermisosPorRol(3),true),'se restablece un permiso revocado');
 // La lectura de autorizaciones debe seguir viendo los cambios en cada consulta.
 $db->query("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo='whatsapp.enviar'");
 waCheck(in_array('whatsapp.enviar',$m->obtenerCodigosPermisosPorRol(3),true),'la autorización no se refresca');
}
waCheck($results['RolModelAntesRendimiento']['fresh']===$results['RolModel']['fresh'],'cambian el catálogo o las relaciones iniciales');
waCheck($results['RolModelAntesRendimiento']['warm']===$results['RolModel']['warm'],'cambian la revocación o normalización posterior');
$catalogo=(new ReflectionMethod(RolModel::class,'obtenerCatalogoInicialPermisos'))->invoke($m);
$saved=$results['RolModelAntesRendimiento']['exec']-$results['RolModel']['exec'];waCheck($saved===count($catalogo)-1,'no se reducen las ejecuciones del catálogo a una sola');
echo 'PASS equivalencia: catálogo y relaciones idénticos, revocaciones y lectura vigente preservadas. '.PHP_EOL;
echo 'EJECUCIONES_ANTES='.$results['RolModelAntesRendimiento']['exec'].' EJECUCIONES_DESPUES='.$results['RolModel']['exec'].' REDUCCION='.$saved.PHP_EOL;
echo 'TIEMPO_LOCAL_ANTES_MS='.$results['RolModelAntesRendimiento']['ms'].' TIEMPO_LOCAL_DESPUES_MS='.$results['RolModel']['ms'].PHP_EOL;
require __DIR__.'/../app/services/TelefoniaDisponibilidadService.php';
new TelefoniaDisponibilidadService($db);
$before=(int)$db->query("SHOW SESSION STATUS LIKE 'Com_create_table'")->fetch_row()[1];
new TelefoniaDisponibilidadService($db);
$after=(int)$db->query("SHOW SESSION STATUS LIKE 'Com_create_table'")->fetch_row()[1];
waCheck($before===$after,'el sondeo sigue creando tablas en una instalación preparada');
$db->query('DROP TABLE telefonia_presencia');new TelefoniaDisponibilidadService($db);
waCheck($db->query("SHOW TABLES LIKE 'telefonia_presencia'")->num_rows===1,'no se reconstruye una tabla faltante');
echo 'PASS sondeo: cero CREATE TABLE en caliente y preparación original si falta una tabla.'.PHP_EOL;
$GLOBALS['catalogo_extra_fixture']=[
 ['modulo'=>'WhatsApp','codigo'=>'custom.fixture','nombre'=>"D'Artagnan · México",'descripcion'=>'Texto personalizado'],
 ['modulo'=>'WhatsApp','codigo'=>'whatsapp.ver','nombre'=>'Nombre personalizado','descripcion'=>'Último valor del catálogo combinado']
];
$snapshots=[];
foreach(['RolModelExtraAntes'=>'a4e7ac7','RolModelExtraDespues'=>null] as $class=>$revision){
 if($revision){$p=proc_open(['git','show',$revision.':app/models/RolModel.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));$src=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);waCheck(proc_close($p)===0,'falta base para catálogo personalizado');}
 else $src=file_get_contents(__DIR__.'/../app/models/RolModel.php');
 $src=preg_replace('/^require_once .*;\s*$/m','',$src);
 $src=str_replace('class RolModel','class '.$class,$src);
 $src=str_replace('$permisos = $this->obtenerCatalogoInicialPermisos();','$permisos = array_merge($this->obtenerCatalogoInicialPermisos(), $GLOBALS[\'catalogo_extra_fixture\']);',$src);
 eval(substr($src,strpos($src,'<?php')+5));
 $db->query('TRUNCATE rol_permisos');$db->query('TRUNCATE permisos');modeloPerfil($class,$db)->inicializarPermisosSistema();$snapshots[]=estadoPerfil($db);
}
waCheck($snapshots[0]===$snapshots[1],'se alteran los catálogos añadidos o los códigos duplicados');
echo 'PASS catálogos adicionales, texto con comillas y Unicode, y precedencia de códigos duplicados.'.PHP_EOL;
