-- Adjuntos opcionales enviados desde el Paso 10 · Seguimiento por correo.
-- Conserva qué archivo acompañó a cada interacción de correo y permite
-- reutilizar archivos ya incorporados al expediente.

CREATE TABLE IF NOT EXISTS seguimientos_vinculacion_correo_adjuntos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    interaccion_id INT NOT NULL,
    usuario_id INT NOT NULL,
    origen VARCHAR(24) NOT NULL DEFAULT 'SUBIDO',
    archivo VARCHAR(500) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(150) NOT NULL,
    tamano BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_seg_correo_adjuntos_seguimiento (seguimiento_id, created_at),
    KEY idx_seg_correo_adjuntos_interaccion (interaccion_id),
    KEY idx_seg_correo_adjuntos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
