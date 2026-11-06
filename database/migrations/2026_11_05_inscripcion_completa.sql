-- Ejecutar sólo en una instalación controlada con respaldo. El publicador no ejecuta SQL.
-- No cambia las tablas históricas ni incorpora prospectos.
CREATE TABLE IF NOT EXISTS formulario_inscripciones_completas (
    inscripcion_id INT UNSIGNED NOT NULL,
    programa_slug VARCHAR(80) NOT NULL,
    solicitud_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identidad_programa CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    datos LONGTEXT NOT NULL CHECK(JSON_VALID(datos)),
    comprobante_archivo VARCHAR(64) NOT NULL,
    comprobante_mime VARCHAR(40) NOT NULL,
    comprobante_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    comprobante_tamano INT UNSIGNED NOT NULL,
    terminos_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    privacidad_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aceptado_at DATETIME NOT NULL,
    PRIMARY KEY(inscripcion_id),
    UNIQUE KEY uq_inscripcion_solicitud(solicitud_token),
    UNIQUE KEY uq_inscripcion_identidad(identidad_programa),
    KEY idx_inscripcion_programa_fecha(programa_slug,aceptado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
