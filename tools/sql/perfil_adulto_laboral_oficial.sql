-- Perfil adulto/laboral para análisis territorial.
-- Se mantiene separado de indicadores_educativos_oficiales porque representa
-- estructura demográfica/laboral y puede provenir de productos oficiales distintos.

CREATE TABLE IF NOT EXISTS perfil_adulto_laboral_oficial (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NOT NULL,
    municipio_id INT NULL,
    clave_geografica VARCHAR(10) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    poblacion_25_34 INT UNSIGNED NULL,
    poblacion_35_44 INT UNSIGNED NULL,
    poblacion_45_54 INT UNSIGNED NULL,
    poblacion_25_54 INT UNSIGNED NULL,
    poblacion_economicamente_activa INT UNSIGNED NULL,
    poblacion_ocupada INT UNSIGNED NULL,
    fuente VARCHAR(255) NOT NULL,
    archivo_origen VARCHAR(255) NULL,
    metodologia TEXT NULL,
    fecha_consulta DATETIME NOT NULL,
    tipo_actualizacion VARCHAR(30) NOT NULL DEFAULT 'AUTOMATICA',
    usuario_importo_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perfil_adulto_geo_periodo (
        estado_id,
        municipio_id,
        clave_geografica,
        anio
    ),
    KEY idx_perfil_adulto_estado (estado_id, anio),
    KEY idx_perfil_adulto_municipio (municipio_id, anio),
    CONSTRAINT fk_perfil_adulto_estado
        FOREIGN KEY (estado_id) REFERENCES estados(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_perfil_adulto_municipio
        FOREIGN KEY (municipio_id) REFERENCES municipios(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
