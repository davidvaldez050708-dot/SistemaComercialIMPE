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
$marcadorBuzon = '[BUZON_VOZ]';
$marcadorFueraServicio = '[FUERA_SERVICIO]';
$marcadorContacto = '[CONTACTO_EFECTIVO]';
$marcadorSinContacto = '[SIN_CONTACTO_EFECTIVO]';

// Zadarma entrega NOTIFY_RECORD cuando el audio ya está listo.
// Conservamos también ANSWER/OUT_END para reconstruir la duración de llamadas
// antiguas que pudieron quedar guardadas con duration=0.
$estadoZadarma = [];
$logPath = $root . '/storage/zadarma_webhooks.log';
if (is_file($logPath)) {
    $lineas = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lineas as $linea) {
        $fila = json_decode($linea, true);
        if (!is_array($fila)) {
            continue;
        }

        $pbxCallId = trim((string)($fila['pbx_call_id'] ?? ''));
        if ($pbxCallId === '') {
            continue;
        }

        if (!isset($estadoZadarma[$pbxCallId])) {
            $estadoZadarma[$pbxCallId] = [
                'respuesta' => null,
                'fin' => null,
                'grabada' => false,
                'grabacion' => false,
            ];
        }

        $evento = (string)($fila['event'] ?? '');
        if ($evento === 'NOTIFY_ANSWER') {
            $estadoZadarma[$pbxCallId]['respuesta'] = $fila;
        } elseif ($evento === 'NOTIFY_OUT_END') {
            $estadoZadarma[$pbxCallId]['fin'] = $fila;
            $estadoZadarma[$pbxCallId]['grabada'] =
                (string)($fila['is_recorded'] ?? '') === '1' ||
                trim((string)($fila['call_id_with_rec'] ?? '')) !== '';
        } elseif ($evento === 'NOTIFY_RECORD') {
            $estadoZadarma[$pbxCallId]['grabacion'] = true;
        }
    }
}

$resultadosConContacto = [
    'CONTACTADO',
    'SOLICITO_LLAMAR_DESPUES',
    'MENSAJE_ENVIADO',
];

foreach ($modelo->obtenerInteraccionesSeguimiento($seguimientoId) as $interaccion) {
    if (strtoupper((string)($interaccion['canal'] ?? '')) !== 'LLAMADA_IP') {
        continue;
    }

    $interaccionId = (int)($interaccion['id'] ?? 0);
    $proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
    $idExterno = trim((string)($interaccion['id_externo'] ?? ''));
    $duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));

    if ($proveedor === 'ZADARMA' && $duracion <= 0 && isset($estadoZadarma[$idExterno])) {
        $metaZadarma = $estadoZadarma[$idExterno];
        $finZadarma = is_array($metaZadarma['fin'] ?? null) ? $metaZadarma['fin'] : null;
        $respuestaZadarma = is_array($metaZadarma['respuesta'] ?? null)
            ? $metaZadarma['respuesta']
            : null;

        $duracion = max(0, (int)($finZadarma['duration'] ?? 0));

        if ($duracion <= 0 && $respuestaZadarma && $finZadarma) {
            $inicioConversacion = strtotime((string)($respuestaZadarma['received_at'] ?? ''));
            $finConversacion = strtotime((string)($finZadarma['received_at'] ?? ''));

            if (
                $inicioConversacion !== false &&
                $finConversacion !== false &&
                $finConversacion > $inicioConversacion
            ) {
                $duracion = max(1, $finConversacion - $inicioConversacion);
            }
        }
    }

    $resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
    $notas = trim((string)($interaccion['notas'] ?? ''));
    $resultadoTelefonico = $resultado;
    $excluirGrabacion = false;

    if (strpos($notas, $marcadorBuzon) !== false) {
        $resultadoTelefonico = 'BUZON_VOZ';
        $excluirGrabacion = true;
    } elseif (strpos($notas, $marcadorFueraServicio) !== false) {
        $resultadoTelefonico = 'FUERA_SERVICIO';
        $excluirGrabacion = true;
    }

    if (in_array($resultado, ['NO_CONTESTO', 'SIN_RESPUESTA', 'NUMERO_INCORRECTO'], true)) {
        $excluirGrabacion = true;
    }

    $contactoEfectivo = in_array($resultado, $resultadosConContacto, true);
    if (strpos($notas, $marcadorSinContacto) !== false) {
        $contactoEfectivo = false;
    } elseif (strpos($notas, $marcadorContacto) !== false) {
        $contactoEfectivo = true;
    }

    $notasLimpias = trim(str_replace(
        [
            $marcadorBuzon,
            $marcadorFueraServicio,
            $marcadorContacto,
            $marcadorSinContacto,
        ],
        '',
        $notas
    ));

    $grabacionTwilio =
        $proveedor === 'TWILIO' &&
        $duracion > 0 &&
        preg_match('/^CA[a-fA-F0-9]{32}$/', $idExterno);

    $grabacionZadarma =
        $proveedor === 'ZADARMA' &&
        $duracion > 0 &&
        preg_match('/^out_[a-fA-F0-9]{32,64}$/', $idExterno) &&
        !empty($estadoZadarma[$idExterno]['grabacion']);

    $puedeTenerGrabacion =
        !$excluirGrabacion &&
        $interaccionId > 0 &&
        ($grabacionTwilio || $grabacionZadarma);

    $grabacionProcesando =
        !$excluirGrabacion &&
        $proveedor === 'ZADARMA' &&
        $duracion > 0 &&
        preg_match('/^out_[a-fA-F0-9]{32,64}$/', $idExterno) &&
        !$grabacionZadarma &&
        !empty($estadoZadarma[$idExterno]['grabada']);

    $nombreUsuario = trim(
        (string)($interaccion['nombre'] ?? '') . ' ' .
        (string)($interaccion['apellidos'] ?? '')
    );

    $llamadas[] = [
        'id' => $interaccionId,
        'fecha_inicio' => $interaccion['fecha_inicio'] ?? null,
        'fecha_fin' => $interaccion['fecha_fin'] ?? null,
        'duracion_segundos' => $duracion,
        'resultado' => $resultado,
        'resultado_telefonico' => $resultadoTelefonico,
        'contacto_efectivo' => (bool)$contactoEfectivo,
        'notas' => $notasLimpias,
        'proveedor' => $proveedor,
        'usuario' => $nombreUsuario,
        'rol' => (string)($interaccion['rol'] ?? ''),
        'excluir_grabacion' => $excluirGrabacion,
        'tiene_grabacion' => (bool)$puedeTenerGrabacion,
        'grabacion_procesando' => (bool)$grabacionProcesando,
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
