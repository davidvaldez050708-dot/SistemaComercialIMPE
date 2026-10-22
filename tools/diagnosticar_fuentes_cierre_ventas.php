<?php
declare(strict_types=1);
/*
 * Sólo lectura: compara webhooks recibidos (log/BD) y estadísticas PBX oficiales.
 * No revela teléfonos, CallerID, enlaces de audio, firma ni claves API.
 * Uso: IMPE_RUNTIME_ROOT=/ruta/public_html php diagnosticar_fuentes_cierre_ventas.php 150 out_ID "2026-10-21 20:00:00" "2026-10-21 20:30:00"
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$extension=trim((string)($argv[1]??''));
$ref=trim((string)($argv[2]??''));
$from=(string)($argv[3]??'');
$to=(string)($argv[4]??'');
$validDate=static function(string $v):bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);
    return $d!==false&&$d->format('Y-m-d H:i:s')===$v;
};
if(count($argv)!==5||!preg_match('/^[0-9]{3,6}$/D',$extension)||
   !preg_match('/^(?:out|in)_[A-Za-z0-9_-]{6,100}$/D',$ref)||
   !$validDate($from)||!$validDate($to)||strcmp($to,$from)<=0||
   (strtotime($to)-strtotime($from))>6*3600){
    fwrite(STDERR,"Uso: php diagnosticar_fuentes_cierre_ventas.php EXT PBX_ID \"AAAA-MM-DD HH:MM:SS\" \"AAAA-MM-DD HH:MM:SS\" (máximo 6 horas)\n");
    exit(2);
}
$root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');
$out=['pbx_call_id'=>$ref,'extension'=>$extension,'desde'=>$from,'hasta'=>$to,
    'eventos_db'=>[],'eventos_log'=>[],'estadisticas_api'=>[],'advertencias'=>[]];
$redact=static function(array $e):array {
    return [
        'evento'=>(string)($e['evento']??$e['event']??''),
        'internal'=>(string)($e['internal']??''),
        'last_internal'=>(string)($e['last_internal']??''),
        'received_at'=>(string)($e['received_at']??''),
    ];
};
try{
    require_once $root.'/app/services/ZadarmaWebhookEventStoreService.php';
    $db=(new Database())->connect(false);
    $stmt=$db->prepare('SELECT evento,internal,received_at,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=? AND received_at>=? AND received_at<=? ORDER BY id LIMIT 150');
    $stmt->bind_param('sss',$ref,$from,$to);$stmt->execute();
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e){
        $payload=json_decode((string)($e['payload_json']??''),true)?:[];
        $e['last_internal']=$payload['last_internal']??'';
        $out['eventos_db'][]=$redact($e);
    }
    $stmt->close();
}catch(Throwable $e){
    $out['advertencias'][]='No se pudo consultar la base de eventos: '.get_class($e);
}
$logs=[$root.'/storage/zadarma_webhooks.log.1',$root.'/storage/zadarma_webhooks.log'];
$foundLog=false;
foreach($logs as $path){
    if(!is_file($path)||!is_readable($path))continue;
    $foundLog=true;$handle=fopen($path,'rb');
    if(!$handle)continue;
    while(($line=fgets($handle))!==false){
        if(strpos($line,$ref)===false)continue;
        $row=json_decode($line,true);
        if(!is_array($row)||(string)($row['pbx_call_id']??'')!==$ref)continue;
        $when=(string)($row['received_at']??'');
        // Log escribe fecha ISO-8601 local; normalizar sin exponer campos sensibles.
        try{$date=new DateTimeImmutable($when);$when=$date->format('Y-m-d H:i:s');}catch(Throwable $e){continue;}
        if($when<$from||$when>$to)continue;
        $row['received_at']=$when;
        if(count($out['eventos_log'])<150)$out['eventos_log'][]=$redact($row);
    }
    fclose($handle);
}
if(!$foundLog)$out['advertencias'][]='No hay archivo auxiliar de webhooks disponible.';
try{
    require_once $root.'/app/services/ZadarmaRecordingService.php';
    $api=new ZadarmaRecordingService(false);
    $stats=$api->estadisticasPbx($from,$to);
    $matches=array_values(array_filter($stats,static function($e) use($ref):bool {
        return is_array($e)&&hash_equals($ref,(string)($e['pbx_call_id']??''));
    }));
    foreach($matches as $item){
        $out['estadisticas_api'][]=[
            'extension'=>(string)($item['sip']??''),
            'fecha_inicio'=>(string)($item['callstart']??''),
            'disposition'=>(string)($item['disposition']??''),
            'segundos'=>isset($item['seconds'])?(int)$item['seconds']:null,
            'grabada'=>filter_var($item['is_recorded']??false,FILTER_VALIDATE_BOOLEAN)
        ];
    }
    if(!$matches)$out['advertencias'][]='La API no devolvió tramos con ese PBX ID en la ventana consultada; no implica ausencia definitiva.';
}catch(Throwable $e){
    $out['advertencias'][]='Consulta oficial Zadarma no disponible: '.get_class($e);
}
usort($out['eventos_log'],static fn($a,$b)=>strcmp($a['received_at'],$b['received_at']));
$out['interpretacion']='Comparar cierre posterior a NOTIFY_ANSWER del asesor. OUT_END previo de 100 no es cierre de 150; NOTIFY_RECORD acredita audio, no fin.';
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
