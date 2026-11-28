<?php
/**
 * Audio privado de una sesión INTERNA del Analista.
 * Autoriza por sesión y usuario actual, nunca acepta URLs ni IDs PBX del navegador.
 */
declare(strict_types=1);
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();
$root = dirname(__DIR__, 2);
require_once $root . '/config/db_connection.php';
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaInternaGrabacionService.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    http_response_code(401);
    exit('Sesión no activa.');
}
if (strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Analista de Datos') !== 0 ||
    !tienePermiso('telefonia.usar') || !tienePermiso('telefonia.recibir') ||
    !tienePermiso('seguimientos_vinculacion.operar_propios')) {
    http_response_code(403);
    exit('No tienes acceso a las grabaciones de esta extensión.');
}
$sessionId = trim((string)($_GET['session_id'] ?? ''));
if (!preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId)) {
    http_response_code(422);
    exit('La sesión telefónica no es válida.');
}
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/D', $range)) {
    http_response_code(416);
    header('Accept-Ranges: bytes');
    exit;
}
try {
    $db = (new Database())->connect();
    $vinculos = new TelefoniaInternaGrabacionService($db);
    $grabacion = $vinculos->obtener($usuarioId, $sessionId);
    if (!$grabacion) {
        http_response_code(404);
        exit('No existe una grabación interna asociada a tu sesión.');
    }
    $servicio = new ZadarmaRecordingService();
    $recurso = $servicio->obtenerGrabacionPorCallId((string)$grabacion['call_id_with_rec']);
    if (!$recurso) {
        http_response_code(404);
        exit('Zadarma aún no tiene disponible esta grabación.');
    }
    $audio = $servicio->descargarGrabacion((string)$recurso['link'], $range);
    require_once $root.'/app/services/TelefoniaAudioRespuesta.php';
    $response=TelefoniaAudioRespuesta::respuesta($audio,$range);
    http_response_code($response['status']);
    foreach($response['headers'] as $name=>$value)header($name.': '.$value);
    $extension=TelefoniaAudioRespuesta::extension($response['headers']['Content-Type']??'');
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="llamada-interna.'.$extension.'"');
    echo $response['body'];
} catch (Throwable $e) {
    if (Database::responderFalloTemporal($e, false)) exit;
    error_log('[grabacion_interna_analista] ' . get_class($e) . ': ' . $e->getCode());
    if (!headers_sent()) http_response_code(502);
    exit('No fue posible recuperar el audio en este momento.');
}
