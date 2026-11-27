-- Contrato mínimo explícito para el laboratorio. El publicador debe contrastar
-- la tabla instalada antes de proponer cualquier migración separada.
CREATE TABLE IF NOT EXISTS telefonia_ventas_evidencia (
    usuario_id INT NOT NULL,
    extension VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pbx_call_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    consultado_at DATETIME NULL,
    datos_json LONGTEXT NULL,
    PRIMARY KEY (usuario_id,extension,pbx_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
