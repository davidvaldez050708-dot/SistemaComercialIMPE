<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rootPath = dirname(__DIR__);

require_once $rootPath . '/app/helpers/PermissionHelper.php';
require_once $rootPath . '/app/services/OficioPdfService.php';

$mensajeError = static function ($mensaje, $codigoHttp) {
    http_response_code((int)$codigoHttp);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo (string)$mensaje;
    exit;
};

if (!isset($_SESSION['usuario_id'])) {
    $mensajeError('La sesión no está activa.', 401);
}

if (!tienePermiso('oficios.ver')) {
    $mensajeError('No tienes permiso para consultar este oficio.', 403);
}

$seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$descargar = (int)($_GET['descargar'] ?? 0) === 1;

if ($seguimientoId <= 0) {
    $mensajeError('El seguimiento solicitado no es válido.', 422);
}

if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
    $modoAcceso = 'administrador';
} elseif (tienePermiso('seguimientos_vinculacion.supervisar')) {
    $modoAcceso = 'supervisor';
} else {
    $modoAcceso = 'analista';
}

$resultado = (new OficioPdfService())->obtenerArchivoPdf(
    $seguimientoId,
    $usuarioId,
    $modoAcceso
);

if (!($resultado['ok'] ?? false)) {
    $mensajeError(
        (string)($resultado['mensaje'] ?? 'No fue posible consultar el PDF.'),
        (int)($resultado['codigo_http'] ?? 404)
    );
}

$ruta = (string)($resultado['ruta_absoluta'] ?? '');
$nombreBase = trim((string)($resultado['nombre_archivo'] ?? 'oficio.pdf'));

if ($ruta === '' || !is_file($ruta)) {
    $mensajeError('El archivo PDF no está disponible.', 404);
}

if (stripos($nombreBase, 'Oficio_') !== 0) {
    $nombreBase = 'Oficio_' . $nombreBase;
}

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($ruta));
header(
    'Content-Disposition: ' .
    ($descargar ? 'attachment' : 'inline') .
    '; filename="' . str_replace('"', '', $nombreBase) . '"; filename*=UTF-8\'\'' .
    rawurlencode($nombreBase)
);
header('Cache-Control: private, no-store, max-age=0');

readfile($ruta);
exit;
