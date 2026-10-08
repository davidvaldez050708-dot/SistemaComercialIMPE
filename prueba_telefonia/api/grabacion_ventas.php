<?php
/**
 * Transmisión privada de grabaciones Zadarma para la agenda telefónica.
 * Nunca recibe una URL externa: resuelve la grabación desde un PBX Call ID
 * que pertenece a la extensión activa del usuario autenticado.
 */
session_start();

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
$modeloRol = new RolModel();
$modeloRol->inicializarPermisosSistema();
$_SESSION['permisos'] = $modeloRol->obtenerCodigosPermisosPorRol(
    (int)($_SESSION['rol_id'] ?? 0)
);
if (
    (int)($_SESSION['rol_id'] ?? 0) !== 3 ||
    !tienePermiso('telefonia.usar') ||
    !tienePermiso('telefonia.salientes')
) {
    http_response_code(403);
    exit('No tienes permiso para escuchar grabaciones telefónicas.');
}

$pbxCallId = trim((string)($_GET['pbx_call_id'] ?? ''));
if (!preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)) {
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
    $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
    $extension = trim((string)($asignacion['extension'] ?? ''));
    if (
        !$asignacion ||
        empty($asignacion['permite_salientes']) ||
        !preg_match('/^\d{3,6}$/', $extension)
    ) {
        http_response_code(403);
        exit('No tienes una extensión activa que permita consultar grabaciones.');
    }

    // Verifica clasificación y estructura antes de consultar la grabación.
    // La existencia de audio en Zadarma no equivale a contacto humano.
    (new TelefoniaResultadoVentasService())->asegurarEstructura();
    $db = (new Database())->connect();

    // Permiso por objeto: únicamente conversación humana confirmada de
    // la extensión propia y evento de audio firmado por Zadarma.
    $consulta = $db->prepare(
        "SELECT 1
         FROM telefonia_zadarma_eventos origen
         INNER JOIN telefonia_ventas_marcaciones ventas
           ON ventas.pbx_call_id = origen.pbx_call_id
          AND ventas.extension = origen.internal
          AND ventas.usuario_id = ?
         INNER JOIN telefonia_ventas_resultados resultado
           ON resultado.pbx_call_id = origen.pbx_call_id
          AND resultado.extension = origen.internal
          AND resultado.usuario_id = ?
          AND resultado.resultado = 'CONVERSACION_PERSONA'
         WHERE origen.pbx_call_id = ?
           AND origen.internal = ?
           AND origen.evento IN ('NOTIFY_OUT_START', 'NOTIFY_OUT_END')
           AND origen.received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND EXISTS (
               SELECT 1 FROM telefonia_zadarma_eventos grab
               WHERE grab.pbx_call_id = origen.pbx_call_id
                 AND (
                     grab.evento = 'NOTIFY_RECORD'
                     OR grab.is_recorded = 1
                     OR NULLIF(grab.call_id_with_rec, '') IS NOT NULL
                 )
           )
         LIMIT 1"
    );
    if (!$consulta) {
        throw new RuntimeException('No fue posible comprobar la propiedad de la grabación.');
    }
    $consulta->bind_param('iiss', $usuarioId, $usuarioId, $pbxCallId, $extension);
    $consulta->execute();
    $autorizada = (bool)$consulta->get_result()->fetch_assoc();
    $consulta->close();

    if (!$autorizada) {
        http_response_code(404);
        exit('No hay una grabación disponible para esta llamada.');
    }

    $service = new ZadarmaRecordingService();
    $recording = $service->obtenerGrabacionParaLlamada($pbxCallId);
    if (!$recording || empty($recording['link'])) {
        http_response_code(404);
        exit('La grabación todavía no está disponible en Zadarma.');
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
    error_log('[telefonia_ventas_grabacion] ' . $error->getMessage());
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
