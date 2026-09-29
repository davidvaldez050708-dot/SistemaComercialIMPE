-- Estimación estatal de afinidad para Titulación por Experiencia Laboral.
-- Fuente: ENOE, cuestionario ampliado (primer trimestre).
-- La estimación usa una aproximación conservadora: personas de 25-49 años
-- con bachillerato aprobado y al menos 3 años de antigüedad en el empleo actual.
-- NO representa elegibilidad individual ni sustituye la validación documental.

CREATE TABLE IF NOT EXISTS perfil_titulacion_experiencia_enoe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave_estado CHAR(2) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    trimestre TINYINT UNSIGNED NOT NULL DEFAULT 1,
    muestra_base INT UNSIGNED NOT NULL DEFAULT 0,
    muestra_3_mas INT UNSIGNED NOT NULL DEFAULT 0,
    poblacion_base_ponderada BIGINT UNSIGNED NOT NULL DEFAULT 0,
    poblacion_3_mas_ponderada BIGINT UNSIGNED NOT NULL DEFAULT 0,
    proporcion_3_mas DECIMAL(7,4) NULL,
    criterio_escolar VARCHAR(255) NOT NULL,
    criterio_laboral VARCHAR(255) NOT NULL,
    fuente VARCHAR(255) NOT NULL,
    archivo_sdem VARCHAR(255) NULL,
    archivo_coe1 VARCHAR(255) NULL,
    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_titulacion_enoe_estado_periodo (clave_estado, anio, trimestre),
    KEY idx_titulacion_enoe_periodo (anio, trimestre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
