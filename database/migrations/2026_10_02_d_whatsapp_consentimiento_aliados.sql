-- Consentimiento explícito para comunicaciones por WhatsApp en Aliados.
-- Separa "el número tiene WhatsApp" de "la institución autorizó recibir mensajes".

ALTER TABLE aliados_contactos
    ADD COLUMN autorizado_whatsapp TINYINT(1) NOT NULL DEFAULT 0
        AFTER confirmado_whatsapp,
    ADD COLUMN autorizado_whatsapp_at DATETIME NULL
        AFTER autorizado_whatsapp,
    ADD COLUMN autorizado_whatsapp_por INT NULL
        AFTER autorizado_whatsapp_at,
    ADD KEY idx_aliado_contacto_autorizado (
        seguimiento_id,
        autorizado_whatsapp,
        activo
    ),
    ADD CONSTRAINT fk_aliado_contacto_autorizado_por
        FOREIGN KEY (autorizado_whatsapp_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL;
