CREATE TABLE IF NOT EXISTS actividad_economica_municipio (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NOT NULL,
    municipio_id INT NOT NULL,
    clave_sector VARCHAR(10) NOT NULL,
    nombre_sector VARCHAR(255) NOT NULL,
    establecimientos INT UNSIGNED NOT NULL DEFAULT 0,
    porcentaje DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    fuente VARCHAR(120) NOT NULL DEFAULT 'INEGI - DENUE',
    fecha_consulta DATETIME NOT NULL,
    tipo_actualizacion VARCHAR(20) NOT NULL DEFAULT 'AUTOMATICA',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_actividad_economica_municipio_sector (municipio_id, clave_sector),
    KEY idx_actividad_economica_municipio_estado (estado_id, municipio_id),
    CONSTRAINT fk_actividad_economica_municipio_estado
        FOREIGN KEY (estado_id) REFERENCES estados(id),
    CONSTRAINT fk_actividad_economica_municipio_municipio
        FOREIGN KEY (municipio_id) REFERENCES municipios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
