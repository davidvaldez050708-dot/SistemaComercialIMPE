-- Aditiva: no altera registros, inscripciones, expedientes ni enlaces existentes.
CREATE TABLE IF NOT EXISTS formulario_programas (
 codigo VARCHAR(64) NOT NULL PRIMARY KEY,
 nombre VARCHAR(180) NOT NULL UNIQUE,
 activo TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS formulario_aliados (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL UNIQUE,
 nombre VARCHAR(180) NOT NULL,
 ubicacion VARCHAR(180) NOT NULL,
 seguimiento_id INT NULL UNIQUE,
 token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 activo TINYINT NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS formulario_aliado_programas (
 aliado_id BIGINT UNSIGNED NOT NULL,
 programa_codigo VARCHAR(64) NOT NULL,
 PRIMARY KEY (aliado_id, programa_codigo),
 FOREIGN KEY (aliado_id) REFERENCES formulario_aliados(id),
 FOREIGN KEY (programa_codigo) REFERENCES formulario_programas(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS formulario_aliado_procedencias (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 aliado_id BIGINT UNSIGNED NOT NULL,
 tipo ENUM('registro','inscripcion') NOT NULL,
 registro_id BIGINT UNSIGNED NOT NULL,
 programa_codigo VARCHAR(64) NOT NULL,
 aliado_nombre VARCHAR(180) NOT NULL,
 aliado_ubicacion VARCHAR(180) NOT NULL,
 clave CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY solicitud_unica (aliado_id, tipo, clave),
 UNIQUE KEY registro_unico (tipo, registro_id),
 FOREIGN KEY (aliado_id) REFERENCES formulario_aliados(id),
 FOREIGN KEY (programa_codigo) REFERENCES formulario_programas(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
