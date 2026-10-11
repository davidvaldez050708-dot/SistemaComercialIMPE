<?php
/**
 * Prueba aislada de Educación Territorial: no necesita MySQL ni red.
 * Ejecutar: php tests/territorial_educacion_smoke.php
 */
$temporal = sys_get_temp_dir() . '/impe-educacion-' . bin2hex(random_bytes(6));
$directorio = $temporal . '/storage/cache/inegi';
if (!mkdir($directorio, 0700, true)) {
    throw new RuntimeException('No fue posible preparar la prueba.');
}
define('ROOT_PATH', $temporal);
require_once dirname(__DIR__) . '/app/services/InegiEducacionObjetivoService.php';

$metricas = [];
foreach ([
    'poblacion_15_17', 'asiste_15_17', 'fuera_15_17', 'fuera_15_17_pct',
    'poblacion_18_24', 'asiste_18_24', 'fuera_18_24', 'fuera_18_24_pct',
    'poblacion_15_24', 'fuera_15_24', 'fuera_15_24_pct',
    'educacion_posbasica_18_mas', 'grado_promedio_escolaridad'
] as $nombre) {
    $metricas[$nombre] = 0;
}
$metricas['secundaria_completa'] = 105;
$metricas['secundaria_completa_pct'] = 18.5;

$archivo = $directorio . '/educacion_objetivo_v3_perfil_completo_01_CPV2020_ZIP_FULL.json';
$datos = [
    'ok' => true,
    'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
    'periodo' => '2020',
    'estado' => ['clave' => '01', 'metricas' => $metricas],
    'municipios' => []
];

try {
    file_put_contents($archivo, json_encode($datos, JSON_THROW_ON_ERROR));
    touch($archivo, time() - 86400 * 35); // 35 días; excede el TTL anterior
    $resultado = (new InegiEducacionObjetivoService())->obtenerPorEstado('01', false);

    if (($resultado['ok'] ?? false) !== true ||
        ($resultado['cache']['estado'] ?? '') !== 'HIT' ||
        (int)($resultado['estado']['metricas']['secundaria_completa'] ?? 0) !== 105 ||
        !file_exists($archivo)) {
        throw new RuntimeException('El caché censal válido no se conservó.');
    }

    $raiz = dirname(__DIR__);
    $controller = file_get_contents($raiz . '/app/controllers/DataTerritorialController.php');
    $view = file_get_contents($raiz . '/app/views/data_territorial/index.php');
    $js = file_get_contents($raiz . '/public/javascript/educacion_objetivo.js');
    $css = file_get_contents($raiz . '/public/css/educacion_objetivo.css');
    $endpoint = file_get_contents($raiz . '/public/inegi_educacion_objetivo.php');

    $checks = [
        'endpoint_admin_permiso' => str_contains($controller, 'public function actualizarContextoEducativoGeneralOficial()') &&
            str_contains($controller, '$this->validarPermisoActualizacionOficialJson();'),
        'opcion_individual_y_masiva' => substr_count($view, 'value="contexto_educativo_general"') === 2,
        'accion_de_actualizacion' => str_contains($view, "action: 'actualizarContextoEducativoGeneralOficial'"),
        'tres_metricas' => str_contains($view, 'data-education-metric-reference'),
        'tendencia_real' => str_contains($view, 'puntosRezago') && str_contains($view, 'historicoRezago'),
        'diseno_compacto' => str_contains($css, '.data-education-subcontext-body .data-education-metrics.has-reference'),
        'aviso_claro' => str_contains($js, 'Contexto educativo general pendiente'),
        'sin_descargas_al_navegar' => str_contains($endpoint, '$claveInegi,' . "\n" . '    false')
    ];
    foreach ($checks as $nombre => $ok) {
        if (!$ok) {
            throw new RuntimeException('Falló: ' . $nombre);
        }
    }

    echo 'Educación Territorial: OK (caché censal y ' . count($checks) . " verificaciones)\n";
} finally {
    @unlink($archivo);
    @rmdir($directorio);
    @rmdir($temporal . '/storage/cache');
    @rmdir($temporal . '/storage');
    @rmdir($temporal);
}
