<?php
require_once __DIR__.'/../app/services/TelefoniaPbxSegmentosService.php';
function checkParts($actual,$expected,$label){if($actual!==$expected)throw new RuntimeException($label.': '.json_encode($actual));echo "OK: $label\n";}
$events=[
 ['evento'=>'NOTIFY_RECORD','internal'=>'100','call_id_with_rec'=>'recepcion-audio'],
 ['evento'=>'NOTIFY_RECORD','internal'=>'150','call_id_with_rec'=>'ventas-audio-1'],
 ['evento'=>'NOTIFY_OUT_END','internal'=>'100','last_internal'=>'150','call_id_with_rec'=>'ventas-audio-1','is_recorded'=>1],
 ['evento'=>'NOTIFY_ANSWER','internal'=>'150','call_id_with_rec'=>'ventas-audio-2'],
 ['evento'=>'NOTIFY_RECORD','internal'=>'','call_id_with_rec'=>'ventas-audio-2'],
 ['evento'=>'NOTIFY_RECORD','internal'=>'','call_id_with_rec'=>'audio-global-sin-destino'],
];
checkParts(TelefoniaPbxSegmentosService::deEventos($events,'150')['segmentos'],['ventas-audio-1','ventas-audio-2'],'Ventas conserva dos partes propias, sin Recepción ni global desconocido');
checkParts(TelefoniaPbxSegmentosService::deEventos($events,'100',['150'])['segmentos'],['recepcion-audio','ventas-audio-1','ventas-audio-2'],'Recepción autorizada conserva partes de su transferencia');
checkParts(TelefoniaPbxSegmentosService::deEventos([$events[1]],'150')['segmentos'],['ventas-audio-1'],'Una parte real sigue siendo una sola');
$events[]=['evento'=>'NOTIFY_RECORD','internal'=>'100','call_id_with_rec'=>'ventas-audio-1'];
checkParts(TelefoniaPbxSegmentosService::deEventos($events,'150')['segmentos'],['ventas-audio-2'],'Conflicto entre dos RECORD propietarios continúa bloqueado');
foreach($events as &$event)if(($event['internal']??'')==='150')$event['internal']='101';unset($event);
checkParts(TelefoniaPbxSegmentosService::deEventos($events,'101')['segmentos'],['ventas-audio-2'],'Misma autorización compartida para Analista');
checkParts(TelefoniaPbxSegmentosService::deEventos($events,'999')['segmentos'],[],'Extensión ajena no obtiene partes');
$out=[['evento'=>'NOTIFY_OUT_START','internal'=>'100'],['evento'=>'NOTIFY_ANSWER','internal'=>'101','payload_json'=>'{"transfer_from":"100"}'],['evento'=>'NOTIFY_OUT_END','internal'=>'100']];
$proof=TelefoniaPbxSegmentosService::transferencia($out,'101');
checkParts([$proof['origen'],$proof['atendida'],$proof['finalizada']],['100',true,true],'ANSWER firmado conserva origen aun sin INTERNAL ni last_internal');
array_shift($out);checkParts(TelefoniaPbxSegmentosService::transferencia($out,'101')['origen'],'','Sin inicio PBX de origen no autoriza otro tramo');
$old=[['evento'=>'NOTIFY_INTERNAL','internal'=>'101'],['evento'=>'NOTIFY_END','internal'=>'101'],['evento'=>'NOTIFY_ANSWER','internal'=>'101']];
checkParts(TelefoniaPbxSegmentosService::transferencia($old,'101')['finalizada'],false,'Cierre de un tramo anterior no finaliza una respuesta posterior');
