<?php
/**
 * Reparación conservadora de la prueba interna 100 -> 101 del 14/10/2026.
 * PREVIEW: php tools/reparar_prueba_interna_100_101.php --check RUTA_PUBLICA
 * APLICAR: php tools/reparar_prueba_interna_100_101.php --apply RUTA_PUBLICA --confirmar-prueba-interna
 *
 * Sólo elimina la interacción institucional errónea DESPUÉS de conservar
 * una copia íntegra en una tabla de auditoría dentro de la misma transacción.
 * No modifica el expediente ni otras interacciones.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo se puede ejecutar por SSH.');
}
$modo = (string)($argv[1] ?? '');
$root = realpath((string)($argv[2] ?? ''));
if (!in_array($modo, ['--check', '--apply'], true) || !$root ||
    !is_file($root . '/config/db_connection.php') ||
    count($argv) !== ($modo === '--apply' ? 4 : 3) ||
    ($modo === '--apply' && ($argv[3] ?? '') !== '--confirmar-prueba-interna')) {
    fwrite(STDERR, "Uso: php tools/reparar_prueba_interna_100_101.php --check RUTA_PUBLICA\n"
        . "  o: php tools/reparar_prueba_interna_100_101.php --apply RUTA_PUBLICA --confirmar-prueba-interna\n");
    exit(2);
}
// La previsualización debe informar cada etapa, incluso si PHP interrumpe
// la ejecución antes de llegar al resultado del diagnóstico.
$etapaDiagnostico = 'inicio';
$diagnosticoFinalizado = false;
if ($modo === '--check') {
    fwrite(STDERR, "[CHECK] Iniciando comprobación de la llamada interna; sin escrituras.\n");
    register_shutdown_function(static function () use (&$etapaDiagnostico, &$diagnosticoFinalizado): void {
        if ($diagnosticoFinalizado) {
            return;
        }
        $last = error_get_last();
        $fatal = is_array($last) &&
            in_array((int)($last['type'] ?? 0),
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        fwrite(STDERR, "[CHECK] La ejecución terminó antes de obtener un resultado. Etapa: "
            . $etapaDiagnostico . ($fatal ? "; fallo fatal PHP" : "; causa no informada") . ".\n");
    });
}
require_once $root . '/config/db_connection.php';
require_once $root . '/config/private_config.php';
require_once $root . '/app/services/TelefoniaInternaGrabacionService.php';
require_once __DIR__ . '/../app/helpers/ZadarmaPbxIdentifierHelper.php';

function validarSesion(array $s): void
{
    if ((string)($s['clasificacion'] ?? '') !== 'VINCULADA' ||
        (string)($s['estado'] ?? '') !== 'ended' ||
        (int)($s['interaccion_id'] ?? 0) <= 0 ||
        (int)($s['seguimiento_id'] ?? 0) <= 0 ||
        (int)$s['interaccion_id'] !== (int)($s['id_interaccion'] ?? 0) ||
        (int)$s['seguimiento_id'] !== (int)($s['id_seguimiento'] ?? 0) ||
        (int)$s['usuario_id'] !== (int)($s['usuario_interaccion'] ?? 0) ||
        (string)($s['id_externo_interaccion'] ?? '') !== 'WEBRTC-' . $s['session_id'] ||
        (string)($s['proveedor_interaccion'] ?? '') !== 'ZADARMA_WEBRTC' ||
        (string)($s['canal_interaccion'] ?? '') !== 'LLAMADA_IP' ||
        (string)($s['resultado_interaccion'] ?? '') !== 'CONTACTADO' ||
        abs((int)($s['duracion_interaccion'] ?? 0) - 11) > 2 ||
        (string)($s['institucion'] ?? '') !== 'DIR GRAL DE ADMINISTRACION Y FINANZAS' ||
        (string)($s['estado_expediente'] ?? '') !== 'CONTACTANDO' ||
        (int)($s['datos_verificados_expediente'] ?? 0) !== 0 ||
        strpos((string)($s['notas_interaccion'] ?? ''), '[WEBRTC OBSERVADO;') === false) {
        throw new DomainException('Los datos de la prueba o del expediente ya no coinciden. Se detiene la reparación.');
    }
}
function referencias(mysqli $db, int $interaccion): void
{
    // No eliminar ninguna interacción referenciada por tablas de otros módulos.
    $rows = $db->query(
        "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = 'interacciones_vinculacion'
           AND REFERENCED_COLUMN_NAME = 'id'"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $f) {
        $schema = (string)$f['TABLE_SCHEMA'];
        $table = (string)$f['TABLE_NAME'];
        $col = (string)$f['COLUMN_NAME'];
        foreach ([$schema,$table,$col] as $part) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $part)) {
                throw new DomainException('Referencia FK inesperada.');
            }
        }
        $q = $db->prepare("SELECT COUNT(*) AS n FROM " . $schema . "." . $table .
            " WHERE " . $col . " = ?");
        $q->bind_param('i', $interaccion);
        $q->execute();
        $n = (int)($q->get_result()->fetch_assoc()['n'] ?? 0);
        $q->close();
        if ($n > 0) throw new DomainException('La interacción tiene dependencias: requiere revisión manual.');
    }
}
function seleccionarGrabacion(string $root, DateTimeImmutable $hora, array $sdk, bool $diagnostico = false): array
{
    $privada = impeRutaConfigPrivada('zadarma_config.php');
    if (!$privada || !is_file($privada) || !is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('No está disponible la API de Zadarma.');
    }
    require_once $root . '/vendor/autoload.php';
    $cfg = require $privada;
    $key = trim((string)($cfg['api_key'] ?? ''));
    $secret = trim((string)($cfg['api_secret'] ?? ''));
    if ($key === '' || $secret === '') throw new RuntimeException('Credenciales Zadarma incompletas.');
    $api = new \Zadarma_API\Api($key, $secret, false);
    $start = $hora->modify('-20 minutes')->format('Y-m-d H:i:s');
    $end = $hora->setTimezone(new DateTimeZone('UTC'))
        ->modify('+20 minutes')->format('Y-m-d H:i:s');
    $response = ZadarmaPbxIdentifierHelper::decodificarEstadisticas((string)$api->call('/v1/statistics/pbx/', [
        'start'=>$start, 'end'=>$end, 'version'=>2, 'limit'=>1000
    ], 'get'));
    if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
        throw new RuntimeException('Zadarma no entregó estadísticas válidas.');
    }
    $stats = is_array($response['stats'] ?? null) ? $response['stats'] : [];
    if (count($stats) >= 1000) throw new DomainException('Respuesta API incompleta por límite.');
    $sdkDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
        (string)$sdk['fecha_inicio'], new DateTimeZone('UTC'));
    if (!$sdkDate) throw new DomainException('Fecha WebRTC inválida.');
    $candidatas = [];
    $razones = [
        'direccion' => 0, 'duracion' => 0, 'grabada' => 0,
        'identificador' => 0, 'hora' => 0
    ];
    $cercanas = [];
    if ($diagnostico) {
        echo "INSPECCIÓN DE CORRELACIÓN (sin identificadores privados):\n";
        echo "Inicio SDK UTC: " . $sdkDate->format('Y-m-d H:i:s')
           . " | registros Zadarma: " . count($stats) . "\n";
    }
    foreach ($stats as $row) {
        if (!is_array($row)) continue;
        $sip = preg_replace('/\D+/', '', (string)($row['sip'] ?? '')) ?: '';
        $dest = preg_replace('/\D+/', '', (string)($row['destination'] ?? '')) ?: '';
        if ($sip !== '100' || $dest !== '101') {
            $razones['direccion']++;
            continue;
        }
        $t = trim((string)($row['callstart'] ?? ''));
        $deltas = [];
        foreach (['UTC','America/Mexico_City'] as $tz) {
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                $t, new DateTimeZone($tz));
            if ($fecha && $fecha->format('Y-m-d H:i:s') === $t) {
                $deltas[] = abs($fecha->getTimestamp() - $sdkDate->getTimestamp());
            }
        }
        $delta = $deltas ? min($deltas) : null;
        $pbx = trim((string)($row['pbx_call_id'] ?? ''));
        $callId = ZadarmaPbxIdentifierHelper::callIdDeFila($row);
        $idsValidos = ZadarmaPbxIdentifierHelper::pbxValido($pbx) &&
            $callId !== '';
        $grabada = filter_var($row['is_recorded'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $duracion = (int)($row['seconds'] ?? -100);
        $duracionValida = abs($duracion - 11) <= 2;
        if ($diagnostico) {
            // Hora y duración no son credenciales. Nunca imprimir pbx_call_id
            // ni call_id; sólo confirmar si pasan la validación actual.
            $cercanas[] = '  PBX 100→101: hora_API=' . $t
                . ' duracion=' . $duracion . 's'
                . ' diferencia_inicio_SDK=' . ($delta === null ? 'desconocida' : $delta . 's')
                . ' grabada=' . ($grabada ? 'SI' : 'NO')
                . ' formato_ids=' . ($idsValidos ? 'VALIDO' : 'RECHAZADO');
        }
        if (!$duracionValida) { $razones['duracion']++; continue; }
        if (!$grabada) { $razones['grabada']++; continue; }
        if (!$idsValidos) { $razones['identificador']++; continue; }
        if ($delta === null || $delta > 90) { $razones['hora']++; continue; }
        $candidatas[] = ['pbx'=>$pbx,'audio'=>$callId];
    }
    if ($diagnostico) {
        foreach (array_slice($cercanas, 0, 10) as $linea) echo $linea . "\n";
        echo "Rechazos: direccion={$razones['direccion']} "
            . "duracion={$razones['duracion']} grabada={$razones['grabada']} "
            . "formato_id={$razones['identificador']} "
            . "desfase_SDK={$razones['hora']}\n";
        echo "Candidatas que superan todos los filtros: " . count($candidatas) . "\n";
    }
    if (count($candidatas) !== 1) {
        throw new DomainException('No hay una grabación PBX inequívoca (' . count($candidatas) . ' candidatas).');
    }
    if ($diagnostico) {
        fwrite(STDERR, "[CHECK] Coincidencia única encontrada. Validando enlace temporal de Zadarma.\n");
    }
    $record = $api->getPbxRecord($candidatas[0]['audio'], null, 300);
    $link = trim((string)($record->link ?? ''));
    if (!str_starts_with(strtolower($link), 'https://') ||
        !filter_var($link, FILTER_VALIDATE_URL)) {
        throw new DomainException('El segmento concreto no pudo verificarse en Zadarma.');
    }
    return $candidatas[0];
}

try {
    $etapaDiagnostico = 'conexión MySQL';
    $db = (new Database())->connect();
    $hora = new DateTimeImmutable('2026-10-14 10:49:00', new DateTimeZone('America/Mexico_City'));
    $desde = $hora->setTimezone(new DateTimeZone('UTC'))->modify('-2 minutes')->format('Y-m-d H:i:s');
    $hasta = $hora->setTimezone(new DateTimeZone('UTC'))->modify('+2 minutes')->format('Y-m-d H:i:s');
    $stmt = $db->prepare(
        "SELECT s.*,
          i.id AS id_interaccion, i.seguimiento_id AS id_seguimiento,
          i.usuario_id AS usuario_interaccion, i.canal AS canal_interaccion,
          i.resultado AS resultado_interaccion, i.notas AS notas_interaccion,
          i.id_externo AS id_externo_interaccion,
          i.proveedor_externo AS proveedor_interaccion,
          i.duracion_segundos AS duracion_interaccion,
          se.nombre_entidad AS institucion,
          se.estado_seguimiento AS estado_expediente,
          se.datos_verificados AS datos_verificados_expediente
        FROM telefonia_analista_webrtc_sesiones s
        LEFT JOIN interacciones_vinculacion i ON i.id = s.interaccion_id
        LEFT JOIN seguimientos_vinculacion se ON se.id = s.seguimiento_id
        WHERE s.extension = '101' AND s.numero_origen = '100'
          AND s.estado = 'ended' AND s.fecha_inicio BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $desde, $hasta);
    $stmt->execute();
    $sesiones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($sesiones) !== 1) {
        throw new DomainException('Se encontraron ' . count($sesiones) . ' sesiones; ninguna operación fue autorizada.');
    }
    $etapaDiagnostico = 'validación de sesión y expediente';
    $sdk = $sesiones[0];
    validarSesion($sdk);
    referencias($db, (int)$sdk['interaccion_id']);
    $etapaDiagnostico = 'estadísticas y enlace de audio Zadarma';
    $audio = seleccionarGrabacion($root, $hora, $sdk, $modo === '--check');
    echo "OK: prueba 100 → 101 del 14/10 a las 10:49 verificada.\n";
    echo "Una interacción asociada al expediente indicado. Una grabación PBX real verificada.\n";
    echo "Sin dependencias FK; no se detectaron otras sesiones coincidentes.\n";
    if ($modo === '--check') {
        echo "PREVISUALIZACIÓN: BD intacta. Para aplicar: --apply RUTA_PUBLICA --confirmar-prueba-interna\n";
        $diagnosticoFinalizado = true;
        exit(0);
    }
    $links = new TelefoniaInternaGrabacionService($db);
    $links->asegurarEstructura();
    $db->query("CREATE TABLE IF NOT EXISTS telefonia_pruebas_internas_auditoria (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL, session_id VARCHAR(100) NOT NULL,
        interaccion_original_id INT NOT NULL, seguimiento_original_id INT NOT NULL,
        original_json LONGTEXT NOT NULL,
        motivo VARCHAR(140) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_prueba_sdk (usuario_id, session_id),
        UNIQUE KEY uq_prueba_interaccion (interaccion_original_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $db->begin_transaction();
    try {
        $sdkId = (int)$sdk['id'];
        $iid = (int)$sdk['interaccion_id'];
        $uid = (int)$sdk['usuario_id'];
        $seg = (int)$sdk['seguimiento_id'];
        $sessionId = (string)$sdk['session_id'];
        $q = $db->prepare("SELECT * FROM telefonia_analista_webrtc_sesiones WHERE id = ? FOR UPDATE");
        $q->bind_param('i', $sdkId);
        $q->execute();
        $current = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$current || (int)$current['interaccion_id'] !== $iid ||
            (int)$current['seguimiento_id'] !== $seg ||
            (string)$current['clasificacion'] !== 'VINCULADA' ||
            (string)$current['session_id'] !== $sessionId) {
            throw new DomainException('La sesión cambió durante el diagnóstico.');
        }
        $q = $db->prepare("SELECT * FROM interacciones_vinculacion WHERE id = ? FOR UPDATE");
        $q->bind_param('i', $iid);
        $q->execute();
        $interaction = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$interaction || (int)$interaction['seguimiento_id'] !== $seg ||
            (string)$interaction['notas'] !== (string)$sdk['notas_interaccion'] ||
            (string)$interaction['id_externo'] !== (string)$sdk['id_externo_interaccion']) {
            throw new DomainException('La interacción fue modificada.');
        }
        referencias($db, $iid);
        $q = $db->prepare(
            "SELECT ultima_interaccion_at, proxima_accion_at,
                    estado_seguimiento, datos_verificados
             FROM seguimientos_vinculacion WHERE id = ? FOR UPDATE"
        );
        $q->bind_param('i', $seg);
        $q->execute();
        $expedienteActual = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$expedienteActual ||
            (string)$expedienteActual['estado_seguimiento'] !== 'CONTACTANDO' ||
            (int)$expedienteActual['datos_verificados'] !== 0) {
            throw new DomainException('El expediente cambió durante la revisión.');
        }
        $snapshot = json_encode([
            'sesion'=>$current,
            'interaccion'=>$interaction,
            'expediente_campos_previos'=>$expedienteActual
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $q = $db->prepare(
            "INSERT INTO telefonia_pruebas_internas_auditoria
            (usuario_id, session_id, interaccion_original_id,
             seguimiento_original_id, original_json, motivo)
            VALUES (?, ?, ?, ?, ?, 'Prueba interna 100 a 101; no contacto institucional')"
        );
        $q->bind_param('isiis', $uid, $sessionId, $iid, $seg, $snapshot);
        $q->execute();
        $q->close();
        $q = $db->prepare(
            "UPDATE telefonia_analista_webrtc_sesiones
             SET clasificacion = 'INTERNA', seguimiento_id = NULL, interaccion_id = NULL
             WHERE id = ? AND usuario_id = ? AND clasificacion = 'VINCULADA'
               AND seguimiento_id = ? AND interaccion_id = ?"
        );
        $q->bind_param('iiii', $sdkId, $uid, $seg, $iid);
        $q->execute();
        if ($q->affected_rows !== 1) throw new DomainException('No se desvinculó la sesión.');
        $q->close();

        $q = $db->prepare(
            "DELETE FROM interacciones_vinculacion
             WHERE id = ? AND usuario_id = ? AND seguimiento_id = ?
               AND canal = 'LLAMADA_IP' AND proveedor_externo = 'ZADARMA_WEBRTC'"
        );
        $q->bind_param('iii', $iid, $uid, $seg);
        $q->execute();
        if ($q->affected_rows !== 1) throw new DomainException('La interacción ya no coincide.');
        $q->close();
        // La interacción de prueba alteró también ultima_interaccion_at.
        // Si todavía es el último registro, recalcular la fecha a partir de
        // las interacciones restantes. Nunca tocar otra fecha más reciente.
        if ((string)($expedienteActual['ultima_interaccion_at'] ?? '') ===
            (string)$interaction['fecha_inicio']) {
            $q = $db->prepare(
                "UPDATE seguimientos_vinculacion
                 SET ultima_interaccion_at = (
                     SELECT MAX(fecha_inicio) FROM interacciones_vinculacion
                     WHERE seguimiento_id = ?
                 )
                 WHERE id = ? AND ultima_interaccion_at = ?"
            );
            $fechaAnterior = (string)$interaction['fecha_inicio'];
            $q->bind_param('iis', $seg, $seg, $fechaAnterior);
            $q->execute();
            $q->close();
        }
        $links->registrar($uid, $sessionId, $audio['pbx'], $audio['audio'], '100', '101');
        $db->commit();
        echo "OK: prueba interna separada del expediente, respaldo de auditoría creado y audio vinculado.\n";
        echo "Ninguna otra interacción ni el estado del expediente fueron alterados.\n";
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    $diagnosticoFinalizado = true;
    fwrite(STDERR, "DETENIDO: etapa=" . $etapaDiagnostico . "; " .
        get_class($e) . "; " . $e->getMessage() . "\n");
    exit(1);
}
