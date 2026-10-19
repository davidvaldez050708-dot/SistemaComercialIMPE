<?php
declare(strict_types=1);
// Sólo lecturas; no consulta el proveedor, no descarga audio ni concilia registros.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/services/TelefoniaVentasLlamadasService.php';
$options=getopt('',['usuario:','pbx:','limite:']);$uid=(int)($options['usuario']??0);
$limit=filter_var($options['limite']??5,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
if($uid<=0||$limit===false){fwrite(STDERR,"Uso: php tools/diagnosticar_audio_ventas.php --usuario=8 [--pbx=REFERENCIA] [--limite=5]\n");exit(2);}
$db=null;
try{
    $db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
    $calls=new TelefoniaVentasLlamadasService($db);$calls->autorizar($uid);$calls->requerir();
    $sql='SELECT pbx_call_id,extension FROM telefonia_ventas_llamadas WHERE usuario_id=?';$args=[$uid];
    if(isset($options['pbx'])){$sql.=' AND pbx_call_id=?';$args[]=(string)$options['pbx'];}
    $sql.=' ORDER BY fecha DESC,id DESC LIMIT '.(int)$limit;
    $s=$db->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$refs=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $out=['solo_lectura'=>true,'usuario_id'=>$uid,'llamadas'=>[]];
    foreach($refs as $ref){
        $c=$calls->obtener($uid,$ref['pbx_call_id'],$ref['extension']);$ui=TelefoniaVentasLlamadasService::presentacionAudio($c);
        $out['llamadas'][]=['pbx_call_id'=>$c['pbx_call_id'],'extension'=>$c['extension'],'fecha'=>$c['fecha'],
            'finalizada'=>$c['finalizada'],'conectada'=>$c['conectada'],'segundos'=>$c['segundos'],
            'resultado'=>$c['registro']['resultado']??null,'segmentos_confirmados'=>$c['segmentos'],
            'motivo'=>$c['audio_motivo'],'evidencia'=>$c['audio_evidencia'],'estado_visible'=>$ui['texto'],'evidencia_pendiente'=>$ui['detalle']];
    }
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,"No fue posible leer el diagnóstico. Revisa permisos, referencia y estructura de Ventas. No se modificaron registros.\n");exit(1);}
finally{if($db){$db->rollback();$db->close();}}
