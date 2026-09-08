<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/app/services/HostingerApiService.php';

const HOSTINGER_DOMINIO_OBJETIVO = 'rededucativamexico.org';
const HOSTINGER_CORREO_DIEGO = 'd.institucional2@rededucativamexico.org';
const HOSTINGER_TOKEN_NOMBRE_DIEGO = 'Sistema Comercial IMPE - Diego prueba';

$crear = in_array('--crear', $argv, true);

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$valorPlano = function ($valor) {
    if (is_string($valor) || is_numeric($valor)) {
        return trim((string)$valor);
    }

    if (!is_array($valor)) {
        return '';
    }

    foreach (['domain', 'name', 'value', 'address'] as $clave) {
        if (isset($valor[$clave]) && (is_string($valor[$clave]) || is_numeric($valor[$clave]))) {
            return trim((string)$valor[$clave]);
        }
    }

    return '';
};

$guardarToken = function ($token, $tokenId = '') use ($rootPath) {
    $ruta = $rootPath . '/config/hostinger_mail_config.php';

    if (!is_file($ruta)) {
        return [
            'ok' => false,
            'mensaje' => 'No existe config/hostinger_mail_config.php.'
        ];
    }

    $contenido = file_get_contents($ruta);

    if ($contenido === false) {
        return [
            'ok' => false,
            'mensaje' => 'No fue posible leer config/hostinger_mail_config.php.'
        ];
    }

    $tokenSeguro = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$token);
    $lineaToken = "define('HOSTINGER_MAIL_API_TOKEN', '" . $tokenSeguro . "');";

    if (preg_match("/define\\(\\s*['\"]HOSTINGER_MAIL_API_TOKEN['\"]\\s*,.*?\\);/", $contenido)) {
        $contenido = preg_replace(
            "/define\\(\\s*['\"]HOSTINGER_MAIL_API_TOKEN['\"]\\s*,.*?\\);/",
            $lineaToken,
            $contenido,
            1
        );
    } else {
        $contenido = rtrim($contenido) . PHP_EOL . $lineaToken . PHP_EOL;
    }

    if ($tokenId !== '') {
        $tokenIdSeguro = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$tokenId);
        $lineaId = "define('HOSTINGER_MAIL_API_TOKEN_ID', '" . $tokenIdSeguro . "');";

        if (preg_match("/define\\(\\s*['\"]HOSTINGER_MAIL_API_TOKEN_ID['\"]\\s*,.*?\\);/", $contenido)) {
            $contenido = preg_replace(
                "/define\\(\\s*['\"]HOSTINGER_MAIL_API_TOKEN_ID['\"]\\s*,.*?\\);/",
                $lineaId,
                $contenido,
                1
            );
        } else {
            $contenido = rtrim($contenido) . PHP_EOL . $lineaId . PHP_EOL;
        }
    }

    if (file_put_contents($ruta, $contenido) === false) {
        return [
            'ok' => false,
            'mensaje' => 'Hostinger devolvió el token, pero no fue posible guardarlo localmente.'
        ];
    }

    return ['ok' => true];
};

$linea('Preparación Hostinger Mail API - Diego Bahena');
$linea(str_repeat('=', 47));
$linea('Dominio: ' . HOSTINGER_DOMINIO_OBJETIVO);
$linea('Buzón confirmado: ' . HOSTINGER_CORREO_DIEGO);
$linea();

$servicio = new HostingerApiService();

if (!$servicio->estaConfigurado()) {
    $linea('[ERROR] El token general de Hostinger no está configurado.');
    exit(1);
}

$ordenes = $servicio->listarOrdenesCorreo();

if (!($ordenes['ok'] ?? false)) {
    $linea('[ERROR] ' . ($ordenes['mensaje'] ?? 'No fue posible consultar los servicios.'));
    $linea('Detalle: ' . ($ordenes['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$orderId = '';

foreach (($ordenes['ordenes'] ?? []) as $orden) {
    if (!is_array($orden)) {
        continue;
    }

    $dominio = strtolower($valorPlano($orden['domain'] ?? ''));

    if ($dominio === HOSTINGER_DOMINIO_OBJETIVO) {
        $orderId = trim((string)($orden['id'] ?? $orden['resource_id'] ?? $orden['resourceId'] ?? ''));
        break;
    }
}

if ($orderId === '') {
    $linea('[ERROR] No se encontró el servicio ' . HOSTINGER_DOMINIO_OBJETIVO . '.');
    exit(1);
}

$linea('[OK] Servicio localizado: ' . $orderId);

$buzones = $servicio->listarBuzones($orderId);

if (!($buzones['ok'] ?? false)) {
    $linea('[ERROR] No fue posible consultar los buzones.');
    $linea('Detalle: ' . ($buzones['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$mailboxId = '';

foreach (($buzones['buzones'] ?? []) as $buzon) {
    if (!is_array($buzon)) {
        continue;
    }

    $correo = strtolower(trim((string)($buzon['address'] ?? $buzon['email'] ?? '')));

    if ($correo === HOSTINGER_CORREO_DIEGO) {
        $mailboxId = trim((string)($buzon['id'] ?? $buzon['resource_id'] ?? $buzon['resourceId'] ?? ''));
        break;
    }
}

if ($mailboxId === '') {
    $linea('[ERROR] El buzón confirmado de Diego no aparece en el servicio.');
    exit(1);
}

$linea('[OK] Buzón localizado: ' . $mailboxId);

$tokens = $servicio->listarTokensMailApi($orderId);

if (!($tokens['ok'] ?? false)) {
    $linea('[ERROR] No fue posible revisar los tokens existentes.');
    $linea('Detalle: ' . ($tokens['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

foreach (($tokens['tokens'] ?? []) as $tokenExistente) {
    if (!is_array($tokenExistente)) {
        continue;
    }

    if (trim((string)($tokenExistente['name'] ?? '')) === HOSTINGER_TOKEN_NOMBRE_DIEGO) {
        $linea('[PENDIENTE] Ya existe un token con el nombre: ' . HOSTINGER_TOKEN_NOMBRE_DIEGO);
        $linea('No se generó otro para evitar credenciales duplicadas.');
        $linea('Si no conservas su token privado, será necesario revocarlo y crear uno nuevo.');
        exit(2);
    }
}

if (!$crear) {
    $linea();
    $linea('[LISTO PARA CREAR] La prueba quedó preparada.');
    $linea('El token tendrá acceso solamente al buzón de Diego.');
    $linea('No se ha creado ningún token todavía.');
    $linea();
    $linea('Para crearlo y guardarlo automáticamente en la configuración local:');
    $linea('C:\\xampp\\php\\php.exe tools\\hostinger_mail_preparar_diego.php --crear');
    exit(0);
}

$linea();
$linea('Creando token limitado exclusivamente al buzón de Diego...');

$resultado = $servicio->crearTokenMailApi(
    $orderId,
    HOSTINGER_TOKEN_NOMBRE_DIEGO,
    [$mailboxId]
);

if (!($resultado['ok'] ?? false)) {
    $linea('[ERROR] ' . ($resultado['mensaje'] ?? 'No fue posible crear el token.'));
    $linea('Detalle: ' . ($resultado['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$guardado = $guardarToken(
    (string)$resultado['token'],
    (string)($resultado['token_id'] ?? '')
);

if (!($guardado['ok'] ?? false)) {
    $linea('[ERROR] ' . ($guardado['mensaje'] ?? 'No fue posible guardar el token.'));
    $linea('El token fue creado en Hostinger. No vuelvas a ejecutar --crear hasta revisar el token en hPanel/API.');
    exit(1);
}

$linea('[OK] Token Mail API creado correctamente.');
$linea('[OK] Alcance: únicamente ' . HOSTINGER_CORREO_DIEGO);
$linea('[OK] Token guardado en config/hostinger_mail_config.php.');
$linea('Por seguridad, el valor del token no se muestra en pantalla.');
$linea();
$linea('Siguiente prueba:');
$linea('C:\\xampp\\php\\php.exe tools\\hostinger_mail_api_diagnostico.php');
