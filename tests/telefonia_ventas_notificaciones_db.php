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
 require_once dirname(__DIR__).'/app/services/TelefoniaMarcacionesVentasService.php';new TelefoniaMarcacionesVentasService($db);
 $svc=new TelefoniaVentasLlamadasService($db);vcheck(!$svc->disponible(),'No crea tablas al consultar');
 $pending=TelefoniaVentasSchema::pendientes($db);vcheck(count($pending)>0&&!$svc->disponible(),'Check de migración es de lectura');
 foreach($pending as $sql)$db->query($sql);
 vcheck(TelefoniaVentasSchema::pendientes($db)===[],'Migración idempotente');
 // Simula una agenda preexistente con colación distinta a las tablas nuevas de Ventas.
 // El UNION de programación debe servir sin modificar tablas de producción.
 $db->query('ALTER TABLE telefonia_contactos_personales CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $contactCollation=$db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_contactos_personales' AND COLUMN_NAME='nombre'")->fetch_assoc()['COLLATION_NAME'];
 $resultCollation=$db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_ventas_resultados' AND COLUMN_NAME='prospecto_nombre'")->fetch_assoc()['COLLATION_NAME'];
 vcheck($contactCollation==='utf8mb4_unicode_ci'&&$resultCollation==='utf8mb4_general_ci','Fixture con dos collations distintas para detectar UNION incompatible');
 $events=new ZadarmaWebhookEventStoreService($db);$results=new TelefoniaResultadoVentasService($db);$base=time()-300;
 $event=function(string $ref,string $type,string $ext='150',int $seconds=0,array $extra=[])use($events,$base){static $offset=0;$at=date('Y-m-d H:i:s',$base);$events->registrar($extra+['event'=>$type,'pbx_call_id'=>$ref,'internal'=>$ext,'caller_id'=>'+525551112233','destination'=>'+525551112233','received_at'=>$at,'call_start'=>$at,'duration'=>$seconds]);};


 require_once dirname(__DIR__).'/app/services/TelefoniaNotificacionService.php';
 require_once dirname(__DIR__).'/app/services/TelefoniaProgramacionVentasService.php';
 $notices=new TelefoniaNotificacionService($db);$program=new TelefoniaProgramacionVentasService($db);$agenda=new TelefoniaContactosService($db);
 // Analista: sólo una devolución activa puede sobrevivir "Marcar leídas".
 $db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at)
     VALUES (10,'101',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $rutaAnalista=json_encode(['tipo'=>'INSTITUCION','responsable_id'=>10,'extension'=>'101','referencia_llamada'=>'out_test_read_keep']);
 $ins=$db->prepare("INSERT INTO telefonia_disponibilidad
     (origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,
      numero,ruta_json,estado,expires_at,created_at,updated_at,contacto,telefono,nota,devolucion)
     VALUES (7,'100',10,'101','out_test_read_keep','+525551119999',?,'DEVOLUCION',
       UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'Institución','+525551119999',
       'Devolver llamada','PENDIENTE')");
 $ins->bind_param('s',$rutaAnalista);$ins->execute();$idAnalista=(int)$db->insert_id;$ins->close();
 $pendientesAnalista=$notices->pendientes(10,false);
 vcheck(count($pendientesAnalista)===1&&$pendientesAnalista[0]['pendiente_operativo'],
     'Analista ve devolución asignada');
 $notices->leerTodas(10);
 $leidosAnalista=$notices->pendientes(10,false);
 vcheck(count($leidosAnalista)===1&&$leidosAnalista[0]['leida']===1,
     'La devolución del Analista no desaparece al marcar leídas');
 $db->query("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA' WHERE id=$idAnalista");
 vcheck($notices->pendientes(10,false)===[],'Al atender devolución desaparece de campana del Analista');
 $event('pbx-notice-missed','NOTIFY_INTERNAL');$event('pbx-notice-missed','NOTIFY_END');
 $one=$notices->pendientes(8,false);vcheck(count($one)===1&&$one[0]['titulo']==='Llamada perdida','Perdida propia con cierre sin conexión');
 $again=$notices->pendientes(8,false);vcheck($again[0]['telefonia_id']===$one[0]['telefonia_id'],'Polling no duplica aviso');
 vcheck($notices->pendientes(9,false)===[],'Sin extensión no hereda avisos ajenos');
 vcheck(!$notices->leer(9,$one[0]['telefonia_id']),'No leer aviso ajeno');
 $event('pbx-notice-missed','NOTIFY_ANSWER');$corrected=$notices->pendientes(8,false);
 vcheck(count($corrected)===1&&$corrected[0]['titulo']==='Resultado pendiente','Respuesta tardía retira falsa perdida');
 vcheck(str_contains($corrected[0]['url'],'action=misLlamadas')&&!str_contains($corrected[0]['url'],'recibidas'),'Destino comercial autorizado');
 vcheck($notices->leer(8,$corrected[0]['telefonia_id']),'Leer resultado propio');
 $reading=$notices->pendientes(8,false);
 vcheck(count($reading)===1&&$reading[0]['titulo']==='Resultado pendiente'&&$reading[0]['leida']===1&&$reading[0]['pendiente_operativo'],
    'Leer no oculta resultado que todavía requiere guardado');
 $results->guardar(8,'pbx-notice-missed','BUZON_VOZ');vcheck($notices->pendientes(8,false)===[],'Buzón no inventa conversación ni nuevo aviso');
 $route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>8,'extension'=>'150','referencia_llamada'=>'pbx-task-notice','estado_id'=>17,'institucion_nombre'=>'María']);
 $st=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at,contacto,telefono,nota,devolucion) VALUES(7,'100',8,'150','pbx-task-notice','+525551112233',?,'DEVOLUCION',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'María','+525551112233','Informes','PENDIENTE')");$st->bind_param('s',$route);$st->execute();$task=(int)$db->insert_id;$st->close();
 $event('pbx-task-notice','NOTIFY_INTERNAL');$event('pbx-task-notice','NOTIFY_END');
 $callbacks=$notices->pendientes(8,false);vcheck(count($callbacks)===1&&$callbacks[0]['etiqueta']==='Por devolver','Devolución asignada reemplaza aviso duplicado de perdida');
 vcheck(str_contains($callbacks[0]['url'],'vista=devoluciones'),'Devolución dirige a Ventas');
 $notices->leerTodas(8);$readCallbacks=$notices->pendientes(8,false);
 vcheck(count($readCallbacks)===1&&$readCallbacks[0]['leida']===1&&$readCallbacks[0]['pendiente_operativo'],
    'Marcar todas leídas nunca cancela devolución pendiente');
 $db->query("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA' WHERE id=$task");
 vcheck($notices->pendientes(8,false)===[],'Devolución atendida retira aviso');
 $results->guardar(8,'pbx-task-notice','SIN_RESPUESTA');
 $contact=$agenda->guardar(8,'Agenda programada','5551112244',['proxima_accion_at'=>date('Y-m-d H:i:s',time()+3600)]);
 vcheck($notices->pendientes(8,false)===[],'Programación futura no genera aviso vencido');
 $db->query("UPDATE telefonia_contactos_personales SET proxima_accion_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=".(int)$contact['id']);
 $rem=$program->listar(8)[0];$due=$notices->pendientes(8,false);
 vcheck(count($due)===1&&$due[0]['etiqueta']==='Programada'&&str_contains($due[0]['url'],'vista=agenda'),'Agenda vencida usa campana comercial');
 $notices->leerTodas(8);$readDue=$notices->pendientes(8,false);
 vcheck(count($readDue)===1&&$readDue[0]['leida']===1&&$readDue[0]['pendiente_operativo'],
    'Contacto programado permanece hasta completar agenda');
 vreject(fn()=>$program->completar(9,'agenda',(int)$rem['id'],$rem['fecha']),'No cerrar agenda ajena');
 vreject(fn()=>$program->completar(8,'agenda',(int)$rem['id'],'2000-01-01 12:00:00'),'No cerrar versión modificada');
 $before=(int)$db->query('SELECT COUNT(*) n FROM telefonia_ventas_resultados')->fetch_assoc()['n'];
 $program->completar(8,'agenda',(int)$rem['id'],$rem['fecha']);vcheck($notices->pendientes(8,false)===[],'Cerrar recordatorio retira aviso');
 vcheck((int)$db->query('SELECT COUNT(*) n FROM telefonia_ventas_resultados')->fetch_assoc()['n']===$before,'Cerrar agenda no fabrica resultado');


 $contact=$agenda->guardar(8,'Contacto vinculado','5553332211',['proxima_accion_at'=>date('Y-m-d H:i:s',time()+7200)]);
 $event('pbx-programa-resultado','NOTIFY_OUT_START','150',0,['destination'=>'+525553332211']);$event('pbx-programa-resultado','NOTIFY_OUT_END','150',15,['disposition'=>'answered']);
 $date=$contact['proxima_accion_at'];$results->guardar(8,'pbx-programa-resultado','REQUIERE_SEGUIMIENTO',['contacto_id'=>$contact['id'],'proxima_accion_at'=>$date,'prospecto_nombre'=>'Contacto vinculado']);
 $list=$program->listar(8);vcheck(count($list)===1&&$list[0]['origen']==='resultado','Agenda y resultado del mismo contacto/fecha no duplican recordatorio');
 $outcome=$svc->obtener(8,'pbx-programa-resultado')['registro'];$program->completar(8,'resultado',(int)$list[0]['id'],$list[0]['fecha']);
 vcheck($program->listar(8)===[],'Cerrar programación vinculada cierra la misma fecha de agenda');
 $after=$svc->obtener(8,'pbx-programa-resultado')['registro'];vcheck($after['resultado']===$outcome['resultado']&&$after['observacion']===$outcome['observacion']&&$after['created_at']===$outcome['created_at'],'Cerrar recordatorio conserva resultado histórico definitivo');
 // Disponibilidad aceptada, transferencia enviada y respuesta real son hechos distintos.
 $route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>8,'extension'=>'150','referencia_llamada'=>'pbx-transfer-noanswer','estado_id'=>17,'institucion_nombre'=>'Pedro']);
 $st=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at) VALUES(7,'100',8,'150','pbx-transfer-noanswer','+525551112233',?,'ACEPTADA',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND),UTC_TIMESTAMP(),UTC_TIMESTAMP())");$st->bind_param('s',$route);$st->execute();$transferTask=(int)$db->insert_id;$st->close();
 vcheck((new TelefoniaDisponibilidadService($db))->obtener(8,$transferTask)['estado']==='ACEPTADA','Aceptación conserva estado sin transferir automáticamente');
 vreject(fn()=>$svc->obtener(8,'pbx-transfer-noanswer'),'Disponibilidad aceptada no inventa llamada atendida');
 $db->query("UPDATE telefonia_disponibilidad SET estado='ENVIADA' WHERE id=$transferTask");
 $db->query("INSERT INTO telefonia_transferencia_operaciones(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,modo,estado,created_at,updated_at) VALUES(7,'100',8,'150','pbx-transfer-noanswer','+525551112233','DIRECTA','ENVIADA',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 MINUTE),UTC_TIMESTAMP())");
 $unconfirmed=$notices->pendientes(8,false);vcheck(count($unconfirmed)===1&&$unconfirmed[0]['etiqueta']==='Por confirmar','Transferencia enviada sin respuesta queda sin confirmar');
 $notices->leerTodas(8);$marked=$notices->pendientes(8,false);
 vcheck(count($marked)===1&&$marked[0]['leida']===1&&$marked[0]['pendiente_operativo'],
    'Transferencia por confirmar no desaparece al marcar lectura');
 $event('pbx-transfer-noanswer','NOTIFY_INTERNAL','150',0,['transfer_from'=>'100']);$event('pbx-transfer-noanswer','NOTIFY_END','150',0,['disposition'=>'no answer']);
 $notAnswered=$notices->pendientes(8,false);$operation=$db->query("SELECT * FROM telefonia_transferencia_operaciones WHERE referencia='pbx-transfer-noanswer'")->fetch_assoc();
 vcheck($operation['estado']==='SIN_RESPUESTA'&&$operation['destino_atendida']===null,'Cierre sin respuesta no marca atendida');
 vcheck(count($notAnswered)===1&&$notAnswered[0]['etiqueta']==='Por devolver','Un solo aviso de devolución sustituye sin confirmar y perdida');
 $event('pbx-transfer-noanswer','NOTIFY_ANSWER');$late=$notices->pendientes(8,false);
 vcheck(count($late)===1&&$late[0]['titulo']==='Resultado pendiente','Respuesta tardía sustituye aviso automático por resultado pendiente');
 $results->guardar(8,'pbx-transfer-noanswer','INTERESADO');vcheck($notices->pendientes(8,false)===[],'Resultado de respuesta tardía cierra sólo devolución automática correspondiente');
 // Conciliación uno a uno: dos llamadas o dos intenciones no se eligen por orden.
 $marker=new TelefoniaMarcacionesVentasService($db,false);vreject(fn()=>$marker->iniciar(9,'5556667788',str_repeat('9',32)),'Sin extensión asignada no prepara llamadas salientes');$token=str_repeat('a',32);$marker->iniciar(8,'5556667788',$token);
 $out='out_'.str_repeat('c',32);$event($out,'NOTIFY_OUT_START','150',0,['destination'=>'+525556667788','received_at'=>date('Y-m-d H:i:s'),'call_start'=>date('Y-m-d H:i:s')]);$svc->sincronizar(8);
 vcheck($marker->pertenece(8,'150',$out),'Conciliación única restaura referencia exacta');
 $marker->iniciar(8,'5557778899',str_repeat('b',32));$marker->iniciar(8,'5557778899',str_repeat('d',32));
 $event('out_'.str_repeat('e',32),'NOTIFY_OUT_START','150',0,['destination'=>'+525557778899','received_at'=>date('Y-m-d H:i:s'),'call_start'=>date('Y-m-d H:i:s')]);$svc->sincronizar(8);
 vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_marcaciones WHERE destino='+525557778899' AND pbx_call_id IS NULL")->fetch_assoc()['n']===2,'Un evento compatible con dos intenciones permanece sin asociación');
 $marker->iniciar(8,'5558889900',str_repeat('f',32));foreach(['1','2'] as $hex)$event('out_'.str_repeat($hex,32),'NOTIFY_OUT_START','150',0,['destination'=>'+525558889900','received_at'=>date('Y-m-d H:i:s'),'call_start'=>date('Y-m-d H:i:s')]);$svc->sincronizar(8);
 vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_marcaciones WHERE destino='+525558889900' AND pbx_call_id IS NULL")->fetch_assoc()['n']===1,'Dos eventos compatibles no eligen el primero');

 // Hostinger: la sesión MySQL usa UTC y el webhook transforma received_at a México.
 // Debe conciliar sin restar horas a los registros ni aceptar parejas ambiguas.
 $db->query("SET time_zone='+00:00'");
 $tokenUtc=str_repeat('6',32);$refUtc='out_'.str_repeat('6',32);
 $marker->iniciar(8,'5556669911',$tokenUtc);
 $event($refUtc,'NOTIFY_OUT_START','150',0,['destination'=>'+525556669911','received_at'=>date('Y-m-d H:i:s'),'call_start'=>date('Y-m-d H:i:s')]);
 $svc->sincronizar(8);
 vcheck($marker->pertenece(8,'150',$refUtc),'MySQL UTC y webhook hora México: conciliación única válida');
 $atUtc=$db->query("SELECT created_at FROM telefonia_ventas_marcaciones WHERE token='$tokenUtc'")->fetch_assoc()['created_at'];
 vcheck($marker->fechaMarcacionLocal($atUtc)!==$atUtc,'Las marcaciones UTC se presentan a la hora local sin modificar la fila');
 $ambigToken=str_repeat('7',32);
 $marker->iniciar(8,'5556679912',$ambigToken);
 foreach(['7','8'] as $hex)$event('out_'.str_repeat($hex,32),'NOTIFY_OUT_START','150',0,['destination'=>'+525556679912','received_at'=>date('Y-m-d H:i:s'),'call_start'=>date('Y-m-d H:i:s')]);
 $svc->sincronizar(8);
 vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_marcaciones WHERE token='$ambigToken' AND pbx_call_id IS NULL")->fetch_assoc()['n']===1,'Dos eventos UTC→México siguen sin vincularse por ambigüedad');
 $db->query("SET time_zone='-06:00'");

 // Caso reproducido en Hostinger: tres intentos del mismo número,
 // dos inicios PBX: #1 y #3 a +2 segundos; #2 queda entre ambos
 // con candidatos a -119 y +55 segundos y no debe asociarse.
 // La fixture anterior restauró -06:00; esta debe replicar la sesión UTC real de Hostinger.
 $db->query("SET time_zone='+00:00'");
 $tokensTres=[str_repeat('1',32),str_repeat('2',32),str_repeat('3',32)];
 $refUno='out_'.str_repeat('a',31).'1';$refTres='out_'.str_repeat('a',31).'3';
 $baseUtc=time()-900;
 $intentEpoch=[$baseUtc,$baseUtc+121,$baseUtc+174];
 $mxZone=new DateTimeZone('America/Mexico_City');
 $utcZone=new DateTimeZone('UTC');
 $updateIntent=$db->prepare("UPDATE telefonia_ventas_marcaciones SET created_at=? WHERE token=?");
 foreach($tokensTres as $index=>$tok){
     $marker->iniciar(8,'5553334499',$tok);
     $stored=(new DateTimeImmutable('@'.$intentEpoch[$index]))->setTimezone($utcZone)->format('Y-m-d H:i:s');
     $updateIntent->bind_param('ss',$stored,$tok);$updateIntent->execute();
 }
 $updateIntent->close();
 $e1=(new DateTimeImmutable('@'.($intentEpoch[0]+2)))->setTimezone($mxZone)->format('Y-m-d H:i:s');
 $e3=(new DateTimeImmutable('@'.($intentEpoch[2]+2)))->setTimezone($mxZone)->format('Y-m-d H:i:s');
 $event($refUno,'NOTIFY_OUT_START','150',0,['destination'=>'+525553334499','received_at'=>$e1,'call_start'=>$e1]);
 $event($refTres,'NOTIFY_OUT_START','150',0,['destination'=>'+525553334499','received_at'=>$e3,'call_start'=>$e3]);
 $svc->sincronizar(8);
 vcheck($marker->pertenece(8,'150',$refUno),'Primer intento corresponde a evento PBX +2 segundos');
 vcheck($marker->pertenece(8,'150',$refTres),'Tercer intento corresponde a evento PBX +2 segundos');
 $middle=$db->query("SELECT pbx_call_id FROM telefonia_ventas_marcaciones WHERE token='".$tokensTres[1]."'")->fetch_assoc();
 vcheck(($middle['pbx_call_id']??null)===null,'Segundo intento +55 segundos sigue sin referencia PBX');
 vreject(fn()=>$marker->vincular(8,$tokensTres[1],$refTres),'No vincular manualmente referencia PBX a intento remoto +55 segundos');
 $svc->sincronizar(8);
 vcheck($marker->pertenece(8,'150',$refUno)&&$marker->pertenece(8,'150',$refTres)
   &&(int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_marcaciones WHERE token='".$tokensTres[1]."' AND pbx_call_id IS NULL")->fetch_assoc()['n']===1,
   'Idempotencia: dos llamadas reales y un intento no confirmado');

 $db->query("SET time_zone='-06:00'");

 $audio=TelefoniaVentasLlamadasService::hechos([
  ['evento'=>'NOTIFY_RECORD','internal'=>'100','call_id_with_rec'=>'record-shared-001'],
  ['evento'=>'NOTIFY_RECORD','internal'=>'150','call_id_with_rec'=>'record-shared-001'],
  ['evento'=>'NOTIFY_RECORD','internal'=>'150','call_id_with_rec'=>'record-exclusive-150']], '150');
 vcheck($audio['segmentos']===['record-exclusive-150'],'Audio con la misma referencia en otra extensión queda ambiguo, sin mezclar recepción');
 $unknown=TelefoniaVentasLlamadasService::hechos([['evento'=>'NOTIFY_END','internal'=>'150','duration'=>0,'payload_json'=>'{"disposition":"answered"}','disposition'=>'answered']], '150');
 vcheck($unknown['conectada']&&$unknown['segundos']===null,'Duración omitida por PBX permanece sin confirmar');
 // Historial histórico visible con su propietario, sin extensión actual y sin alertas prestadas.
 $db->query("UPDATE telefonia_extensiones SET usuario_id=9,updated_at=NOW() WHERE extension='150'");
 vcheck($svc->obtener(8,$out)['usuario_id']==8,'Propiedad histórica después de reasignar');
 vcheck($notices->pendientes(9,false)===[],'Nuevo titular no recibe avisos de llamadas previas');
 vcheck($notices->leer(8,$due[0]['telefonia_id']),'Lectura histórica por usuario aunque cambie la extensión');
 echo "Ventas E: $checks comprobaciones de notificaciones, programación y conciliación aprobadas.\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
