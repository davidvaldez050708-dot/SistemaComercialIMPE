-- Etapa 13: envío de carta propuesta (PDF) + convenio editable (DOCX).
-- La formalización continúa usando los campos convenio_* existentes.
-- Ejecutar una sola vez después de actualizar el código.

ALTER TABLE seguimientos_vinculacion_post_envio
    ADD COLUMN IF NOT EXISTS convenio_documentacion_enviada_at DATETIME NULL
        AFTER reunion_realizada_por,
    ADD COLUMN IF NOT EXISTS convenio_documentacion_enviada_por INT NULL
        AFTER convenio_documentacion_enviada_at,
    ADD COLUMN IF NOT EXISTS convenio_carta_fecha DATE NULL
        AFTER convenio_documentacion_enviada_por,
    ADD COLUMN IF NOT EXISTS convenio_destinatario VARCHAR(255) NULL
        AFTER convenio_carta_fecha,
    ADD COLUMN IF NOT EXISTS convenio_correo_asunto VARCHAR(255) NULL
        AFTER convenio_destinatario,
    ADD COLUMN IF NOT EXISTS convenio_correo_cuerpo MEDIUMTEXT NULL
        AFTER convenio_correo_asunto,
    ADD COLUMN IF NOT EXISTS convenio_carta_pdf VARCHAR(500) NULL
        AFTER convenio_correo_cuerpo,
    ADD COLUMN IF NOT EXISTS convenio_docx VARCHAR(500) NULL
        AFTER convenio_carta_pdf;

CREATE INDEX IF NOT EXISTS idx_post_envio_convenio_documentos
    ON seguimientos_vinculacion_post_envio (convenio_documentacion_enviada_at);
