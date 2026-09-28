<?php

require_once __DIR__ . '/../app/services/InegiPerfilEducativoPrioritarioImportService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script sólo puede ejecutarse desde terminal.\n");
    exit(1);
}

$ruta = trim((string)($argv[1] ?? ''));

if ($ruta === '') {
    fwrite(
        STDERR,
        "Uso:\n  php tools/importar_perfil_educativo_prioritario.php <B2020_07_08_M.xlsx>\n"
    );
    exit(1);
}

$rutaReal = realpath($ruta);

if ($rutaReal === false || !is_file($rutaReal)) {
    fwrite(STDERR, "No se encontró el archivo indicado.\n");
    exit(1);
}

$servicio = new InegiPerfilEducativoPrioritarioImportService();
$resultado = $servicio->importarXlsx(
    $rutaReal,
    basename($rutaReal)
);

echo json_encode(
    $resultado,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;

exit(($resultado['ok'] ?? false) === true ? 0 : 1);
