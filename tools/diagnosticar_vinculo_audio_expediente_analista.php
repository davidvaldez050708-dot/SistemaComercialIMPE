<?php
declare(strict_types=1);
/**
 * SOLO LECTURA. Diagnóstico preciso cuando Recibidas posee dos audios
 * pero el expediente muestra "No fue posible comprobar los eventos".
 *
 * Uso:
 * IMPE_RUNTIME_ROOT=/home/..../public_html php tools/diagnosticar_vinculo_audio_expediente_analista.php 101 2026-10-22
 *
 * No imprime nombres, teléfonos, SQL con PII, enlaces de audio, PBX ni SDK.
 * Sólo consulta registros de la extensión y fecha indicada.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ext=(string)($argv[1]??'');
$day=(string)($argv[2]??'');
if(count($argv)!==3||!preg_match('/^[0-9]{3,6}$/D',$ext)||
    !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$day)){
    fwrite(STDERR,"Uso: php diagnosticar_vinculo_audio_expediente_analista.php 101 2026-10-22\n");exit(2);
}
$root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');
require_once $root.'/config/db_connection.php';
require_once $root.'/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
require_once $root.'/app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php';
$redact=static function(string $text):string{
    // Ocultar valores arbitrarios y conservar nombres de columna/tabla.
    $text=preg_replace('/(?:out|in)_[a-f0-9]{20,}/i','PBX_OCULTO',$text);
    $text=preg_replace('/sdk-[a-z0-9-]{10,}/i','SDK_OCULTO',$text);
    $text=preg_replace('/\\+?[0-9]{7,}/','NUM_OCULTO',$text);
    return mb_substr($text,0,240,'UTF-8');
};
try{
    $db=(new Database())->connect(false);
    $stmt=$db->prepare("SELECT i.id,i.seguimiento_id,i.usuario_id,
      i.proveedor_externo,i.id_externo,i.fecha_inicio
      FROM interacciones_vinculacion i
      WHERE i.canal='LLAMADA_IP' AND i.fecha_inicio >= DATE_SUB(?,INTERVAL 1 DAY)
        AND i.fecha_inicio < DATE_ADD(?,INTERVAL 2 DAY)
        AND EXISTS(SELECT 1 FROM telefonia_extensiones t
                   WHERE t.usuario_id=i.usuario_id AND t.extension=BINARY ?
                     AND t.proveedor='ZADARMA')
        AND UPPER(COALESCE(i.proveedor_externo,'')) IN ('ZADARMA','ZADARMA_WEBRTC')
      ORDER BY i.id DESC LIMIT 12");
    $stmt->bind_param('sss',$day,$day,$ext);$stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $svc=new TelefoniaGrabacionExpedienteTransferidaService($db);
    $verified=new TelefoniaGrabacionesTransferenciaInstitucionService($db);
    $out=['version'=>'solo_lectura_1','extension'=>$ext,'dia'=>$day,'total'=>count($rows),'llamadas'=>[]];
    foreach($rows as $i){
        $item=['hora_registro'=>substr((string)$i['fecha_inicio'],0,16),
            'proveedor'=>(string)$i['proveedor_externo'],
            'id_registro'=>(int)$i['id'],
            'tiene_seguimiento'=>(int)$i['seguimiento_id']>0,
            'tipo_identificador'=>str_starts_with((string)$i['id_externo'],'WEBRTC-')?'WEBRTC':(
                str_starts_with((string)$i['id_externo'],'out_')?'PBX_SALIENTE':'OTRO')];
        try{
            $result=$svc->inspeccionar((int)$i['usuario_id'],(int)$i['seguimiento_id'],(int)$i['id']);
            $item['inspeccionar_estado']=$result['estado']??'SIN_ESTADO';
            $item['segmentos']=(int)($result['segmentos']??0);
            $item['tipo_audio']=$result['tipo_audio']??null;
        }catch(Throwable $e){
            $item['inspeccionar_estado']='ERROR';
            $item['error_clase']=get_class($e);
            $item['error_codigo']=(string)$e->getCode();
            $item['error_detalle']=$redact($e->getMessage());
        }
        $sid=TelefoniaGrabacionExpedienteTransferidaService::sessionIdExterno((string)$i['id_externo']);
        if($sid!==null){
            try {
                $s=$db->prepare("SELECT session_id,extension,clasificacion,fecha_atendida
                    FROM telefonia_analista_webrtc_sesiones
                    WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=? LIMIT 4");
                $s->bind_param('iii',$i['usuario_id'],$i['seguimiento_id'],$i['id']);$s->execute();
                $sessions=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
                $item['sesiones_asociadas']=count($sessions);
                $item['sdk_exacto_asociado']=count(array_filter($sessions,static fn($v)=>$v['session_id']===$sid));
                $item['sesion_respondida']=count(array_filter($sessions,static fn($v)=>!empty($v['fecha_atendida'])));
            }catch(Throwable $e){
                $item['error_sesiones']=$redact($e->getMessage());
            }
        }
        try{
            $s=$db->prepare("SELECT pbx_call_id,extension FROM telefonia_analista_entrantes
                WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=? LIMIT 4");
            $s->bind_param('iii',$i['usuario_id'],$i['seguimiento_id'],$i['id']);$s->execute();
            $linked=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            $item['referencias_pbx_clasificadas']=count($linked);
        }catch(Throwable $e){$item['error_pbx_clasificado']=$redact($e->getMessage());}
        // Sólo contar candidatos de la MISMA ruta institucional y destino.
        try {
            $s=$db->prepare("SELECT o.referencia,o.destino_extension,
                     o.sdk_consulta,o.estado,o.entregada_at,o.destino_atendida,d.ruta_json
                FROM telefonia_transferencia_operaciones o
                JOIN telefonia_disponibilidad d
                  ON d.referencia=BINARY o.referencia
                 AND d.destino_usuario=o.destino_usuario
                 AND d.origen_usuario=o.origen_usuario
                 AND d.destino_extension=BINARY o.destino_extension
                 AND d.origen_extension=BINARY o.origen_extension
                WHERE o.destino_usuario=? AND o.destino_extension=BINARY ?
                  AND o.created_at >= DATE_SUB(?,INTERVAL 2 DAY)
                  AND o.created_at < DATE_ADD(?,INTERVAL 3 DAY)
                ORDER BY o.id DESC LIMIT 18");
            $s->bind_param('isss',$i['usuario_id'],$ext,$day,$day);$s->execute();
            $candidate=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            $matched=0;$followupMatched=0;$sdkMatched=0;$withRecords=0;$routeUnassigned=0;
            foreach($candidate as $op){
                $route=json_decode((string)$op['ruta_json'],true)?:[];
                if(($route['tipo']??'')!=='INSTITUCION')continue;
                ++$matched;
                if((int)($route['seguimiento_id']??0)>0 &&
                   (int)($route['seguimiento_id']??0)===(int)$i['seguimiento_id'])++$followupMatched;
                if((int)($route['seguimiento_id']??0)===0)++$routeUnassigned;
                if($sid!==null&&TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(
                    (string)($op['sdk_consulta']??''),$sid))++$sdkMatched;
                // Limitar las consultas del diagnóstico: sólo verificar
                // operaciones con el seguimiento correcto o ruta sin
                // seguimiento, o con SDK vinculado inequívocamente.
                if((int)($route['seguimiento_id']??0)===(int)$i['seguimiento_id'] ||
                   (int)($route['seguimiento_id']??0)===0 ||
                   ($sid!==null&&TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(
                       (string)($op['sdk_consulta']??''),$sid))){
                    try{
                        $proof=$verified->verificar((string)$op['referencia'],
                            (int)$i['usuario_id'],$ext,0);
                        if(count($proof['ids'])>=2)++$withRecords;
                    }catch(Throwable $ignore){}
                }
            }
            $item['operaciones_institucionales_cercanas']=$matched;
            $item['operaciones_mismo_seguimiento']=$followupMatched;
            $item['operaciones_sin_seguimiento_en_ruta']=$routeUnassigned;
            $item['operaciones_con_sdk_exacto']=$sdkMatched;
            $item['operaciones_con_dos_records']=$withRecords;
        }catch(Throwable $e){$item['error_contexto']=$redact($e->getMessage());}
        $out['llamadas'][]=$item;
    }
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: ".get_class($e)." | ".$redact($e->getMessage())."\n");
    exit(1);
}
