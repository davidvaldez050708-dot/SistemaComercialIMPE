-- Preparado únicamente; no ejecutado en producción.
-- Las operaciones existentes permanecen en protocolo1 hasta que terminen.
-- CHECK debe confirmar ausencia/presencia antes de solicitar esta migración.
ALTER TABLE telefonia_transferencia_operaciones
    ADD COLUMN protocolo TINYINT UNSIGNED NOT NULL DEFAULT 1;
