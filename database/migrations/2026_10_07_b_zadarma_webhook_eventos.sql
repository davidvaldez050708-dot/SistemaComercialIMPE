-- Persistencia productiva de eventos webhook de Zadarma.
-- El log de storage queda únicamente como respaldo técnico.

CREATE TABLE IF NOT EXISTS telefonia_zadarma_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evento VARCHAR(32) NOT NULL,
    pbx_call_id VARCHAR(100) NOT NULL,
    internal VARCHAR(20) NULL,
    destination VARCHAR(80) NULL,
    destination_digits VARCHAR(20) NULL,
    caller_id VARCHAR(80) NULL,
    call_start DATETIME NULL,
    duration INT NOT NULL DEFAULT 0,
    disposition VARCHAR(40) NULL,
    status_code VARCHAR(40) NULL,
    is_recorded TINYINT(1) NOT NULL DEFAULT 0,
    call_id_with_rec VARCHAR(100) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_json LONGTEXT NULL,
    event_hash CHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_zadarma_event_hash (event_hash),
    KEY idx_zadarma_call_event (pbx_call_id, evento, id),
    KEY idx_zadarma_outgoing_lookup (
        internal,
        destination_digits,
        received_at
    ),
    KEY idx_zadarma_record_lookup (
        pbx_call_id,
        call_id_with_rec
    ),
    KEY idx_zadarma_incoming_lookup (
        internal,
        evento,
        received_at
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
