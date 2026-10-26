<?php
require __DIR__.'/../tools/limpiar_llamadas_prueba.php';
require __DIR__.'/../app/helpers/PermissionHelper.php';
require __DIR__.'/../app/services/TelefoniaVentasSchema.php';
require __DIR__.'/../app/services/TelefoniaVentasLlamadasService.php';
require __DIR__.'/../app/services/TelefoniaNotificacionService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);date_default_timezone_set('America/Mexico_City');
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port)throw new RuntimeException('Usar únicamente una instancia SQL de pruebas explícita.');
$db=new mysqli('127.0.0.1','root','',null,$port);$name=getenv('IMPE_LIMPIEZA_TEST_DB')?:'impe_limpieza_test_'.bin2hex(random_bytes(5));
if(!preg_match('/^impe_limpieza_test_[a-f0-9]{10}$/D',$name))throw new RuntimeException('Nombre de base de pruebas inválido.');
if(($argv[1]??'')==='--cleanup'){$db->query("DROP DATABASE IF EXISTS `$name`");exit;}
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');$db->query("SET time_zone='-06:00'");$n=0;$keep=false;
function cleanCheck(bool $ok,string $why):void {global $n;if(!$ok)throw new RuntimeException($why);++$n;}
function cleanReject(callable $f,string $why):void {try{$f();}catch(RuntimeException $e){cleanCheck(true,$why);return;}throw new RuntimeException($why);}
function cleanRows(mysqli $db,string $table):array{return $db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);}
try {
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(90),apellidos VARCHAR(90),estado INT,rol_id INT)');
 $db->query("INSERT INTO usuarios VALUES(7,'Recepción','Prueba',1,7),(8,'Asesor','Prueba',1,3),(10,'Analista','Preservar',1,4)");
 $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(80),estado INT)');$db->query("INSERT INTO permisos VALUES(1,'telefonia.usar',1),(2,'telefonia.recibir',1),(3,'telefonia.salientes',1)");
 $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');$db->query('INSERT INTO rol_permisos VALUES(3,1),(3,2),(3,3),(7,1),(7,2),(7,3),(4,1),(4,2)');
 (new TelefoniaExtensionService($db))->asegurarEstructura();
 $db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at) VALUES(7,'100',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY)),(8,'150',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY)),(10,'101',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY))");
 new TelefoniaDisponibilidadService($db);$notices=new TelefoniaNotificacionService($db);
 foreach(TelefoniaVentasSchema::pendientes($db) as $sql)$db->query($sql);
 $db->query('CREATE TABLE telefonia_recepcion_resultados(id INT AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),resultado VARCHAR(50),nota VARCHAR(300),created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
 $db->query('CREATE TABLE telefonia_ventas_evidencia(usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),datos_json LONGTEXT,PRIMARY KEY(usuario_id,extension,pbx_call_id))');
 $db->query('CREATE TABLE telefonia_ventas_prospecto_llamada(usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),datos_json LONGTEXT,PRIMARY KEY(usuario_id,extension,pbx_call_id))');
 $db->query('CREATE TABLE interacciones_vinculacion(id INT PRIMARY KEY,usuario_id INT,canal VARCHAR(30),id_externo VARCHAR(100))');
 $db->query("INSERT INTO interacciones_vinculacion VALUES(1,10,'LLAMADA_IP','call-shared-analyst')");
 $db->query('CREATE TABLE telefonia_analista_entrantes(id INT PRIMARY KEY,usuario_id INT,pbx_call_id VARCHAR(100))');$db->query("INSERT INTO telefonia_analista_entrantes VALUES(1,10,'call-shared-analyst')");
 $db->query('CREATE TABLE telefonia_analista_webrtc_sesiones(id INT PRIMARY KEY,usuario_id INT,session_id VARCHAR(100))');$db->query("INSERT INTO telefonia_analista_webrtc_sesiones VALUES(1,10,'sdk-analyst-preserve-0001')");
 $db->query('CREATE TABLE telefonia_webrtc_grabaciones_internas(id INT PRIMARY KEY,usuario_id INT,session_id VARCHAR(100),pbx_call_id VARCHAR(100),extension_destino VARCHAR(12),created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
 $db->query("INSERT INTO telefonia_webrtc_grabaciones_internas(id,usuario_id,session_id,pbx_call_id,extension_destino) VALUES(1,10,'sdk-analyst-preserve-0001','call-shared-analyst','101')");
 $store=new ZadarmaWebhookEventStoreService($db);$store->asegurarEstructura();
 $event=static function(string $ref,string $ext,string $event='NOTIFY_OUT_START',?string $time=null)use($store):void {
  $time=$time??date('Y-m-d H:i:s',time()-3600);$store->registrar(['event'=>$event,'pbx_call_id'=>$ref,'internal'=>$ext,'destination'=>'5550000000','caller_id'=>'5550000001','received_at'=>$time,'call_start'=>$time,'duration'=>12,'disposition'=>'answered']);
 };
 $event('call-clean-sales','150');$event('call-clean-reception','100');$event('call-shared-analyst','100');$event('call-shared-analyst','101','NOTIFY_INTERNAL');
 $event('call-clean-sales','150','NOTIFY_OUT_END');$event('call-shared-analyst','100','NOTIFY_END');
 $db->query("INSERT INTO telefonia_recepcion_resultados(usuario_id,extension,pbx_call_id,resultado,nota) VALUES(7,'100','call-clean-reception','ATENDIDA','Prueba')");
 $db->query("INSERT INTO telefonia_ventas_resultados(usuario_id,extension,pbx_call_id,resultado,observacion,proxima_accion_at) VALUES(8,'150','call-clean-sales','INTERESADO','Prueba','2099-01-01 12:00:00')");
 $db->query("INSERT INTO telefonia_contactos_personales(usuario_id,nombre,telefono,proxima_accion_at) VALUES(8,'Conservar contacto','+525550000000','2099-01-01 12:00:00'),(10,'Agenda analista','+525550000001','2099-01-01 12:00:00')");
 $db->query("INSERT INTO telefonia_ventas_evidencia VALUES(8,'150','call-clean-sales','{}')");$db->query("INSERT INTO telefonia_ventas_prospecto_llamada VALUES(8,'150','call-clean-sales','{}')");
 $route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>8,'extension'=>'150','referencia_llamada'=>'callback-clean-001']);
 $s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at,contacto,telefono,nota,devolucion) VALUES(7,'100',8,'150','callback-clean-001','5550000000',?,'DEVOLUCION',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'Prueba','5550000000','Prueba','PENDIENTE')");$s->bind_param('s',$route);$s->execute();$s->close();
 $db->query("INSERT INTO telefonia_transferencia_operaciones(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,modo,estado,created_at,updated_at) VALUES(7,'100',8,'150','callback-clean-001','5550000000','DIRECTA','SIN_CONFIRMAR',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(7,'100',10,'101','call-shared-analyst','5550000000','DIRECTA','SIN_CONFIRMAR',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $db->query("INSERT INTO telefonia_notificaciones(usuario_id,extension,llave,numero,titulo,mensaje,created_at) VALUES(8,'150','ventas:programada:agenda:1:prueba','5550000000','Prueba','Prueba',NOW()),(7,'100','llamada:call-clean-reception','5550000000','Prueba','Prueba',NOW()),(10,'101','analista-preservar','5550000000','Preservar','Preservar',NOW())");
 if(($argv[1]??'')==='--setup'){$keep=true;echo "Fixture SQL temporal lista.\n";return;}
 $tool=new LimpiezaLlamadasPrueba($db);$plan=$tool->plan();$finger=LimpiezaLlamadasPrueba::digest($plan);
 cleanCheck(!TelefoniaLimpiezaGuard::existe($db),'Check no crea tabla de exclusiones');
 cleanCheck($plan['compartidas_protegidas']===1,'La llamada compartida se protege');
 // Una verificación real puede tardar varios segundos entre --check y --apply.
 sleep(2);
 cleanCheck($tool->plan($plan['corte'])===$plan,'Plan reproducible después de dos segundos y sin escrituras');
 $analyst=[];foreach(['interacciones_vinculacion','telefonia_analista_entrantes','telefonia_analista_webrtc_sesiones','telefonia_webrtc_grabaciones_internas','usuarios','telefonia_extensiones','rol_permisos'] as $t)$analyst[$t]=cleanRows($db,$t);
 $contactBefore=cleanRows($db,'telefonia_contactos_personales');
 $sharedBefore=$db->query("SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id='call-shared-analyst' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
 $id=str_repeat('a',32);$sha=str_repeat('b',64);$tool->aplicar($plan,$id,$sha);
 foreach($analyst as $t=>$rows)cleanCheck(cleanRows($db,$t)===$rows,'Preservar '.$t);
 cleanCheck($db->query("SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id='call-shared-analyst' ORDER BY id")->fetch_all(MYSQLI_ASSOC)===$sharedBefore,'Evidencia y audio de Analista intactos');
 foreach(['telefonia_ventas_llamadas','telefonia_ventas_resultados','telefonia_ventas_evidencia','telefonia_ventas_prospecto_llamada','telefonia_recepcion_resultados','telefonia_disponibilidad'] as $t)cleanCheck(cleanRows($db,$t)===[],'Vaciar sólo datos de prueba: '.$t);
 cleanCheck(count(cleanRows($db,'telefonia_notificaciones'))===1,'Avisos del Analista se conservan');
 $contacts=cleanRows($db,'telefonia_contactos_personales');cleanCheck(count($contacts)===2&&$contacts[0]['nombre']==='Conservar contacto'&&$contacts[0]['proxima_accion_at']===null&&$contacts[0]['updated_at']===$contactBefore[0]['updated_at']&&$contacts[1]===$contactBefore[1],'Contactos conservados, sólo programación comercial retirada');
 cleanCheck(TelefoniaLimpiezaGuard::bloqueada($db,'call-shared-analyst','100')&&!TelefoniaLimpiezaGuard::bloqueada($db,'call-shared-analyst','101'),'Exclusión por tramo sin bloquear Analista');
 $event('call-clean-sales','150','NOTIFY_ANSWER',date('Y-m-d H:i:s'));$event('call-shared-analyst','100','NOTIFY_ANSWER',date('Y-m-d H:i:s'));
 cleanCheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_zadarma_eventos WHERE pbx_call_id='call-clean-sales'")->fetch_assoc()['n']===0,'Webhook tardío no resucita una llamada borrada');
 cleanCheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_zadarma_eventos WHERE pbx_call_id='call-shared-analyst'")->fetch_assoc()['n']===count($sharedBefore)+1,'Evento tardío compartido se conserva para el Analista sin reaparecer en Recepción');
 (new TelefoniaVentasLlamadasService($db))->sincronizar(8);cleanCheck(cleanRows($db,'telefonia_ventas_llamadas')===[],'Sincronización no reconstruye ventas anteriores');
 $sql=TelefoniaLimpiezaGuard::condicion($db,'e.pbx_call_id','e.internal');cleanCheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_zadarma_eventos e WHERE $sql AND internal='101'")->fetch_assoc()['n']===1,'La consulta conserva el tramo de Analista');
 $_SESSION=['usuario_id'=>7,'rol_id'=>7,'rol'=>'Marketing','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes']];
 $reception=(new TelefoniaRecepcionMarketingService($db))->resumen(7);cleanCheck($reception['llamadas']===[],'Recepción queda vacía incluso con llamada compartida');
 cleanCheck($notices->pendientes(7,true)===[],'No recrear aviso de recepción por transferencia conservada para Analista');
 $event('call-live-new','150','NOTIFY_OUT_START',date('Y-m-d H:i:s'));
 cleanCheck(count(cleanRows($db,'telefonia_ventas_llamadas'))===1,'Nuevas llamadas se registran normalmente');
 $tool->aplicar($plan,$id,$sha);cleanCheck(count(cleanRows($db,'telefonia_ventas_llamadas'))===1,'Reaplicar el mismo plan no borra actividad nueva');
 $db->query("UPDATE telefonia_contactos_personales SET proxima_accion_at='2099-02-01 12:00:00' WHERE usuario_id=8");
 cleanReject(fn()=>$tool->restaurar($plan,$id,$sha),'Restaurar no sobrescribe una programación posterior');
 cleanCheck(count(cleanRows($db,'telefonia_ventas_llamadas'))===1,'Conflicto de restauración revierte todas las inserciones');
 $db->query('UPDATE telefonia_contactos_personales SET proxima_accion_at=NULL,updated_at=updated_at WHERE usuario_id=8');$tool->restaurar($plan,$id,$sha);
 cleanCheck(count(cleanRows($db,'telefonia_ventas_llamadas'))===2&&!TelefoniaLimpiezaGuard::bloqueada($db,'call-clean-sales','150'),'Restaurar recupera originales y conserva la llamada nueva');
 cleanCheck(cleanRows($db,'telefonia_contactos_personales')[0]['proxima_accion_at']===$contactBefore[0]['proxima_accion_at'],'Restaurar agenda original');
 // Cambio entre check y apply: no borrar nada y no crear un historial aplicado.
 $changed=$tool->plan();$db->query("UPDATE telefonia_ventas_resultados SET observacion='Edición posterior' WHERE usuario_id=8");
 cleanReject(fn()=>$tool->aplicar($changed,str_repeat('c',32),str_repeat('d',64)),'Plan obsoleto se rechaza');
 cleanCheck(count(cleanRows($db,'telefonia_ventas_llamadas'))===2,'Rechazo no borra datos');
 echo "Limpieza CRM: $n comprobaciones SQL, alcance, respaldo, reaparición, Analista, llamadas nuevas y restauración OK\n";
}finally{if(!$keep){$db->select_db('mysql');$db->query("DROP DATABASE `$name`");}$db->close();}
