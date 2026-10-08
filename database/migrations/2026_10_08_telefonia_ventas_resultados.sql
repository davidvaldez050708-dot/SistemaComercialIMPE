-- Resultados manuales de llamadas de asesores de Ventas.
-- No se almacenan ni eliminan audios: se controla su disponibilidad en el CRM.
CREATE TABLE IF NOT EXISTS telefonia_ventas_resultados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    extension VARCHAR(20) NOT NULL,
    pbx_call_id VARCHAR(100) NOT NULL,
    resultado VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ventas_llamada_extension (pbx_call_id, extension),
    KEY idx_ventas_usuario_fecha (usuario_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
