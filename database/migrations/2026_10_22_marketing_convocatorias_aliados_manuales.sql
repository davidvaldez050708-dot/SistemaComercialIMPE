-- Una convocatoria por imagen/programa, todas las publicaciones de un aliado usan
-- la misma URL y QR de Registro. Sin seguimiento institucional ficticio.
CREATE TABLE IF NOT EXISTS marketing_aliado_manual_convocatorias (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  manual_id BIGINT UNSIGNED NOT NULL,
  convocatoria_id INT UNSIGNED NOT NULL,
  programa_codigo VARCHAR(64) NOT NULL,
  creado_por INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_convocatoria (convocatoria_id),
  KEY idx_aliado_programa (manual_id,programa_codigo),
  CONSTRAINT fk_aliado_manual_convocatoria
      FOREIGN KEY (manual_id) REFERENCES aliados_manuales(id),
  CONSTRAINT fk_manual_publicacion
      FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id),
  CONSTRAINT fk_programa_manual
      FOREIGN KEY (programa_codigo) REFERENCES formulario_programas(codigo),
  CONSTRAINT fk_manual_publicacion_usuario
      FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
