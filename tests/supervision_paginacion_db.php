<?php
// Datos sintéticos y base temporal explícita: nunca usa las credenciales del sitio.
date_default_timezone_set('America/Mexico_City');mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__);$runtime=sys_get_temp_dir().'/impe-supervision-runtime-'.bin2hex(random_bytes(6));
foreach(['app/services','app/helpers','config'] as $p)mkdir($runtime.'/'.$p,0700,true);
foreach(['app/services','app/helpers'] as $p)foreach(glob($root.'/'.$p.'/*.php') as $file)copy($file,$runtime.'/'.$p.'/'.basename($file));
file_put_contents($runtime.'/config/db_connection.php','<?php class Database { function connect(...$args) { throw new RuntimeException("Conexión ajena a la prueba"); } }');
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/supervision_paginacion_runtime.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixtures as $p=>$f){
 $content=gzdecode(base64_decode($f['content'],true));if(hash('sha256',$content)!==$f['sha256'])throw new RuntimeException('Fixture alterado');
 if(str_ends_with($p,'SupervisionLlamadasRepository.php'))$content=str_replace('final class SupervisionLlamadasRepository','final class LegacySupervisionLlamadasRepository',$content);
 file_put_contents($runtime.'/'.(str_ends_with($p,'SupervisionLlamadasRepository.php')?'app/services/LegacySupervisionLlamadasRepository.php':$p),$content);
}
require $runtime.'/app/services/SupervisionLlamadasRepository.php';
require $runtime.'/app/services/LegacySupervisionLlamadasRepository.php';
class SupervisionTestDb extends mysqli {
 public array $queries=[];
 public function prepare(string $query): mysqli_stmt|false {$this->queries[]=$query;return parent::prepare($query);}
}
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port||$port===3306)throw new RuntimeException('Puerto de pruebas aislado obligatorio, distinto de 3306.');
$db=new SupervisionTestDb('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_test_supervision_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function statsBytes(){global $db;return (int)$db->query("SHOW SESSION STATUS LIKE 'Bytes_sent'")->fetch_assoc()['Value'];}
try{
 $db->query('CREATE TABLE roles(id INT PRIMARY KEY,estado INT)');$db->query('INSERT INTO roles VALUES(1,1),(2,1),(3,1),(4,1),(6,1)');
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,rol_id INT,estado INT,nombre VARCHAR(60),apellidos VARCHAR(60),foto_perfil VARCHAR(200))');
 $db->query("INSERT INTO usuarios VALUES(1,1,1,'Admin','Sintético',''),(2,2,1,'Dirección','Sintética',''),(6,6,1,'Cuenta','Clave',''),(8,3,1,'Asesora','Uno',''),(15,3,1,'Asesora','Dos','')");
 $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(80),estado INT)');$db->query("INSERT INTO permisos VALUES(1,'llamadas.supervision.ver',1),(2,'llamadas.supervision.audio',1)");
 $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');$db->query('INSERT INTO rol_permisos VALUES(1,1),(1,2),(2,1),(6,1)');
 $db->query('CREATE TABLE estados(id INT PRIMARY KEY,estado INT)');$db->query('INSERT INTO estados VALUES(1,1)');
 $db->query('CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,tipo_asignacion VARCHAR(30),estado_id INT,activo INT,cuenta_clave_asignacion_id INT,fecha_inicio DATE,fecha_fin DATE)');
 $db->query("INSERT INTO asignaciones_territorio VALUES(1,6,'CUENTA_CLAVE',1,1,NULL,NULL,NULL),(2,8,'ASESOR',1,1,1,NULL,NULL)");
 $db->query('CREATE TABLE telefonia_ventas_llamadas(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(10),pbx_call_id VARCHAR(100),direccion VARCHAR(20),numero VARCHAR(40),nombre VARCHAR(150),fecha DATETIME,origen_extension VARCHAR(10),solicitud_id INT,estado_id INT,KEY fecha_usuario(usuario_id,fecha),UNIQUE KEY tramo(usuario_id,extension,pbx_call_id))');
 $db->query('CREATE TABLE telefonia_ventas_resultados(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(10),pbx_call_id VARCHAR(100),resultado VARCHAR(80),prospecto_nombre VARCHAR(150),observacion TEXT,KEY referencia(usuario_id,pbx_call_id))');
 $db->query('CREATE TABLE telefonia_ventas_resultado_detalles(resultado_id INT PRIMARY KEY,catalogo_version VARCHAR(10),resultado_principal VARCHAR(80),envio_informacion VARCHAR(30),contacto_antes_corte VARCHAR(10),motivo_sin_whatsapp VARCHAR(300))');
 $db->query('CREATE TABLE telefonia_ventas_resultado_selecciones(resultado_id INT,codigo VARCHAR(80),posicion INT,KEY resultado(resultado_id))');
 $db->query('CREATE TABLE telefonia_zadarma_eventos(id INT AUTO_INCREMENT PRIMARY KEY,pbx_call_id VARCHAR(100),evento VARCHAR(40),internal VARCHAR(10),disposition VARCHAR(30),duration INT,received_at DATETIME,payload_json TEXT,KEY referencia(pbx_call_id))');
 $call=$db->prepare('INSERT INTO telefonia_ventas_llamadas VALUES(?,?,?,?,?,?,?,?,NULL,NULL,NULL)');
 $saved=$db->prepare('INSERT INTO telefonia_ventas_resultados VALUES(?,?,?,?,?,?,?)');
 $event=$db->prepare('INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,disposition,duration,received_at,payload_json) VALUES(?,?,?,?,?,?,?)');
 $n=2670;$db->begin_transaction();
 for($i=1;$i<=$n;$i++){
  $uid=$i%2?8:15;$ext=$uid===8?'150':'151';$ref='out_'.md5('fixture-'.$i);$dir=$i%3?'Saliente':'Entrante';$number='555'.str_pad((string)$i,7,'0',STR_PAD_LEFT);$nombre='Ejemplo '.$i;$date='2026-11-04 '.sprintf('%02d:%02d:%02d',intdiv($i,3600)%24,intdiv($i,60)%60,$i%60);
  $call->bind_param('iissssss',$i,$uid,$ext,$ref,$dir,$number,$nombre,$date);$call->execute();
  if($i%4!==3){$code=['PROSPECTO_POTENCIAL','ENVIO_WHATSAPP','SIN_RESPUESTA'][$i%4];$note='Observación sintética';$saved->bind_param('iisssss',$i,$uid,$ext,$ref,$code,$nombre,$note);$saved->execute();}
  if($i%4===0){$db->query("INSERT INTO telefonia_ventas_resultado_detalles VALUES($i,'3','PROSPECTO_POTENCIAL','WHATSAPP','','')");$db->query("INSERT INTO telefonia_ventas_resultado_selecciones VALUES($i,'PROSPECTO_POTENCIAL',0),($i,'WHATSAPP',1)");}
  foreach(['NOTIFY_OUT_START','NOTIFY_ANSWER','NOTIFY_OUT_END'] as $type){$disposition=$type==='NOTIFY_OUT_END'?'answered':'';$duration=$type==='NOTIFY_OUT_END'?33:0;
   $payload=json_encode(['duration'=>$duration,'padding'=>str_repeat('x',4096)]);$event->bind_param('ssssiss',$ref,$type,$ext,$disposition,$duration,$date,$payload);
   $event->execute();
  }
 }
 $db->commit();
 $filters=['area'=>'asesores','persona_id'=>0,'desde'=>'2026-11-01','hasta'=>'2026-11-05','direccion'=>'','resultado'=>'','buscar'=>'','pagina'=>1];
 // La optimización de relaciones también se usa en Analistas: conservar
 // coincidencias exactas, duplicados y consultas con distinto conjunto de claves.
 $relations=['interacciones_vinculacion'=>[['id'=>'1','usuario_id'=>'8','canal'=>'LLAMADA_IP'],['id'=>'1','usuario_id'=>'8','canal'=>'LLAMADA_IP'],['id'=>'2','usuario_id'=>'15','canal'=>'NOTA']]];
 $instances=[new SupervisionLlamadasRepository($db),new LegacySupervisionLlamadasRepository($db)];$lookup=[];
 foreach($instances as $repo){$class=new ReflectionClass($repo);$class->getProperty('relations')->setValue($repo,$relations);$lookup[]=$class->getMethod('relacionados');}
 foreach([['id'=>1,'usuario_id'=>8,'canal'=>'LLAMADA_IP'],['id'=>1,'usuario_id'=>15],['usuario_id'=>8],['canal'=>'llamada_ip']] as $identity){
  check($lookup[0]->invoke($instances[0],'interacciones_vinculacion',$identity)===$lookup[1]->invoke($instances[1],'interacciones_vinculacion',$identity),'Relaciones indexadas mantienen identidad y ambigüedad');
 }
 $bytes=statsBytes();$t=microtime(true);$old=(new LegacySupervisionLlamadasRepository($db))->listado(1,$filters);$oldTime=microtime(true)-$t;$oldBytes=statsBytes()-$bytes;
 $db->queries=[];$bytes=statsBytes();$t=microtime(true);$new=(new SupervisionLlamadasRepository($db))->listado(1,$filters);$newTime=microtime(true)-$t;$newBytes=statsBytes()-$bytes;
 check($new===$old,'Paridad de página inicial, totales, etiquetas, estados y audio');
 check($new['paginas']===89&&$new['total']===2670,'89 páginas completas');
 foreach($db->queries as $sql)if(str_contains($sql,'SELECT * FROM telefonia_zadarma_eventos'))check(substr_count($sql,'?')<=30,'Sólo eventos de la página visible');
 check($newBytes<$oldBytes/4,'Reducir al menos 75% del tráfico SQL sin caché');
 foreach([['pagina'=>45],['pagina'=>89],['pagina'=>999],['persona_id'=>8],['direccion'=>'Entrante'],['resultado'=>'WHATSAPP'],['resultado'=>'ENVIO_WHATSAPP'],['buscar'=>'Ejemplo 40'],['buscar'=>'registrado']] as $change){
  $f=array_replace($filters,$change);check((new SupervisionLlamadasRepository($db))->listado(1,$f)===(new LegacySupervisionLlamadasRepository($db))->listado(1,$f),'Paridad con filtros '.json_encode($change));
 }
 check((new SupervisionLlamadasRepository($db))->listado(6,$filters)===(new LegacySupervisionLlamadasRepository($db))->listado(6,$filters),'Cuenta Clave conserva alcance');
 try{(new SupervisionLlamadasRepository($db))->listado(6,array_replace($filters,['persona_id'=>15]));throw new RuntimeException('Acceso indebido');}catch(DomainException $e){}
 $db->query("UPDATE telefonia_ventas_llamadas SET numero='' WHERE id=1");
 $db->query("UPDATE telefonia_zadarma_eventos SET payload_json=JSON_SET(payload_json,'$.caller_id','5551234567') WHERE pbx_call_id=(SELECT pbx_call_id FROM telefonia_ventas_llamadas WHERE id=1)");
 $sparse=array_replace($filters,['buscar'=>'5551234567']);
 check((new SupervisionLlamadasRepository($db))->listado(1,$sparse)===(new LegacySupervisionLlamadasRepository($db))->listado(1,$sparse),'Número histórico recuperado de eventos conserva búsqueda');
 $db->query("UPDATE telefonia_ventas_llamadas SET numero='5550000001' WHERE id=1");
 $db->query("UPDATE telefonia_ventas_llamadas SET pbx_call_id='sdk-fixture' WHERE id=1");
 check((new SupervisionLlamadasRepository($db))->listado(1,$filters)===(new LegacySupervisionLlamadasRepository($db))->listado(1,$filters),'Referencia histórica SDK conserva normalización');
 $ref1='out_'.md5('fixture-1');$db->query("UPDATE telefonia_ventas_llamadas SET pbx_call_id='$ref1' WHERE id=1");
 for($i=$n+1;$i<=3100;$i++){
  $uid=8;$ext='150';$ref='out_'.md5('fixture-'.$i);$dir='Saliente';$number='5550000000';$nombre='Sin resultado';$date='2026-11-04 15:00:00';
  $call->execute(); // Variables enlazadas al statement original, sin eventos ficticios.
 }
 $larger=(new SupervisionLlamadasRepository($db))->listado(1,array_replace($filters,['pagina'=>104]));
 check($larger['total']===3100&&$larger['paginas']===104&&count($larger['llamadas'])===10,'Sin corte oculto en 3,000 llamadas');
 $unconfirmed=(new SupervisionLlamadasRepository($db))->listado(1,array_replace($filters,['buscar'=>'confirmado']));
 check($unconfirmed['total']===430,'Búsqueda de estado PBX sin truncar ni deducirlo del resultado');
 $db->query('DELETE FROM rol_permisos WHERE rol_id=1 AND permiso_id=1');
 try{(new SupervisionLlamadasRepository($db))->listado(1,$filters);throw new RuntimeException('Permiso revocado ignorado');}catch(DomainException $e){}
 echo json_encode(['estado'=>'PASS','registros'=>$n,'paginas'=>89,'anterior_ms'=>round($oldTime*1000),'nuevo_ms'=>round($newTime*1000),'anterior_bytes_sql'=>$oldBytes,'nuevo_bytes_sql'=>$newBytes,'paridad_filtros_y_permisos'=>true],JSON_PRETTY_PRINT).PHP_EOL;
}finally{
 $db->query("DROP DATABASE `$name`");
 // Únicamente la carpeta temporal creada arriba; no acepta rutas externas.
 if(str_starts_with(basename($runtime),'impe-supervision-runtime-')){
  $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($runtime);
 }
}
