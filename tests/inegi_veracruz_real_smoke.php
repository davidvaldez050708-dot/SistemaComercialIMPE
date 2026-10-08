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
            if (isset($grupos[$edad]) && $grupos[$edad] !== $medidas) {
                throw new RuntimeException('Las hojas de INEGI difieren para ' . $edad .
                    ': primera base=' . $grupos[$edad][1] . ', otra=' . $medidas[1]);
            }
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
    $total25 = 0;
    $total18 = 0;
    foreach ($necesarios as $edad) {
        $total18 += $grupos[$edad][1];
        if ($edad !== '18' && $edad !== '19' && $edad !== '20-24') {
            $total25 += $grupos[$edad][1];
        }
    }
    if ($total18 <= $total25 || $total25 <= 0) {
        throw new RuntimeException('Los totales del rango 18+ y 25+ no son consistentes.');
    }
    echo 'Población base 18+: ' . $total18 . '; 25+: ' . $total25 . "\n";
    $juvenil = InegiEscolaridadAdultaXlsxService::calcularJuvenil($grupos);
    $esperadosJuveniles = [
        '30' => ['base' => 418032, 'minimo' => 405268, 'indeterminados' => 1264],
        '17' => ['base' => 100192, 'minimo' => 96646, 'indeterminados' => 876]
    ];
    $esperado = $esperadosJuveniles[$clave] ?? null;
    if (!$esperado ||
        $juvenil['poblacion_base'] !== $esperado['base'] ||
        $juvenil['cantidad_personas'] !== $esperado['minimo'] ||
        $juvenil['personas_indeterminadas'] !== $esperado['indeterminados']) {
        throw new RuntimeException('La clasificación juvenil 15-17 difiere del cuadro INEGI comprobado: ' .
            json_encode($juvenil, JSON_UNESCAPED_UNICODE));
    }
    echo 'Jóvenes de 15-17: total ' . $juvenil['poblacion_base'] .
        '; sin media superior concluida (mínimo) ' .
        $juvenil['cantidad_personas'] . '; incidencia ' .
        $juvenil['porcentaje'] . '%; no determinables ' .
        $juvenil['personas_indeterminadas'] . "\n";
    echo "VALIDACION_OK\n";
} finally {
    $zip->close();
}
