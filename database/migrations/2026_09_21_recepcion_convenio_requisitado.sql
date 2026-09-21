-- Etapa 13: recepción del convenio requisitado por la institución.
-- Conserva separado el documento enviado del documento que devuelve el aliado.
-- Ejecutar una sola vez después de actualizar el código.

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS convenio_recibido_fecha DATE NULL
        AFTER convenio_docx,
    ADD COLUMN IF NOT EXISTS convenio_recibido_at DATETIME NULL
        AFTER convenio_recibido_fecha,
    ADD COLUMN IF NOT EXISTS convenio_recibido_por INT NULL
        AFTER convenio_recibido_at,
    ADD COLUMN IF NOT EXISTS convenio_recibido_archivo VARCHAR(500) NULL
        AFTER convenio_recibido_por,
    ADD COLUMN IF NOT EXISTS convenio_recibido_nombre_original VARCHAR(255) NULL
        AFTER convenio_recibido_archivo,
    ADD COLUMN IF NOT EXISTS convenio_recibido_mime VARCHAR(120) NULL
        AFTER convenio_recibido_nombre_original,
    ADD COLUMN IF NOT EXISTS convenio_recibido_tamano INT UNSIGNED NULL
        AFTER convenio_recibido_mime,
    ADD COLUMN IF NOT EXISTS convenio_recibido_notas TEXT NULL
        AFTER convenio_recibido_tamano;

CREATE INDEX IF NOT EXISTS idx_post_envio_convenio_recibido
    ON seguimientos_vinculacion_post_envio (convenio_recibido_at);
