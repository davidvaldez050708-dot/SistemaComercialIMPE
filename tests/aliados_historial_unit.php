<?php
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__.'/../app/services/AliadoHistorialService.php';
$count=0;
function checkHist($v,$message){global $count;if(!$v)throw new RuntimeException($message);$count++;echo "OK $message\n";}
$row=['id'=>8,'canal'=>'CORREO','realizado_at'=>'2026-10-23 22:32:18','responsable'=>'Cuenta Clave','nota'=>'Convocatoria #13 · Bachillerato General · Campus A · Convocatoria · Octubre · contacto@example.test'];
$e=AliadoHistorialService::normalizarManual($row);
checkHist($e['convocatoria_id']===13,'identidad de convocatoria histórica');
checkHist($e['convocatoria_titulo']==='Bachillerato General · Campus A · Convocatoria · Octubre','programa y título con puntos medios íntegros');
checkHist($e['destinatario']==='contacto@example.test'&&$e['estado_envio']==='ENVIADO','correo registrado con destinatario');
checkHist($e['enviado_por_nombre']==='Cuenta Clave'&&$e['enviado_at']===$row['realizado_at'],'responsable y fecha conservados');
$e=AliadoHistorialService::normalizarManual(array_replace($row,['canal'=>'WHATSAPP']));
checkHist($e['canal']==='WHATSAPP_MANUAL'&&$e['estado_envio']==='COMPARTIDO','WhatsApp se identifica como confirmación manual');
foreach(['CORREO','WHATSAPP','OTRO'] as $canal){
 $note="Nota histórica sin convocatoria <script>prueba</script>\nSegunda línea";
 $e=AliadoHistorialService::normalizarManual(array_replace($row,['canal'=>$canal,'nota'=>$note]));
 checkHist($e['estado_envio']==='REGISTRADO'&&$e['convocatoria_id']===null&&$e['nota']===$note,'registro genérico no se presenta como envío CRM: '.$canal);
}
$e=AliadoHistorialService::normalizarManual(array_replace($row,['nota'=>'Convocatoria #13 · incompleta']));
checkHist($e['estado_envio']==='REGISTRADO','nota incompleta no inventa datos del envío');
echo "PASS $count normalizaciones históricas sin BD\n";
