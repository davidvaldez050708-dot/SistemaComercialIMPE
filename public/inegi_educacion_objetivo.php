<?php

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/helpers/PermissionHelper.php';
require_once __DIR__ . '/../app/models/DataTerritorialModel.php';
require_once __DIR__ . '/../app/services/InegiEducacionObjetivoService.php';

require_once __DIR__ . '/../app/models/PerfilEducativoPrioritarioModel.php';
require_once __DIR__ . '/../app/services/InegiPerfilEducativoPrioritarioAutoService.php';

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

/*
 * Esta ruta es de LECTURA. No descarga XLSX/ZIP ni consulta servicios remotos
 * pesados mientras un analista navega por Información territorial.
 *
 * La sincronización oficial se realiza desde Administración >
 * "Actualizar información oficial" y aquí se consume el cache/local DB.
 */
$servicio = new InegiEducacionObjetivoService();
$resultadoGeneral = $servicio->obtenerPorEstado(
    $claveInegi,
    false
);

if (($resultadoGeneral['ok'] ?? false) === true) {
    $resultado = $resultadoGeneral;
    $resultado['contexto_general_disponible'] = true;
} else {
    $resultado = [
        'ok' => true,
        'fuente' => 'INEGI - Censo de Población y Vivienda 2020',
        'periodo' => '2020',
        'producto' => 'CPV',
        'estado' => [
            'clave' => $claveInegi,
            'nombre' => (string)($estado['nombre'] ?? ''),
            'metricas' => []
        ],
        'municipios' => [],
        'municipios_total' => 0,
        'contexto_general_disponible' => false,
        'mensaje_contexto_general' =>
            'El contexto educativo general todavía no está sincronizado localmente.'
    ];
}

/*
 * El perfil prioritario 25-49 sí se lee desde la tabla local.
 * Si falta, se informa "Sin sincronizar"; nunca se dispara una descarga
 * pesada desde esta pantalla.
 */
$resultado['perfil_educativo_prioritario'] =
    (new InegiPerfilEducativoPrioritarioAutoService())
        ->obtenerOActualizar(
            $estadoId,
            $claveInegi,
            false
        );

/*
 * El contexto adulto/laboral municipal se persiste por separado desde la
 * actualización oficial. Esta pantalla no lo vuelve a descargar de INEGI.
 */
$resultado['perfil_adulto'] = [
    'ok' => false,
    'mensaje' =>
        'El perfil adulto/laboral se consulta desde la información oficial sincronizada.'
];

$resultado['modo_lectura'] = 'LOCAL';

$responder($resultado);
