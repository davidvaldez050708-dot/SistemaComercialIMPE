-- Migración aditiva. Los servicios también la preparan al utilizarse.

CREATE TABLE IF NOT EXISTS telefonia_presencia (
            usuario_id INT PRIMARY KEY,extension VARCHAR(12) NOT NULL,ocupado TINYINT NOT NULL,
            updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS telefonia_disponibilidad (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,origen_usuario INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL,destino_usuario INT NOT NULL,destino_extension VARCHAR(12) NOT NULL,
            referencia VARCHAR(100) NOT NULL,numero VARCHAR(40) NOT NULL,ruta_json TEXT NOT NULL,
            estado VARCHAR(20) NOT NULL,version INT NOT NULL DEFAULT 1,expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
            contacto VARCHAR(100) NOT NULL DEFAULT '',telefono VARCHAR(40) NOT NULL DEFAULT '',
            nota VARCHAR(500) NOT NULL DEFAULT '',devolucion VARCHAR(12) NOT NULL DEFAULT '',
            UNIQUE KEY uk_disp_origen(origen_usuario,referencia),KEY idx_disp_destino(destino_usuario,estado,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
