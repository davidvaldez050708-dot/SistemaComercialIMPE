<?php
// Included ONLY by the existing disposable MariaDB fixture.
if (getenv('IMPE_WHATSAPP_VENTAS_TEST')!=='YES' || !preg_match('/^impe_wa_ventas_test_[a-f0-9]{10}$/D',$schema)) exit(2);
date_default_timezone_set('UTC');
function rxRow(mysqli $db,string $sql): array {return $db->query($sql)->fetch_assoc()??[];}
function rxExpectError(callable $f): void {try{$f();}catch(Throwable $e){return;}throw new RuntimeException('Expected failure did not occur');}
function rxCheck(bool $ok,string $label): void {waCheck($ok,$label);echo "PASS | $label\n";}
$time=1700000000;
$cid=$model->registrarMensajeEntrada($company,'527770090001','Sintetico','rx-1','TEXT','newest',$time);
$model->registrarMensajeEntrada($company,'527770090001','Sintetico','rx-1','TEXT','newest',$time);
$row=rxRow($db,"SELECT no_leidos,ultimo_mensaje_preview,ventana_servicio_hasta FROM whatsapp_conversaciones WHERE id=$cid");
rxCheck((int)$row['no_leidos']===1,'duplicate WAMID increments unread only once');
$model->registrarMensajeEntrada($company,'527770090001','Sintetico','rx-2','TEXT','older',$time-3600);
$after=rxRow($db,"SELECT no_leidos,ultimo_mensaje_preview,ventana_servicio_hasta FROM whatsapp_conversaciones WHERE id=$cid");
rxCheck((int)$after['no_leidos']===2 && $after['ultimo_mensaje_preview']==='newest' && $after['ventana_servicio_hasta']===$row['ventana_servicio_hasta'],'late entry preserves newest preview and window');
rxExpectError(fn()=>$model->registrarMensajeEntrada($company,'527770090002','Sintetico','rx-1','TEXT','conflict',$time));
rxCheck((int)rxRow($db,"SELECT COUNT(*) n FROM whatsapp_mensajes WHERE wamid='rx-1'")['n']===1,'cross-conversation WAMID rejected');
$db->query("CREATE TRIGGER rx_fail BEFORE UPDATE ON whatsapp_conversaciones FOR EACH ROW BEGIN IF NEW.no_leidos>OLD.no_leidos THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic rollback'; END IF; END");
try {rxExpectError(fn()=>$model->registrarMensajeEntrada($company,'527770090001','Sintetico','rx-rollback','TEXT','rollback',$time+1));}
finally {$db->query('DROP TRIGGER rx_fail');}
rxCheck((int)rxRow($db,"SELECT COUNT(*) n FROM whatsapp_mensajes WHERE wamid='rx-rollback'")['n']===0 && (int)rxRow($db,"SELECT no_leidos FROM whatsapp_conversaciones WHERE id=$cid")['no_leidos']===2,'summary failure rolls back message');
$model->registrarMensajeEntrada($company,'527770090001','Sintetico','rx-rollback','TEXT','retry',$time+1);
rxCheck((int)rxRow($db,"SELECT no_leidos FROM whatsapp_conversaciones WHERE id=$cid")['no_leidos']===3,'retry after rollback commits once');
$payload='{"synthetic":"rx-event"}';
$eid=$model->registrarEventoWebhook($payload,'MESSAGE');
$model->finalizarEventoWebhook($eid,'synthetic failure');
$model->liberarEventoWebhook();
rxCheck($model->registrarEventoWebhook($payload,'MESSAGE')===$eid,'failed webhook is retried with same event id');
$db2=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),$schema,(int)getenv('MYSQL_PORT'));
$otherModel=new WhatsAppModel($db2);
rxExpectError(fn()=>$otherModel->registrarEventoWebhook($payload,'MESSAGE'));
$otherModel->liberarEventoWebhook();
rxCheck((int)rxRow($db,"SELECT COUNT(*) n FROM whatsapp_webhook_eventos WHERE id=$eid")['n']===1,'second connection cannot acquire same event');
$model->finalizarEventoWebhook($eid);
$model->liberarEventoWebhook();
rxCheck($otherModel->registrarEventoWebhook($payload,'MESSAGE')===0,'processed replay ignored');
$otherModel->liberarEventoWebhook();$db2->close();
$model->registrarMensajeSalida($cid,22,'synthetic status',['ok'=>true,'wamid'=>'rx-out'],'TEXT');
$model->actualizarEstadoMensaje('rx-out','read',$time+10);
$model->actualizarEstadoMensaje('rx-out','delivered',$time+5);
$model->actualizarEstadoMensaje('rx-out','sent',$time);
$model->actualizarEstadoMensaje('rx-out','failed',$time);
rxCheck(rxRow($db,"SELECT estado FROM whatsapp_mensajes WHERE wamid='rx-out'")['estado']==='LEIDO','late statuses do not downgrade read');
$model->actualizarEstadoMensaje('rx-1','read',$time+10);
rxCheck(rxRow($db,"SELECT estado FROM whatsapp_mensajes WHERE wamid='rx-1'")['estado']==='RECIBIDO','status cannot overwrite inbound message');
echo "RECEPCION_SQL_OK\n";
