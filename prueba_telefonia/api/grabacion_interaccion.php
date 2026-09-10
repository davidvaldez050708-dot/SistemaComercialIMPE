<?php
session_start();

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/models/SeguimientoVinculacionModel.php';
require_once $root . '/app/services/TwilioRecordingService.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/config/db_connection.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Sesión no activa.');
}

if (!isset($_SESSION['permisos'])) {
    $modeloRol = new RolModel();
    $modeloRol->inicializarPermisosSistema();
    $_SESSION['permisos'] = $modeloRol->obtenerCodigosPermisosPorRol(
        (int)($_SESSION['rol_id'] ?? 0)
    );
}

if (!tienePermiso('seguimientos_vinculacion.ver')) {
    http_response_code(403);
    exit('No tienes permiso para consultar esta grabación.');
}

$interaccionId = (int)($_GET['interaccion_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($interaccionId <= 0 || $usuarioId <= 0) {
    http_response_code(422);
    exit('Interacción no válida.');
}

$database = new Database();
$connection = $database->connect();
$sql = "SELECT
            id,
            seguimiento_id,
            canal,
            resultado,
            notas,
            proveedor_externo,
            id_externo,
            duracion_segundos
        FROM interacciones_vinculacion
        WHERE id = ?
        LIMIT 1";
$stmt = $connection->prepare($sql);
$stmt->bind_param('i', $interaccionId);
$stmt->execute();
$interaccion = $stmt->get_result()->fetch_assoc() ?: null;

if (!$interaccion || strtoupper((string)$interaccion['canal']) !== 'LLAMADA_IP') {
    http_response_code(404);
    exit('La llamada solicitada no existe.');
}

$seguimientoId = (int)$interaccion['seguimiento_id'];
$modelo = new SeguimientoVinculacionModel();
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($rolId === 1) {
    $seguimiento = $modelo->obtenerSeguimientoAdministrador($seguimientoId);
} elseif (tienePermiso('seguimientos_vinculacion.supervisar')) {
    $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
} else {
    $seguimiento = $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
}

if (!$seguimiento) {
    http_response_code(403);
    exit('No tienes acceso a esta grabación.');
}

$proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
$idExterno = trim((string)($interaccion['id_externo'] ?? ''));
$duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));
$resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
$notas = (string)($interaccion['notas'] ?? '');
$excluirGrabacion =
    strpos($notas, '[BUZON_VOZ]') !== false ||
    strpos($notas, '[FUERA_SERVICIO]') !== false ||
    in_array($resultado, ['NO_CONTESTO', 'SIN_RESPUESTA', 'NUMERO_INCORRECTO'], true);

if ($excluirGrabacion) {
    http_response_code(404);
    exit('Esta llamada se conserva únicamente en el historial telefónico.');
}

if ($duracion <= 0) {
    http_response_code(404);
    exit('Esta llamada no tiene una grabación asociada.');
}

$rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($rangeHeader !== '' && !preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
    http_response_code(416);
    header('Accept-Ranges: bytes');
    exit;
}

$forzarDescarga = (int)($_GET['download'] ?? 0) === 1;

try {
    $audio = null;

    if ($proveedor === 'TWILIO') {
        if (!preg_match('/^CA[a-fA-F0-9]{32}$/', $idExterno)) {
            http_response_code(404);
            exit('Esta llamada no tiene una grabación asociada.');
        }

        $servicio = new TwilioRecordingService();
        $grabacion = $servicio->obtenerGrabacionParaLlamada($idExterno);

        if (!$grabacion || empty($grabacion['sid'])) {
            http_response_code(404);
            exit('La grabación todavía no está disponible.');
        }

        $audio = $servicio->descargarGrabacion($grabacion['sid'], $rangeHeader);
    } elseif ($proveedor === 'ZADARMA') {
        if (!preg_match('/^out_[a-fA-F0-9]{32,64}$/', $idExterno)) {
            http_response_code(404);
            exit('Esta llamada no tiene una grabación asociada.');
        }

        $servicio = new ZadarmaRecordingService();
        $grabacion = $servicio->obtenerGrabacionParaLlamada($idExterno);

        if (!$grabacion || empty($grabacion['link'])) {
            http_response_code(404);
            exit('La grabación todavía no está disponible.');
        }

        $audio = $servicio->descargarGrabacion((string)$grabacion['link'], $rangeHeader);
    } else {
        http_response_code(404);
        exit('Esta llamada no tiene una grabación asociada.');
    }

    $body = (string)($audio['body'] ?? '');
    $contentType = trim((string)($audio['content_type'] ?? ''));
    $statusUpstream = (int)($audio['status'] ?? 200);
    $headersUpstream = is_array($audio['headers'] ?? null) ? $audio['headers'] : [];

    if ($body === '') {
        http_response_code(404);
        exit('La grabación todavía no está disponible.');
    }

    $extension = 'mp3';
    $contentTypeLower = strtolower($contentType);
    if (str_contains($contentTypeLower, 'wav')) {
        $extension = 'wav';
    } elseif (str_contains($contentTypeLower, 'ogg')) {
        $extension = 'ogg';
    }

    $nombreArchivo = 'llamada-' . $interaccionId . '.' . $extension;

    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'audio/mpeg'));
    header('Accept-Ranges: bytes');
    header(
        'Content-Disposition: ' . ($forzarDescarga ? 'attachment' : 'inline') .
        '; filename="' . $nombreArchivo . '"'
    );
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');

    if (
        $rangeHeader !== '' &&
        $statusUpstream === 206 &&
        !empty($headersUpstream['content-range'])
    ) {
        http_response_code(206);
        header('Content-Range: ' . $headersUpstream['content-range']);
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    if ($rangeHeader !== '') {
        $size = strlen($body);

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($startRaw === '') {
            $suffixLength = (int)$endRaw;
            if ($suffixLength <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int)$startRaw;
            $end = $endRaw === '' ? $size - 1 : min((int)$endRaw, $size - 1);
        }

        if ($size <= 0 || $start < 0 || $start >= $size || $end < $start) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $partial = substr($body, $start, ($end - $start) + 1);
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . strlen($partial));
        echo $partial;
        exit;
    }

    http_response_code(200);
    header('Content-Length: ' . strlen($body));
    echo $body;
} catch (Throwable $error) {
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
