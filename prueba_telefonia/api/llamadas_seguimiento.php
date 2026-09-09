<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/models/SeguimientoVinculacionModel.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
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
    echo json_encode(['ok' => false, 'mensaje' => 'No tienes permiso para consultar este expediente.']);
    exit;
}

$seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($seguimientoId <= 0 || $usuarioId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Seguimiento no válido.']);
    exit;
}

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
    echo json_encode(['ok' => false, 'mensaje' => 'No tienes acceso al seguimiento solicitado.']);
    exit;
}

$llamadas = [];

foreach ($modelo->obtenerInteraccionesSeguimiento($seguimientoId) as $interaccion) {
    if (strtoupper((string)($interaccion['canal'] ?? '')) !== 'LLAMADA_IP') {
        continue;
    }

    $interaccionId = (int)($interaccion['id'] ?? 0);
    $proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
    $callSid = trim((string)($interaccion['id_externo'] ?? ''));
    $duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));
    $puedeTenerGrabacion =
        $interaccionId > 0 &&
        $proveedor === 'TWILIO' &&
        $duracion > 0 &&
        preg_match('/^CA[a-fA-F0-9]{32}$/', $callSid);

    $nombreUsuario = trim(
        (string)($interaccion['nombre'] ?? '') . ' ' .
        (string)($interaccion['apellidos'] ?? '')
    );

    $llamadas[] = [
        'id' => $interaccionId,
        'fecha_inicio' => $interaccion['fecha_inicio'] ?? null,
        'fecha_fin' => $interaccion['fecha_fin'] ?? null,
        'duracion_segundos' => $duracion,
        'resultado' => (string)($interaccion['resultado'] ?? ''),
        'notas' => (string)($interaccion['notas'] ?? ''),
        'proveedor' => $proveedor,
        'usuario' => $nombreUsuario,
        'rol' => (string)($interaccion['rol'] ?? ''),
        'tiene_grabacion' => (bool)$puedeTenerGrabacion,
        'grabacion_url' => $puedeTenerGrabacion
            ? 'prueba_telefonia/api/grabacion_interaccion.php?interaccion_id=' . $interaccionId
            : null
    ];
}

echo json_encode([
    'ok' => true,
    'seguimiento_id' => $seguimientoId,
    'total' => count($llamadas),
    'llamadas' => $llamadas
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
