-- Índice para consulta rápida de llamadas entrantes por extensión.
-- Idempotente: puede ejecutarse aunque la aplicación ya haya creado el índice.

SET @zadarma_incoming_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'telefonia_zadarma_eventos'
      AND index_name = 'idx_zadarma_incoming_lookup'
);

SET @zadarma_incoming_index_sql := IF(
    @zadarma_incoming_index_exists = 0,
    'ALTER TABLE telefonia_zadarma_eventos ADD INDEX idx_zadarma_incoming_lookup (internal, evento, received_at)',
    'SELECT 1'
);

PREPARE zadarma_incoming_index_stmt
FROM @zadarma_incoming_index_sql;

EXECUTE zadarma_incoming_index_stmt;

DEALLOCATE PREPARE zadarma_incoming_index_stmt;
