<?php
/**
 * Segmentos PBX de una entrante transferida, para participantes verificados.
 * Nunca acepta call_id_with_rec ni URLs de audio aportados por el navegador.
 */
declare(strict_types=1);
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/db_connection.php';
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/app/helpers/ZadarmaPbxIdentifierHelper.php';
require_once $root . '/app/services/TelefoniaAudioTransferidoService.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
$userId = (int)($_SESSION['usuario_id'] ?? 0);
$pbx = trim((string)($_GET['pbx_call_id'] ?? ''));
if ($userId < 1) { http_response_code(401); exit; }
if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $pbx)) { http_response_code(422); exit; }
$rol = new RolModel();
$rol->inicializarPermisosSistema();
$_SESSION['permisos'] = $rol->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id'] ?? 0));
$analista = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Analista de Datos') === 0
    && tienePermiso('telefonia.usar') && tienePermiso('telefonia.recibir')
    && tienePermiso('seguimientos_vinculacion.operar_propios');
$recepcion = TelefoniaRecepcionMarketingService::tienePermisos();
if (!$analista && !$recepcion) { http_response_code(403); exit; }
try {
    $db = (new Database())->connect();
    $q = $db->prepare("SELECT extension, GREATEST(created_at,updated_at) AS desde
        FROM telefonia_extensiones WHERE usuario_id=? AND proveedor='ZADARMA'
          AND activo=1 AND permite_entrantes=1 LIMIT 1");
    $q->bind_param('i', $userId);
    $q->execute();
    $assignment = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$assignment) { http_response_code(404); exit; }
    $ext = (string)$assignment['extension'];
    $service = new ZadarmaRecordingService(false);
    // Recepción conserva su autorización histórica. El destinatario sólo
    // obtiene el origen y su tramo tras acreditar la transferencia exacta.
    if ($recepcion) {
        $allowed=false;
        foreach ((new TelefoniaRecepcionMarketingService())->resumen($userId)['llamadas'] as $call) {
            if ($call['pbx_call_id']===$pbx && $call['estado']==='Transferida' && !empty($call['finalizada']) && empty($call['excluir_grabacion'])) {$allowed=true;break;}
        }
        if (!$allowed) throw new DomainException('ACCESO_NO_AUTORIZADO');
        // El receptor de la transferencia se toma del historial privado.
        $receiver=(string)($call['destino_transferencia']??'');
        $q=$db->prepare('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $parts=TelefoniaPbxSegmentosService::deEventos($events,$ext,[$receiver]);
        $records=[];$info=[];
        foreach($parts['segmentos'] as $i=>$id){$r=$service->obtenerGrabacionPorCallId($id);if(!empty($r['link'])){$records[$i]=$r;$info[]=($parts['segmentos_info'][$i]??[])+['indice'=>$i];}}
        $result=['ok'=>true,'estado'=>$records?'DISPONIBLE':'GRABACION_EN_PREPARACION','disponible'=>(bool)$records,'segmentos'=>count($records),'segmentos_info'=>$info];
    } else {
        $cacheKey=$ext.'|'.$pbx;$cache=$_SESSION['impe_audio_transferido'][$cacheKey]??[];
        $cached=time()-(int)($cache['at']??0)<3600?($cache['rows']??[]):[];
        $stats=isset($_GET['verificar'])&&(!$cached||time()-(int)$cache['at']>=60)?[$service,'estadisticasPbx']:null;
        $result=(new TelefoniaAudioTransferidoService($db))->resolver($pbx,$ext,(string)$assignment['desde'],[$service,'obtenerGrabacionPorCallId'],$stats,$cached);
        if($result['cache_tramos']){
            $_SESSION['impe_audio_transferido'][$cacheKey]=['at'=>$stats?time():(int)$cache['at'],'rows'=>$result['cache_tramos']];
            $_SESSION['impe_audio_transferido']=array_slice($_SESSION['impe_audio_transferido'],-20,null,true);
        }
        $records=$result['records'];
    }
    if (isset($_GET['verificar'])) {
        unset($result['ids'],$result['records'],$result['cache_tramos']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);exit;
    }
    $index = filter_var($_GET['segmento'] ?? null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0,'max_range'=>7]]);
    if ($index===false || $index===null || !isset($records[$index])) {
        http_response_code(404); exit;
    }
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range!=='' && !preg_match('/^bytes=\\d*-\\d*$/D',$range)) {
        http_response_code(416); exit;
    }
    $audio = $service->descargarGrabacion((string)$records[$index]['link'],$range);
    $body = (string)($audio['body'] ?? '');
    $type = strtolower(trim((string)($audio['content_type'] ?? '')));
    if ($body==='' || ($type!=='' && !str_starts_with($type,'audio/') &&
        $type!=='application/octet-stream')) { http_response_code(502); exit; }
    $mime = str_contains($type,'wav')?'audio/wav':
        (str_contains($type,'ogg')?'audio/ogg':
        (str_contains($type,'mp4')?'audio/mp4':'audio/mpeg'));
    header('Content-Type: '.$mime);
    if ((string)($_GET['download'] ?? '') === '1') {
        $extension = $mime === 'audio/wav' ? 'wav'
            : ($mime === 'audio/ogg' ? 'ogg'
            : ($mime === 'audio/mp4' ? 'm4a' : 'mp3'));
        header('Content-Disposition: attachment; filename="llamada-transferida-parte-'
            . ($index + 1) . '.' . $extension . '"');
    } else {
        header('Content-Disposition: inline');
    }
    header('Accept-Ranges: bytes');
    if ($range!=='' && (int)($audio['status']??200)===206 &&
        preg_match('~^bytes \\d+-\\d+/\\d+$~',
            (string)($audio['headers']['content-range']??''))) {
        header('Content-Range: '.$audio['headers']['content-range']);
        http_response_code(206);
    } else http_response_code(200);
    header('Content-Length: '.strlen($body));
    echo $body;
} catch (DomainException $error) {
    http_response_code(403);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'estado'=>'ACCESO_NO_AUTORIZADO']);exit;
} catch (Throwable $error) {
    error_log('[audio_transferido_pbx] '.get_class($error));
    if (!headers_sent()) http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'estado'=>'ERROR_TEMPORAL']);exit;
}
