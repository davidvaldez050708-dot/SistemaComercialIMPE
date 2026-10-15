<?php
/**
 * Traspaso de identificación institucional validado por servidor.
 * No inventa que el destino respondió ni garantiza la identidad del llamante.
 * Nunca divulga expedientes que no pertenezcan al Analista destinatario.
 */
require_once __DIR__ . '/TelefoniaEnrutamientoService.php';
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

final class TelefoniaTransferenciaContextoService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
    }

    private function asegurarTabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_transferencia_contextos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            origen_usuario_id INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL,
            destino_usuario_id INT NOT NULL,
            destino_extension VARCHAR(12) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            telefono10 CHAR(10) NOT NULL,
            seguimiento_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_transfer_origin_call (origen_usuario_id,pbx_call_id),
            KEY ix_transfer_recipient (destino_usuario_id,destino_extension,
                telefono10,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No fue posible preparar el contexto de transferencia.');
        }
    }

    public function registrar(
        int $origenId, string $rol, string $origenExtension,
        int $destinoId, string $destinoExtension, int $estadoId,
        int $seguimientoId, string $pbxCallId
    ): bool {
        if (TelefoniaEnrutamientoService::categoriaRol($rol) !== 'RECEPCION' ||
            $origenId <= 0 || $destinoId <= 0 || $estadoId <= 0 ||
            $seguimientoId <= 0 ||
            !preg_match('/^[0-9]{3,6}$/D', $origenExtension) ||
            !preg_match('/^[0-9]{3,6}$/D', $destinoExtension) ||
            !preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbxCallId)) {
            return false;
        }
        // Sólo la Recepción autenticada y autorizada puede transmitir el
        // expediente, en el momento de ejecutar la orden PBX validada.
        // Confirmar que ese PBX ID realmente llegó a su extensión, pero NO
        // exigir NOTIFY_ANSWER: la notificación puede llegar después de
        // autorizar la transferencia. Aquí sólo se transmite identidad
        // declarada por Tania; no se acredita llamada efectiva ni audio.
        $stmt = $this->db->prepare(
            "SELECT caller_id FROM telefonia_zadarma_eventos
               WHERE pbx_call_id = ? AND internal = ?
                 AND evento = 'NOTIFY_INTERNAL'
                 AND received_at >= DATE_SUB(NOW(),INTERVAL 5 MINUTE)
               ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ss', $pbxCallId, $origenExtension);
        $stmt->execute();
        $started = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$started) return false;
        // No confundir identificación validada por un humano con un
        // evento ANSWER firmado. La recepción puede haber hablado y
        // confirmado AFIPSA antes de recibir esa notificación del proveedor.

        $telefono10 = TelefoniaEnrutamientoService::numeroNacional(
            (string)($started['caller_id'] ?? '')
        );
        if ($telefono10 === '') return false;

        // El expediente y territorio están autorizados al destinatario,
        // no basta el número, nombre ni ID capturado desde el navegador.
        $stmt = $this->db->prepare(
            "SELECT id FROM seguimientos_vinculacion
              WHERE id = ? AND analista_id = ? AND estado_id = ?
                AND activo = 1 AND estado_seguimiento <> 'DESCARTADO'
              LIMIT 1"
        );
        $stmt->bind_param('iii', $seguimientoId, $destinoId, $estadoId);
        $stmt->execute();
        $valid = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$valid) return false;

        $this->asegurarTabla();
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_transferencia_contextos
            (origen_usuario_id, origen_extension, destino_usuario_id,
             destino_extension, pbx_call_id, telefono10, seguimiento_id, expires_at)
            VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 3 MINUTE))
            ON DUPLICATE KEY UPDATE
                destino_usuario_id = VALUES(destino_usuario_id),
                destino_extension = VALUES(destino_extension),
                telefono10 = VALUES(telefono10),
                seguimiento_id = VALUES(seguimiento_id),
                expires_at = VALUES(expires_at)"
        );
        $stmt->bind_param('isisssi', $origenId, $origenExtension,
            $destinoId, $destinoExtension, $pbxCallId, $telefono10, $seguimientoId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    public function obtenerParaDestinatario(
        int $destinoId, string $destinoExtension, string $numero
    ): ?array {
        $numero10 = TelefoniaEnrutamientoService::numeroNacional($numero);
        if ($destinoId <= 0 || $numero10 === '' ||
            !preg_match('/^[0-9]{3,6}$/D', $destinoExtension)) return null;
        $this->asegurarTabla();
        $stmt = $this->db->prepare(
            "SELECT s.id AS seguimiento_id, s.nombre_entidad AS institucion,
                    e.nombre AS estado, u.nombre AS origen_nombre,
                    c.pbx_call_id
             FROM telefonia_transferencia_contextos c
             JOIN seguimientos_vinculacion s
               ON s.id = c.seguimiento_id AND s.analista_id = c.destino_usuario_id
              AND s.activo = 1 AND s.estado_seguimiento <> 'DESCARTADO'
             JOIN estados e ON e.id = s.estado_id
             JOIN usuarios u ON u.id = c.origen_usuario_id
             WHERE c.destino_usuario_id = ? AND c.destino_extension = ?
               AND c.telefono10 = ? AND c.expires_at >= NOW()
               AND c.created_at >= DATE_SUB(NOW(),INTERVAL 3 MINUTE)
             ORDER BY c.id DESC LIMIT 2"
        );
        $stmt->bind_param('iss', $destinoId, $destinoExtension, $numero10);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        // Más de una candidata podría pertenecer a otra llamada simultánea.
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        return [
            'seguimiento_id' => (int)$row['seguimiento_id'],
            'institucion' => (string)$row['institucion'],
            'estado' => (string)$row['estado'],
            'confirmado_por' => trim((string)$row['origen_nombre']),
            'etiqueta' => 'Institución confirmada por Recepción; verifica los datos durante la conversación.'
        ];
    }
}
