CREATE TABLE IF NOT EXISTS telefonia_recepcion_resultados (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL, extension VARCHAR(12) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL, resultado VARCHAR(30) NOT NULL,
            nota VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_recepcion_resultado (usuario_id,extension,pbx_call_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
