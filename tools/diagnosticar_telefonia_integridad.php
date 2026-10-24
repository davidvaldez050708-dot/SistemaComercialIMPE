<?php
declare(strict_types=1);
// Sólo CLI y SELECT/SHOW. No sincroniza llamadas, crea tablas ni pide audios.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ext=(string)($argv[1]??'');
if(count($argv)!==2 || !preg_match('/^[0-9]{3,6}$/D',$ext)){
    fwrite(STDERR,"Uso: IMPE_RUNTIME_ROOT=/ruta/public_html php diagnosticar_telefonia_integridad.php EXTENSION\n");exit(2);
}
try{
    $root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');
    require_once $root.'/config/db_connection.php';
    require_once $root.'/app/services/TelefoniaRecepcionMarketingService.php';
    require_once $root.'/app/services/TelefoniaMisLlamadasService.php';
    $db=(new Database())->connect(false);
    // La sesión de diagnóstico tampoco puede escribir accidentalmente.
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    // Una referencia es propia por su inicio, nunca por coincidencia de teléfono.
    $q=$db->prepare("SELECT pbx_call_id,MAX(received_at) fecha FROM telefonia_zadarma_eventos
        WHERE internal=BINARY ? AND evento IN('NOTIFY_INTERNAL','NOTIFY_OUT_START')
          AND received_at>=DATE_SUB(NOW(),INTERVAL 3 DAY) AND pbx_call_id<>''
        GROUP BY pbx_call_id ORDER BY fecha DESC LIMIT 8");
    $q->bind_param('s',$ext);$q->execute();$starts=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $available=[];
    foreach(['telefonia_recepcion_resultados','telefonia_ventas_resultados','telefonia_transferencia_operaciones'] as $table){
        $q=$db->prepare('SHOW TABLES LIKE ?');$q->bind_param('s',$table);$q->execute();
        $available[$table]=$q->get_result()->num_rows>0;$q->close();
    }
    $output=[];
    foreach($starts as $start){
        $ref=(string)$start['pbx_call_id'];
        $q=$db->prepare('SELECT evento,pbx_call_id,internal,received_at,disposition,duration,is_recorded,call_id_with_rec,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $q->bind_param('s',$ref);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $sequence=[];$recordings=[];
        foreach($events as $e){
            $p=json_decode((string)$e['payload_json'],true)?:[];
            $sequence[]=['evento'=>$e['evento'],'extension'=>$e['internal'],
                'desde_extension'=>(string)($p['transfer_from']??''),
                'ultima_extension'=>(string)($p['last_internal']??''),
                'fecha'=>$e['received_at'],'estado'=>$e['disposition'],
                'segundos'=>(int)$e['duration']];
            if($e['evento']==='NOTIFY_RECORD' && trim((string)$e['call_id_with_rec'])!=='')
                $recordings[(string)$e['call_id_with_rec']]=true;
        }
        $calls=TelefoniaRecepcionMarketingService::agruparEventos($events,$ext,true);
        $rows=TelefoniaMisLlamadasService::normalizarMarketing($calls,1,$ext);
        $item=['referencia'=>$ref,'extension'=>$ext,
            'atendida_recepcion'=>(bool)($calls[0]['atendida']??false),
            'conectada_historial'=>(bool)($rows[0]['conectada']??false),
            'avisos_grabacion_distintos'=>count($recordings),'eventos'=>$sequence];
        foreach(['telefonia_recepcion_resultados','telefonia_ventas_resultados'] as $table){
            if(!$available[$table]){$item[$table]='TABLA_AUSENTE';continue;}
            $q=$db->prepare("SELECT extension,resultado FROM $table WHERE pbx_call_id=BINARY ?");
            $q->bind_param('s',$ref);$q->execute();$item[$table]=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        }
        if($available['telefonia_transferencia_operaciones']){
            $q=$db->prepare('SELECT origen_extension,destino_extension,modo,estado FROM telefonia_transferencia_operaciones WHERE referencia=BINARY ?');
            $q->bind_param('s',$ref);$q->execute();$item['transferencias']=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        }
        $output[]=$item;
    }
    $db->rollback();
    echo json_encode(['extension'=>$ext,'llamadas'=>$output,
        'limite'=>'Ocho referencias de los últimos tres días. Sin nombres, teléfonos, notas, credenciales ni enlaces de audio.',
        'alcance'=>'Un aviso de grabación no acredita que el archivo se pueda reproducir. Confirmar cada audio desde el CRM.'],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'Diagnóstico no disponible: '.get_class($e).'. Sin escrituras.'.PHP_EOL);exit(1);
}
