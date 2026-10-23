-- Aditiva: no modifica convocatorias, programas, tokens ni registros históricos.
CREATE TABLE IF NOT EXISTS convocatoria_borradores (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 propietario_id INT NOT NULL,
 aliado_tipo VARCHAR(20) NOT NULL,
 aliado_id BIGINT UNSIGNED NOT NULL,
 territorio_id INT NOT NULL,
 clave_activa CHAR(64) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
 datos LONGTEXT NOT NULL,
 resultado LONGTEXT NULL,
 creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_por INT NOT NULL,
 UNIQUE KEY uq_borrador_activo (clave_activa),
 KEY idx_borrador_propietario (propietario_id,aliado_tipo,aliado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS convocatoria_borrador_imagenes (
 borrador_id BIGINT UNSIGNED NOT NULL,
 programa_codigo VARCHAR(64) NOT NULL,
 referencia CHAR(64) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 nombre VARCHAR(180) NOT NULL,
 mime VARCHAR(40) NOT NULL,
 bytes INT UNSIGNED NOT NULL,
 actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_por INT NOT NULL,
 PRIMARY KEY (borrador_id,programa_codigo),
 UNIQUE KEY uq_borrador_imagen_ref (referencia),
 CONSTRAINT fk_borrador_imagen FOREIGN KEY (borrador_id) REFERENCES convocatoria_borradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS convocatoria_borrador_operaciones (
 borrador_id BIGINT UNSIGNED NOT NULL,
 operacion CHAR(64) NOT NULL,
 tipo VARCHAR(20) NOT NULL,
 peticion_sha256 CHAR(64) NOT NULL,
 respuesta LONGTEXT NOT NULL,
 creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (borrador_id,operacion),
 CONSTRAINT fk_borrador_operacion FOREIGN KEY (borrador_id) REFERENCES convocatoria_borradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
