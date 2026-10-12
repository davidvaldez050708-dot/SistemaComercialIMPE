<?php
/**
 * Diagnóstico CLI, de solo lectura, de llamadas recibidas por Zadarma.
 *
 * Uso:
 *   php tools/diagnosticar_entrantes_zadarma.php 100
 *
 * No realiza llamadas, no crea tablas, no consulta API ni revela teléfonos,
 * tokens, nombres, IDs de llamada o contenido de webhooks.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo disponible desde la terminal.');
}

$extension = trim((string)($argv[1] ?? '100'));
if (!preg_match('/^[0-9]{3,6}$/D', $extension) || count($argv) > 2) {
    fwrite(STDERR, "Uso: php tools/diagnosticar_entrantes_zadarma.php [extension]\n");
    exit(2);
}

$raiz = dirname(__DIR__);
$webhook = $raiz . '/prueba_telefonia/api/zadarma_webhook.php';
$endpoint = $raiz . '/prueba_telefonia/api/zadarma_entrada_activa.php';
$log = $raiz . '/storage/zadarma_webhooks.log';

echo "DIAGNÓSTICO DE LLAMADAS ENTRANTES - EXTENSIÓN {$extension}\n";
echo "Webhook instalado: " . (is_file($webhook) ? 'SÍ' : 'NO') . "\n";
echo "Consulta de llamadas instalada: " . (is_file($endpoint) ? 'SÍ' : 'NO') . "\n";
echo "Registro auxiliar de webhook: " .
    (is_file($log) ? 'EXISTE' : 'SIN ARCHIVO') . "\n";
echo "Nota: el registro auxiliar no sustituye a la base de datos.\n";

try {
    require_once $raiz . '/config/db_connection.php';
    $db = (new Database())->connect();
    $db->set_charset('utf8mb4');

    $verificar = $db->query("SHOW TABLES LIKE 'telefonia_zadarma_eventos'");
    if (!$verificar || $verificar->num_rows === 0) {
        echo "Tabla de eventos: NO EXISTE\n";
        echo "DIAGNÓSTICO: no hay eventos guardados. Verifica la URL y las notificaciones de Zadarma.\n";
        exit(0);
    }
    echo "Tabla de eventos: EXISTE\n";

    $ventanaMinutos = 30;
    $sql = "SELECT evento,
                   CASE WHEN internal = ? THEN 'ESTA_EXTENSION'
                        WHEN internal IS NULL OR internal = '' THEN 'SIN_EXTENSION'
                        ELSE 'OTRA_EXTENSION' END AS destino,
                   COUNT(*) AS cantidad
            FROM telefonia_zadarma_eventos
            WHERE received_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            GROUP BY evento, destino
            ORDER BY evento, destino";
    $st = $db->prepare($sql);
    if (!$st) {
        throw new RuntimeException('No fue posible preparar el diagnóstico.');
    }
    $st->bind_param('s', $extension);
    $st->execute();
    $results = $st->get_result();
    $cuentas = [];
    $total = 0;
    $entrantes = 0;
    $otrosInternos = 0;
    $inicio = 0;
    echo "\nEVENTOS ÚLTIMOS {$ventanaMinutos} MINUTOS (sólo cantidades)\n";
    while ($fila = $results->fetch_assoc()) {
        $tipo = (string)$fila['evento'];
        $destino = (string)$fila['destino'];
        $numero = (int)$fila['cantidad'];
        $total += $numero;
        if ($tipo === 'NOTIFY_INTERNAL' && $destino === 'ESTA_EXTENSION') {
            $entrantes += $numero;
        }
        if ($tipo === 'NOTIFY_INTERNAL' && $destino === 'OTRA_EXTENSION') {
            $otrosInternos += $numero;
        }
        if ($tipo === 'NOTIFY_START') {
            $inicio += $numero;
        }
        $cuentas[] = "{$tipo} | {$destino} | {$numero}";
    }
    $st->close();
    if (!$cuentas) {
        echo "Sin eventos recientes.\n";
    } else {
        echo implode("\n", $cuentas) . "\n";
    }

    $ultimo = $db->query("SELECT MAX(received_at) AS ultimo
                           FROM telefonia_zadarma_eventos")->fetch_assoc();
    $hora = trim((string)($ultimo['ultimo'] ?? ''));
    echo "\nÚltima notificación guardada: " . ($hora !== '' ? $hora : 'NINGUNA') . "\n";
    echo "Hora actual de MySQL: " .
        (string)$db->query('SELECT NOW() AS ahora')->fetch_assoc()['ahora'] . "\n";

    echo "\nINTERPRETACIÓN\n";
    if ($entrantes > 0) {
        echo "Se recibieron llamadas dirigidas a la extensión {$extension}.\n";
        echo "Si el CRM no las mostró, revisar sondeo, respuesta de API y conexión WebRTC.\n";
    } elseif ($inicio > 0) {
        echo "La centralita notificó entradas, pero no hay NOTIFY_INTERNAL para {$extension}.\n";
        echo $otrosInternos > 0
            ? "Sí hay notificaciones para otras extensiones: revisar IVR/ruta a {$extension}.\n"
            : "Verificar NOTIFY_INTERNAL habilitado y ruta de la centralita.\n";
    } elseif ($total > 0) {
        echo "Hay actividad de Zadarma, pero sin inicio y entrega a {$extension}.\n";
        echo "Revisar rutas del IVR, notificaciones y hora de la prueba.\n";
    } else {
        echo "No hay actividad reciente de webhook en MySQL.\n";
        echo "Verificar URL pública del webhook, permisos y notificaciones NOTIFY_START / NOTIFY_INTERNAL.\n";
    }
    echo "Importante: estos eventos NO certifican que el SIP/WebRTC esté registrado.\n";
    echo "Comprueba por separado si la extensión figura ONLINE en Zadarma.\n";
} catch (Throwable $e) {
    error_log('[diagnostico-entrantes] ' . $e->getMessage());
    fwrite(STDERR, "ERROR: no fue posible consultar eventos en MySQL.\n");
    exit(1);
}
