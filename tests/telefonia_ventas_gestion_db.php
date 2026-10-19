<?php
declare(strict_types=1);
// Base desechable explícita: nunca usar Database::connect() ni credenciales del CRM.
require_once dirname(__DIR__).'/app/services/TelefoniaVentasSchema.php';
require_once dirname(__DIR__).'/app/services/TelefoniaVentasLlamadasService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaResultadoVentasService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaDisponibilidadService.php';
require_once dirname(__DIR__).'/app/services/ZadarmaWebhookEventStoreService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);date_default_timezone_set('America/Mexico_City');
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port)throw new RuntimeException('Define IMPE_TEST_MYSQL_PORT para la instancia aislada.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_ventas_test_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
$db->query("SET time_zone='-06:00'");$checks=0;
function vcheck($ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function vreject(callable $fn,string $why): void {try{$fn();}catch(DomainException|InvalidArgumentException $e){vcheck(true,$why);return;}throw new RuntimeException($why);}
try {
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,estado INT,rol_id INT,nombre VARCHAR(90),apellidos VARCHAR(90))');
 $db->query("INSERT INTO usuarios VALUES (7,1,7,'Tania',''),(8,1,3,'Asesor Uno',''),(9,1,3,'Asesor Dos',''),(10,1,4,'Analista','')");
 $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(60),estado INT)');
 $db->query("INSERT INTO permisos VALUES (1,'telefonia.usar',1),(2,'telefonia.recibir',1),(3,'telefonia.salientes',1)");
 $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');$db->query('INSERT INTO rol_permisos VALUES (3,1),(3,2),(3,3)');
 (new TelefoniaExtensionService($db))->asegurarEstructura();
 $db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at) VALUES (8,'150',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY)),(7,'100',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY))");
 new TelefoniaDisponibilidadService($db);
 $db->query('CREATE TABLE telefonia_contactos_personales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT,nombre VARCHAR(90),telefono VARCHAR(18),UNIQUE KEY own_phone(usuario_id,telefono))');
 $db->query("INSERT INTO telefonia_contactos_personales VALUES (1,8,'Ana','+525551110000'),(2,9,'Ajena','+525559998877')");
 $db->query('CREATE TABLE telefonia_ventas_marcaciones(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),destino VARCHAR(20),pbx_call_id VARCHAR(100),created_at DATETIME)');
 $svc=new TelefoniaVentasLlamadasService($db);vcheck(!$svc->disponible(),'No crea tablas al consultar');
 $pending=TelefoniaVentasSchema::pendientes($db);vcheck(count($pending)>0&&!$svc->disponible(),'Check de migración es de lectura');
 foreach($pending as $sql)$db->query($sql);
 vcheck(TelefoniaVentasSchema::pendientes($db)===[],'Migración idempotente');
 $events=new ZadarmaWebhookEventStoreService($db);$results=new TelefoniaResultadoVentasService($db);$base=time();
 $event=function(string $ref,string $type,string $ext='150',int $seconds=0,array $extra=[])use($events,$base){static $offset=0;$at=date('Y-m-d H:i:s',$base);$events->registrar($extra+['event'=>$type,'pbx_call_id'=>$ref,'internal'=>$ext,'caller_id'=>'+525551112233','destination'=>'+525551112233','received_at'=>$at,'call_start'=>$at,'duration'=>$seconds]);};

 require_once dirname(__DIR__).'/app/services/TelefoniaDevolucionVentasService.php';
 $agenda=new TelefoniaContactosService($db);$cb=new TelefoniaDevolucionVentasService($db);
 $contact=$agenda->guardar(8,'Luis','5551112244',['correo'=>'luis@example.test','observacion'=>'Solicitó llamada','proxima_accion_at'=>date('Y-m-d H:i:s',time()+3600)]);
 vcheck($contact['correo']==='luis@example.test'&&$contact['proxima_accion_at']!==null,'Agenda ampliada con correo y próxima llamada');
 vcheck($agenda->guardar(8,'Luis','5551112244',['correo'=>'luis@example.test','observacion'=>'Solicitó llamada','proxima_accion_at'=>$contact['proxima_accion_at']])['id']===$contact['id'],'Reintento idéntico no duplica contacto');
 vreject(fn()=>$agenda->guardar(8,'Otra persona','5551112244'),'No renombrar otro prospecto por número');
 vreject(fn()=>$agenda->guardar(9,'Ajeno','5551112244',['contacto_id'=>$contact['id']]),'No editar agenda ajena');
 vreject(fn()=>$agenda->guardar(8,'Luis','5551112244',['contacto_id'=>$contact['id'],'correo'=>'incorrecto']),'Valida correo');
 vreject(fn()=>$agenda->guardar(8,'Luis','5551112244',['contacto_id'=>$contact['id'],'proxima_accion_at'=>'2026-02-30T12:00']),'Valida fecha real');
 $edited=$agenda->guardar(8,'Luis Pérez','5551112244',['contacto_id'=>$contact['id'],'correo'=>'nuevo@example.test']);
 vcheck($edited['nombre']==='Luis Pérez'&&$edited['correo']==='nuevo@example.test','Editar propio');
 vcheck(!$agenda->eliminar(9,(int)$contact['id']),'No eliminar ajeno');
 vcheck($agenda->eliminar(8,(int)$contact['id']),'Eliminar propio');
 $route=['tipo'=>'PROSPECTO','responsable_id'=>8,'extension'=>'150','referencia_llamada'=>'pbx-callback-original','estado_id'=>17,'institucion_nombre'=>'María Prospecto'];$json=json_encode($route);
 $s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at,contacto,telefono,nota,devolucion) VALUES (7,'100',8,'150','pbx-callback-original','+525551112233',?,'SIN_RESPUESTA',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'María','+525551112233','Solicita informes','PENDIENTE')");$s->bind_param('s',$json);$s->execute();$id=(int)$db->insert_id;$s->close();
 vcheck(count($cb->listar(8))===1&&$cb->listar(9)===[],'Devolución sólo al asesor asignado');
 vreject(fn()=>$cb->preparar(9,$id),'No preparar devolución ajena ni extensión inexistente');
 $a=$cb->preparar(8,$id);vreject(fn()=>$cb->preparar(8,$id),'No duplicar preparaciones');
 $event('pbx-callback-buzon','NOTIFY_OUT_START');$event('pbx-callback-buzon','NOTIFY_OUT_END','150',8,['disposition'=>'answered']);
 $a=$cb->estado(8,(int)$a['id']);vcheck($a['pbx_call_id']==='pbx-callback-buzon'&&$a['llamada']['finalizada'],'Vincular intento con evento propio posterior al watermark');
 vreject(fn()=>$cb->estado(9,(int)$a['id']),'No leer intento ajeno');
 $results->guardar(8,'pbx-callback-buzon','BUZON_VOZ');vcheck($cb->listar(8)[0]['devolucion']==='PENDIENTE','Buzón conserva pendiente');
 $a=$cb->preparar(8,$id);$event('pbx-callback-humano','NOTIFY_OUT_START');$event('pbx-callback-humano','NOTIFY_ANSWER');$event('pbx-callback-humano','NOTIFY_OUT_END','150',20,['disposition'=>'answered']);
 $results->guardar(8,'pbx-callback-humano','INTERESADO',['prospecto_nombre'=>'María','guardar_contacto'=>'1','correo'=>'maria@example.test','observacion'=>'Enviar información']);
 vcheck($cb->listar(8)[0]['devolucion']==='ATENDIDA','Resultado humano cierra devolución exacta');
 $saved=$svc->obtener(8,'pbx-callback-humano')['registro'];vcheck($saved['contacto_id']!==null&&(int)$db->query('SELECT COUNT(*) n FROM telefonia_contactos_personales WHERE usuario_id=8 AND telefono=\'+525551112233\'')->fetch_assoc()['n']===1,'Resultado vincula agenda comercial');
 vreject(fn()=>$results->guardar(8,'pbx-callback-humano','NO_INTERESADO',['guardar_contacto'=>'1','prospecto_nombre'=>'Otro']),'Resultado definitivo no modifica contacto');
 // Un fallo de agenda revierte el resultado, sin cerrar gestiones ni perder el intento.
 $event('pbx-agenda-conflicto','NOTIFY_OUT_START');$event('pbx-agenda-conflicto','NOTIFY_OUT_END','150',10,['disposition'=>'answered']);
 vreject(fn()=>$results->guardar(8,'pbx-agenda-conflicto','INTERESADO',['guardar_contacto'=>'1','prospecto_nombre'=>'Otra María']),'Conflicto de agenda aborta resultado');
 vcheck($svc->obtener(8,'pbx-agenda-conflicto')['registro']===null,'Rollback conserva llamada pendiente');
 $results->guardar(8,'pbx-agenda-conflicto','SIN_RESPUESTA');
 // Dos referencias contemporáneas al mismo número no se eligen arbitrariamente.
 $db->query("UPDATE telefonia_disponibilidad SET devolucion='PENDIENTE' WHERE id=$id");$a=$cb->preparar(8,$id);
 $event('pbx-ambiguo-uno','NOTIFY_OUT_START');$event('pbx-ambiguo-dos','NOTIFY_OUT_START');
 $ambiguous=$cb->estado(8,(int)$a['id']);vcheck($ambiguous['ambigua']&&$ambiguous['pbx_call_id']===null,'No asociar por número cuando hay dos referencias');
 vreject(fn()=>$cb->cancelar(8,(int)$a['id']),'No descartar evidencia ambigua');
 // Respuesta transferida sin NOTIFY_INTERNAL y sin duración propia.
 $route['referencia_llamada']='pbx-answer-only';$route['institucion_nombre']='Pedro';$json=json_encode($route);
 $s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at) VALUES (7,'100',8,'150','pbx-answer-only','+525551112233',?,'ENVIADA',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->bind_param('s',$json);$s->execute();$s->close();
 $event('pbx-answer-only','NOTIFY_ANSWER');$event('pbx-answer-only','NOTIFY_END','',999,['last_internal'=>'150']);
 $answer=$svc->obtener(8,'pbx-answer-only');vcheck($answer['conectada']&&$answer['finalizada']&&$answer['segundos']===null&&$answer['nombre']==='Pedro','ANSWER de destino conserva identidad actual y no inventa duración');
 $db->query("UPDATE telefonia_extensiones SET usuario_id=9,updated_at=NOW() WHERE extension='150'");
 vcheck(count($cb->listar(8))===1&&$cb->listar(9)===[],'Reasignar extensión no transfiere devoluciones');
 vcheck(!$db->query("SHOW TABLES LIKE 'telefonia_devolucion_intentos'")->num_rows&&!$db->query("SHOW TABLES LIKE 'interacciones_vinculacion'")->num_rows,'No utiliza almacenamiento institucional');
 echo "Ventas D: $checks comprobaciones de agenda, devolución, aislamiento y transacción aprobadas.\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
