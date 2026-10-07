-- Permite fechas opcionales exclusivamente para el flujo de Inscripciones Abiertas.
-- La validación de negocio permanece en el controlador para que el resto de
-- convocatorias siga exigiendo fecha de inicio y fecha de término.

ALTER TABLE convocatorias
    MODIFY COLUMN fecha_inicio DATE NULL,
    MODIFY COLUMN fecha_termino DATE NULL;
