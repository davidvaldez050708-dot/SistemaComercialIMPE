-- Limpieza controlada de seguimientos de prueba de Diego Bahena
-- en Guanajuato, previa a la importación del Excel histórico.
--
-- IMPORTANTE:
-- 1) Ejecuta primero 00_previsualizar_limpieza_diego_bahena.sql.
-- 2) Este script NO elimina al usuario, su rol, su asignación territorial,
--    municipios, territorios, plantillas ni datos territoriales.
-- 3) Solo elimina los expedientes actualmente asignados a Diego en Guanajuato
--    y los registros operativos relacionados.
-- 4) Todo se ejecuta dentro de una transacción.

START TRANSACTION;

SET @analista_id := (
    SELECT u.id
    FROM usuarios u
    WHERE u.estado = 1
      AND u.rol_id = 4
      AND LOWER(TRIM(u.nombre)) = 'diego'
      AND LOWER(TRIM(u.apellidos)) = 'bahena'
    ORDER BY u.id
    LIMIT 1
);

SET @estado_id := (
    SELECT e.id
    FROM estados e
    WHERE e.estado = 1
      AND (
          e.clave_inegi = '11'
          OR LOWER(TRIM(e.nombre)) = 'guanajuato'
      )
    ORDER BY
        CASE WHEN e.clave_inegi = '11' THEN 0 ELSE 1 END,
        e.id
    LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS tmp_seguimientos_prueba_diego_gto;

CREATE TEMPORARY TABLE tmp_seguimientos_prueba_diego_gto (
    id INT NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_seguimientos_prueba_diego_gto (id)
SELECT s.id
FROM seguimientos_vinculacion s
WHERE @analista_id IS NOT NULL
  AND @estado_id IS NOT NULL
  AND s.analista_id = @analista_id
  AND s.estado_id = @estado_id;

SELECT
    @analista_id AS analista_id,
    @estado_id AS estado_id,
    COUNT(*) AS expedientes_objetivo
FROM tmp_seguimientos_prueba_diego_gto;

-- -------------------------------------------------------------------------
-- Tablas auxiliares creadas por migraciones posteriores.
-- Cada bloque comprueba primero que la tabla exista, por compatibilidad con
-- bases que todavía no hayan ejecutado todas las migraciones.
-- -------------------------------------------------------------------------

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'seguimientos_vinculacion_correo_adjuntos'
    ),
    'DELETE t FROM seguimientos_vinculacion_correo_adjuntos t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'seguimientos_vinculacion_correos'
    ),
    'DELETE t FROM seguimientos_vinculacion_correos t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'seguimientos_vinculacion_convenio_versiones'
    ),
    'DELETE t FROM seguimientos_vinculacion_convenio_versiones t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'reuniones_vinculacion_reprogramaciones'
    ),
    'DELETE t FROM reuniones_vinculacion_reprogramaciones t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'seguimientos_vinculacion_cambios_datos'
    ),
    'DELETE t FROM seguimientos_vinculacion_cambios_datos t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'correos_oficio_vinculacion'
    ),
    'DELETE t FROM correos_oficio_vinculacion t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'observaciones_seguimiento'
    ),
    'DELETE t FROM observaciones_seguimiento t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'recordatorios_vinculacion'
    ),
    'DELETE t FROM recordatorios_vinculacion t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'reuniones_vinculacion'
    ),
    'DELETE t FROM reuniones_vinculacion t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'seguimientos_vinculacion_post_envio'
    ),
    'DELETE t FROM seguimientos_vinculacion_post_envio t INNER JOIN tmp_seguimientos_prueba_diego_gto x ON x.id = t.seguimiento_id',
    'SELECT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Estas dos tablas pertenecen al esquema base y además tienen ON DELETE
-- CASCADE; se limpian explícitamente para dejar la operación transparente.
DELETE i
FROM interacciones_vinculacion i
INNER JOIN tmp_seguimientos_prueba_diego_gto x
    ON x.id = i.seguimiento_id;

DELETE o
FROM oficios_vinculacion o
INNER JOIN tmp_seguimientos_prueba_diego_gto x
    ON x.id = o.seguimiento_id;

-- Finalmente se eliminan únicamente los expedientes objetivo.
DELETE s
FROM seguimientos_vinculacion s
INNER JOIN tmp_seguimientos_prueba_diego_gto x
    ON x.id = s.id;

SET @expedientes_eliminados := ROW_COUNT();

-- Verificación antes del COMMIT.
SELECT
    @expedientes_eliminados AS expedientes_eliminados,
    (
        SELECT COUNT(*)
        FROM seguimientos_vinculacion s
        WHERE s.analista_id = @analista_id
          AND s.estado_id = @estado_id
    ) AS expedientes_restantes_diego_guanajuato;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_seguimientos_prueba_diego_gto;
