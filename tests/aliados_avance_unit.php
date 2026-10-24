<?php
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../app/services/AliadoAvanceService.php';
$n=0;function avanceCheck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;echo "OK $m\n";}
$pubs=[['id'=>1,'titulo'=>'A'],['id'=>2,'titulo'=>'B'],['id'=>1,'titulo'=>'Duplicado'],['id'=>3,'titulo'=>'C']];
$events=[['convocatoria_id'=>1,'canal'=>'CORREO','estado_envio'=>'ENVIADO','enviado_at'=>'2026-10-23 12:00:00','id'=>9],['convocatoria_id'=>1,'canal'=>'CORREO','estado_envio'=>'ENVIADO','enviado_at'=>'2026-10-22 12:00:00','id'=>8],['convocatoria_id'=>1,'canal'=>'WHATSAPP_MANUAL','estado_envio'=>'COMPARTIDO','enviado_at'=>'2026-10-21 12:00:00'],['convocatoria_id'=>2,'canal'=>'CORREO','estado_envio'=>'ERROR','enviado_at'=>'2026-10-23 12:00:00'],['convocatoria_id'=>99,'canal'=>'CORREO','estado_envio'=>'ENVIADO','enviado_at'=>'2026-10-23 12:00:00']];
$a=AliadoAvanceService::combinar($pubs,$events);
avanceCheck($a['total']===3&&$a['compartidas']===1&&$a['pendientes']===2,'avance cuenta publicaciones únicas, no canales o reenvíos');
avanceCheck(count($a['convocatorias'][0]['difusion_canales'])===2,'conserva correo y WhatsApp de la misma convocatoria');
avanceCheck($a['convocatorias'][0]['difusion_canales']['CORREO']['id']===9,'fecha del último correo');
avanceCheck(!$a['convocatorias'][1]['compartida'],'un correo fallido no cuenta como compartido');
avanceCheck(AliadoAvanceService::combinar([],$events)['total']===0,'expiradas o no autorizadas no entran al denominador');
avanceCheck(AliadoAvanceService::combinar([['id'=>99]],[])['pendientes']===1,'una publicación nueva queda pendiente');
avanceCheck(!in_array('Esperando respuesta',AliadoAvanceService::RESPUESTAS,true),'respuesta sin automatismo engañoso');
echo "PASS $n comprobaciones de avance sin BD\n";
