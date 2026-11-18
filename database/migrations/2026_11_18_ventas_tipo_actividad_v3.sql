-- Ampliación aditiva: las actividades históricas conservan motivo y fechas.
ALTER TABLE prospecto_embudo_actividad_v3
    ADD COLUMN IF NOT EXISTS tipo_actividad VARCHAR(32) NOT NULL DEFAULT 'GESTION',
    ADD COLUMN IF NOT EXISTS detalle_actividad VARCHAR(190) NULL;
