<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este verificador solo puede ejecutarse por CLI.');
}

$root = dirname(__DIR__);
$configPath = $root . '/config/zadarma_config.php';
$autoloadPath = $root . '/vendor/autoload.php';

$errores = 0;
$avisos = 0;

function estadoTelefonia(string $tipo, string $mensaje): void
{
    global $errores, $avisos;

    if ($tipo === 'ERROR') {
        $errores++;
    } elseif ($tipo === 'AVISO') {
        $avisos++;
    }

    echo '[' . $tipo . '] ' . $mensaje . PHP_EOL;
}

echo 'Verificación de telefonía Zadarma - SistemaComercialIMPE' . PHP_EOL;
echo str_repeat('=', 58) . PHP_EOL;

if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    estadoTelefonia('OK', 'PHP ' . PHP_VERSION . '.');
} else {
    estadoTelefonia(
        'ERROR',
        'Se requiere PHP 8.1 o superior. Versión actual: ' . PHP_VERSION . '.'
    );
}

if (!extension_loaded('curl')) {
    estadoTelefonia('ERROR', 'La extensión PHP cURL no está habilitada.');
} else {
    estadoTelefonia('OK', 'PHP cURL disponible.');
}

if (!is_file($configPath)) {
    estadoTelefonia(
        'ERROR',
        'Falta config/zadarma_config.php. Copia el archivo example y agrega las credenciales privadas.'
    );
    $config = [];
} else {
    $config = require $configPath;
    estadoTelefonia('OK', 'Archivo privado de configuración Zadarma presente.');
}

$apiKey = trim((string)($config['api_key'] ?? ''));
$apiSecret = trim((string)($config['api_secret'] ?? ''));

if (
    $apiKey === '' ||
    $apiSecret === '' ||
    $apiKey === 'TU_API_KEY' ||
    $apiSecret === 'TU_API_SECRET'
) {
    estadoTelefonia('ERROR', 'API key/secret de Zadarma no están configurados.');
} else {
    estadoTelefonia('OK', 'Credenciales Zadarma configuradas sin mostrarlas.');
}

if (!is_file($autoloadPath)) {
    estadoTelefonia(
        'ERROR',
        'Falta vendor/autoload.php. Ejecuta composer install en el servidor.'
    );
} else {
    require_once $autoloadPath;
    estadoTelefonia('OK', 'Dependencias Composer disponibles.');
}

try {
    require_once $root . '/config/db_connection.php';
    require_once $root . '/app/services/TelefoniaExtensionService.php';
    require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';

    $database = new Database();
    $connection = $database->connect();

    if (!$connection || !$connection->ping()) {
        throw new RuntimeException('La conexión MySQL no respondió al ping.');
    }

    estadoTelefonia('OK', 'Conexión a base de datos disponible.');

    (new TelefoniaExtensionService())->asegurarEstructura();
    (new ZadarmaWebhookEventStoreService())->asegurarEstructura();

    estadoTelefonia(
        'OK',
        'Tablas telefonia_extensiones y telefonia_zadarma_eventos disponibles.'
    );

    $sql = "SELECT
                te.extension,
                te.permite_salientes,
                u.nombre,
                u.apellidos
            FROM telefonia_extensiones te
            INNER JOIN usuarios u
                ON u.id = te.usuario_id
            WHERE te.proveedor = 'ZADARMA'
              AND te.activo = 1
              AND u.estado = 1
            ORDER BY te.extension";

    $resultado = $connection->query($sql);
    $asignaciones = [];

    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) {
            $asignaciones[] = $fila;
        }
    }

    if (empty($asignaciones)) {
        estadoTelefonia(
            'AVISO',
            'No hay extensiones Zadarma activas asignadas a usuarios. En producción asigna la extensión de Diego desde Administración > Telefonía.'
        );
    } else {
        estadoTelefonia(
            'OK',
            count($asignaciones) . ' extensión(es) activa(s) asignada(s) a usuarios.'
        );

        foreach ($asignaciones as $asignacion) {
            $nombre = trim(
                (string)($asignacion['nombre'] ?? '') . ' ' .
                (string)($asignacion['apellidos'] ?? '')
            );
            $salientes = !empty($asignacion['permite_salientes'])
                ? 'salientes habilitadas'
                : 'salientes deshabilitadas';

            echo '    - ' .
                ($nombre !== '' ? $nombre : 'Usuario') .
                ': extensión ' .
                (string)($asignacion['extension'] ?? '') .
                ' (' . $salientes . ')' .
                PHP_EOL;
        }
    }

    $eventos24h = 0;
    $consultaEventos = $connection->query(
        "SELECT COUNT(*) AS total
         FROM telefonia_zadarma_eventos
         WHERE received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );

    if ($consultaEventos) {
        $eventos24h = (int)(
            $consultaEventos->fetch_assoc()['total'] ?? 0
        );
    }

    if ($eventos24h > 0) {
        estadoTelefonia(
            'OK',
            $eventos24h . ' evento(s) Zadarma recibido(s) en las últimas 24 horas.'
        );
    } else {
        estadoTelefonia(
            'AVISO',
            'No hay eventos Zadarma en las últimas 24 horas. Esto es normal si todavía no has hecho una llamada después de configurar el webhook.'
        );
    }

    if (
        is_file($autoloadPath) &&
        $apiKey !== '' &&
        $apiSecret !== '' &&
        $apiKey !== 'TU_API_KEY' &&
        $apiSecret !== 'TU_API_SECRET'
    ) {
        try {
            $api = new \Zadarma_API\Api(
                $apiKey,
                $apiSecret,
                false
            );
            $pbx = $api->getPbxInternal();
            $pbxId = trim((string)($pbx->pbx_id ?? ''));
            $numeros = is_array($pbx->numbers ?? null)
                ? array_map('intval', $pbx->numbers)
                : [];

            if ($pbxId === '') {
                estadoTelefonia(
                    'ERROR',
                    'La API respondió, pero no devolvió un PBX ID válido.'
                );
            } else {
                estadoTelefonia(
                    'OK',
                    'Conexión real con la API Zadarma y centralita PBX confirmada.'
                );

                foreach ($asignaciones as $asignacion) {
                    $extension = (int)($asignacion['extension'] ?? 0);

                    if (
                        $extension > 0 &&
                        !in_array($extension, $numeros, true)
                    ) {
                        estadoTelefonia(
                            'ERROR',
                            'La extensión ' .
                            $extension .
                            ' está asignada en el CRM pero no existe en la centralita Zadarma.'
                        );
                    }
                }
            }
        } catch (Throwable $errorApi) {
            estadoTelefonia(
                'ERROR',
                'No fue posible validar la API/PBX de Zadarma: ' .
                $errorApi->getMessage()
            );
        }
    }
} catch (Throwable $error) {
    estadoTelefonia(
        'ERROR',
        'Falló la verificación local: ' . $error->getMessage()
    );
}

echo PHP_EOL;
echo 'Webhook que debe configurarse en Zadarma:' . PHP_EOL;
echo '  https://TU-DOMINIO/RUTA/prueba_telefonia/api/zadarma_webhook.php' . PHP_EOL;
echo PHP_EOL;

if ($errores > 0) {
    echo 'RESULTADO: NO LISTO · ' . $errores . ' error(es), ' .
        $avisos . ' aviso(s).' . PHP_EOL;
    exit(1);
}

echo 'RESULTADO: LISTO PARA PRUEBA REAL · ' .
    $avisos . ' aviso(s).' . PHP_EOL;
exit(0);
