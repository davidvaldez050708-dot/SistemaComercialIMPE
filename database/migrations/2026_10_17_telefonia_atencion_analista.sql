-- Aditiva e idempotente. Importar en la base del CRM si la aplicación no tiene permiso CREATE.

CREATE TABLE IF NOT EXISTS telefonia_verificacion_evidencias (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            interaccion_id INT NOT NULL, usuario_id INT NOT NULL, seguimiento_id INT NOT NULL,
            campo VARCHAR(20) NOT NULL, valor_anterior VARCHAR(180) NOT NULL DEFAULT '',
            valor_confirmado VARCHAR(180) NOT NULL DEFAULT '', accion VARCHAR(20) NOT NULL,
            informante VARCHAR(140) NOT NULL, estado VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL DEFAULT '', sdk_session_id VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            UNIQUE KEY uk_verificacion_dato (interaccion_id,campo),
            KEY idx_verificacion_usuario (usuario_id,seguimiento_id,estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS telefonia_borradores (
            usuario_id INT NOT NULL, referencia VARCHAR(100) NOT NULL,
            datos_json MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL, PRIMARY KEY(usuario_id,referencia), KEY idx_borrador_vigencia(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
