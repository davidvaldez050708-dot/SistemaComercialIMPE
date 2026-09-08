-- Migración para población objetivo educativa oficial.
-- Conserva separado el indicador de rezago educativo oficial de Pobreza Multidimensional.

CREATE TABLE IF NOT EXISTS indicadores_educativos_oficiales (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NULL,
    clave_geografica VARCHAR(2) NOT NULL,
    codigo_indicador VARCHAR(80) NOT NULL,
    nombre_indicador VARCHAR(255) NOT NULL,
    grupo_edad VARCHAR(80) DEFAULT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    cantidad_personas BIGINT UNSIGNED DEFAULT NULL,
    porcentaje DECIMAL(6,2) DEFAULT NULL,
    fuente VARCHAR(255) NOT NULL,
    archivo_origen VARCHAR(255) DEFAULT NULL,
    fecha_consulta DATETIME NOT NULL,
    tipo_actualizacion VARCHAR(30) NOT NULL DEFAULT 'IMPORTACION',
    usuario_importo_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_indicador_educativo_geo_periodo (
        clave_geografica,
        codigo_indicador,
        anio
    ),
    KEY idx_indicadores_educativos_estado (estado_id),
    KEY idx_indicadores_educativos_periodo (codigo_indicador, anio),
    CONSTRAINT fk_indicadores_educativos_estado
        FOREIGN KEY (estado_id) REFERENCES estados(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_indicadores_educativos_usuario
        FOREIGN KEY (usuario_importo_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
