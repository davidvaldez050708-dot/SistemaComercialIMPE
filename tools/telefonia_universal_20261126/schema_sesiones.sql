-- Preparado; NO ejecutado en producción. Extiende la bitácora existente.
-- Los registros históricos entrantes conservan su dirección y protocolo1.
ALTER TABLE telefonia_analista_webrtc_sesiones
    ADD COLUMN direccion VARCHAR(8) NOT NULL DEFAULT 'ENTRANTE',
    ADD COLUMN protocolo TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN emitida TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN evento_desde BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN asignacion_desde DATETIME NULL,
    ADD COLUMN sdk_id VARCHAR(180) NULL,
    ADD COLUMN ultima_secuencia INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN ultimo_evento_hash CHAR(64) NULL,
    ADD COLUMN pbx_call_id VARCHAR(100) NULL,
    ADD COLUMN pbx_inicio_evento BIGINT UNSIGNED NULL;
