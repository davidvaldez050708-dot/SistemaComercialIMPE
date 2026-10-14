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
