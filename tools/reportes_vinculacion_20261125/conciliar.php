<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function outReport(string $stage,array $data): void {echo json_encode(['etapa'=>$stage]+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
$db=null;$started=false;$status=0;
try {
    $opt=getopt('',['site:','fecha:','actor:']);$site=realpath((string)($opt['site']??''));
    $day=(string)($opt['fecha']??'');$actor=filter_var($opt['actor']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $mexico=new DateTimeZone('America/Mexico_City');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,$mexico);
    if(!$site||!$date||$date->format('Y-m-d')!==$day||$actor===false)throw new InvalidArgumentException('Argumentos inválidos');
    require_once $site.'/app/services/SeguimientoReporteAnaliticaService.php';
    $db=(new Database())->connect(false);$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);$started=true;
    $reflection=new ReflectionClass(SeguimientoReporteAnaliticaService::class);$service=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('connection')->setValue($service,$db);
    $repoRows=static function(string $sql,array $args=[])use($db): array {$s=$db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;};
    $ids=array_map('intval',array_column($repoRows('SELECT id FROM seguimientos_vinculacion WHERE activo=1 ORDER BY nombre_entidad,id'),'id'));
    $limited=$reflection->getMethod('normalizarIds')->invoke($service,$ids);
    $raw=$repoRows("SELECT COUNT(*) llamadas,COUNT(DISTINCT seguimiento_id) instituciones FROM interacciones_vinculacion WHERE usuario_id=? AND canal IN ('LLAMADA','LLAMADA_IP') AND fecha_inicio BETWEEN ? AND ?",[$actor,$day.' 00:00:00',$day.' 23:59:59']);
    outReport('base',['actor'=>$actor,'fecha'=>$day,'expedientes_activos'=>count($ids),'expedientes_que_conserva_el_servicio'=>count($limited),'interacciones_directas'=>$raw[0]??[],
        'service_sha256'=>hash_file('sha256',$site.'/app/services/SeguimientoReporteAnaliticaService.php')]);
    foreach(['con_recorte'=>$limited,'sin_recorte'=>$ids] as $name=>$scope){
        foreach(['obtenerResumenInteracciones','obtenerRendimientoTelefonicoDiario','obtenerActividadPorActor'] as $method){
            try {
                $args=$method==='obtenerActividadPorActor'?[$scope,$day,$day,'',[$actor]]:[$scope,$day,$day,$actor,'administrador','',[$actor]];
                $result=$reflection->getMethod($method)->invokeArgs($service,$args);
                outReport($name.':'.$method,['resultado'=>$result]);
            }catch(Throwable $e){outReport($name.':'.$method,['error'=>get_class($e),'codigo'=>(int)$e->getCode(),'detalle'=>mb_substr($e->getMessage(),0,240)]);}
        }
    }
    try {
        $r=new ReflectionClass(SeguimientoAtencionOperativaService::class);$attention=$r->newInstanceWithoutConstructor();$r->getProperty('connection')->setValue($attention,$db);
        outReport('atencion',['casos'=>count($attention->obtenerPorIds($limited))]);
    }catch(Throwable $e){outReport('atencion',['error'=>get_class($e),'codigo'=>(int)$e->getCode(),'detalle'=>mb_substr($e->getMessage(),0,240)]);}
    $utc=new DateTimeZone('UTC');$from=$date->setTimezone($utc)->format('Y-m-d H:i:s');$until=$date->modify('+1 day')->setTimezone($utc)->modify('-1 second')->format('Y-m-d H:i:s');
    $exists=$repoRows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_analista_entrantes'");
    if($exists)outReport('telefonia',['registros'=>$repoRows("SELECT estado,COUNT(*) cantidad,SUM(interaccion_id IS NOT NULL AND interaccion_id>0) vinculadas FROM telefonia_analista_entrantes WHERE usuario_id=? AND created_at BETWEEN ? AND ? GROUP BY estado",[$actor,$from,$until])]);
    outReport('fin',['solo_lectura'=>true,'telefonos_observaciones_y_nombres_institucionales_omitidos'=>true]);
}catch(Throwable $e){outReport('error',['clase'=>get_class($e),'codigo'=>(int)$e->getCode()]);$status=1;}
finally{if($started&&$db instanceof mysqli)$db->rollback();}
exit($status);
