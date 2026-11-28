-- Preparación explícita; no ejecutar automáticamente en producción.
CREATE TABLE IF NOT EXISTS telefonia_webrtc_grabaciones_internas (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
