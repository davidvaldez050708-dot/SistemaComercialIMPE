<?php
/**
 * Prueba sin conexión a INEGI ni base de datos.
 * Reproduce el formato XLSX (columnas A-E + 28 categorías) de B2020_07_08_M.
 */
require_once __DIR__ . '/../app/services/InegiEscolaridadAdultaXlsxService.php';

if (!extension_loaded('zip') || !extension_loaded('dom') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "Faltan extensiones PHP zip/dom/mbstring.\n");
    exit(2);
}
function celda(int $indice): string
{
    $col = '';
    while ($indice > 0) {
        $indice--;
        $col = chr(65 + $indice % 26) . $col;
        $indice = intdiv($indice, 26);
    }
    return $col;
}
function fila(int $numero, array $contenido): string
{
    $celdas = '';
    foreach ($contenido as $indice => $valor) {
        $tipo = is_int($valor) ? '' : ' t="inlineStr"';
        $celdas .= '<c r="' . celda((int)$indice) . $numero . '"' . $tipo . '>';
        $celdas .= is_int($valor) ? '<v>' . $valor . '</v>' : '<is><t>' .
            htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is>';
        $celdas .= '</c>';
    }
    return '<row r="' . $numero . '">' . $celdas . '</row>';
}
$metricas = array_fill(1, 28, 0);
$metricas[1] = 100;
$metricas[2] = 30;
$metricas[4] = 10;
$metricas[8] = 10;
$metricas[13] = 10;
$metricas[14] = 5;
$metricas[15] = 5;
$metricas[17] = 20;
$metricas[18] = 10;
$metricas[19] = 10;
$metricas[23] = 15;
$metricas[27] = 5;
// Las categorías de nivel total suman 100; no se suman grados subdivididos.
$sheet = fila(1, [1 => 'Población', 2 => 'Escolaridad']);
$n = 2;
$edades = [
    ['15-19 años', '15 años'],
    ['', '16 años'],
    ['', '17 años'],
    ['', '18 años'],
    ['', '19 años'],
    ['20-24 años', 'Total']
];
foreach (['25-29','30-34','35-39','40-44','45-49','50-54','55-59',
          '60-64','65-69','70-74','75-79','80-84','85 años y más'] as $grupo) {
    $edades[] = [$grupo . (str_contains($grupo, 'años') ? '' : ' años'), 'Total'];
}
foreach ($edades as $idx => [$grupo,$edad]) {
    $row = [5 => $edad];
    if ($idx === 0) {
        $row[1] = '17 Morelos';
        $row[2] = 'Entidad federativa';
        $row[3] = 'Total';
    }
    if ($grupo !== '') {
        $row[4] = $grupo;
    }
    foreach ($metricas as $key => $value) {
        $row[5 + $key] = $value;
    }
    $sheet .= fila($n++, $row);
}
// Los datos del municipio no se agregan al total estatal.
$sheet .= fila($n++, [2 => '001 Cuernavaca', 3 => 'Total', 4 => '25-29 años', 5 => 'Total']);
$nombreArchivo = tempnam(sys_get_temp_dir(), 'test_escolaridad_');
$zip = new ZipArchive();
if ($zip->open($nombreArchivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('No se pudo preparar XLSX sintético.');
}
$zip->addFromString('xl/worksheets/sheet1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' .
    $sheet . '</sheetData></worksheet>');
$zip->close();
$zip->open($nombreArchivo);
try {
    $parser = new InegiEscolaridadAdultaXlsxService();
    $method = new ReflectionMethod($parser, 'extraerHoja');
    $resultado = $method->invoke($parser, $zip, 'xl/worksheets/sheet1.xml', [], '17');
    $grupos = $resultado['grupos'] ?? [];
    $esperados = ['15','16','17','18','19','20-24','25-29','30-34','35-39','40-44','45-49',
        '50-54','55-59','60-64','65-69','70-74','75-79','80-84','85+'];
    if (array_diff($esperados, array_keys($grupos)) ||
        count($grupos) !== count($esperados) ||
        array_sum(array_column(array_values($grupos), 1)) !== 1900 ||
        isset($grupos['15-19'])) {
        throw new RuntimeException('El desglose de 15, 16, 17, 18, 19 años o 25+ es incorrecto: ' .
            implode(', ', array_keys($grupos)));
    }
    $otro = $method->invoke($parser, $zip, 'xl/worksheets/sheet1.xml', [], '30');
    if (($otro['grupos'] ?? []) !== []) {
        throw new RuntimeException('El parser aceptó otro Estado sin validación de clave.');
    }
    echo "OK: 19 grupos estatales exactos, con 15-17, sin duplicar 15-19 ni municipios.\n";
} finally {
    $zip->close();
    unlink($nombreArchivo);
}
