-- V3: prueba institucional explícita de solicitud de inscripción.
-- No se migran prospectos históricos ni se confirman pagos.
-- Aplicar solamente tras preflight y respaldo; no forma parte de CHECK.
CREATE TABLE IF NOT EXISTS prospecto_embudo_inscripciones_v3 (
    asignacion_id BIGINT UNSIGNED NOT NULL,
    prospecto_id BIGINT UNSIGNED NOT NULL,
    formulario_inscripcion_id INT UNSIGNED NOT NULL,
    vinculado_por INT NOT NULL,
    evidencia_tipo VARCHAR(40) NOT NULL DEFAULT 'FORMULARIO_INSCRIPCION',
    verificado_at DATETIME NOT NULL,
    PRIMARY KEY (asignacion_id),
    UNIQUE KEY uq_peiv3_formulario (formulario_inscripcion_id),
    KEY idx_peiv3_prospecto (prospecto_id),
    KEY idx_peiv3_verificado (verificado_at),
    CONSTRAINT fk_peiv3_asignacion FOREIGN KEY (asignacion_id)
        REFERENCES prospecto_asignaciones(id),
    CONSTRAINT fk_peiv3_prospecto FOREIGN KEY (prospecto_id)
        REFERENCES prospectos_comerciales(id),
    CONSTRAINT fk_peiv3_formulario FOREIGN KEY (formulario_inscripcion_id)
        REFERENCES formulario_inscripciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
