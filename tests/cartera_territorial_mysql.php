<?php
declare(strict_types=1);
// Sólo base efímera local, sin cargar credenciales ni registros del CRM.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli('127.0.0.1', 'root', (string)getenv('CARTERA_TEST_PASSWORD'), '', (int)(getenv('CARTERA_TEST_PORT') ?: 33319));
$database = 'impe_cartera_test_' . bin2hex(random_bytes(5));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db = new mysqli('127.0.0.1','root',(string)getenv('CARTERA_TEST_PASSWORD'),$database,(int)(getenv('CARTERA_TEST_PORT') ?: 33319));
$db->set_charset('utf8mb4');putenv('CARTERA_TEST_DATABASE='.$database);$GLOBALS['cartera_test_db']=$db;
$root=getenv('CARTERA_TEST_PROJECT');$source=getenv('CARTERA_TEST_SOURCE');
define('BASE_URL','https://example.test/');define('ROOT_PATH',$root);
ini_set('error_log',$root.'/test-errors.log');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function callPrivate($object,$method,...$args){$m=new ReflectionMethod($object,$method);$m->setAccessible(true);return $m->invoke($object,...$args);}
function seed($table,array $values): void {
 global $db;
 foreach($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $col){
  $name=$col['Field'];if(array_key_exists($name,$values)||$col['Null']==='YES'||$col['Default']!==null||str_contains($col['Extra'],'auto_increment'))continue;
  $type=$col['Type'];
  if(str_starts_with($type,'enum(')){preg_match("/enum\\('([^']*)'/",$type,$m);$values[$name]=$m[1];}
  elseif(preg_match('/int|decimal|float|double/',$type))$values[$name]=1;
  elseif(str_starts_with($type,'date'))$values[$name]=date(str_starts_with($type,'datetime')?'Y-m-d H:i:s':'Y-m-d');
  else $values[$name]=$name.'_'.($values['id']??uniqid());
 }
 $cols='`'.implode('`,`',array_keys($values)).'`';$q=$db->prepare("INSERT INTO `$table` ($cols) VALUES (".implode(',',array_fill(0,count($values),'?')).')');$params=array_values($values);$q->bind_param(str_repeat('s',count($params)),...$params);$q->execute();
}
function sessionAs(int $id,int $role): void {$_SESSION=['usuario_id'=>$id,'rol_id'=>$role,'rol'=>$role===4?'Analista de Datos':($role===6?'Cuenta Clave':'Director de Operaciones'),'permisos'=>['seguimientos_vinculacion.ver','seguimientos_vinculacion.operar_propios','reuniones.solicitar','reuniones.gestionar','oficios.generar','oficios.enviar']];}
try {
 // Sólo DDL del esquema versionado: no se importan INSERT con datos reales.
 $schema=file_get_contents($source.'/database/sistema_comercial_impe.sql');
 preg_match_all('/^CREATE TABLE .*?;/ms',$schema,$creates);
 foreach($creates[0] as $sql)$db->query($sql);
 preg_match_all('/^ALTER TABLE .*?;/ms',$schema,$alters);
 foreach($alters[0] as $sql)if(!str_contains($sql,'FOREIGN KEY'))$db->query($sql);
 // Las migraciones aportan columnas operativas; sólo se ejecuta DDL en esta base efímera.
 foreach(glob($source.'/database/migrations/*.sql') as $migration){
  $text=file_get_contents($migration);
  preg_match_all('/^(?:CREATE TABLE(?: IF NOT EXISTS)?|ALTER TABLE) (?:`?)(seguimientos_vinculacion[^`\s(]*|reuniones_vinculacion[^`\s(]*|interacciones_vinculacion[^`\s(]*|oficios_vinculacion[^`\s(]*|observaciones_seguimiento[^`\s(]*|recordatorios_vinculacion[^`\s(]*|correos_oficio_vinculacion[^`\s(]*)(?:`?).*?;/ms',$text,$ddl);
  foreach($ddl[0] as $sql){try{$db->query($sql);}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1050,1060,1061,1091,1826],true))throw $e;}}
 }
 $db->query('CREATE TABLE IF NOT EXISTS observaciones_seguimiento (id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT NOT NULL,autor_id INT NOT NULL,destinatario_id INT NOT NULL,observacion TEXT NOT NULL,leida TINYINT DEFAULT 0,leida_at DATETIME NULL,activo TINYINT DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
 foreach([1=>'Administrador',2=>'Director de Operaciones',4=>'Analista de Datos',6=>'Cuenta Clave'] as $id=>$name)seed('roles',['id'=>$id,'nombre'=>$name,'estado'=>1]);
 foreach([1=>1,2=>2,5=>4,7=>6,8=>4,9=>6,10=>4,11=>4,12=>4,13=>4] as $id=>$role)seed('usuarios',['id'=>$id,'rol_id'=>$role,'nombre'=>'Persona '.$id,'apellidos'=>'Prueba','correo'=>"persona$id@example.test",'estado'=>$id===13?0:1]);
 foreach([11=>'Guanajuato',17=>'Morelos',19=>'Nuevo León'] as $id=>$name)seed('estados',['id'=>$id,'nombre'=>$name,'nombre_corto'=>$name,'estado'=>1]);
 seed('municipios',['id'=>101,'estado_id'=>11,'nombre'=>'León','estado'=>1]);
 seed('asignaciones_territorio',['id'=>166,'estado_id'=>11,'usuario_id'=>7,'tipo_asignacion'=>'CUENTA_CLAVE','activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('-3 days'))]);
 foreach([168=>11,169=>10] as $id=>$uid)seed('asignaciones_territorio',['id'=>$id,'estado_id'=>11,'usuario_id'=>$uid,'tipo_asignacion'=>'ANALISTA_DATOS','cuenta_clave_asignacion_id'=>166,'activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('-3 days'))]);
 seed('asignaciones_territorio',['id'=>160,'estado_id'=>11,'usuario_id'=>5,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>0,'fecha_fin'=>date('Y-m-d',strtotime('-4 days'))]);
 seed('asignaciones_territorio',['id'=>170,'estado_id'=>17,'usuario_id'=>5,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1]);
 seed('asignaciones_territorio',['id'=>171,'estado_id'=>11,'usuario_id'=>12,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('+1 day'))]);
 seed('asignaciones_territorio',['id'=>172,'estado_id'=>11,'usuario_id'=>13,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1]);
 for($i=1;$i<=214;$i++)seed('seguimientos_vinculacion',['id'=>$i,'estado_id'=>11,'municipio_id'=>101,'analista_id'=>5,'nombre_entidad'=>'Institución '.$i,'activo'=>1,'estado_seguimiento'=>'NUEVO','fecha_inicio'=>date('Y-m-d H:i:s'),'datos_verificados'=>0]);
 seed('seguimientos_vinculacion',['id'=>300,'estado_id'=>19,'analista_id'=>8,'nombre_entidad'=>'Fuera del equipo','activo'=>1]);
 seed('seguimientos_vinculacion',['id'=>301,'estado_id'=>17,'analista_id'=>5,'nombre_entidad'=>'Cartera actual Diego','activo'=>1]);
 seed('interacciones_vinculacion',['id'=>1,'seguimiento_id'=>1,'usuario_id'=>5,'canal'=>'LLAMADA_IP','resultado'=>'CONTACTADO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'Actividad original [VERIFICACION_EFECTIVA]','proveedor_externo'=>'ZADARMA','id_externo'=>'audio-prueba-inalterado','duracion_segundos'=>33]);
 require_once $root.'/app/helpers/PermissionHelper.php';
 require_once $root.'/app/helpers/SupervisionRolePolicy.php';
 require_once $root.'/app/models/SeguimientoVinculacionModel.php';
 require_once $root.'/app/models/AnalistaDashboardModel.php';
 require_once $root.'/app/services/AgendaReunionRepository.php';
 require $source.'/tests/seguimientos_propios_cases.php';
} finally {
 $db->close();$server->query("DROP DATABASE `$database`");$server->close();
}
