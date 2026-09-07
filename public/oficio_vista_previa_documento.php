<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rootPath = dirname(__DIR__);

require_once $rootPath . '/app/helpers/PermissionHelper.php';
require_once $rootPath . '/app/services/OficioPreviewService.php';
require_once $rootPath . '/app/services/OficioDocxPdfService.php';

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

$servicioVista = new OficioPreviewService();
$resultadoVista = $servicioVista->obtenerVistaPrevia(
    $seguimientoId,
    $usuarioId,
    $modoAcceso
);

if (!($resultadoVista['ok'] ?? false)) {
    $mensajeError(
        (string)($resultadoVista['mensaje'] ?? 'No fue posible preparar la vista previa.'),
        (int)($resultadoVista['codigo_http'] ?? 500)
    );
}

$vista = is_array($resultadoVista['vista_previa'] ?? null)
    ? $resultadoVista['vista_previa']
    : [];

$generador = new OficioDocxPdfService();
$resultadoDocumento = $generador->generarPdf($vista);

if (!($resultadoDocumento['ok'] ?? false)) {
    $detalle = trim((string)($resultadoDocumento['mensaje_tecnico'] ?? ''));

    if ($detalle !== '') {
        error_log('Vista previa DOCX/PDF: ' . $detalle);
    }

    $mensajeError(
        (string)($resultadoDocumento['mensaje'] ?? 'No fue posible generar la vista previa del documento.'),
        500
    );
}

$contenidoPdf = (string)($resultadoDocumento['contenido_pdf'] ?? '');

if ($contenidoPdf === '') {
    $mensajeError('La vista previa del documento está vacía.', 500);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="vista_previa_oficio.pdf"');
header('Content-Length: ' . strlen($contenidoPdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo $contenidoPdf;
exit;
