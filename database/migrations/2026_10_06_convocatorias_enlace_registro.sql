-- Corrige el desfase entre el modelo de Convocatorias y el esquema de base de datos.
-- El modelo y el formulario ya utilizan enlace_registro, pero instalaciones previas
-- pueden no tener todavía la columna, provocando "Unknown column ... enlace_registro".

ALTER TABLE convocatorias
    ADD COLUMN IF NOT EXISTS enlace_registro VARCHAR(1000) NULL
    AFTER imagen;
