-- Motores de asignación 1B + 1C. Aditiva; SIN backfill de importaciones históricas.
-- Instalar tras respaldo. No crea ni modifica asignaciones por sí sola.
-- Meta: sólo lotes marcados completos después de esta migración.
-- Aliados: sólo nuevas solicitudes del formulario tras activar el código.
-- Interruptores inicialmente desactivados: ni publicar PHP ni migrar inicia reparto.
-- La activación se hará por separado después de pruebas en Hostinger.
CREATE TABLE IF NOT EXISTS prospecto_distribucion_control (
    id TINYINT UNSIGNED NOT NULL,
    meta_habilitado TINYINT UNSIGNED NOT NULL DEFAULT 0,
    aliados_habilitado TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO prospecto_distribucion_control
(id,meta_habilitado,aliados_habilitado,updated_at)
VALUES (1,0,0,UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS prospecto_meta_lotes_confirmados (
    lote_ref CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmado_at DATETIME NOT NULL,
    nuevos INT UNSIGNED NOT NULL,
    importado_por INT NOT NULL,
    PRIMARY KEY (lote_ref),
    KEY idx_pml_confirmado (confirmado_at),
    CONSTRAINT fk_pml_usuario FOREIGN KEY (importado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_distribucion_registros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prospecto_id BIGINT UNSIGNED NOT NULL,
    asignacion_id BIGINT UNSIGNED NOT NULL,
    asesor_id INT NOT NULL,
    origen_id BIGINT UNSIGNED NOT NULL,
    tipo_origen VARCHAR(12) NOT NULL,
    fecha_jornada DATE NOT NULL,
    lote_ref CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    asignado_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pdr_prospecto (prospecto_id),
    UNIQUE KEY uq_pdr_asignacion (asignacion_id),
    KEY idx_pdr_cupo_meta (tipo_origen,fecha_jornada,asesor_id),
    KEY idx_pdr_origen (origen_id),
    CONSTRAINT fk_pdr_prospecto FOREIGN KEY (prospecto_id) REFERENCES prospectos_comerciales(id),
    CONSTRAINT fk_pdr_asignacion FOREIGN KEY (asignacion_id) REFERENCES prospecto_asignaciones(id),
    CONSTRAINT fk_pdr_usuario FOREIGN KEY (asesor_id) REFERENCES usuarios(id),
    CONSTRAINT fk_pdr_origen FOREIGN KEY (origen_id) REFERENCES prospecto_origenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_distribucion_pendientes (
    prospecto_id BIGINT UNSIGNED NOT NULL,
    origen_id BIGINT UNSIGNED NOT NULL,
    tipo_origen VARCHAR(12) NOT NULL,
    motivo VARCHAR(40) NOT NULL,
    revisado_at DATETIME NOT NULL,
    PRIMARY KEY (prospecto_id),
    KEY idx_pdp_tipo_motivo (tipo_origen,motivo,revisado_at),
    CONSTRAINT fk_pdp_prospecto FOREIGN KEY (prospecto_id) REFERENCES prospectos_comerciales(id),
    CONSTRAINT fk_pdp_origen FOREIGN KEY (origen_id) REFERENCES prospecto_origenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_distribucion_corridas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_origen VARCHAR(12) NOT NULL,
    fecha_jornada DATE NOT NULL,
    inicio_at DATETIME NOT NULL,
    fin_at DATETIME NOT NULL,
    asignados INT UNSIGNED NOT NULL,
    pendientes INT UNSIGNED NOT NULL,
    inspeccionados INT UNSIGNED NOT NULL,
    modo VARCHAR(12) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pdc_fecha (fecha_jornada,tipo_origen,inicio_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
