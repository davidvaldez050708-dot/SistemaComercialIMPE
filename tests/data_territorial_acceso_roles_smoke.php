<?php
/**
 * Regresión del alcance de Información Territorial, sin MySQL.
 * Ejecutar: php tests/data_territorial_acceso_roles_smoke.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/helpers/DataTerritorialAccessPolicy.php';

$casos = [
    [1, 'Administrador', 'TODOS'],
    [2, 'Coordinador Comercial', 'TODOS'],
    [2, 'Director de Operaciones', 'TODOS'],
    [5, 'Finanzas', 'TODOS'],
    [7, 'Marketing', 'TODOS'],
    [99, 'Nuevo rol de consulta', 'TODOS'],
    [3, 'Rol renombrado', 'ASESOR'],
    [4, 'Rol renombrado', 'ANALISTA_DATOS'],
    [6, 'Rol renombrado', 'CUENTA_CLAVE'],
    [33, 'Asesor', 'ASESOR'],
    [34, 'Asesor de Ventas', 'ASESOR'],
    [35, 'Analista de Datos', 'ANALISTA_DATOS'],
    [36, 'Cuenta Clave', 'CUENTA_CLAVE'],
    [0, 'Marketing', ''],
    [8, '', '']
];
foreach ($casos as [$id, $nombre, $esperado]) {
    $obtenido = DataTerritorialAccessPolicy::resolver($id, $nombre);
    if ($obtenido !== $esperado) {
        fwrite(STDERR, "FALLO: alcance de $nombre / rol $id\n");
        exit(1);
    }
}

$model = (string)file_get_contents($root . '/app/models/DataTerritorialModel.php');
$controller = (string)file_get_contents($root . '/app/controllers/DataTerritorialController.php');
$reportController = (string)file_get_contents($root . '/app/controllers/DataTerritorialReporteController.php');
$view = (string)file_get_contents($root . '/app/views/data_territorial/index.php');
$checks = [
    'Listado y ficha usan la política común' =>
        substr_count($model, '$this->obtenerAlcanceConsultaPorRol($rolId)') === 2 &&
        substr_count($model, "if (\$alcance === 'TODOS')") === 2,
    'Solo se consultan roles activos' =>
        str_contains($model, 'WHERE id = ? AND estado = 1'),
    'No se permite un identificador de usuario inválido' =>
        str_contains($model, 'if ((int)$usuarioId <= 0)'),
    'La ficha está protegida por permiso de lectura' =>
        str_contains($controller, "\$this->validarPermiso('data_territorial.ver')") &&
        str_contains($controller, '$modelo->puedeAccederEstado('),
    'Los reportes siguen verificando permisos y alcance' =>
        str_contains($reportController, "tienePermiso('data_territorial.ver')") &&
        str_contains($reportController, '$modelo->puedeAccederEstado('),
    'El listado distingue consulta global de falta de asignaciones' =>
        str_contains($view, '!empty($consultaTerritorialGlobal)') &&
        str_contains($view, 'No hay Estados activos disponibles.')
];
foreach ($checks as $nombre => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $nombre\n");
        exit(1);
    }
}
echo 'Acceso territorial por roles: OK (' .
    count($casos) . ' casos de alcance y ' . count($checks) .
    " verificaciones de integración)\n";
