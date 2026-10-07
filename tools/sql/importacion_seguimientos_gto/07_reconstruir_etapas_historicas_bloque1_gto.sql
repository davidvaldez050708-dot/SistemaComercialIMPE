-- ================================================================
-- BLOQUE 1 · GUANAJUATO
-- Reconstrucción de etapas históricas avanzadas desde Excel
-- Compatible con phpMyAdmin / MySQL 8
--
-- NO crea tablas de importación.
-- NO inventa llamadas, reuniones realizadas ni convenios formalizados.
-- Reconoce únicamente evidencia explícita ya conservada en observaciones:
--   - Convenio / seguimiento para firma  -> Convenio en proceso
--   - "Se agenda reunión"                -> Reunión en coordinación
--
-- Este script complementa la corrección previa de oficios históricos.
-- ================================================================

ROLLBACK;
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
    ORDER BY e.id
    LIMIT 1
);

SELECT
    @analista_id AS analista_id,
    @estado_id AS estado_id,
    COUNT(*) AS total_bloque
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%';

SELECT
    s.clave_origen,
    s.nombre_entidad,
    s.estado_seguimiento,
    CASE
        WHEN LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
          OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
        THEN 'CONVENIO_EN_PROCESO'
        WHEN LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
          OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
        THEN 'REUNION_EN_COORDINACION'
        ELSE 'SIN_ETAPA_HISTORICA_AVANZADA'
    END AS etapa_historica_detectada
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
  )
ORDER BY s.clave_origen;

UPDATE seguimientos_vinculacion s
SET s.estado_seguimiento = 'ESPERANDO_RESPUESTA',
    s.proxima_accion_at = NULL
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
  )
  AND s.estado_seguimiento <> 'DESCARTADO';

SET @convenios_normalizados := ROW_COUNT();

INSERT IGNORE INTO seguimientos_vinculacion_post_envio (seguimiento_id)
SELECT s.id
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
  );

SET @contenedores_convenio_creados := ROW_COUNT();

INSERT INTO interacciones_vinculacion (
    seguimiento_id,
    usuario_id,
    canal,
    fecha_inicio,
    resultado,
    notas
)
SELECT
    s.id,
    s.analista_id,
    'SISTEMA',
    COALESCE(s.created_at, NOW()),
    'OTRO',
    '[IMPORTACION_HISTORICA_GTO_B1_ETAPA] Etapa histórica reconocida: Convenio en proceso. La fuente Excel indica convenio o seguimiento para firma. No se registró reunión ficticia y el expediente NO se considera convenio formalizado ni Aliado.'
FROM seguimientos_vinculacion s
LEFT JOIN interacciones_vinculacion i
    ON i.seguimiento_id = s.id
   AND i.canal = 'SISTEMA'
   AND i.notas LIKE '[IMPORTACION_HISTORICA_GTO_B1_ETAPA] Etapa histórica reconocida: Convenio en proceso.%'
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
  )
  AND i.id IS NULL;

SET @trazas_convenio_insertadas := ROW_COUNT();

INSERT INTO interacciones_vinculacion (
    seguimiento_id,
    usuario_id,
    canal,
    fecha_inicio,
    resultado,
    notas
)
SELECT
    s.id,
    s.analista_id,
    'SISTEMA',
    COALESCE(s.created_at, NOW()),
    'OTRO',
    '[IMPORTACION_HISTORICA_GTO_B1_ETAPA] Etapa histórica reconocida: Reunión en coordinación. La fuente Excel dice "Se agenda reunión", pero no contiene fecha confirmada. No se creó una reunión ficticia ni se contabiliza como reunión realizada.'
FROM seguimientos_vinculacion s
LEFT JOIN interacciones_vinculacion i
    ON i.seguimiento_id = s.id
   AND i.canal = 'SISTEMA'
   AND i.notas LIKE '[IMPORTACION_HISTORICA_GTO_B1_ETAPA] Etapa histórica reconocida: Reunión en coordinación.%'
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
  )
  AND i.id IS NULL;

SET @trazas_reunion_insertadas := ROW_COUNT();

SELECT
    @convenios_normalizados AS convenios_normalizados,
    @contenedores_convenio_creados AS contenedores_convenio_creados,
    @trazas_convenio_insertadas AS trazas_convenio_insertadas,
    @trazas_reunion_insertadas AS trazas_reunion_insertadas;

SELECT
    COUNT(*) AS convenios_historicos
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
  );

SELECT
    COUNT(*) AS reuniones_historicas_en_coordinacion
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
  );

SELECT
    s.clave_origen,
    s.nombre_entidad,
    s.estado_seguimiento,
    (
        SELECT o.folio
        FROM oficios_vinculacion o
        WHERE o.seguimiento_id = s.id
        ORDER BY o.id DESC
        LIMIT 1
    ) AS folio,
    CASE
        WHEN LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
          OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
        THEN 'Convenio en proceso'
        WHEN LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
          OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
        THEN 'Reunión en coordinación'
        ELSE ''
    END AS etapa_historica
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
  AND s.activo = 1
  AND s.clave_origen LIKE 'XLSX:GTO:B1:%'
  AND (
      LOWER(COALESCE(s.observaciones, '')) LIKE '%estado(s) excel: convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%firma de convenio%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunión%'
      OR LOWER(COALESCE(s.observaciones, '')) LIKE '%se agenda reunion%'
  )
ORDER BY s.clave_origen;

COMMIT;
