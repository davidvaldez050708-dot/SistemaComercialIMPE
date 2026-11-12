-- Piloto Zoho Developer - SOLO REGISTROS DE PRUEBA.
-- Aplicar únicamente en BD de staging/copia, después de respaldo y revisión.
-- No altera formulario_registros, formulario_inscripciones ni su contenido.
CREATE TABLE IF NOT EXISTS zoho_dev_registros_pendientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registro_id BIGINT UNSIGNED NOT NULL,
    estado ENUM('pendiente','en_proceso','reintentar','enviado','revision')
        NOT NULL DEFAULT 'pendiente',
    zoho_id VARCHAR(40) DEFAULT NULL,
    intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proximo_intento DATETIME DEFAULT NULL,
    bloqueado_hasta DATETIME DEFAULT NULL,
    ultimo_error VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zoho_dev_registro (registro_id),
    KEY idx_zoho_dev_estado (estado, proximo_intento, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
