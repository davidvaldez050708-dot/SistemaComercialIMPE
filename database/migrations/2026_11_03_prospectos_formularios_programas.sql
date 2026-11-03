CREATE TABLE IF NOT EXISTS prospecto_formulario_programas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 formulario_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
 formulario_nombre VARCHAR(255) NOT NULL,
 programa_codigo VARCHAR(80) NULL,
 programa_nombre VARCHAR(255) NULL,
 version INT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_formulario_version (formulario_id,version),
 KEY idx_programa (programa_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
