<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

function emitDiagnostic(string $type,array $data): void {
    echo json_encode(['tipo'=>$type]+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}
function referenceHash($value): string {
    $value=(string)$value;return $value===''?'':substr(hash('sha256',$value),0,12);
}
function diagnosticSummary(string $source,array $row): array {
    return ['fuente'=>$source,'id'=>(int)($row['id']??0),'usuario_id'=>(int)($row['usuario_id']??0),
        'extension'=>(string)($row['extension']??''),'interaccion_id'=>(int)($row['interaccion_id']??0),
        'seguimiento_id'=>(int)($row['seguimiento_id']??0),
        'clasificacion'=>(string)($row['clasificacion']??$row['estado']??''),
        'resultado'=>(string)($row['resultado']??''),'canal'=>(string)($row['canal']??''),
        'proveedor'=>(string)($row['proveedor_externo']??''),
        'fecha_guardada'=>(string)($row['fecha_inicio']??$row['created_at']??''),
        'ref_h'=>referenceHash($row['pbx_call_id']??$row['id_externo']??''),
        'sdk_h'=>referenceHash($row['session_id']??''),
        'webrtc_ref_h'=>isset($row['session_id'])?referenceHash('WEBRTC-'.$row['session_id']):'',
        'tiene_notas'=>trim((string)($row['notas']??$row['observacion']??''))!==''];
}

$db=null;$transaction=false;$stage='argumentos';$exitCode=0;
try {
    $options=getopt('',['site:','persona:','usuario:','fecha:','limite:']);
    $site=realpath((string)($options['site']??''));
    if(!$site||!is_file($site.'/app/services/SupervisionLlamadasRepository.php'))throw new InvalidArgumentException('Sitio inválido.');
    $date=(string)($options['fecha']??'');$mexico=new DateTimeZone('America/Mexico_City');$utc=new DateTimeZone('UTC');
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$mexico);
    if(!$day||$day->format('Y-m-d')!==$date)throw new InvalidArgumentException('Fecha inválida.');
    $limit=filter_var($options['limite']??12,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>50]]);
    if($limit===false)throw new InvalidArgumentException('Límite inválido.');
    if(empty($options['persona'])&&empty($options['usuario']))throw new InvalidArgumentException('Indica persona o usuario.');
    $stage='conexion_solo_lectura';
    require_once $site.'/app/services/SupervisionLlamadasRepository.php';
    require_once $site.'/app/helpers/TelefoniaFechaHoraHelper.php';
    $db=(new Database())->connect(false);
    if(!$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY))throw new RuntimeException('No se pudo iniciar transacción de sólo lectura.');
    $transaction=true;$repo=new SupervisionLlamadasRepository($db);
    emitDiagnostic('entorno',['php_zona'=>date_default_timezone_get(),'php_mexico'=>(new DateTimeImmutable('now',$mexico))->format('Y-m-d H:i:s'),
        'db'=>$repo->rows('SELECT NOW() AS ahora,UTC_TIMESTAMP() AS utc,@@session.time_zone AS zona_sesion,@@global.time_zone AS zona_global,@@system_time_zone AS zona_sistema')[0],
        'repositorio_sha256'=>hash_file('sha256',$site.'/app/services/SupervisionLlamadasRepository.php'),'transaccion'=>'READ ONLY']);
    $stage='identificar_analista';
    if(isset($options['usuario'])){
        $uid=filter_var($options['usuario'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($uid===false)throw new InvalidArgumentException('Usuario inválido.');
        $people=$repo->rows("SELECT id,CONCAT_WS(' ',nombre,apellidos) nombre,foto_perfil,estado activo FROM usuarios WHERE id=? AND rol_id=4",[$uid]);
    }else{
        $pattern='%'.preg_replace('/\s+/u','%',trim((string)$options['persona'])).'%';
        $people=$repo->rows("SELECT id,CONCAT_WS(' ',nombre,apellidos) nombre,foto_perfil,estado activo FROM usuarios WHERE rol_id=4 AND CONCAT_WS(' ',nombre,apellidos) LIKE ? ORDER BY id LIMIT 6",[$pattern]);
    }
    if(count($people)!==1){emitDiagnostic('seleccion_no_unica',['usuarios'=>array_map(static fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre']],$people)]);$db->rollback();$transaction=false;exit(2);}
    $uid=(int)$people[0]['id'];emitDiagnostic('analista',['id'=>$uid,'nombre'=>$people[0]['nombre'],'fecha'=>$date]);
    $stage='leer_llamadas';
    // Ventana amplia sólo para diagnóstico: cubre DATETIME local y UTC, sin
    // correlacionar por cercanía horaria ni por teléfono.
    $from=$day->format('Y-m-d H:i:s');
    $until=$day->modify('+1 day')->setTimezone($utc)->modify('-1 second')->format('Y-m-d H:i:s');
    $sources=['interaccion'=>['interacciones_vinculacion','fecha_inicio'],'entrante'=>['telefonia_analista_entrantes','created_at'],'sesion'=>['telefonia_analista_webrtc_sesiones','fecha_inicio']];
    $raw=[];
    foreach($sources as $source=>[$table,$column]){
        if(!$repo->tabla($table))continue;
        $rows=$repo->rows("SELECT * FROM $table WHERE usuario_id=? AND $column BETWEEN ? AND ?".
            ($source==='interaccion'?" AND canal='LLAMADA_IP'":'')." ORDER BY $column DESC,id DESC LIMIT ".($limit+1),[$uid,$from,$until]);
        emitDiagnostic('cantidad',['fuente'=>$source,'mostradas'=>min($limit,count($rows)),'truncada'=>count($rows)>$limit]);
        foreach(array_slice($rows,0,$limit) as $row)$raw[]=[$source,$row];
    }
    $stage='resolver_supervision';
    (new ReflectionMethod($repo,'precargar'))->invoke($repo,$raw,[$uid]);
    foreach($raw as [$source,$row]){
        $call=(new ReflectionMethod($repo,'normalizar'))->invoke($repo,$source,$row,$people);
        emitDiagnostic('llamada',diagnosticSummary($source,$row)+[
            'supervision'=>['key'=>$call['key'],'resultado'=>$call['resultado'],'fecha'=>$call['fecha'],
                'direccion'=>$call['direccion'],'interaccion_id'=>$call['interaccion_id'],'seguimiento_id'=>$call['seguimiento_id'],
                'ref_h'=>referenceHash($call['pbx_call_id']),'sdk_h'=>referenceHash($call['session_id']),
                'tiene_notas'=>trim($call['observacion'])!=='','tiene_institucion'=>$call['institucion']!==''],
            'fecha_si_utc'=>TelefoniaFechaHoraHelper::aMexico((string)($row['fecha_inicio']??$row['created_at']??''))]);
    }
    $relations=(new ReflectionProperty($repo,'relations'))->getValue($repo);
    foreach($relations['interacciones_vinculacion']??[] as $row)emitDiagnostic('interaccion_relacionada',diagnosticSummary('interaccion',$row));
    foreach($relations['telefonia_transferencia_operaciones']??[] as $row)emitDiagnostic('transferencia',[
        'destino_usuario'=>(int)$row['destino_usuario'],'destino_extension'=>(string)$row['destino_extension'],
        'estado'=>(string)$row['estado'],'ref_h'=>referenceHash($row['referencia']),
        'sdk_h'=>referenceHash($row['sdk_consulta']??''),'sdk_external_h'=>referenceHash(($row['sdk_consulta']??'').'-external')]);
    $stage='estructura_vinculos';
    emitDiagnostic('columnas_vinculos',['columnas'=>$repo->rows("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'telefonia%analista%' AND COLUMN_NAME IN ('pbx_call_id','session_id','sdk_session_id','sdk_reference','interaccion_id','seguimiento_id','referencia') ORDER BY TABLE_NAME,COLUMN_NAME")]);
    emitDiagnostic('fin',['solo_lectura'=>true,'telefonos_notas_y_nombres_institucion_omitidos'=>true]);
} catch(Throwable $error) {
    emitDiagnostic('error',['etapa'=>$stage,'clase'=>get_class($error),'codigo'=>(int)$error->getCode()]);$exitCode=1;
} finally {
    if($transaction&&$db instanceof mysqli)$db->rollback();
}
exit($exitCode);
