-- Corrige la clasificación inicial de convocatorias.
-- IMJUVE solo se asigna a registros identificados explícitamente como IMJUVE.
-- Los demás registros quedan sin categoría hasta que se agreguen nuevas categorías.

ALTER TABLE convocatorias
    MODIFY COLUMN categoria VARCHAR(100) NULL DEFAULT NULL;

UPDATE convocatorias
SET categoria = CASE
    WHEN UPPER(TRIM(titulo)) = 'IMJUVE' THEN 'IMJUVE'
    ELSE NULL
END;
