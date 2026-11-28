<?php
/**
 * Reproducción PRIVADA de una saliente comercial cerrada por CDR cuando falta OUT_END.
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
    require_once $root.'/app/services/TelefoniaVentasGrabacionCdrService.php';
    $calls=new TelefoniaVentasLlamadasService();
    try {
        $call=$calls->obtener($usuarioId,$pbxCallId,(string)($_GET['extension']??''));
    } catch (DomainException $e) {
        http_response_code(404);
        exit('Esta llamada no pertenece a tu extensión o tiene tramos ambiguos.');
    }
    // Resolver CDR ÚNICO, exactamente del titular ya autenticado:
    // llamada, extensión, respuesta, fecha de inicio y audio grabado.
    $cdr=TelefoniaVentasGrabacionCdrService::resolver($call);
    if(!$cdr){
        http_response_code(404);
        if(($_GET['verificar']??'')==='1'){
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'disponible'=>false,
                'mensaje'=>'Zadarma aún no acredita una grabación propia verificable para esta llamada.']);
        }else{
            echo 'No se pudo verificar un audio propio para esta llamada.';
        }
        exit;
    }
    require_once $root.'/app/services/TelefoniaGrabacionSelector.php';
    try{TelefoniaGrabacionSelector::indice([(string)$cdr['call_id']],$_GET);}
    catch(DomainException $e){http_response_code($e->getCode());exit($e->getMessage());}
    $service=new ZadarmaRecordingService(false);
    $recording=$service->obtenerGrabacionPorCallId($cdr['call_id']);
    if(!$recording||empty($recording['link'])){
        http_response_code(404);
        if(($_GET['verificar']??'')==='1'){
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'disponible'=>false,
                'mensaje'=>'Zadarma reconoce la grabación, pero todavía no entrega un enlace reproducible.']);
        }else{
            echo 'Zadarma no tiene todavía listo el audio.';
        }
        exit;
    }
    if(($_GET['verificar']??'')==='1'){
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>true,'disponible'=>true,'segmentos'=>1,
            'segmentos_info'=>[['indice'=>0,'referencia'=>(string)$cdr['call_id'],'segundos'=>$cdr['seconds']]],
            'segundos'=>$cdr['seconds']]);
        exit;
    }

    $audio = $service->descargarGrabacion((string)$recording['link'], $rangeHeader);
    require_once $root.'/app/services/TelefoniaAudioRespuesta.php';
    $response=TelefoniaAudioRespuesta::respuesta($audio,$rangeHeader);
    http_response_code($response['status']);
    foreach($response['headers'] as $name=>$value)header($name.': '.$value);
    $extensionArchivo=TelefoniaAudioRespuesta::extension($response['headers']['Content-Type']??'');
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="llamada-ventas-'.$pbxCallId.'.'.$extensionArchivo.'"');
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    echo $response['body'];
} catch (Throwable $error) {
    error_log('[telefonia_ventas_grabacion] ' . $error->getMessage());
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
