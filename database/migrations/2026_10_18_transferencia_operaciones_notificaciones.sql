-- Tablas aditivas; los servicios también las preparan al utilizarlas.
-- No modifica llamadas anteriores, usuarios, expedientes ni grabaciones.

CREATE TABLE IF NOT EXISTS telefonia_transferencia_operaciones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, origen_usuario INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL, destino_usuario INT NOT NULL,
            destino_extension VARCHAR(12) NOT NULL, referencia VARCHAR(100) NOT NULL,
            numero VARCHAR(40) NOT NULL, modo VARCHAR(16) NOT NULL, estado VARCHAR(24) NOT NULL,
            intento INT NOT NULL DEFAULT 1, evento_desde BIGINT NOT NULL DEFAULT 0,
            sdk_consulta VARCHAR(100) NULL, destino_atendida DATETIME NULL,
            destino_fin DATETIME NULL, entregada_at DATETIME NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_tr_operacion (origen_usuario,referencia),
            KEY idx_tr_destino (destino_usuario,estado,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telefonia_notificaciones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,
            extension VARCHAR(12) NOT NULL,llave VARCHAR(150) NOT NULL,numero VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,mensaje VARCHAR(300) NOT NULL,created_at DATETIME NOT NULL,
            leida TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uk_tel_aviso(usuario_id,extension,llave),
            KEY idx_tel_aviso(usuario_id,extension,leida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
