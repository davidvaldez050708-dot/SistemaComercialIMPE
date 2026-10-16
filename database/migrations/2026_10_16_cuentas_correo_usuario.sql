-- Configuración de correo saliente por usuario.
CREATE TABLE IF NOT EXISTS usuario_correo_config (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    proveedor VARCHAR(30) NOT NULL DEFAULT 'OTRO',
    correo VARCHAR(255) NOT NULL,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_encryption VARCHAR(20) NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password_cipher MEDIUMTEXT NOT NULL,
    smtp_password_iv VARCHAR(255) NOT NULL,
    smtp_password_tag VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_correo_config_usuario (usuario_id),
    KEY idx_usuario_correo_config_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
