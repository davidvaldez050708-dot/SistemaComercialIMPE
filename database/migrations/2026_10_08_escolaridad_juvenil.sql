-- Indicador estatal para jóvenes de 15 a 17 años. No mezclar con la tabla de escolaridad adulta.
-- Fuente de referencia: INEGI, Censo 2020, B2020_07_08_M.
CREATE TABLE IF NOT EXISTS indicadores_escolaridad_juvenil (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_id INT NOT NULL,
    codigo_indicador VARCHAR(64) NOT NULL,
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
    UNIQUE KEY uq_juvenil_estado_indicador_anio (estado_id, codigo_indicador, anio),
    KEY idx_juvenil_estado_anio (estado_id, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
