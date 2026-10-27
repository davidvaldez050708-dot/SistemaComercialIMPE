-- Notas adicionales: el resultado y su observación original permanecen intactos.
CREATE TABLE IF NOT EXISTS telefonia_ventas_observaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 resultado_id BIGINT UNSIGNED NOT NULL,
 autor_usuario_id INT NOT NULL,
 extension VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 pbx_call_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 autor_nombre VARCHAR(255) NOT NULL,
 nota VARCHAR(1000) NOT NULL,
 token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uk_ventas_observacion_envio (autor_usuario_id,token),
 KEY idx_ventas_observacion_resultado (resultado_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
