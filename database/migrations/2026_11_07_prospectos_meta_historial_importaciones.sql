-- Bitácora permanente por ARCHIVO Meta, no por registro ni por asesor.
-- Historial nuevo: NO reconstruir fechas ni cantidades de importaciones antiguas.
-- No ejecuta reparto, cron, cambios sobre leads o asignaciones.
CREATE TABLE IF NOT EXISTS prospecto_meta_importaciones_historial (
    lote_ref CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_id INT NOT NULL,
    nombre_archivo VARCHAR(140) NOT NULL,
    filas_leidas INT UNSIGNED NOT NULL,
    filas_importables INT UNSIGNED NOT NULL,
    nuevos INT UNSIGNED NOT NULL,
    repetidos_crm INT UNSIGNED NOT NULL,
    repetidos_archivo INT UNSIGNED NOT NULL,
    rechazados INT UNSIGNED NOT NULL,
    formularios_total INT UNSIGNED NOT NULL,
    campanias_total INT UNSIGNED NOT NULL,
    formularios_resumen_json TEXT NOT NULL,
    terminado_at DATETIME NOT NULL,
    PRIMARY KEY (lote_ref),
    KEY idx_meta_hist_fecha (terminado_at,lote_ref),
    KEY idx_meta_hist_usuario (usuario_id,terminado_at),
    CONSTRAINT fk_meta_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
