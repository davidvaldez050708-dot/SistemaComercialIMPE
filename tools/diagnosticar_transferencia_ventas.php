<?php
declare(strict_types=1);
// Diagnóstico CLI de solo lectura, por extensión; sin nombres ni teléfonos.
// Uso: IMPE_RUNTIME_ROOT=/ruta/public_html php diagnosticar_transferencia_ventas.php 150
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ext=(string)($argv[1]??'');
if(count($argv)!==2||!preg_match('/^[0-9]{3,6}$/D',$ext)){
    fwrite(STDERR,"Uso: php diagnosticar_transferencia_ventas.php EXTENSION\n");exit(2);
}
try{
    $root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');
    require_once $root.'/app/services/TelefoniaVentasLlamadasService.php';
    $db=(new Database())->connect(false);
    // Leer cinco llamadas recientes del propietario histórico: nada se reconcilia o escribe.
    $q=$db->prepare("SELECT usuario_id,extension,pbx_call_id,origen_extension,fecha
        FROM telefonia_ventas_llamadas WHERE extension=?
        AND fecha>=DATE_SUB(NOW(),INTERVAL 3 DAY)
        ORDER BY fecha DESC,id DESC LIMIT 5");
    $q->bind_param('s',$ext);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $diagnostics=[];
    foreach($rows as $row){
        $ref=(string)$row['pbx_call_id'];
        $q=$db->prepare("SELECT id,evento,internal,received_at,disposition,duration,payload_json
            FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id");
        $q->bind_param('s',$ref);$q->execute();
        $events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $sequence=[];
        foreach($events as &$event){
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            $event['last_internal']=(string)($payload['last_internal']??'');
            $event['has_duration']=array_key_exists('duration',$payload);
            $sequence[]=[
                'evento'=>(string)$event['evento'],
                'internal'=>(string)$event['internal'],
                'last_internal'=>$event['last_internal'],
                'received_at'=>(string)$event['received_at']
            ];
        }unset($event);
        $facts=TelefoniaVentasLlamadasService::hechos($events,(string)$row['extension'],(string)($row['origen_extension']??''));
        $diagnostics[]=[
            'usuario_id'=>(int)$row['usuario_id'],
            'pbx_call_id'=>$ref,
            'extension'=>(string)$row['extension'],
            'origen_extension'=>(string)($row['origen_extension']??''),
            'fecha'=>(string)$row['fecha'],
            'finalizada'=>$facts['finalizada'],
            'conectada'=>$facts['conectada'],
            'estado_pbx'=>$facts['estado_pbx'],
            'eventos'=>$sequence
        ];
    }
    echo json_encode(['extension'=>$ext,'registros'=>count($diagnostics),
        'llamadas'=>$diagnostics,
        'advertencia'=>'Solo lectura. Si faltan OUT_START/ANSWER/OUT_END, verificar webhooks de Zadarma; el frontend no debe inventarlos.'],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'Diagnóstico no disponible: '.get_class($e).PHP_EOL);exit(1);
}
