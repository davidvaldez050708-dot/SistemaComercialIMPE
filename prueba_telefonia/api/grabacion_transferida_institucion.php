<?php
/**
 * Analista: dos tramos de la SALIENTE Recepción -> Institución.
 * No altera grabacion_transferida_pbx.php, usada en las entrantes normales
 * y en los tramos de Recepción/Ventas.
 */
declare(strict_types=1);
session_start();
$root=dirname(__DIR__,2);
require_once $root.'/config/db_connection.php';
require_once $root.'/app/helpers/PermissionHelper.php';
require_once $root.'/app/models/RolModel.php';
require_once $root.'/app/services/ZadarmaRecordingService.php';
require_once $root.'/app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);exit;}
$uid=(int)($_SESSION['usuario_id']??0);
if($uid<=0){http_response_code(401);exit;}
$roles=new RolModel();$roles->inicializarPermisosSistema();
$_SESSION['permisos']=$roles->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id']??0));
if(strcasecmp(trim((string)($_SESSION['rol']??'')),'Analista de Datos')!==0||
   !tienePermiso('telefonia.usar')||!tienePermiso('telefonia.recibir')||
   !tienePermiso('seguimientos_vinculacion.operar_propios')){
    http_response_code(403);exit;
}
$pbx=trim((string)($_GET['pbx_call_id']??''));
$seguimiento=filter_var($_GET['seguimiento_id']??'0',FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0]]) ;
if(!preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx)||$seguimiento===false){
    http_response_code(422);exit;
}
$range=trim((string)($_SERVER['HTTP_RANGE']??''));
if($range!==''&&!preg_match('/^bytes=\d*-\d*$/D',$range)){
    http_response_code(416);exit;
}
try{
    $db=(new Database())->connect();
    $q=$db->prepare("SELECT extension FROM telefonia_extensiones WHERE usuario_id=?
      AND proveedor='ZADARMA' AND activo=1 AND permite_entrantes=1 LIMIT 1");
    $q->bind_param('i',$uid);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    if(!$row)throw new DomainException('EXTENSION_NO_ACREDITADA');
    $provider=new ZadarmaRecordingService(false);
    $svc=new TelefoniaGrabacionesTransferenciaInstitucionService($db);
    $result=$svc->resolver($pbx,$uid,(string)$row['extension'],(int)$seguimiento,
        [$provider,'obtenerGrabacionPorCallId']);
    if(isset($_GET['verificar'])){
        unset($result['records']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result,JSON_UNESCAPED_UNICODE);
        exit;
    }
    $index=filter_var($_GET['segmento']??null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0,'max_range'=>7]]);
    if($index===null||$index===false||!isset($result['records'][$index])){
        http_response_code(404);exit;
    }
    $audio=$provider->descargarGrabacion((string)$result['records'][$index]['link'],$range);
    $body=(string)($audio['body']??'');
    $type=strtolower(trim((string)($audio['content_type']??'')));
    if($body===''||($type!==''&&!str_starts_with($type,'audio/')&&
        $type!=='application/octet-stream')){http_response_code(502);exit;}
    $mime=str_contains($type,'wav')?'audio/wav':
        (str_contains($type,'ogg')?'audio/ogg':
        (str_contains($type,'mp4')?'audio/mp4':'audio/mpeg'));
    header('Content-Type: '.$mime);
    header('Accept-Ranges: bytes');
    if((string)($_GET['download']??'')==='1'){
        $ext=$mime==='audio/wav'?'wav':($mime==='audio/ogg'?'ogg':
            ($mime==='audio/mp4'?'m4a':'mp3'));
        header('Content-Disposition: attachment; filename="transferencia-institucion-tramo-'
            .($index+1).'.'.$ext.'"');
    }else header('Content-Disposition: inline');
    $size=strlen($body);
    $code=(int)($audio['status']??200);
    $headers=is_array($audio['headers']??null)?$audio['headers']:[];
    if($range!==''&&$code===206&&preg_match('~^bytes \d+-\d+/\d+$~',
            (string)($headers['content-range']??''))){
        http_response_code(206);header('Content-Range: '.$headers['content-range']);
    }elseif($range!==''){
        // Un upstream sin Range se rebanará localmente; nunca devolver
        // bytes incorrectos con Content-Range de una descarga completa.
        preg_match('/^bytes=(\d*)-(\d*)$/',$range,$m);
        $from=$m[1]??'';$to=$m[2]??'';
        if(!$size||($from===''&&$to==='')){http_response_code(416);exit;}
        if($from===''){
            if((int)$to<=0){http_response_code(416);exit;}
            $start=max(0,$size-(int)$to);$end=$size-1;
        }else{
            $start=(int)$from;$end=$to===''?$size-1:min((int)$to,$size-1);
        }
        if($start>=$size||$end<$start){http_response_code(416);exit;}
        $body=substr($body,$start,$end-$start+1);
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    header('Content-Length: '.strlen($body));
    echo $body;
}catch(DomainException $e){
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'estado'=>'ACCESO_NO_AUTORIZADO']);
}catch(Throwable $e){
    error_log('[grabacion_institucion_transferida] '.get_class($e));
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'estado'=>'ERROR_TEMPORAL']);
}
