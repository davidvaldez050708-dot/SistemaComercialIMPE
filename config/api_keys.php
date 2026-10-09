<?php

// Este archivo es código público, NO contiene tokens.
// Las credenciales viven en api_keys.local.php, fuera de Git.
require_once __DIR__ . '/private_config.php';
$rutaLocal = impeRutaConfigPrivada('api_keys.local.php');
if ($rutaLocal !== null) {
    require_once $rutaLocal;
}

$valores = [
    'INEGI_INDICADORES_TOKEN' => '',
    'INEGI_INDICADORES_BASE_URL' =>
        'https://www.inegi.org.mx/app/api/indicadores/desarrolladores/jsonxml',
    'DENUE_TOKEN' => '',
    'DENUE_BASE_URL' => 'https://www.inegi.org.mx/app/api/denue/v1/consulta',
];

foreach ($valores as $nombre => $predeterminado) {
    if (!defined($nombre)) {
        $valor = getenv($nombre);
        define($nombre, $valor === false || trim((string)$valor) === ''
            ? $predeterminado : trim((string)$valor));
    }
}
