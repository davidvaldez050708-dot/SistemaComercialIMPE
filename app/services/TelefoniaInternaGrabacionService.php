<?php
/**
 * Relación uno-a-uno entre sesión WebRTC interna y grabación PBX real.
 * El SDK conserva su identificador original. Nunca se asocia audio por hora
 * en una solicitud HTTP: el vínculo lo establece el diagnóstico CLI validado.
 */
final class TelefoniaInternaGrabacionService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function asegurarEstructura(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_webrtc_grabaciones_internas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            session_id VARCHAR(100) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            call_id_with_rec VARCHAR(100) NOT NULL,
            extension_origen VARCHAR(12) NOT NULL,
            extension_destino VARCHAR(12) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_interna_sdk (usuario_id, session_id),
            UNIQUE KEY uq_interna_pbx (pbx_call_id),
            UNIQUE KEY uq_interna_audio (call_id_with_rec),
            KEY idx_interna_usuario (usuario_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public function porUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id FROM telefonia_webrtc_grabaciones_internas
             WHERE usuario_id = ?"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_fill_keys(array_column($filas, 'session_id'), true);
    }

    public function obtener(int $usuarioId, string $sessionId): ?array
    {
        if ($usuarioId <= 0 || !preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId)) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT v.session_id, v.pbx_call_id, v.call_id_with_rec,
                    v.extension_origen, v.extension_destino
             FROM telefonia_webrtc_grabaciones_internas v
             INNER JOIN telefonia_analista_webrtc_sesiones s
                ON s.usuario_id = v.usuario_id
               AND s.session_id = v.session_id
               AND s.extension = v.extension_destino
               AND s.numero_origen = v.extension_origen
             WHERE v.usuario_id = ? AND v.session_id = ?
               AND s.estado = 'ended' AND s.clasificacion = 'INTERNA'
               AND s.seguimiento_id IS NULL AND s.interaccion_id IS NULL
             LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    /**
     * Conciliación a demanda de una llamada INTERNA ya finalizada. Sólo
     * relaciona el audio si hay una única coincidencia PBX en la API oficial.
     * No crea interacciones institucionales ni expone identificadores.
     */
    public function sincronizar(int $usuarioId, string $sessionId): bool
    {
        if ($usuarioId <= 0 ||
            !preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId)) {
            throw new DomainException('Sesión telefónica inválida.');
        }
        $this->asegurarEstructura();
        if ($this->obtener($usuarioId, $sessionId)) {
            return true;
        }
        $stmt = $this->db->prepare(
            "SELECT s.extension, s.numero_origen, s.estado,
                    s.clasificacion, s.seguimiento_id, s.interaccion_id,
                    s.fecha_inicio, s.fecha_atendida, s.fecha_fin
               FROM telefonia_analista_webrtc_sesiones s
              WHERE s.usuario_id = ? AND s.session_id = ?
              LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$session || $session['estado'] !== 'ended' ||
            $session['clasificacion'] !== 'INTERNA' ||
            $session['seguimiento_id'] !== null ||
            $session['interaccion_id'] !== null) {
            throw new DomainException('Sólo se buscan grabaciones de llamadas internas finalizadas y sin expediente.');
        }
        $orig = (string)$session['numero_origen'];
        $dest = (string)$session['extension'];
        if (!preg_match('/^[0-9]{3,6}$/D', $orig) ||
            !preg_match('/^[0-9]{3,6}$/D', $dest) || $orig === $dest) {
            throw new DomainException('Las extensiones internas no son válidas.');
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT extension) AS extensiones
               FROM telefonia_extensiones
              WHERE extension IN (?, ?) AND proveedor = 'ZADARMA' AND activo = 1"
        );
        $stmt->bind_param('ss', $orig, $dest);
        $stmt->execute();
        $activas = (int)($stmt->get_result()->fetch_assoc()['extensiones'] ?? 0);
        $stmt->close();
        if ($activas !== 2) {
            throw new DomainException('Las extensiones no están ambas activas.');
        }
        $utc = new DateTimeZone('UTC');
        $mexico = new DateTimeZone('America/Mexico_City');
        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', (string)$session['fecha_inicio'], $utc
        );
        $answer = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', (string)$session['fecha_atendida'], $utc
        );
        $end = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', (string)$session['fecha_fin'], $utc
        );
        if (!$start || !$answer || !$end) {
            throw new DomainException('La conversación interna no tiene fechas completas.');
        }
        $duration = $end->getTimestamp() - $answer->getTimestamp();
        if ($duration < 1 || $duration > 7200) {
            throw new DomainException('La duración de la sesión no es verificable.');
        }
        if (time() - $end->getTimestamp() < 45) {
            throw new DomainException('Zadarma puede tardar en preparar el audio. Inténtalo en un minuto.');
        }

        $root = dirname(__DIR__, 2);
        require_once $root . '/config/private_config.php';
        $path = impeRutaConfigPrivada('zadarma_config.php');
        if (!$path || !is_file($path) || !is_file($root . '/vendor/autoload.php')) {
            throw new RuntimeException('Zadarma no está configurada.');
        }
        $cred = require $path;
        $key = trim((string)($cred['api_key'] ?? ''));
        $secret = trim((string)($cred['api_secret'] ?? ''));
        if ($key === '' || $secret === '') {
            throw new RuntimeException('Faltan credenciales de Zadarma.');
        }
        require_once $root . '/vendor/autoload.php';
        $api = new \Zadarma_API\Api($key, $secret, false);
        // Incluir ambos formatos horarios posibles del proveedor, pero
        // comparar siempre cada candidata como instante real.
        $from = $start->setTimezone($mexico)->modify('-5 minutes')->format('Y-m-d H:i:s');
        $to = $start->modify('+5 minutes')->format('Y-m-d H:i:s');
        $response = json_decode((string)$api->call(
            '/v1/statistics/pbx/',
            ['start' => $from, 'end' => $to, 'version' => 2, 'limit' => 1000],
            'get'
        ), true);
        if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
            throw new RuntimeException('No fue posible consultar la estadística de Zadarma.');
        }
        $stats = is_array($response['stats'] ?? null) ? $response['stats'] : [];
        if (count($stats) >= 1000) {
            throw new DomainException('Hay demasiadas llamadas para asociar el audio de forma segura.');
        }
        $matches = [];
        foreach ($stats as $row) {
            if (!is_array($row)) continue;
            $source = preg_replace('/\D+/', '', (string)($row['sip'] ?? '')) ?: '';
            $called = preg_replace('/\D+/', '', (string)($row['destination'] ?? '')) ?: '';
            if ($source !== $orig || $called !== $dest ||
                abs((int)($row['seconds'] ?? -100) - $duration) > 2 ||
                !filter_var($row['is_recorded'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $pbx = trim((string)($row['pbx_call_id'] ?? ''));
            $recordId = trim((string)($row['call_id'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $pbx) ||
                !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $recordId)) continue;

            $time = trim((string)($row['callstart'] ?? ''));
            $delta = null;
            foreach ([$utc, $mexico] as $tz) {
                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s', $time, $tz
                );
                if (!$date || $date->format('Y-m-d H:i:s') !== $time) continue;
                $difference = abs($date->getTimestamp() - $start->getTimestamp());
                $delta = $delta === null ? $difference : min($delta, $difference);
            }
            if ($delta === null || $delta > 90) continue;
            $matches[] = ['pbx' => $pbx, 'record' => $recordId];
        }
        if (count($matches) !== 1) {
            throw new DomainException(
                'La grabación no está lista o hay varias candidatas. No se vinculó ningún audio.'
            );
        }
        // Comprobar que el segmento exacto existe antes de guardar su vínculo.
        $record = $api->getPbxRecord($matches[0]['record'], null, 300);
        $link = trim((string)($record->link ?? ''));
        if (!str_starts_with(strtolower($link), 'https://') ||
            !filter_var($link, FILTER_VALIDATE_URL)) {
            throw new DomainException('La grabación todavía no está disponible en Zadarma.');
        }

        $this->db->begin_transaction();
        try {
            $lock = $this->db->prepare(
                "SELECT clasificacion, seguimiento_id, interaccion_id, estado,
                        extension, numero_origen
                   FROM telefonia_analista_webrtc_sesiones
                  WHERE usuario_id = ? AND session_id = ? FOR UPDATE"
            );
            $lock->bind_param('is', $usuarioId, $sessionId);
            $lock->execute();
            $rowNow = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if (!$rowNow || $rowNow['clasificacion'] !== 'INTERNA' ||
                $rowNow['estado'] !== 'ended' ||
                $rowNow['seguimiento_id'] !== null ||
                $rowNow['interaccion_id'] !== null ||
                $rowNow['extension'] !== $dest ||
                $rowNow['numero_origen'] !== $orig) {
                throw new DomainException('El estado de la sesión cambió durante la búsqueda.');
            }
            $this->registrar($usuarioId, $sessionId, $matches[0]['pbx'],
                $matches[0]['record'], $orig, $dest);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function registrar(
        int $usuarioId, string $sessionId, string $pbxCallId,
        string $callId, string $origen, string $destino
    ): void {
        foreach ([$sessionId, $pbxCallId, $callId] as $id) {
            if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $id)) {
                throw new DomainException('El identificador de grabación no es válido.');
            }
        }
        if (!preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId) ||
            !preg_match('/^\d{3,6}$/D', $origen) ||
            !preg_match('/^\d{3,6}$/D', $destino) || $origen === $destino) {
            throw new DomainException('La llamada no es una sesión interna válida.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_webrtc_grabaciones_internas
              (usuario_id, session_id, pbx_call_id, call_id_with_rec,
               extension_origen, extension_destino)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssss', $usuarioId, $sessionId, $pbxCallId, $callId, $origen, $destino);
        $stmt->execute();
        $stmt->close();
    }
}
