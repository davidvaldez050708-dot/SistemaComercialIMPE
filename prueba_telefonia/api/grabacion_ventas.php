<?php
/**
 * Transmisión privada de grabaciones Zadarma para la agenda telefónica.
 * Nunca recibe una URL externa: resuelve la grabación desde un PBX Call ID
 * que pertenece a la extensión activa del usuario autenticado.
 */
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();

$root = dirname(__DIR__, 2);
require_once $root . '/config/db_connection.php';
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaExtensionService.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/app/services/TelefoniaResultadoVentasService.php';
require_once $root . '/app/services/TelefoniaMarcacionesVentasService.php';

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

// Releer los permisos evita conservar capacidades revocadas en la sesión.

if (
    (int)($_SESSION['rol_id'] ?? 0) !== 3 ||
    !tienePermiso('telefonia.usar') ||
    (!tienePermiso('telefonia.salientes') && !tienePermiso('telefonia.recibir'))
) {
    http_response_code(403);
    exit('No tienes permiso para escuchar grabaciones telefónicas.');
}

$pbxCallId = trim((string)($_GET['pbx_call_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbxCallId)) {
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
    require_once $root.'/app/services/TelefoniaVentasLlamadasService.php';
    require_once $root.'/app/services/TelefoniaGrabacionSelector.php';
    $db=Database::compartida();$calls=new TelefoniaVentasLlamadasService($db);
    try {$call=$calls->obtener($usuarioId,$pbxCallId,(string)($_GET['extension']??''));}
    catch(DomainException $e){http_response_code(404);exit('No hay un tramo autorizado para esta llamada.');}
    $transferida=($call['direccion']??'')==='Entrante' &&
        (string)($call['origen_extension']??'')!=='' && !empty($call['solicitud_id']);
    if(!TelefoniaVentasLlamadasService::audioConsultable($call)){
        http_response_code(404);exit('Este intento no tiene reproducción de audio habilitada.');
    }
    if($transferida){
        // El resultado humano puede guardarse sin END de Zadarma. Verificar
        // identidad y ruta comercial en la operación PBX exacta; devolver
        // tantos segmentos NOTIFY_RECORD como hayan sido corroborados.
        require_once $root.'/app/services/TelefoniaGrabacionesTransferenciaVentasService.php';
        $svc=new TelefoniaGrabacionesTransferenciaVentasService($db);
        $provider=new ZadarmaRecordingService(false);
        $service=$provider; // La descarga Range final reutiliza el mismo proveedor.
        try{
            $response=$svc->resolver($pbxCallId,$usuarioId,'destino',(string)$call['extension'],
                [$provider,'obtenerGrabacionPorCallId'],
                isset($_GET['verificar'])?[$provider,'estadisticasPbx']:null);
        }catch(DomainException $e){
            http_response_code(404);exit('No hay tramos transferidos autorizados.');
        }
        $ids=TelefoniaGrabacionSelector::referencias($response['records'],$response['segmentos_info']);
        if(isset($_GET['verificar'])){
            $response['catalogo']=TelefoniaGrabacionSelector::catalogo($ids);
            unset($response['records']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo json_encode($response,JSON_UNESCAPED_UNICODE);exit;
        }
        try{$index=TelefoniaGrabacionSelector::indice($ids,$_GET);}
        catch(DomainException $e){http_response_code($e->getCode());exit($e->getMessage());}
        $recording=$response['records'][$index];
    }else{
    $ids=$call['segmentos'];
    if(isset($_GET['verificar'])){
        $service=new ZadarmaRecordingService(false);$info=[];$temporary=false;
        foreach($ids as $i=>$id){try{$r=$service->obtenerGrabacionPorCallId($id);if(!empty($r['link']))$info[]=array_replace($call['segmentos_info'][$i]??[],['indice'=>$i,'referencia'=>(string)$id]);}catch(Throwable $e){$temporary=true;error_log('[ventas_audio] RECORD_TEMPORARY ref='.substr(hash('sha256',$pbxCallId),0,12));}}
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
        echo json_encode(['ok'=>true,'disponible'=>(bool)$info,'segmentos'=>count($info),'segmentos_info'=>$info,'catalogo'=>TelefoniaGrabacionSelector::catalogo($ids),'estado'=>$info?(count($info)<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':'TRAMO_PENDIENTE_VINCULACION'))]);exit;
    }
    try{$segment=TelefoniaGrabacionSelector::indice($ids,$_GET);}
    catch(DomainException $e){http_response_code($e->getCode());exit($e->getMessage());}
    // Resolver cada ID autorizado; nunca pedir audio global del PBX.
    $service=new ZadarmaRecordingService(false);$recording=$service->obtenerGrabacionPorCallId($ids[$segment]);
    if(!$recording||empty($recording['link'])){http_response_code(404);exit('La grabación aún no está disponible en Zadarma.');}
    } // Fin del reproductor original de llamada individual.

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
        '; filename="llamada-ventas-' . $pbxCallId . '.' . $extensionArchivo . '"'
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
    error_log('[telefonia_ventas_grabacion] ' . $error->getMessage());
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
