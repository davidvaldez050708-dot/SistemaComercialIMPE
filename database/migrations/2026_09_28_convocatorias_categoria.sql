-- Agrega una categoría inicial a las convocatorias.
-- Por ahora el catálogo utiliza únicamente IMJUVE y podrá ampliarse posteriormente.

ALTER TABLE convocatorias
    ADD COLUMN categoria VARCHAR(100) NOT NULL DEFAULT 'IMJUVE'
    AFTER titulo;

CREATE INDEX idx_convocatorias_categoria
    ON convocatorias (categoria);
