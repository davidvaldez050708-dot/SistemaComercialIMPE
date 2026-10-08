<?php
/**
 * Prueba de datos reales (sin MySQL): tabulado nacional INEGI 2020,
 * hoja de Escolaridad por entidad federativa, clave 20 Oaxaca.
 */
require_once __DIR__ . '/../app/services/InegiEscolaridadAdultaXlsxService.php';
$archivo = $argv[1] ?? '';
if (!is_file($archivo) || filesize($archivo) < 2000) {
    throw new RuntimeException('No llegó un XLSX nacional de INEGI.');
}
$zip = new ZipArchive();
if ($zip->open($archivo, ZipArchive::CHECKCONS) !== true) {
    throw new RuntimeException('El archivo nacional no es XLSX válido.');
}
try {
    $parser = new InegiEscolaridadAdultaXlsxService();
    $getStrings = new ReflectionMethod($parser, 'cadenas');
    $getRows = new ReflectionMethod($parser, 'extraerHoja');
    $shared = $getStrings->invoke($parser, $zip);
    $datos = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombre = (string)$zip->getNameIndex($i);
        if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $nombre)) continue;
        $resultado = $getRows->invoke($parser, $zip, $nombre, $shared, '20', true);
        foreach ($resultado['grupos'] as $edad => $m) {
            if (isset($datos[$edad]) && $datos[$edad] !== $m) {
                throw new RuntimeException('Distintos totales en hojas nacionales: ' . $edad);
            }
            $datos[$edad] = $m;
        }
    }
    $edades = ['15','16','17','18','19','20-24','25-29','30-34','35-39',
        '40-44','45-49','50-54','55-59','60-64','65-69','70-74','75-79','80-84','85+'];
    $faltantes = array_values(array_diff($edades, array_keys($datos)));
    if ($faltantes) throw new RuntimeException('Edades faltantes de Oaxaca: ' . implode(',',$faltantes));
    $base25 = 0; $base18 = 0; $base15 = 0;
    $sin25 = 0; $min18 = 0; $min15 = 0;
    foreach ($edades as $edad) {
        $m=$datos[$edad];
        if ($m[1] <= 0) throw new RuntimeException('Base inválida para ' . $edad);
        if (in_array($edad,['15','16','17'],true)) {
            $base15+=$m[1];
            $min15+=$m[2]+$m[3]+$m[4]+$m[8]+$m[12]+$m[18];
            continue;
        }
        $base18+=$m[1];
        $min18+=$m[2]+$m[3]+$m[4]+$m[8]+$m[12]+$m[18];
        if (!in_array($edad,['18','19','20-24'],true)) {
            $base25+=$m[1];
            $sin25+=$m[2]+$m[3]+$m[4]+$m[8]+$m[12]+$m[13]+$m[17]+$m[21];
        }
    }
    if ($base25 <= 0 || $base18 <= $base25 || $base15 <= 0 ||
        $min15 > $base15 || $min18 > $base18 || $sin25 > $base25) {
        throw new RuntimeException('Universos o numeradores inconsistentes.');
    }
    echo 'OAXACA_ESTATAL_BASE_15_17=' . $base15 . ' MIN=' . $min15 .
        ' BASE_18=' . $base18 . ' MIN=' . $min18 .
        ' BASE_25=' . $base25 . ' SIN_SUPERIOR=' . $sin25 . "\n";
    echo "VALIDACION_OK\n";
} finally {
    $zip->close();
}
