-- Historial estructurado de correos de seguimiento.
-- Permite consultar el correo completo sin depender del campo notas de la interacción.

CREATE TABLE IF NOT EXISTS seguimientos_vinculacion_correos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    interaccion_id INT NOT NULL,
    usuario_id INT NOT NULL,
    destinatario VARCHAR(255) NOT NULL,
    asunto VARCHAR(255) NOT NULL,
    cuerpo MEDIUMTEXT NOT NULL,
    proveedor VARCHAR(80) DEFAULT NULL,
    adjuntos_count INT NOT NULL DEFAULT 0,
    enviado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seg_correo_interaccion (interaccion_id),
    KEY idx_seg_correos_seguimiento (seguimiento_id, enviado_at),
    KEY idx_seg_correos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
