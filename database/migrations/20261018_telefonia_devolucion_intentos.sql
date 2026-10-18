-- Estructura aditiva. El servicio también la prepara al utilizarse.
CREATE TABLE IF NOT EXISTS telefonia_devolucion_intentos (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,solicitud_id BIGINT UNSIGNED NOT NULL,version INT NOT NULL,
          usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,numero VARCHAR(40) NOT NULL,
          desde_evento_id BIGINT NOT NULL,pbx_call_id VARCHAR(100) NULL,estado VARCHAR(20) NOT NULL DEFAULT 'PREPARADO',
          resultado VARCHAR(30) NOT NULL DEFAULT '',identificacion VARCHAR(24) NOT NULL DEFAULT '',
          seguimiento_id INT NOT NULL DEFAULT 0,interaccion_id INT NULL,institucion_nombre VARCHAR(150) NOT NULL DEFAULT '',
          observacion VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
          UNIQUE KEY uk_dev_llamada(usuario_id,pbx_call_id),KEY idx_dev_pendiente(usuario_id,estado,resultado),
          KEY idx_dev_solicitud(solicitud_id,version,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
