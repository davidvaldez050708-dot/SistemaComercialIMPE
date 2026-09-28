-- Auditoría de cambios relevantes en datos de seguimiento.
-- El Analista puede enriquecer libremente el expediente; cuando sustituye un
-- dato existente de contacto, el cambio queda auditado y se notifica a Cuenta Clave.

CREATE TABLE IF NOT EXISTS seguimientos_vinculacion_cambios_datos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    autor_id INT NOT NULL,
    destinatario_id INT NULL,
    campo VARCHAR(64) NOT NULL,
    etiqueta VARCHAR(120) NOT NULL,
    valor_anterior TEXT NULL,
    valor_nuevo TEXT NULL,
    requiere_notificacion TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    leida_at DATETIME NULL,
    leida_por INT NULL,
    PRIMARY KEY (id),
    KEY idx_cambio_seguimiento (seguimiento_id, created_at),
    KEY idx_cambio_destinatario (destinatario_id, requiere_notificacion, leida_at, created_at),
    KEY idx_cambio_autor (autor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
