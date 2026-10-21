CREATE TABLE IF NOT EXISTS telefonia_ventas_evidencia (
 usuario_id INT NOT NULL, extension VARCHAR(12) NOT NULL, pbx_call_id VARCHAR(100) NOT NULL,
 consultado_at DATETIME NULL, datos_json LONGTEXT NULL,
 PRIMARY KEY(usuario_id,extension,pbx_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS telefonia_ventas_prospecto_llamada (
 usuario_id INT NOT NULL, extension VARCHAR(12) NOT NULL, pbx_call_id VARCHAR(100) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0, datos_json LONGTEXT NOT NULL,
 PRIMARY KEY(usuario_id,extension,pbx_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
