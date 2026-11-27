-- Propuesta aditiva; NO ejecutada en producción. Sólo mediante publicación autorizada.
ALTER TABLE telefonia_devolucion_intentos
 ADD COLUMN sdk_session_id VARCHAR(100) NULL,
 ADD UNIQUE KEY uk_dev_sesion(usuario_id,sdk_session_id);
