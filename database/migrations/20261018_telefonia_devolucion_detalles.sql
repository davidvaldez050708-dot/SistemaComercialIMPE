-- Aplicar sólo cuando falte la columna; TelefoniaDevolucionService lo comprueba automáticamente.
ALTER TABLE telefonia_devolucion_intentos ADD COLUMN datos_json TEXT NULL;
