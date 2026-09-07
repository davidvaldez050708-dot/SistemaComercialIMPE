<?php

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/helpers/PermissionHelper.php';
require_once __DIR__ . '/../app/models/DataTerritorialModel.php';
require_once __DIR__ . '/../app/services/InegiEducacionObjetivoService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);
$estadoId = (int)($_GET['estado_id'] ?? 0);

if ($usuarioId <= 0) {
    $responder([
        'ok' => false,
        'mensaje' => 'La sesión no está activa.'
    ], 401);
}

if (!tienePermiso('data_territorial.ver')) {
    $responder([
        'ok' => false,
        'mensaje' => 'No tienes permiso para consultar información territorial.'
    ], 403);
}

if ($estadoId <= 0) {
    $responder([
        'ok' => false,
        'mensaje' => 'El Estado solicitado no es válido.'
    ], 422);
}

$modelo = new DataTerritorialModel();

if (!$modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
    $responder([
        'ok' => false,
        'mensaje' => 'No tienes acceso a este territorio.'
    ], 403);
}

$estado = $modelo->obtenerEstado($estadoId);
$claveInegi = str_pad(trim((string)($estado['clave_inegi'] ?? '')), 2, '0', STR_PAD_LEFT);

if (!preg_match('/^\d{2}$/', $claveInegi)) {
    $responder([
        'ok' => false,
        'mensaje' => 'El territorio no tiene una clave INEGI válida.'
    ], 422);
}

$servicio = new InegiEducacionObjetivoService();
$resultado = $servicio->obtenerPorEstado($claveInegi);

if (($resultado['ok'] ?? false) !== true) {
    $responder($resultado, 502);
}

$responder($resultado);
