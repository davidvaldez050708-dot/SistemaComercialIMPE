<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
require __DIR__.'/../app/services/TelefoniaSalienteAnalistaEstado.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$start=['event'=>'NOTIFY_OUT_START','pbx_call_id'=>'out-fixture-001','internal'=>'101','received_at'=>'2026-11-04 10:00:00','destination'=>'+525551234567','call_start'=>'2026-11-04 10:00:00'];
$answer=array_merge($start,['event'=>'NOTIFY_ANSWER','received_at'=>'2026-11-04 10:00:05']);
$end=array_merge($start,['event'=>'NOTIFY_OUT_END','received_at'=>'2026-11-04 10:00:12','disposition'=>'answered','duration'=>7]);
$read=fn($events,$ext='101',$id='out-fixture-001',$since=0)=>TelefoniaSalienteAnalistaEstado::desdeEventos($events,$ext,$id,$since);
$r=$read([$start,$answer,$end]);check($r['tipo_llamada']==='SALIENTE'&&$r['estado']==='ended'&&$r['duration']===7&&$r['caller_id']==='+525551234567','Saliente firmada conserva identidad, destino y duración');
check($read([$start,$answer,$end],'102')===null,'Otra extensión no se autoriza');
check($read([$start,$answer,$end],'101','otra-referencia')===null,'Otra referencia no se autoriza');
check($read([$start,$answer,$end],'101','out-fixture-001',strtotime('2026-11-05'))===null,'No aceptar llamada anterior a la asignación');
check($read([$answer,$end])===null,'Contestar sin OUT_START no demuestra origen saliente');
$r=$read([$start,$answer]);check($r['estado']!=='ended','No declarar finalizada sin fin PBX');
$other=array_merge($end,['pbx_call_id'=>'otra-llamada','duration'=>39]);$r=$read([$start,$answer,$other]);check($r['estado']!=='ended'&&$r['duration']===0,'No mezclar el cierre de otra llamada al mismo teléfono');
$r=$read([$start,array_merge($end,['disposition'=>'busy','duration'=>0])]);check(!$r['contestada']&&$r['duration']===0,'Ocupada no demuestra conversación');
$r=$read([array_merge($start,['event'=>'NOTIFY_INTERNAL']),$answer,$end]);check($r===null,'Transferida conserva su propio circuito entrante');
echo "PASS servidor salientes: referencia, extensión, vigencia, duración firmada, fin pendiente, ocupada y separación de transferidas\n";
