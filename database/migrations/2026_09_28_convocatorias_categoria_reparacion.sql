-- Reparación idempotente de la categoría de convocatorias.
-- Permite corregir instalaciones donde la migración anterior no alcanzó a crear la columna.

SET @categoria_existe = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'convocatorias'
      AND COLUMN_NAME = 'categoria'
);

SET @sql_categoria = IF(
    @categoria_existe = 0,
    "ALTER TABLE convocatorias ADD COLUMN categoria VARCHAR(100) NOT NULL DEFAULT 'IMJUVE' AFTER titulo",
    "SELECT 1"
);

PREPARE stmt_categoria FROM @sql_categoria;
EXECUTE stmt_categoria;
DEALLOCATE PREPARE stmt_categoria;

UPDATE convocatorias
SET categoria = 'IMJUVE'
WHERE categoria IS NULL OR TRIM(categoria) = '';

SET @indice_categoria_existe = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'convocatorias'
      AND INDEX_NAME = 'idx_convocatorias_categoria'
);

SET @sql_indice_categoria = IF(
    @indice_categoria_existe = 0,
    "CREATE INDEX idx_convocatorias_categoria ON convocatorias (categoria)",
    "SELECT 1"
);

PREPARE stmt_indice_categoria FROM @sql_indice_categoria;
EXECUTE stmt_indice_categoria;
DEALLOCATE PREPARE stmt_indice_categoria;
