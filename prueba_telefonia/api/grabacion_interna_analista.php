<?php
/**
 * Audio privado de una sesión INTERNA del Analista.
 * Autoriza por sesión y usuario actual, nunca acepta URLs ni IDs PBX del navegador.
 */
declare(strict_types=1);
session_start();
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
if (!isset($_SESSION['permisos'])) {
    $rol = new RolModel();
    $rol->inicializarPermisosSistema();
    $_SESSION['permisos'] = $rol->obtenerCodigosPermisosPorRol(
        (int)($_SESSION['rol_id'] ?? 0)
    );
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
    $vinculos->asegurarEstructura();
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
    $body = (string)($audio['body'] ?? '');
    $mime = strtolower(trim((string)($audio['content_type'] ?? '')));
    if ($body === '' ||
        preg_match('~(?:text/html|application/json|text/plain)~i', $mime)) {
        throw new RuntimeException('El proveedor no entregó un archivo de audio válido.');
    }
    // No servir HTML arbitrario bajo un MIME no confirmado.
    $mimeSeguro = 'audio/mpeg';
    if (str_contains($mime, 'wav')) {
        $mimeSeguro = 'audio/wav';
    } elseif (str_contains($mime, 'ogg')) {
        $mimeSeguro = 'audio/ogg';
    } elseif (str_contains($mime, 'mp4') || str_contains($mime, 'm4a')) {
        $mimeSeguro = 'audio/mp4';
    } elseif ($mime !== '' && !str_contains($mime, 'audio/') &&
        $mime !== 'application/octet-stream') {
        throw new RuntimeException('Formato de grabación no admitido.');
    }
    header('Content-Type: ' . $mimeSeguro);
    header('Accept-Ranges: bytes');
    $download = (int)($_GET['download'] ?? 0) === 1;
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') .
        '; filename="llamada-interna.' .
        ($mimeSeguro === 'audio/wav' ? 'wav' :
         ($mimeSeguro === 'audio/ogg' ? 'ogg' :
          ($mimeSeguro === 'audio/mp4' ? 'm4a' : 'mp3'))) . '"');
    if ($range !== '' && (int)($audio['status'] ?? 200) === 206 &&
        !empty($audio['headers']['content-range'])) {
        $contentRange = (string)$audio['headers']['content-range'];
        if (!preg_match('~^bytes \d+-\d+/\d+$~', $contentRange)) {
            throw new RuntimeException('Rango de audio inválido.');
        }
        header('Content-Range: ' . $contentRange);
        http_response_code(206);
    } else {
        http_response_code(200);
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
} catch (Throwable $e) {
    error_log('[grabacion_interna_analista] ' . get_class($e) . ': ' . $e->getCode());
    if (!headers_sent()) http_response_code(502);
    exit('No fue posible recuperar el audio en este momento.');
}
