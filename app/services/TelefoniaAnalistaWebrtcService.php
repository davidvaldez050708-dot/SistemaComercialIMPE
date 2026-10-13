<?php
require_once __DIR__ . '/../../config/db_connection.php';
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
                  (usuario_id, extension, session_id, numero_origen, caller_name)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issss', $usuarioId, $extension, $id, $numero, $nombre);
            $stmt->execute();
            $stmt->close();
            return;
        }
        // Avance monotónico del estado: un cierre nunca reabre una sesión.
        if ($evento === 'answered') {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = 'answered', fecha_atendida = COALESCE(fecha_atendida, NOW())
                    WHERE usuario_id = ? AND session_id = ? AND extension = ?
                      AND estado = 'ringing'";
        } elseif ($evento === 'ended') {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = 'ended', fecha_fin = COALESCE(fecha_fin, NOW())
                    WHERE usuario_id = ? AND session_id = ? AND extension = ?
                      AND estado IN ('ringing','answered')";
        } else {
            $sql = "UPDATE telefonia_analista_webrtc_sesiones
                    SET estado = ?, fecha_fin = COALESCE(fecha_fin, NOW())
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
        return $inicio !== false && $fin !== false && $fin > $inicio
            ? $fin - $inicio : 0;
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
        return array_map(static function (array $row): array {
            return [
                'pbx_call_id' => (string)$row['session_id'],
                'numero' => (string)$row['numero_origen'],
                'fecha' => (string)$row['fecha_inicio'],
                'duracion' => self::duracion($row),
                'estado_pbx' => $row['fecha_fin'] !== null ? 'ended' : 'ringing',
                'contestada' => $row['fecha_atendida'] !== null,
                'clasificacion' => (string)$row['clasificacion'],
                'seguimiento_id' => (int)($row['seguimiento_id'] ?? 0),
                'interaccion_id' => (int)($row['interaccion_id'] ?? 0),
                'fuente' => 'SDK_WEBRTC',
                'estado_webrtc' => (string)$row['estado']
            ];
        }, $rows);
    }
}
