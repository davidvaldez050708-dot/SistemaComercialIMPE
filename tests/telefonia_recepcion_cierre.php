<?php
require_once dirname(__DIR__).'/app/services/TelefoniaRecepcionMarketingService.php';
function ok($v,$message){if(!$v)throw new RuntimeException($message);}
function e($tipo,$ext,$at,$extra=[]){return $extra+['evento'=>$tipo,'internal'=>$ext,'pbx_call_id'=>'pbx-test','caller_id'=>'5551234567','destination'=>'5551234567','received_at'=>'2026-10-18 09:00:'.$at];}
$inicio=e('NOTIFY_INTERNAL','100','00');$answer=e('NOTIFY_ANSWER','100','03');$fin=e('NOTIFY_END','100','40',['duration'=>37]);
$calls=TelefoniaRecepcionMarketingService::agruparEventos([$inicio,$answer,$fin],'100',true);
ok(TelefoniaRecepcionMarketingService::requiereResultado($calls[0]),'Conversación atendida pide resultado');
ok(!TelefoniaRecepcionMarketingService::requiereResultado($calls[0]+['resultado'=>'CONTACTO']),'Resultado guardado definitivo');
$calls=TelefoniaRecepcionMarketingService::agruparEventos([$inicio,$fin],'100',true);
ok(!TelefoniaRecepcionMarketingService::requiereResultado($calls[0]) && $calls[0]['estado']==='Perdida','Perdida se cierra sin pedir resultado');
$transfer=e('NOTIFY_INTERNAL','101','10',['payload_json'=>json_encode(['transfer_from'=>'100'])]);
$calls=TelefoniaRecepcionMarketingService::agruparEventos([$inicio,$answer,$transfer,$fin],'100',true);
ok($calls[0]['estado']==='Transferida' && !TelefoniaRecepcionMarketingService::requiereResultado($calls[0]),'Transferencia conserva destino automáticamente');
$return=e('NOTIFY_INTERNAL','100','35',['payload_json'=>json_encode(['transfer_from'=>'101'])]);
$calls=TelefoniaRecepcionMarketingService::agruparEventos([$inicio,$answer,$transfer,$return,$fin],'100',true);
ok($calls[0]['estado']==='Atendida' && $calls[0]['destino_transferencia']==='' && TelefoniaRecepcionMarketingService::requiereResultado($calls[0]),'Devolución a recepción retira transferencia y pide resultado al finalizar');
echo "Recepción: atendida, perdida, transferida, resultado definitivo y devolución: OK\n";
