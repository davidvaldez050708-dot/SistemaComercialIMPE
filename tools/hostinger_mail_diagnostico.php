<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/app/services/HostingerApiService.php';

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$valor = function ($datos, $claves, $default = '') {
    foreach ($claves as $clave) {
        if (array_key_exists($clave, $datos) && $datos[$clave] !== null) {
            return trim((string)$datos[$clave]);
        }
    }

    return $default;
};

$linea('Diagnóstico Hostinger - Sistema Comercial IMPE');
$linea(str_repeat('=', 46));
$linea('Esta prueba solamente consulta servicios y buzones.');
$linea('No crea cuentas, no genera tokens y no envía correos.');
$linea();

$servicio = new HostingerApiService();

if (!$servicio->estaConfigurado()) {
    $linea('[PENDIENTE] HOSTINGER_API_TOKEN no está configurado.');
    $linea('Copia config/hostinger_mail_config.example.php como');
    $linea('config/hostinger_mail_config.php y coloca ahí el token general.');
    exit(2);
}

$ordenes = $servicio->listarOrdenesCorreo();

if (!($ordenes['ok'] ?? false)) {
    $linea('[ERROR] ' . ($ordenes['mensaje'] ?? 'No fue posible consultar Hostinger.'));
    $linea('Detalle: ' . ($ordenes['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$listaOrdenes = is_array($ordenes['ordenes'] ?? null)
    ? $ordenes['ordenes']
    : [];

if (count($listaOrdenes) === 0) {
    $linea('[OK] El token fue aceptado, pero no se encontraron servicios de correo.');
    exit(0);
}

$linea('[OK] Token general aceptado por Hostinger.');
$linea('Servicios de correo encontrados: ' . count($listaOrdenes));
$linea();

foreach ($listaOrdenes as $indice => $orden) {
    if (!is_array($orden)) {
        continue;
    }

    $orderId = $valor($orden, ['id', 'resource_id', 'resourceId']);
    $dominio = $valor($orden, ['domain', 'domain_name', 'domainName'], 'Sin dominio');
    $estado = $valor($orden, ['status'], 'Sin estado');

    $linea('Servicio #' . ($indice + 1));
    $linea('  Order ID: ' . ($orderId !== '' ? $orderId : 'No disponible'));
    $linea('  Dominio: ' . $dominio);
    $linea('  Estado: ' . $estado);

    if ($orderId === '') {
        $linea('  Buzones: no se pudieron consultar porque falta el Order ID.');
        $linea();
        continue;
    }

    $buzones = $servicio->listarBuzones($orderId);

    if (!($buzones['ok'] ?? false)) {
        $linea('  [ERROR] No fue posible consultar los buzones.');
        $linea('  Detalle: ' . ($buzones['mensaje_tecnico'] ?? 'Sin detalle.'));
        $linea();
        continue;
    }

    $listaBuzones = is_array($buzones['buzones'] ?? null)
        ? $buzones['buzones']
        : [];

    $linea('  Buzones encontrados: ' . count($listaBuzones));

    foreach ($listaBuzones as $buzon) {
        if (!is_array($buzon)) {
            continue;
        }

        $id = $valor($buzon, ['id', 'resource_id', 'resourceId'], 'Sin ID');
        $correo = $valor($buzon, ['address', 'email'], 'Sin correo');
        $estadoBuzon = $valor($buzon, ['status'], 'Sin estado');

        $linea('    - ' . $correo . ' | ' . $estadoBuzon . ' | ' . $id);
    }

    $linea();
}

$linea('Diagnóstico terminado. No se envió ningún correo.');
