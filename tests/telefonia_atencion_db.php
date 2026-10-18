<?php
declare(strict_types=1);
// Exclusivamente una base desechable, nunca la configuración del sistema.
require_once dirname(__DIR__).'/app/services/TelefoniaEntrantesAnalistaService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaBorradorService.php';
require_once dirname(__DIR__).'/app/services/AnalistaDashboardIntegrityService.php';
require_once dirname(__DIR__).'/app/services/InteraccionRutaService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('Define IMPE_TEST_MYSQL_PORT para la instancia aislada.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$timezone=date('P');$tzStmt=$db->prepare('SET time_zone=?');$tzStmt->bind_param('s',$timezone);$tzStmt->execute();$tzStmt->close();
$name='impe_test_atencion_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
$checks=0;
function check($value,string $message): void {global $checks;if (!$value) throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,string $message): void {try {$fn();}catch(DomainException|InvalidArgumentException $e){check(true,$message);return;}throw new RuntimeException($message);}
function one(string $sql): array {global $db;return $db->query($sql)->fetch_assoc()?:[];}
try {
    $schema=file_get_contents(dirname(__DIR__).'/database/sistema_comercial_impe.sql');
    foreach (['seguimientos_vinculacion','interacciones_vinculacion'] as $table) {
        preg_match('/CREATE TABLE `'.$table.'` \([\s\S]*?\) ENGINE=InnoDB[^;]*;/',$schema,$match);
        $db->query(str_replace('`id` int(11) NOT NULL,','`id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,',$match[0]));
    }
    $db->query('CREATE TABLE usuarios (id INT PRIMARY KEY,estado INT,rol_id INT)');
    $db->query('CREATE TABLE permisos (id INT PRIMARY KEY,codigo VARCHAR(60),estado INT)');
    $db->query('CREATE TABLE rol_permisos (rol_id INT,permiso_id INT)');
    $db->query('CREATE TABLE estados (id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)');
    $db->query('CREATE TABLE asignaciones_territorio (usuario_id INT,tipo_asignacion VARCHAR(30),estado_id INT,activo INT,fecha_inicio DATE,fecha_fin DATE)');
    (new TelefoniaExtensionService($db))->asegurarEstructura();
    $db->query("INSERT INTO usuarios VALUES (1,1,1),(2,1,1)");
    $db->query("INSERT INTO permisos VALUES (1,'telefonia.usar',1),(2,'telefonia.recibir',1),(3,'telefonia.salientes',1)");
    $db->query('INSERT INTO rol_permisos VALUES (1,1),(1,2),(1,3)');
    $db->query("INSERT INTO telefonia_extensiones (usuario_id,extension,created_at,updated_at) VALUES (1,'101',DATE_SUB(NOW(),INTERVAL 10 DAY),DATE_SUB(NOW(),INTERVAL 10 DAY)),(2,'102',DATE_SUB(NOW(),INTERVAL 10 DAY),DATE_SUB(NOW(),INTERVAL 10 DAY))");
    $db->query("INSERT INTO estados VALUES (1,'Morelos',1),(2,'Puebla',1)");
    $db->query("INSERT INTO asignaciones_territorio VALUES (1,'ANALISTA_DATOS',1,1,NULL,NULL)");
    $db->query("INSERT INTO seguimientos_vinculacion (id,estado_id,analista_id,origen,nombre_entidad,telefono_fuente,correo_fuente,contacto_nombre,estado_seguimiento,proxima_accion_at)
        VALUES (1,1,1,'MANUAL','Institución Uno','5551234567','uno@example.org','Ana','ESPERANDO_RESPUESTA',DATE_ADD(NOW(),INTERVAL 3 DAY)),
               (2,1,2,'MANUAL','Institución ajena','5552222222','dos@example.org','Beatriz','CONTACTANDO',NULL)");
    $svc=new TelefoniaEntrantesAnalistaService($db);$events=new ZadarmaWebhookEventStoreService($db);
    $sdk=new TelefoniaAnalistaWebrtcService($db);
    $base=time()-2400;
    $event=function(string $id,string $type,int $time,string $number='5551234567',string $ext='101') use($events){
        $events->registrar(['event'=>$type,'pbx_call_id'=>$id,'internal'=>$ext,'caller_id'=>$number,'destination'=>$ext,
            'call_start'=>date('Y-m-d H:i:s',$time),'received_at'=>date('Y-m-d H:i:s',$time),'duration'=>30]);
    };
    $pbx=function(string $id,int $offset=0,string $number='5551234567',string $ext='101') use($event,$base){
        $event($id,'NOTIFY_INTERNAL',$base+$offset,$number,$ext);$event($id,'NOTIFY_ANSWER',$base+$offset+3,$number,$ext);$event($id,'NOTIFY_END',$base+$offset+33,$number,$ext);
    };
    $args=['decision'=>'existente','seguimiento_id'=>'1','resultado'=>'CONTACTO_CORRECTO','persona_atendio'=>'Ana','verificacion_telefono_confirmado'=>'1'];
    $pbx('pbx_first');
    $res=$svc->registrar(1,['pbx_call_id'=>'pbx_first']+$args);
    check($res['verificacion_confirmada']===true,'PBX acredita evidencia explícita');
    $follow=one('SELECT * FROM seguimientos_vinculacion WHERE id=1');
    check($follow['estado_seguimiento']==='ESPERANDO_RESPUESTA','No regresa la ruta a Contactando');
    check($follow['datos_verificados']==0,'Confirmar un dato no completa la ficha');
    check(!empty($follow['proxima_accion_at']),'Conserva devolución futura');
    check(one('SELECT estado FROM telefonia_verificacion_evidencias')['estado']==='CONFIRMADA','Evidencia durable');
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_first']+$args),'No duplicar registro');
    check(one('SELECT COUNT(*) AS n FROM interacciones_vinculacion')['n']==1,'Un guardado una interacción');
    $pbx('pbx_referred',120);
    $res=$svc->registrar(1,['pbx_call_id'=>'pbx_referred','resultado'=>'CONTACTO_REFERIDO','nuevo_contacto_nombre'=>'Luis','nuevo_contacto_cargo'=>'Compras','nuevo_telefono_contacto'=>'5557654321','observacion'=>'Nota [VERIFICACION_EFECTIVA]']+$args);
    $follow=one('SELECT * FROM seguimientos_vinculacion WHERE id=1');
    check($follow['contacto_nombre']==='Luis' && $follow['contacto_cargo']==='Compras' && $follow['telefono_verificado']==='5557654321','Actualiza persona referida distinta del informante');
    check(one("SELECT COUNT(*) AS n FROM interacciones_vinculacion WHERE notas LIKE '%[VERIFICACION_EFECTIVA]%'")['n']==2,'Dos conversaciones verificadas conservan historial');
    $expectedDays=count(array_unique([date('Y-m-d',$base+3),date('Y-m-d',$base+123)]));
    check(one("SELECT COUNT(DISTINCT CONCAT(seguimiento_id,'|',DATE(fecha_inicio))) AS n FROM interacciones_vinculacion WHERE notas LIKE '%[VERIFICACION_EFECTIVA]%' AND usuario_id=1 AND duracion_segundos>0")['n']==$expectedDays,'Meta: una institución al día, incluso si las pruebas cruzan medianoche');
    $pbx('pbx_wrong',240);$before=one('SELECT COUNT(*) AS n FROM interacciones_vinculacion')['n'];
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_wrong','seguimiento_id'=>'2']+$args),'Rechaza expediente ajeno');
    check(one('SELECT COUNT(*) AS n FROM interacciones_vinculacion')['n']==$before,'Error revierte interacción');
    $pbx('pbx_negative',360);
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_negative','decision'=>'nueva','resultado'=>'BUZON_VOZ','nombre_entidad'=>'Institución inventada','estado_id'=>'1']),'No crear expediente por buzón');
    $res=$svc->registrar(1,['pbx_call_id'=>'pbx_negative','resultado'=>'BUZON_VOZ','persona_atendio'=>'[VERIFICACION_EFECTIVA]','observacion'=>'[VERIFICACION_EFECTIVA] [VERIFICACION_PENDIENTE_PBX] Locución.']+$args);
    $last=one('SELECT * FROM interacciones_vinculacion ORDER BY id DESC LIMIT 1');
    check(!$res['verificacion_confirmada'] && strpos($last['notas'],'[VERIFICACION_')===false,'Resultado negativo no acredita ni acepta marcadores libres');
    $idSdk='sdk-pending-1234567890';$date=date('Y-m-d H:i:s',$base+600);$answered=date('Y-m-d H:i:s',$base+603);$end=date('Y-m-d H:i:s',$base+633);
    // El SDK almacena UTC; el historial presenta hora institucional.
    $utc=fn($s)=>gmdate('Y-m-d H:i:s',strtotime($s));
    $stmt=$db->prepare("INSERT INTO telefonia_analista_webrtc_sesiones (usuario_id,extension,session_id,numero_origen,estado,fecha_inicio,fecha_atendida,fecha_fin,duracion_observada_segundos) VALUES (1,'101',?,'5551234567','ended',?,?,?,30)");
    $d=$utc($date);$a=$utc($answered);$e=$utc($end);$stmt->bind_param('ssss',$idSdk,$d,$a,$e);$stmt->execute();
    $res=$svc->registrar(1,['sdk_session_id'=>$idSdk,'confirmacion_manual_sdk'=>'1']+$args);
    check($res['verificacion_pendiente'] && !$res['verificacion_confirmada'],'WebRTC guarda atención sin acreditar antes de PBX');
    $interaction=$res['interaccion_id'];$count=one('SELECT COUNT(*) AS n FROM interacciones_vinculacion')['n'];
    $pbx('pbx_late',600);
    $res=$svc->registrar(1,['pbx_call_id'=>'pbx_late','sdk_reference'=>$idSdk,'confirmar_verificacion'=>'1']);
    check($res['verificacion_confirmada'],'PBX tardía confirma atención existente');
    check(one('SELECT COUNT(*) AS n FROM interacciones_vinculacion')['n']==$count,'Confirmación tardía no duplica');
    check(one("SELECT proveedor_externo FROM interacciones_vinculacion WHERE id=$interaction")['proveedor_externo']==='ZADARMA','Reemplaza metadata provisional por fuente PBX');
    $again=$svc->registrar(1,['pbx_call_id'=>'pbx_late','sdk_reference'=>$idSdk,'confirmar_verificacion'=>'1']);
    check(!$again['verificacion_confirmada'],'Confirmación repetida no acredita otra vez');
    rejects(fn()=>$svc->registrar(2,['pbx_call_id'=>'pbx_late','sdk_reference'=>$idSdk,'confirmar_verificacion'=>'1']),'No confirmar evidencia de otro usuario');
    $drafts=new TelefoniaBorradorService($db);$revision=(int)(microtime(true)*1000);
    $drafts->guardar(1,['sdk-draft-000001','pbx-draft-000001'],['persona'=>'Ana','csrf_token'=>'NO GUARDAR'],$revision);
    check($drafts->leer(1,'pbx-draft-000001')['valores']===['persona'=>'Ana'],'Borrador alias y lista de campos permitidos');
    check($drafts->leer(2,'sdk-draft-000001')===null,'Borrador privado por usuario');
    rejects(fn()=>$drafts->guardar(1,['sdk-draft-000001'],['persona'=>'Viejo'],$revision-1),'Petición antigua no pisa captura');
    $drafts->eliminar(1,['sdk-draft-000001','pbx-draft-000001']);
    check($drafts->leer(1,'sdk-draft-000001')===null,'Al guardar atención se cierra el borrador');
    rejects(fn()=>$drafts->guardar(1,['sdk-draft-000001'],['persona'=>'Resucitado'],$revision+1),'Petición en vuelo no resucita borrador cerrado');
    // El modelo de llamadas salientes también persiste la evidencia dentro de la transacción.
    $model=new SeguimientoVinculacionModel($db);$details=VerificacionInstitucionalService::detalles($args,$follow);
    check($model->registrarInteraccionManual(1,1,['canal'=>'LLAMADA_IP','resultado'=>'CONTACTADO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'[VERIFICACION_PENDIENTE_TELEFONIA]','preservar_estado'=>true,'verificacion_detalles'=>$details,'persona_informante'=>'Ana']), 'Saliente guarda evidencia pendiente');
    $out=$model->obtenerUltimaInteraccionCreadaId();
    $db->begin_transaction();(new TelefoniaVerificacionEvidenciaService($db))->confirmarSalida(1,$out,'pbx_out',false);$db->commit();
    check(one("SELECT estado FROM telefonia_verificacion_evidencias WHERE interaccion_id=$out")['estado']==='RECHAZADA','Sin respuesta PBX no acredita evidencia saliente');
    $pbx('pbx_new',900,'5554443333');
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_new','decision'=>'nueva','nombre_entidad'=>'Nueva institución','tipo_entidad'=>'INSTITUCION','estado_id'=>'2','resultado'=>'CONTACTO_CORRECTO']),'No crear institución en territorio ajeno');
    $res=$svc->registrar(1,['pbx_call_id'=>'pbx_new','decision'=>'nueva','nombre_entidad'=>'Nueva institución','tipo_entidad'=>'INSTITUCION','estado_id'=>'1','resultado'=>'CONTACTO_REFERIDO','persona_atendio'=>'Ana','nuevo_contacto_nombre'=>'Luis','nuevo_correo_contacto'=>'luis@example.org']);
    check($res['seguimiento_id']>2 && $res['verificacion_confirmada'],'Nueva institución obtiene expediente y evidencia en la misma atención');
    $pbx('pbx_internal',1020,'102');
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_internal']+$args),'Llamada interna no se convierte en verificación');
    $event('pbx_unanswered','NOTIFY_INTERNAL',$base+1140);$event('pbx_unanswered','NOTIFY_END',$base+1170);
    rejects(fn()=>$svc->registrar(1,['pbx_call_id'=>'pbx_unanswered']+$args),'No acredita llamada sin respuesta');
    $svc->registrar(1,['pbx_call_id'=>'pbx_unanswered','decision'=>'sin_identificar']);
    check(one("SELECT estado FROM telefonia_analista_entrantes WHERE pbx_call_id='pbx_unanswered'")['estado']==='SIN_IDENTIFICAR','Intento sin conversación permanece en historial');
    // Ejecutar la consulta real del tablero sin abrir la conexión configurada del CRM.
    $reflection=new ReflectionClass(AnalistaDashboardIntegrityService::class);$dashboard=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('connection')->setValue($dashboard,$db);
    $expectedMonth=[];
    foreach ([[1,$base+3],[1,$base+123],[1,$base+603],[(int)$res['seguimiento_id'],$base+903]] as [$institution,$at]) {
        if(date('Y-m',$at)===date('Y-m'))$expectedMonth[$institution.'|'.date('Y-m-d',$at)]=true;
    }
    check($reflection->getMethod('contarContactosEfectivosMes')->invoke($dashboard,1)===count($expectedMonth),'Tablero cuenta instituciones por día como Desempeño');
    $db->query('CREATE TABLE oficios_vinculacion (id INT PRIMARY KEY,seguimiento_id INT,fecha_envio DATETIME,estado_oficio VARCHAR(30))');
    $reflection=new ReflectionClass(InteraccionRutaService::class);$ruta=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('connection')->setValue($ruta,$db);
    $before=one('SELECT estado_seguimiento,proxima_accion_at FROM seguimientos_vinculacion WHERE id=1');
    $res=$ruta->registrarInformativa(1,1,['canal'=>'LLAMADA','resultado'=>'CONTACTO_REFERIDO','persona_atendio'=>'Ana','nuevo_contacto_nombre'=>'Carla','nuevo_contacto_cargo'=>'Dirección','origen_llamada'=>'ZADARMA']);
    check($res['ok']===true,'Ruta avanzada acepta contacto referido con nombre y cargo');
    check(one('SELECT estado_seguimiento,proxima_accion_at FROM seguimientos_vinculacion WHERE id=1')===$before,'Ruta avanzada conserva etapa y fecha');
    $out=(int)$res['interaccion']['id'];
    check(one("SELECT COUNT(*) AS n FROM telefonia_verificacion_evidencias WHERE interaccion_id=$out AND estado='PENDIENTE'")['n']==2,'Ruta avanzada conserva evidencia pendiente por campo');
    // Transferencia saliente 100 -> 101 sin NOTIFY_INTERNAL del destinatario.
    $t=time()-180;
    foreach ([
        ['event'=>'NOTIFY_OUT_START','internal'=>'100','destination'=>'5556667788','at'=>$t],
        ['event'=>'NOTIFY_ANSWER','internal'=>'101','caller_id'=>'100','destination'=>'101','transfer_from'=>'100','at'=>$t+20],
        ['event'=>'NOTIFY_OUT_END','internal'=>'100','destination'=>'5556667788','disposition'=>'answered','duration'=>80,'at'=>$t+80],
        ['event'=>'NOTIFY_RECORD','call_id_with_rec'=>'1234567890.1234','at'=>$t+82]
    ] as $e) {
        $events->registrar($e+['pbx_call_id'=>'out_transfer_100_101','call_start'=>date('Y-m-d H:i:s',$t),'received_at'=>date('Y-m-d H:i:s',$e['at'])]);
    }
    $own=$events->obtenerEstadoEntrantePorPbxCallId('101','out_transfer_100_101');
    check($own && $own['caller_id']==='5556667788' && $own['transfer_from']==='100','Identifica número externo y origen 100 desde una saliente transferida');
    check($own['estado']==='ended' && $own['duration']===60,'Duración corresponde sólo al tramo atendido por el analista');
    check($events->obtenerEstadoEntrantePorPbxCallId('102','out_transfer_100_101')===null,'Otra extensión no adquiere acceso a la transferencia');
    $rows=array_column($svc->listar(1)['llamadas'],null,'pbx_call_id');
    check(!empty($rows['out_transfer_100_101']['grabacion_pbx_reportada']),'Audio de una saliente transferida disponible en la bandeja propia');
    check(!empty($rows['out_transfer_100_101']['contestada']),'Respuesta propia confirmada sin inventar un evento entrante');
    $events->registrar(['event'=>'NOTIFY_ANSWER','pbx_call_id'=>'orphan_answer','internal'=>'101','transfer_from'=>'100','received_at'=>date('Y-m-d H:i:s')]);
    check($events->obtenerEstadoEntrantePorPbxCallId('101','orphan_answer')===null,'Una respuesta sin saliente original no acredita transferencia');
    require_once dirname(__DIR__).'/app/services/TelefoniaNotificacionService.php';
    $db->query('INSERT INTO usuarios VALUES (3,1,1)');
    $db->query("INSERT INTO telefonia_extensiones (usuario_id,extension,created_at,updated_at) VALUES (3,'100',DATE_SUB(NOW(),INTERVAL 10 DAY),DATE_SUB(NOW(),INTERVAL 10 DAY))");
    $workflow=function($ref)use($events){$events->registrar(['event'=>'NOTIFY_OUT_START','pbx_call_id'=>$ref,'internal'=>'100','destination'=>'5553334444','call_start'=>date('Y-m-d H:i:s',time()-40),'received_at'=>date('Y-m-d H:i:s',time()-40)]);};
    $ops=new TelefoniaTransferenciaOperacionService($db);$workflow('workflow_consult_001');
    $op=$ops->iniciar(3,'workflow_consult_001',1,'101','CONSULTADA');$oid=(int)$op['id'];
    rejects(fn()=>$ops->iniciar(3,'workflow_consult_001',1,'101','CONSULTADA'),'Cerrar el panel no autoriza otra transferencia');
    check($ops->obtener(2,$oid)===null,'Otro usuario no puede consultar la operación');
    rejects(fn()=>$ops->accion(3,$oid,'COMPLETAR'),'No entregar antes de que el analista conteste');
    $bound=$ops->paraDestino(1,'100','sdk-consulta-prueba-000001');
    check((int)$bound['id']===$oid,'Vincula la consulta sólo al destino autorizado');
    $sdk->registrarEvento(1,['evento'=>'ringing','session_id'=>'sdk-consulta-prueba-000001','numero'=>'100']);
    $ops->accion(1,$oid,'ATENDIDA');
    check($ops->obtener(3,$oid)['estado']==='CONSULTANDO','Contestar la consulta no entrega al cliente');
    $ops->accion(3,$oid,'COMPLETAR');
    $ops->accion(1,$oid,'TERMINADA');
    check($ops->obtener(3,$oid)['estado']==='ENTREGANDO','Cierre simultáneo del diálogo no revierte una entrega en curso');
    $ops->accion(3,$oid,'LIBERADA');
    check($ops->accion(3,$oid,'LIBERADA')['estado']==='ENTREGADA','Entrega y confirmación repetida idempotentes');
    foreach([['NOTIFY_INTERNAL',-5],['NOTIFY_ANSWER',-4],['NOTIFY_END',5]] as [$type,$offset])$events->registrar(['event'=>$type,'pbx_call_id'=>'workflow_consult_001','internal'=>'101','transfer_from'=>'100','caller_id'=>'100','received_at'=>date('Y-m-d H:i:s',time()+$offset),'duration'=>50]);
    $sdk->registrarEvento(1,['evento'=>'ringing','session_id'=>'sdk-consulta-prueba-000001-external','numero'=>'5553334444']);
    $sdk->registrarEvento(1,['evento'=>'answered','session_id'=>'sdk-consulta-prueba-000001-external']);
    $rows=$svc->listar(1)['llamadas'];$map=array_column($rows,null,'pbx_call_id');
    check(!isset($map['sdk-consulta-prueba-000001']),'No duplica la consulta como llamada interna');
    check($map['workflow_consult_001']['numero']==='5553334444','Entrega recupera el número externo confirmado');
    check(!isset($map['sdk-consulta-prueba-000001-external']),'La sesión externa se une al mismo registro con identidad exacta');
    $workflow('workflow_missed_002');$op=$ops->iniciar(3,'workflow_missed_002',1,'101','CONSULTADA');$oid2=(int)$op['id'];
    $ops->paraDestino(1,'100','sdk-consulta-perdida-000002');
    $sdk->registrarEvento(1,['evento'=>'ringing','session_id'=>'sdk-consulta-perdida-000002','numero'=>'100']);
    $ops->accion(1,$oid2,'TERMINADA');
    check($ops->obtener(3,$oid2)['estado']==='DEVUELTA','Consulta sin respuesta devuelve la atención a recepción');
    $missedRows=array_values(array_filter($svc->listar(1)['llamadas'],fn($r)=>($r['fuente']??'')==='OPERACION'));
    check(count($missedRows)===1 && !$missedRows[0]['contestada'] && !$missedRows[0]['grabacion_pbx_reportada'] && $missedRows[0]['estado_pbx']==='ended','Un intento sin respuesta termina en historial sin conversación ni audio');
    $notifications=new TelefoniaNotificacionService($db);$notices=$notifications->pendientes(1,false);
    $missed=array_values(array_filter($notices,fn($r)=>$r['titulo']==='Transferencia sin respuesta'));
    check(count($missed)===1,'Campana incluye un solo aviso persistente por intento perdido');
    $notice=(int)$missed[0]['telefonia_id'];$notifications->leer(2,$notice);
    check(one("SELECT leida FROM telefonia_notificaciones WHERE id=$notice")['leida']==0,'Otro usuario no puede leer el aviso');
    $notifications->leer(1,$notice);$notifications->pendientes(1,false);
    check(one("SELECT leida FROM telefonia_notificaciones WHERE id=$notice")['leida']==1,'El aviso leído no reaparece al sincronizar');
    check($ops->iniciar(3,'workflow_missed_002',1,'101','CONSULTADA')['intento']==2,'Sólo una devolución permite otro intento explícito');
    echo "Atención analista/MySQL: $checks comprobaciones OK\n";
} finally {$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
