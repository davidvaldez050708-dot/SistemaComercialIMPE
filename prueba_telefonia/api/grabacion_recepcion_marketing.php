<?php
/**
 * Reproductor privado de recepción de Marketing. No recibe URLs externas.
 * La grabación debe corresponder a una llamada realmente atendida por la
 * extensión asignada, no transferida y dentro del período de asignación.
 */
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();
$root = dirname(__DIR__, 2);
require_once $root . '/config/db_connection.php';
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';
require_once $root . '/app/services/TelefoniaGrabacionSalienteMarketingService.php';
require_once $root . '/app/services/TelefoniaGrabacionSelector.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Método no permitido.');
}
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    http_response_code(401);
    exit('Sesión no activa.');
}

if (!TelefoniaRecepcionMarketingService::tienePermisos()) {
    http_response_code(403);
    exit('No tienes permiso para escuchar grabaciones de recepción.');
}
$tipo = strtolower(trim((string)($_GET['tipo'] ?? 'entrante')));
if (!in_array($tipo, ['entrante','saliente'], true)) {
    http_response_code(422);
    exit('Tipo de llamada inválido.');
}
if ($tipo === 'saliente' && !tienePermiso('telefonia.salientes')) {
    http_response_code(403);
    exit('No tienes permiso para escuchar grabaciones salientes.');
}
$pbxCallId = trim((string)($_GET['pbx_call_id'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/', $pbxCallId)) {
    http_response_code(422);
    exit('Identificador de llamada no válido.');
}
$rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($rangeHeader !== '' && !preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
    http_response_code(416);
    header('Accept-Ranges: bytes');
    exit;
}

try {
    $db=Database::compartida();
    $recepcion = new TelefoniaRecepcionMarketingService($db);
    $asignacion = $recepcion->asignacion($usuarioId);
    if (!$asignacion) {
        http_response_code(403);
        exit('No tienes una extensión de recepción activa.');
    }
    $extension = $asignacion['extension'];
    // La misma política del historial se aplica también al enlace directo,
    // tanto al verificar como al reproducir o descargar.
    $permitidaPorHistorial = false;
    foreach ($recepcion->resumen($usuarioId)['llamadas'] as $call) {
        if ($call['pbx_call_id'] === $pbxCallId &&
            $call['tipo'] === ($tipo === 'saliente' ? 'Saliente' : 'Entrante') &&
            !empty($call['finalizada']) && !empty($call['tiene_grabacion']) &&
            empty($call['excluir_grabacion'])) {
            $permitidaPorHistorial = true;
            break;
        }
    }
    if (!$permitidaPorHistorial) {
        http_response_code(404);
        exit('Esta llamada no tiene una grabación disponible en recepción.');
    }
    $cutoff=max((string)$asignacion['asignada_desde'],date('Y-m-d H:i:s',strtotime('-30 days')));
    $events=array_values(array_filter((new ZadarmaWebhookEventStoreService($db))->obtenerPorPbxCallId($pbxCallId),
        static fn(array $event):bool=>(string)($event['received_at']??'')>=$cutoff));
    $raw=array_map(static function(array $event):array{$event['evento']=$event['event']??$event['evento']??'';return $event;},$events);
    $facts=TelefoniaPbxSegmentosService::hechos($raw,$extension);

    // El historial ya releyó usuario, rol, extensión y fecha de asignación.
    // Corroborar el mismo tramo con el lote filtrado, sin otra conexión ni
    // consultas de autorización paralelas con fechas diferentes.
    if($tipo==='entrante'){
        $ownStart=false;
        foreach($raw as $event)if($event['evento']==='NOTIFY_INTERNAL'&&(string)($event['internal']??'')===$extension)$ownStart=true;
        if(!$ownStart||!$facts['finalizada']||$facts['answer_at']===''||$facts['transferida_a']!==''){
            http_response_code(404);exit('No hay una grabación de recepción disponible para esta llamada.');
        }
    }elseif(empty($asignacion['permite_salientes'])||!TelefoniaGrabacionSalienteMarketingService::eventosAutorizados($events,$extension)){
        http_response_code(404);exit('No hay una grabación saliente autorizada para esta llamada.');
    }

    $ids=$facts['segmentos'];
    if((string)($_GET['verificar']??'')==='1'){
        $info=[];$temporary=false;
        if($ids){
            $service=new ZadarmaRecordingService(false);
            foreach($ids as $i=>$id)try{
                $record=$service->obtenerGrabacionPorCallId($id);
                if(!empty($record['link']))$info[]=array_replace($facts['segmentos_info'][$i]??[],['indice'=>$i,'referencia'=>(string)$id]);
            }catch(Throwable $e){$temporary=true;}
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>true,'disponible'=>(bool)$info,'segmentos'=>count($info),'segmentos_info'=>$info,
            'catalogo'=>TelefoniaGrabacionSelector::catalogo($ids),
            'estado'=>$info?(count($info)<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':'TRAMO_PENDIENTE_VINCULACION'))]);
        exit;
    }
    try{$index=TelefoniaGrabacionSelector::indice($ids,$_GET);}
    catch(DomainException $e){http_response_code($e->getCode());exit($e->getMessage());}
    $service=new ZadarmaRecordingService(false);
    $recording=$service->obtenerGrabacionPorCallId($ids[$index]);
    if(!$recording||empty($recording['link'])){
        http_response_code(404);exit('La grabación todavía no está disponible en Zadarma.');
    }

    $audio = $service->descargarGrabacion((string)$recording['link'], $rangeHeader);
    $body = (string)($audio['body'] ?? '');
    if ($body === '') {
        http_response_code(404);
        exit('La grabación todavía no está disponible.');
    }

    $contentType = strtolower(trim((string)($audio['content_type'] ?? '')));
    // Solo admitir audio: no devolver contenido HTML del proveedor como si
    // fuera una grabación reproducible.
    if (
        $contentType !== '' &&
        strpos($contentType, 'audio/') !== 0 &&
        strpos($contentType, 'application/octet-stream') !== 0
    ) {
        throw new RuntimeException('Zadarma no devolvió un archivo de audio.');
    }
    $extensionArchivo = 'mp3';
    if (strpos($contentType, 'wav') !== false) $extensionArchivo = 'wav';
    if (strpos($contentType, 'ogg') !== false) $extensionArchivo = 'ogg';

    $disposition = (int)($_GET['download'] ?? 0) === 1
        ? 'attachment'
        : 'inline';
    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'audio/mpeg'));
    header('Accept-Ranges: bytes');
    header(
        'Content-Disposition: ' . $disposition .
        '; filename="llamada-' . $tipo . '-' . $pbxCallId . '.' . $extensionArchivo . '"'
    );
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    $headersProveedor = is_array($audio['headers'] ?? null)
        ? $audio['headers']
        : [];
    if (
        $rangeHeader !== '' &&
        (int)($audio['status'] ?? 200) === 206 &&
        !empty($headersProveedor['content-range'])
    ) {
        http_response_code(206);
        header('Content-Range: ' . $headersProveedor['content-range']);
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    // Si Zadarma ignoró Range, devolver igualmente la parte solicitada
    // para que funcionen la barra de avance y la reproducción en navegador.
    if ($rangeHeader !== '') {
        $size = strlen($body);
        preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches);
        $inicio = $matches[1] ?? '';
        $fin = $matches[2] ?? '';
        if ($size === 0 || ($inicio === '' && $fin === '')) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($inicio === '') {
            $suffix = (int)$fin;
            if ($suffix <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int)$inicio;
            $end = $fin === '' ? $size - 1 : min((int)$fin, $size - 1);
        }

        if ($start >= $size || $end < $start) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $part = substr($body, $start, $end - $start + 1);
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . strlen($part));
        echo $part;
        exit;
    }

    header('Content-Length: ' . strlen($body));
    echo $body;
} catch (Throwable $error) {
    if (Database::responderFalloTemporal($error, false)) exit;
    error_log('[telefonia_marketing_grabacion] ' . $error->getMessage());
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
