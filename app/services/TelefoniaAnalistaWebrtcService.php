<?php
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../helpers/TelefoniaFechaHoraHelper.php';
require_once __DIR__ . '/TelefoniaExtensionService.php';

/**
 * Bitácora de eventos WEBRTC reportados por la sesión autenticada del Analista.
 * NO son webhooks firmados ni identificadores PBX: deben mostrarse como tal.
 * Las fechas se asignan EN EL SERVIDOR; no se aceptan duraciones enviadas
 * por el navegador ni se contabilizan verificaciones institucionales.
 */
class TelefoniaAnalistaWebrtcService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_analista_webrtc_sesiones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            extension VARCHAR(12) NOT NULL,
            session_id VARCHAR(100) NOT NULL,
            numero_origen VARCHAR(40) NOT NULL DEFAULT '',
            caller_name VARCHAR(100) NOT NULL DEFAULT '',
            estado VARCHAR(16) NOT NULL DEFAULT 'ringing',
            fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_atendida DATETIME NULL,
            fecha_fin DATETIME NULL,
            duracion_observada_segundos INT UNSIGNED NULL,
            clasificacion VARCHAR(24) NOT NULL DEFAULT 'PENDIENTE',
            seguimiento_id INT NULL,
            interaccion_id INT NULL,
            observacion VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_analista_webrtc_sesion (usuario_id, session_id),
            KEY idx_analista_webrtc_lista (usuario_id, fecha_inicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    private function asegurarDuracionObservada(): bool
    {
        try {
            $cols = $this->db->query(
                "SHOW COLUMNS FROM telefonia_analista_webrtc_sesiones
                  LIKE 'duracion_observada_segundos'"
            );
            if ($cols && $cols->num_rows > 0) return true;
            $this->db->query(
                "ALTER TABLE telefonia_analista_webrtc_sesiones
                  ADD COLUMN duracion_observada_segundos INT UNSIGNED NULL AFTER fecha_fin"
            );
            return true;
        } catch (Throwable $error) {
            try {
                $cols = $this->db->query(
                    "SHOW COLUMNS FROM telefonia_analista_webrtc_sesiones
                      LIKE 'duracion_observada_segundos'"
                );
                if ($cols && $cols->num_rows > 0) return true;
            } catch (Throwable $ignored) {}
            error_log('[telefonia_duracion_observada] ' . get_class($error));
            return false;
        }
    }

    public static function validarSesion(string $id): bool
    {
        return (bool)preg_match('/^sdk-[a-z0-9-]{12,94}$/', $id);
    }

    private function extension(int $usuarioId): string
    {
        $a = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
        $extension = trim((string)($a['extension'] ?? ''));
        if (empty($a['permite_entrantes']) ||
            !preg_match('/^[0-9]{3,6}$/', $extension)) {
            throw new DomainException('No hay extensión entrante activa para este usuario.');
        }
        return $extension;
    }

    /**
     * Sólo se considera interna cuando el origen coincide exactamente con
     * otra extensión ZADARMA activa de la centralita. La numeración la
     * valida el servidor, no la clasificación sugerida por el navegador.
     */
    public function esNumeroInterno(string $numero, string $extension): bool
    {
        $numero = trim($numero);
        if (!preg_match('/^[0-9]{3,6}$/D', $numero) ||
            !preg_match('/^[0-9]{3,6}$/D', $extension) ||
            $numero === $extension) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM telefonia_extensiones
             WHERE extension = ? AND proveedor = 'ZADARMA' AND activo = 1
             LIMIT 1"
        );
        $stmt->bind_param('s', $numero);
        $stmt->execute();
        $existe = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $existe;
    }

    public function registrarEvento(int $usuarioId, array $datos): void
    {
        $id = trim((string)($datos['session_id'] ?? ''));
        $evento = trim((string)($datos['evento'] ?? ''));
        if ($usuarioId <= 0 || !self::validarSesion($id) ||
            !in_array($evento, ['ringing', 'answered', 'ended', 'declined', 'missed'], true)) {
            throw new InvalidArgumentException('Evento WebRTC no válido.');
        }
        $extension = $this->extension($usuarioId);
        if ($evento === 'ringing') {
            $numero = trim((string)($datos['numero'] ?? ''));
            $nombre = trim((string)($datos['caller_name'] ?? ''));
            if (!preg_match('/^[+0-9*#]{0,40}$/', $numero)) {
                $numero = '';
            }
            $nombre = mb_substr($nombre, 0, 100, 'UTF-8');
            // Nunca modificar una sesión que ya existe (evita duplicados).
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO telefonia_analista_webrtc_sesiones
                  (usuario_id, extension, session_id, numero_origen, caller_name, fecha_inicio)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            );
            $stmt->bind_param('issss', $usuarioId, $extension, $id, $numero, $nombre);
            $stmt->execute();
            $stmt->close();
            return;
        }
        // Avance monotónico del estado: un cierre nunca reabre una sesión.
        if ($evento === 'answered') {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = 'answered', fecha_atendida = COALESCE(fecha_atendida, UTC_TIMESTAMP())
                    WHERE usuario_id = ? AND session_id = ? AND extension = ?
                      AND estado = 'ringing'";
        } elseif ($evento === 'ended') {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = 'ended', fecha_fin = COALESCE(fecha_fin, UTC_TIMESTAMP())
                    WHERE usuario_id = ? AND session_id = ? AND extension = ?
                      AND estado = 'answered'";
        } else {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = ?, fecha_fin = COALESCE(fecha_fin, UTC_TIMESTAMP())
                    WHERE usuario_id = ? AND session_id = ? AND extension = ?
                      AND estado = 'ringing'";
        }
        $stmt = $this->db->prepare($sql);
        if ($evento === 'declined' || $evento === 'missed') {
            $stmt->bind_param('siss', $evento, $usuarioId, $id, $extension);
        } else {
            $stmt->bind_param('iss', $usuarioId, $id, $extension);
        }
        $stmt->execute();
        $stmt->close();

        // Un UPDATE que no encontró la sesión NO significa que se guardó.
        // Confirmar en BD, también para reintentos idempotentes.
        $check = $this->db->prepare(
            "SELECT estado, fecha_atendida, fecha_fin, numero_origen, clasificacion
             FROM telefonia_analista_webrtc_sesiones
             WHERE usuario_id = ? AND session_id = ? AND extension = ?
             LIMIT 1"
        );
        $check->bind_param('iss', $usuarioId, $id, $extension);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();
        $saved = match ($evento) {
            'answered' => $row &&
                !empty($row['fecha_atendida']) &&
                in_array($row['estado'], ['answered', 'ended'], true),
            'ended' => $row && $row['estado'] === 'ended' &&
                !empty($row['fecha_fin']),
            'declined', 'missed' => $row && $row['estado'] === $evento &&
                !empty($row['fecha_fin']),
            default => false
        };
        if (!$saved) {
            throw new RuntimeException('No se confirmó el guardado de la sesión WebRTC.');
        }

        // Sólo guardamos la duración como OBSERVACIÓN del navegador, no
        // como duración firmada por Zadarma. Los segundos deben caber
        // en el intervalo temporal entre el primer timbrado y el cierre.
        if ($evento === 'ended') {
            $secs = filter_var($datos['duracion_observada_segundos'] ?? null,
                FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>7200]]
            );
            if (is_int($secs) && $this->asegurarDuracionObservada()) {
                $up = $this->db->prepare(
                    "UPDATE telefonia_analista_webrtc_sesiones
                     SET duracion_observada_segundos = GREATEST(
                        COALESCE(duracion_observada_segundos,0), ?)
                     WHERE usuario_id=? AND session_id=? AND extension=?
                       AND estado='ended' AND fecha_atendida IS NOT NULL
                       AND fecha_fin IS NOT NULL
                       AND ? <= GREATEST(1,
                            TIMESTAMPDIFF(SECOND, fecha_inicio, fecha_fin) + 3)"
                );
                $up->bind_param('iissi', $secs, $usuarioId, $id, $extension, $secs);
                $up->execute();
                $up->close();
            }
        }

        // Registro automático: una llamada 100 -> 101 no debe quedar
        // pendiente para asociarla a un expediente o contarla como contacto.
        if ($evento === 'ended' && $row['clasificacion'] === 'PENDIENTE' &&
            $this->esNumeroInterno((string)($row['numero_origen'] ?? ''), $extension)) {
            $stmt = $this->db->prepare(
                "UPDATE telefonia_analista_webrtc_sesiones
                 SET clasificacion = 'INTERNA'
                 WHERE usuario_id = ? AND session_id = ? AND extension = ?
                   AND estado = 'ended' AND clasificacion = 'PENDIENTE'
                   AND seguimiento_id IS NULL AND interaccion_id IS NULL"
            );
            $stmt->bind_param('iss', $usuarioId, $id, $extension);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function esSesionInterna(int $usuarioId, string $sessionId): bool
    {
        if (!self::validarSesion($sessionId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT numero_origen, extension
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND session_id = ? AND estado = 'ended'
              LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila && $this->esNumeroInterno(
            (string)$fila['numero_origen'], (string)$fila['extension']
        );
    }

    public function obtenerFinalizada(int $usuarioId, string $id): array
    {
        if (!self::validarSesion($id)) {
            throw new InvalidArgumentException('Sesión WebRTC no válida.');
        }
        $extension = $this->extension($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT * FROM telefonia_analista_webrtc_sesiones
             WHERE usuario_id = ? AND session_id = ? AND extension = ?
             LIMIT 1"
        );
        $stmt->bind_param('iss', $usuarioId, $id, $extension);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['fecha_fin'] === null) {
            throw new DomainException('La llamada WebRTC aún no figura como finalizada.');
        }
        return $row;
    }

    public static function duracion(array $row): int
    {
        $inicio = strtotime((string)($row['fecha_atendida'] ?? ''));
        $fin = strtotime((string)($row['fecha_fin'] ?? ''));
        $tiempoServidor = $inicio !== false && $fin !== false && $fin > $inicio
            ? $fin - $inicio : 0;
        return max($tiempoServidor, (int)($row['duracion_observada_segundos'] ?? 0));
    }

    public function listar(int $usuarioId): array
    {
        $extension = $this->extension($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT * FROM telefonia_analista_webrtc_sesiones
             WHERE usuario_id = ? AND extension = ?
               AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY fecha_inicio DESC, id DESC LIMIT 75"
        );
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(function (array $row) use ($extension): array {
            $interna = ($row['clasificacion'] ?? '') === 'INTERNA' ||
                $this->esNumeroInterno((string)($row['numero_origen'] ?? ''), $extension);
            return [
                'pbx_call_id' => (string)$row['session_id'],
                'numero' => (string)$row['numero_origen'],
                'fecha' => TelefoniaFechaHoraHelper::aMexico((string)$row['fecha_inicio']),
                'duracion' => self::duracion($row),
                'estado_pbx' => $row['fecha_fin'] !== null ? 'ended' : 'ringing',
                'contestada' => $row['fecha_atendida'] !== null,
                'clasificacion' => (string)$row['clasificacion'],
                'seguimiento_id' => (int)($row['seguimiento_id'] ?? 0),
                'interaccion_id' => (int)($row['interaccion_id'] ?? 0),
                'fuente' => 'SDK_WEBRTC',
                'tipo_llamada' => $interna ? 'INTERNA' : 'ENTRANTE',
                'estado_webrtc' => (string)$row['estado']
            ];
        }, $rows);
    }
}
