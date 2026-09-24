-- Contexto operativo para seguimientos posteriores a una reunión.
-- Permite saber qué pendiente se revisará, de quién depende y qué acción corresponde.

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS reunion_seguimiento_objetivo TEXT NULL
        AFTER reunion_resultado_notas,
    ADD COLUMN IF NOT EXISTS reunion_seguimiento_pendiente_de VARCHAR(24) NULL
        AFTER reunion_seguimiento_objetivo,
    ADD COLUMN IF NOT EXISTS reunion_seguimiento_accion VARCHAR(40) NULL
        AFTER reunion_seguimiento_pendiente_de;

-- Compatibilidad con seguimientos ya programados antes de esta mejora.
-- Conservamos las notas de la reunión como contexto mínimo y evitamos que
-- un expediente existente quede sin explicación al llegar su próxima fecha.
UPDATE seguimientos_vinculacion_post_envio
SET reunion_seguimiento_objetivo = COALESCE(
        NULLIF(TRIM(reunion_resultado_notas), ''),
        'Revisar los acuerdos pendientes derivados de la reunión.'
    ),
    reunion_seguimiento_pendiente_de = COALESCE(
        reunion_seguimiento_pendiente_de,
        'AMBOS'
    ),
    reunion_seguimiento_accion = COALESCE(
        reunion_seguimiento_accion,
        'REVISAR_ACUERDOS'
    )
WHERE reunion_resultado = 'REQUIERE_SEGUIMIENTO'
  AND reunion_realizada_at IS NOT NULL
  AND (
      reunion_seguimiento_objetivo IS NULL
      OR reunion_seguimiento_pendiente_de IS NULL
      OR reunion_seguimiento_accion IS NULL
  );
