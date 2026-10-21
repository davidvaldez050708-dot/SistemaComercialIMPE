-- Relación adicional con convocatorias existentes. No crea programas ni modifica identificadores.
CREATE TABLE IF NOT EXISTS formulario_aliado_convocatorias (
 aliado_id BIGINT UNSIGNED NOT NULL,
 convocatoria_id INT NOT NULL,
 programa_codigo VARCHAR(64) NOT NULL,
 formulario ENUM('registro','inscripcion') NOT NULL,
 referencia CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 convenio_seguimiento_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (convocatoria_id),
 UNIQUE KEY referencia_unica (referencia),
 KEY aliado_convocatorias (aliado_id,created_at),
 FOREIGN KEY (aliado_id) REFERENCES formulario_aliados(id),
 FOREIGN KEY (programa_codigo) REFERENCES formulario_programas(codigo),
 FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
