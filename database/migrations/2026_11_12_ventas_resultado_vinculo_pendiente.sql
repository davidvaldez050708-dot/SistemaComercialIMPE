ALTER TABLE telefonia_ventas_resultados
    ADD COLUMN embudo_asignacion_id BIGINT UNSIGNED NULL,
    ADD INDEX idx_ventas_vinculo_embudo (usuario_id, embudo_asignacion_id, id);
