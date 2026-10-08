-- Índice para consulta rápida de llamadas entrantes por extensión.
-- Ejecutar después de 2026_10_07_b_zadarma_webhook_eventos.sql.

ALTER TABLE telefonia_zadarma_eventos
    ADD INDEX idx_zadarma_incoming_lookup (
        internal,
        evento,
        received_at
    );
