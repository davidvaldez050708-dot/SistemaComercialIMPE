-- Clasificación persistente de llamadas entrantes de Analistas de Datos.
-- Los eventos PBX firmados continúan en telefonia_zadarma_eventos.
-- Un expediente sólo se crea cuando el Analista lo identifica.
CREATE TABLE IF NOT EXISTS telefonia_analista_entrantes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    extension VARCHAR(12) NOT NULL,
    pbx_call_id VARCHAR(100) NOT NULL,
    estado VARCHAR(24) NOT NULL DEFAULT 'PENDIENTE',
    seguimiento_id INT NULL,
    interaccion_id INT NULL,
    observacion VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_analista_llamada (usuario_id, pbx_call_id),
    KEY idx_analista_entrantes_estado (usuario_id, estado, updated_at),
    KEY idx_analista_entrantes_expediente (seguimiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
