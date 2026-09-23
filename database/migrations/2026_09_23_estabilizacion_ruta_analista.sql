-- Estabilización de la ruta del Analista: resultados de interacción y Paso 10.
-- 1) Conserva resultados semánticos reales para reportes.
-- 2) Separa "correo de seguimiento enviado" de "coordinación de reunión habilitada".
-- Ejecutar una sola vez después de actualizar el código.

ALTER TABLE interacciones_vinculacion
    MODIFY COLUMN resultado ENUM(
        'CONTACTADO',
        'NO_CONTESTO',
        'OCUPADO',
        'NUMERO_INCORRECTO',
        'CONTACTO_INCORRECTO',
        'SOLICITO_LLAMAR_DESPUES',
        'SOLICITO_INFORMACION',
        'MENSAJE_ENVIADO',
        'CORREO_ENVIADO',
        'SIN_RESPUESTA',
        'NO_INTERESADO',
        'OTRO'
    ) DEFAULT NULL;

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS coordinacion_reunion_habilitada_at DATETIME NULL
        AFTER seguimiento_correo_por,
    ADD COLUMN IF NOT EXISTS coordinacion_reunion_habilitada_por INT NULL
        AFTER coordinacion_reunion_habilitada_at;

-- Compatibilidad con expedientes existentes:
-- antes de esta migración, seguimiento_correo_at también se usaba como señal
-- técnica de que el Analista había decidido continuar a reunión.
UPDATE seguimientos_vinculacion_post_envio
SET coordinacion_reunion_habilitada_at = seguimiento_correo_at,
    coordinacion_reunion_habilitada_por = seguimiento_correo_por
WHERE coordinacion_reunion_habilitada_at IS NULL
  AND seguimiento_correo_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_post_envio_coordinacion_reunion
    ON seguimientos_vinculacion_post_envio (coordinacion_reunion_habilitada_at);
