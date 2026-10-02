-- Bandeja de correos del módulo Marketing.
-- Conserva los envíos realizados desde el nuevo modal de redacción.

CREATE TABLE IF NOT EXISTS correos_marketing (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    destinatario VARCHAR(255) NOT NULL,
    destinatario_nombre VARCHAR(255) NULL,
    asunto VARCHAR(255) NOT NULL,
    cuerpo MEDIUMTEXT NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    proveedor VARCHAR(80) NULL,
    firma_incluida TINYINT(1) NOT NULL DEFAULT 0,
    adjuntos_count INT NOT NULL DEFAULT 0,
    adjuntos_nombres TEXT NULL,
    error_envio TEXT NULL,
    fecha_envio DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correos_marketing_usuario (usuario_id, created_at),
    KEY idx_correos_marketing_estado (estado, created_at),
    KEY idx_correos_marketing_fecha (fecha_envio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
