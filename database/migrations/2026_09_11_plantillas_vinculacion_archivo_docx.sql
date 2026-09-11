-- Sincroniza el esquema necesario para las plantillas DOCX de oficio.
-- Es idempotente en MariaDB 10.4+: si la columna ya existe, no la duplica.

ALTER TABLE `plantillas_vinculacion`
    ADD COLUMN IF NOT EXISTS `archivo_docx` varchar(255) DEFAULT NULL
    AFTER `contenido`;
