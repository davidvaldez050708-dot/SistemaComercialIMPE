-- Preparar explícitamente antes de publicar el protocolo de sucesores.
ALTER TABLE telefonia_analista_webrtc_sesiones
 ADD COLUMN sdk_dialogo_json TEXT NULL,
 ADD COLUMN predecesora_session_id VARCHAR(100) NULL,
 ADD COLUMN operacion_transferencia_id BIGINT UNSIGNED NULL;
