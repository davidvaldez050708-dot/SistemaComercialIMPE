<?php

require_once __DIR__ . '/../app/services/InegiEnoeTitulacionImportService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script sólo puede ejecutarse desde terminal.\n");
    exit(1);
}

$sdem = trim((string)($argv[1] ?? ''));
$coe1 = trim((string)($argv[2] ?? ''));
$anio = (int)($argv[3] ?? 0);
$trimestre = (int)($argv[4] ?? 1);

if ($sdem === '' || $coe1 === '' || $anio <= 0) {
    fwrite(
        STDERR,
        "Uso:\n  php tools/importar_enoe_titulacion.php <SDEMT.csv> <COE1T.csv> <anio> [trimestre]\n"
    );
    exit(1);
}

$sdemReal = realpath($sdem);
$coe1Real = realpath($coe1);

if ($sdemReal === false || $coe1Real === false) {
    fwrite(STDERR, "No se encontró uno de los archivos indicados.\n");
    exit(1);
}

$resultado = (new InegiEnoeTitulacionImportService())->importar(
    $sdemReal,
    $coe1Real,
    $anio,
    $trimestre
);

echo json_encode(
    $resultado,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;

exit(($resultado['ok'] ?? false) === true ? 0 : 1);
