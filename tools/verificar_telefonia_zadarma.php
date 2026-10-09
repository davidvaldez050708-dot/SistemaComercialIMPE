<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este verificador solo puede ejecutarse por CLI.');
}

$root = dirname(__DIR__);
require_once $root . '/config/private_config.php';
$configPath = impeRutaConfigPrivada('zadarma_config.php');
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
    require_once $root . '/app/models/RolModel.php';
    require_once $root . '/app/services/TelefoniaExtensionService.php';
    require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';

    $database = new Database();
    $connection = $database->connect();

    if (!$connection || !$connection->ping()) {
        throw new RuntimeException('La conexión MySQL no respondió al ping.');
    }

    estadoTelefonia('OK', 'Conexión a base de datos disponible.');

    (new RolModel())->inicializarPermisosSistema();
    estadoTelefonia(
        'OK',
        'Catálogo y permisos de telefonía inicializados.'
    );

    (new TelefoniaExtensionService())->asegurarEstructura();
    (new ZadarmaWebhookEventStoreService())->asegurarEstructura();

    estadoTelefonia(
        'OK',
        'Tablas telefonia_extensiones y telefonia_zadarma_eventos disponibles.'
    );

    $permisosRoles = $connection->query(
        "SELECT
            r.nombre AS rol,
            GROUP_CONCAT(
                p.codigo
                ORDER BY p.codigo
                SEPARATOR ', '
            ) AS permisos
         FROM roles r
         LEFT JOIN rol_permisos rp
            ON rp.rol_id = r.id
         LEFT JOIN permisos p
            ON p.id = rp.permiso_id
           AND p.estado = 1
           AND p.codigo LIKE 'telefonia.%'
         WHERE r.nombre IN (
            'Marketing',
            'Analista de Datos',
            'Asesor de Ventas'
         )
         GROUP BY r.id, r.nombre
         ORDER BY r.nombre"
    );

    if ($permisosRoles) {
        while ($filaRol = $permisosRoles->fetch_assoc()) {
            echo '    · ' .
                (string)($filaRol['rol'] ?? 'Rol') .
                ': ' .
                (
                    trim((string)($filaRol['permisos'] ?? '')) !== ''
                        ? (string)$filaRol['permisos']
                        : 'sin permisos de telefonía'
                ) .
                PHP_EOL;
        }
    }

    $sql = "SELECT
                te.extension,
                te.permite_salientes,
                te.permite_entrantes,
                u.nombre,
                u.apellidos,
                r.nombre AS rol
            FROM telefonia_extensiones te
            INNER JOIN usuarios u
                ON u.id = te.usuario_id
            INNER JOIN roles r
                ON r.id = u.rol_id
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
            'No hay extensiones Zadarma activas asignadas a usuarios. Asigna recepción y equipo desde Administración > Telefonía.'
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
            $capacidades = [];

            if (!empty($asignacion['permite_salientes'])) {
                $capacidades[] = 'salientes';
            }

            if (!empty($asignacion['permite_entrantes'])) {
                $capacidades[] = 'entrantes';
            }

            echo '    - ' .
                ($nombre !== '' ? $nombre : 'Usuario') .
                ' · ' .
                trim((string)($asignacion['rol'] ?? '')) .
                ': extensión ' .
                (string)($asignacion['extension'] ?? '') .
                ' (' .
                (
                    !empty($capacidades)
                        ? implode(' + ', $capacidades)
                        : 'sin capacidades habilitadas'
                ) .
                ')' .
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

                try {
                    $callInfoBody = $api->call(
                        '/v1/pbx/callinfo/',
                        [],
                        'get'
                    );
                    $callInfo = json_decode(
                        (string)$callInfoBody,
                        true
                    );

                    $webhookUrl = trim(
                        (string)($callInfo['url'] ?? '')
                    );
                    $notifications = is_array(
                        $callInfo['notifications'] ?? null
                    )
                        ? $callInfo['notifications']
                        : [];

                    if ($webhookUrl === '') {
                        estadoTelefonia(
                            'AVISO',
                            'La nueva cuenta Zadarma todavía no tiene configurada la URL de notificaciones PBX.'
                        );
                    } else {
                        estadoTelefonia(
                            'OK',
                            'Zadarma tiene configurada una URL de notificaciones PBX.'
                        );
                    }

                    $requiredNotifications = [
                        'notify_start',
                        'notify_internal',
                        'notify_answer',
                        'notify_end',
                        'notify_out_start',
                        'notify_out_end',
                    ];
                    $missingNotifications = [];

                    foreach ($requiredNotifications as $notification) {
                        $enabled = strtolower(
                            trim(
                                (string)(
                                    $notifications[$notification] ??
                                    'false'
                                )
                            )
                        ) === 'true';

                        if (!$enabled) {
                            $missingNotifications[] =
                                $notification;
                        }
                    }

                    if (empty($missingNotifications)) {
                        estadoTelefonia(
                            'OK',
                            'Notificaciones PBX necesarias para entrantes y salientes habilitadas.'
                        );
                    } else {
                        estadoTelefonia(
                            'AVISO',
                            'Faltan notificaciones PBX: ' .
                            implode(
                                ', ',
                                $missingNotifications
                            ) .
                            '.'
                        );
                    }
                } catch (Throwable $errorCallInfo) {
                    estadoTelefonia(
                        'AVISO',
                        'No fue posible consultar la configuración de notificaciones PBX: ' .
                        $errorCallInfo->getMessage()
                    );
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
