-- Estabilización de agenda, reuniones y reactivaciones.
-- 1) reuniones_vinculacion pasa a conservar también resultado/cierre de la reunión.
-- 2) agrega cancelación trazable.
-- 3) permite reiniciar una ruta cerrada por NO_INTERESADO sin borrar el historial.
-- Ejecutar una sola vez después de actualizar el código.

ALTER TABLE reuniones_vinculacion
    ADD COLUMN IF NOT EXISTS reunion_resultado VARCHAR(40) NULL
        AFTER notas_kam,
    ADD COLUMN IF NOT EXISTS reunion_resultado_notas TEXT NULL
        AFTER reunion_resultado,
    ADD COLUMN IF NOT EXISTS realizada_at DATETIME NULL
        AFTER reunion_resultado_notas,
    ADD COLUMN IF NOT EXISTS realizada_por INT NULL
        AFTER realizada_at,
    ADD COLUMN IF NOT EXISTS cancelacion_motivo TEXT NULL
        AFTER correo_confirmacion_por,
    ADD COLUMN IF NOT EXISTS cancelada_at DATETIME NULL
        AFTER cancelacion_motivo,
    ADD COLUMN IF NOT EXISTS cancelada_por INT NULL
        AFTER cancelada_at;

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS reactivacion_ruta_at DATETIME NULL
        AFTER contactar_despues_at,
    ADD COLUMN IF NOT EXISTS reactivacion_ruta_por INT NULL
        AFTER reactivacion_ruta_at;

-- Lleva el resultado histórico del espejo al registro real de la reunión.
UPDATE reuniones_vinculacion reunion
JOIN (
    SELECT seguimiento_id, MAX(id) AS reunion_id
    FROM reuniones_vinculacion
    WHERE estado = 'REALIZADA'
    GROUP BY seguimiento_id
) ultima
    ON ultima.reunion_id = reunion.id
JOIN seguimientos_vinculacion_post_envio post
    ON post.seguimiento_id = reunion.seguimiento_id
SET reunion.reunion_resultado =
        COALESCE(reunion.reunion_resultado, post.reunion_resultado),
    reunion.reunion_resultado_notas =
        COALESCE(reunion.reunion_resultado_notas, post.reunion_resultado_notas),
    reunion.realizada_at =
        COALESCE(reunion.realizada_at, post.reunion_realizada_at),
    reunion.realizada_por =
        COALESCE(reunion.realizada_por, post.reunion_realizada_por)
WHERE post.reunion_realizada_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_reunion_cancelada_at
    ON reuniones_vinculacion (cancelada_at);

CREATE INDEX IF NOT EXISTS idx_post_envio_reactivacion_ruta
    ON seguimientos_vinculacion_post_envio (reactivacion_ruta_at);
