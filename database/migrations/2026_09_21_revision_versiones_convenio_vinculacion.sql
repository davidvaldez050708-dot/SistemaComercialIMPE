-- Paso 13 · revisión y versiones del convenio recibido.
-- Ejecutar después de:
--   2026_09_21_documentacion_convenio_vinculacion.sql
--   2026_09_21_recepcion_convenio_requisitado.sql

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS convenio_revision_estado VARCHAR(30) NULL
        AFTER convenio_recibido_notas,
    ADD COLUMN IF NOT EXISTS convenio_revision_notas TEXT NULL
        AFTER convenio_revision_estado,
    ADD COLUMN IF NOT EXISTS convenio_revision_at DATETIME NULL
        AFTER convenio_revision_notas,
    ADD COLUMN IF NOT EXISTS convenio_revision_por INT NULL
        AFTER convenio_revision_at,
    ADD COLUMN IF NOT EXISTS convenio_version_actual INT UNSIGNED NULL
        AFTER convenio_revision_por;

CREATE TABLE IF NOT EXISTS seguimientos_vinculacion_convenio_versiones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    version_num INT UNSIGNED NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'REQUISITADO',
    fecha_recepcion DATE NOT NULL,
    archivo VARCHAR(500) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(120) NOT NULL,
    tamano INT UNSIGNED NOT NULL DEFAULT 0,
    notas TEXT NULL,
    recibido_por INT NULL,
    recibido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_convenio_version_seguimiento (seguimiento_id, version_num),
    KEY idx_convenio_version_seguimiento (seguimiento_id, recibido_at),
    KEY idx_convenio_version_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conserva como versión 1 cualquier convenio recibido antes de esta migración.
INSERT INTO seguimientos_vinculacion_convenio_versiones (
    seguimiento_id,
    version_num,
    tipo,
    fecha_recepcion,
    archivo,
    nombre_original,
    mime,
    tamano,
    notas,
    recibido_por,
    recibido_at
)
SELECT
    p.seguimiento_id,
    1,
    'REQUISITADO',
    COALESCE(p.convenio_recibido_fecha, DATE(p.convenio_recibido_at), CURDATE()),
    p.convenio_recibido_archivo,
    COALESCE(NULLIF(p.convenio_recibido_nombre_original, ''), 'Convenio_requisitado'),
    COALESCE(NULLIF(p.convenio_recibido_mime, ''), 'application/octet-stream'),
    COALESCE(p.convenio_recibido_tamano, 0),
    p.convenio_recibido_notas,
    p.convenio_recibido_por,
    COALESCE(p.convenio_recibido_at, NOW())
FROM seguimientos_vinculacion_post_envio p
WHERE p.convenio_recibido_at IS NOT NULL
  AND NULLIF(TRIM(p.convenio_recibido_archivo), '') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM seguimientos_vinculacion_convenio_versiones v
      WHERE v.seguimiento_id = p.seguimiento_id
        AND v.version_num = 1
  );

UPDATE seguimientos_vinculacion_post_envio p
LEFT JOIN (
    SELECT seguimiento_id, MAX(version_num) AS version_num
    FROM seguimientos_vinculacion_convenio_versiones
    GROUP BY seguimiento_id
) v ON v.seguimiento_id = p.seguimiento_id
SET p.convenio_version_actual = COALESCE(p.convenio_version_actual, v.version_num),
    p.convenio_revision_estado = CASE
        WHEN p.convenio_formalizado_at IS NOT NULL THEN 'APROBADO'
        WHEN p.convenio_recibido_at IS NOT NULL
             AND NULLIF(TRIM(p.convenio_revision_estado), '') IS NULL THEN 'PENDIENTE'
        ELSE p.convenio_revision_estado
    END;
