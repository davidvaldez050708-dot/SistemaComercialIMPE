-- Separa las convocatorias por tipo y subtipo para que cada vista sea independiente.
-- Los registros existentes se conservan en Titulación > Ejecutivas para evitar duplicarlos
-- en todas las opciones nuevas.

ALTER TABLE convocatorias
    ADD COLUMN tipo_convocatoria VARCHAR(30) NULL AFTER categoria,
    ADD COLUMN subtipo_convocatoria VARCHAR(50) NULL AFTER tipo_convocatoria;

UPDATE convocatorias
SET tipo_convocatoria = 'titulacion',
    subtipo_convocatoria = 'ejecutivas'
WHERE tipo_convocatoria IS NULL
   OR tipo_convocatoria = ''
   OR subtipo_convocatoria IS NULL
   OR subtipo_convocatoria = '';

CREATE INDEX idx_convocatorias_tipo_subtipo
    ON convocatorias (tipo_convocatoria, subtipo_convocatoria);
