-- Indicadores adultos por entidad. No reutilizar el perfil municipal 25-49 ni
-- estimar "concluida" a partir de P18YM_PB (incluye cualquier grado aprobado).
CREATE TABLE IF NOT EXISTS indicadores_escolaridad_adulta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NOT NULL,
    codigo_indicador VARCHAR(48) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    poblacion_base BIGINT UNSIGNED NOT NULL,
    cantidad_personas BIGINT UNSIGNED NOT NULL,
    fuente VARCHAR(255) NOT NULL,
    referencia_url VARCHAR(1024) NOT NULL,
    metodologia TEXT NOT NULL,
    archivo_origen VARCHAR(255) NOT NULL,
    fecha_consulta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_escolaridad_adulta_estado_codigo_anio (estado_id, codigo_indicador, anio),
    KEY idx_escolaridad_adulta_estado (estado_id, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
