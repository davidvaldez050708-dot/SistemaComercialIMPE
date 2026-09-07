<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/services/InegiEducacionObjetivoService.php';

$claveEstado = trim((string)($argv[1] ?? '11'));
$claveEstado = str_pad($claveEstado, 2, '0', STR_PAD_LEFT);

$servicio = new InegiEducacionObjetivoService();
$resultado = $servicio->obtenerPorEstado($claveEstado);

echo "Diagnóstico INEGI - Población objetivo educativa\n";
echo "================================================\n";
echo "Clave de Estado: {$claveEstado}\n";
echo "Fuente esperada: Censo de Población y Vivienda 2020 (ITER)\n\n";

if (($resultado['ok'] ?? false) !== true) {
    echo '[ERROR] ' . ($resultado['mensaje'] ?? 'No fue posible consultar INEGI.') . PHP_EOL;
    exit(1);
}

$estado = $resultado['estado'] ?? [];
$metricas = $estado['metricas'] ?? [];

$formatoNumero = static function ($valor): string {
    return $valor === null
        ? 'N/D'
        : number_format((int)$valor, 0, '.', ',');
};

$formatoPorcentaje = static function ($valor): string {
    return $valor === null
        ? 'N/D'
        : number_format((float)$valor, 2, '.', ',') . ' %';
};

echo '[OK] Estado: ' . ($estado['nombre'] ?? 'Sin nombre') . PHP_EOL;
echo 'Secundaria como máxima escolaridad: ' .
    $formatoNumero($metricas['secundaria_completa'] ?? null) .
    ' (' . $formatoPorcentaje($metricas['secundaria_completa_pct'] ?? null) . ')' . PHP_EOL;
echo '15-17 fuera de la escuela: ' .
    $formatoNumero($metricas['fuera_15_17'] ?? null) .
    ' (' . $formatoPorcentaje($metricas['fuera_15_17_pct'] ?? null) . ')' . PHP_EOL;
echo '18-24 fuera de la escuela: ' .
    $formatoNumero($metricas['fuera_18_24'] ?? null) .
    ' (' . $formatoPorcentaje($metricas['fuera_18_24_pct'] ?? null) . ')' . PHP_EOL;
echo '15-24 fuera de la escuela: ' .
    $formatoNumero($metricas['fuera_15_24'] ?? null) .
    ' (' . $formatoPorcentaje($metricas['fuera_15_24_pct'] ?? null) . ')' . PHP_EOL;
echo '18+ con educación posbásica: ' .
    $formatoNumero($metricas['educacion_posbasica_18_mas'] ?? null) . PHP_EOL;
echo 'Grado promedio de escolaridad: ' .
    (($metricas['grado_promedio_escolaridad'] ?? null) === null
        ? 'N/D'
        : number_format((float)$metricas['grado_promedio_escolaridad'], 2, '.', ',')) . PHP_EOL;
echo 'Municipios procesados: ' . (int)($resultado['municipios_total'] ?? 0) . PHP_EOL;

$municipios = array_slice($resultado['municipios'] ?? [], 0, 6);

if (!empty($municipios)) {
    echo "\nTop municipal 15-24 fuera de la escuela\n";
    echo "--------------------------------------\n";

    foreach ($municipios as $indice => $municipio) {
        echo ($indice + 1) . '. ' .
            ($municipio['nombre'] ?? 'Municipio') . ': ' .
            $formatoNumero($municipio['metricas']['fuera_15_24'] ?? null) . PHP_EOL;
    }
}

echo "\nDiagnóstico terminado. Los datos quedan en caché local para las siguientes consultas.\n";
