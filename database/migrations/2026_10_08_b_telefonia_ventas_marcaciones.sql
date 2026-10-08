-- Identificar exclusivamente llamadas originadas desde Inicio del Asesor de Ventas.
-- Nunca usar únicamente la extensión como prueba de propietario histórico.
CREATE TABLE IF NOT EXISTS telefonia_ventas_marcaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    extension VARCHAR(12) NOT NULL,
    destino VARCHAR(20) NOT NULL,
    token CHAR(32) NOT NULL,
    pbx_call_id VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vinculada_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ventas_marcacion_token (token),
    UNIQUE KEY uk_ventas_marcacion_pbx (pbx_call_id),
    KEY idx_ventas_marcacion_usuario (usuario_id, extension, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
