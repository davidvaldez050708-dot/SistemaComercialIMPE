-- Conserva de forma persistente los archivos adjuntos enviados
-- desde el módulo Correos de Marketing.

CREATE TABLE IF NOT EXISTS correos_marketing_adjuntos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correo_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT NOT NULL,
    archivo VARCHAR(500) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(150) NOT NULL,
    tamano BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correo_marketing_adjuntos_correo (correo_id),
    KEY idx_correo_marketing_adjuntos_usuario (usuario_id),
    CONSTRAINT fk_correo_marketing_adjuntos_correo
        FOREIGN KEY (correo_id)
        REFERENCES correos_marketing(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
