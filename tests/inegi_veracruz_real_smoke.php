<?php
require_once __DIR__ . '/../app/services/InegiEscolaridadAdultaXlsxService.php';
$nombre = $argv[1] ?? '';
$clave = $argv[2] ?? '';
if (!is_file($nombre) || filesize($nombre) < 200) {
    throw new RuntimeException('Sin XLSX oficial descargado.');
}
$zip = new ZipArchive();
if ($zip->open($nombre, ZipArchive::CHECKCONS) !== true) {
    throw new RuntimeException('INEGI no devolvió un XLSX ZIP válido.');
}
try {
    $parser = new InegiEscolaridadAdultaXlsxService();
    $cadenas = new ReflectionMethod($parser, 'cadenas');
    $extraer = new ReflectionMethod($parser, 'extraerHoja');
    $strings = $cadenas->invoke($parser, $zip);
    $grupos = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $hoja = $zip->getNameIndex($i);
        if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $hoja)) {
            continue;
        }
        $res = $extraer->invoke($parser, $zip, $hoja, $strings, $clave);
        foreach ($res['grupos'] as $edad => $medidas) {
            $grupos[$edad] = $medidas;
        }
        echo $hoja . ': ' . count($res['grupos']) . " edades encontradas\n";
    }
    $necesarios = ['18','19','20-24','25-29','30-34','35-39','40-44','45-49','50-54','55-59','60-64','65-69','70-74','75-79','80-84','85+'];
    $faltantes = array_values(array_diff($necesarios, array_keys($grupos)));
    echo "Grupos: " . implode(',', array_keys($grupos)) . "\n";
    echo "Faltantes: " . ($faltantes ? implode(',', $faltantes) : 'NINGUNO') . "\n";
    if ($faltantes) {
        throw new RuntimeException('No se recuperaron todos los grupos requeridos.');
    }
    echo "VALIDACION_OK\n";
} finally {
    $zip->close();
}
