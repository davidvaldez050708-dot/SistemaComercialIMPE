-- Aditiva e idempotente: diseño único de QR por perfil Registro del aliado.
-- No cambia enlaces, tokens, borradores ni convocatorias anteriores.
CREATE TABLE IF NOT EXISTS formulario_aliado_qr_diseno (
  aliado_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  diseno_json TEXT NOT NULL,
  logo_binario MEDIUMBLOB NULL,
  logo_mime VARCHAR(24) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  actualizado_por INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_form_aliado_qr_perfil FOREIGN KEY (aliado_id) REFERENCES formulario_aliados(id) ON DELETE CASCADE,
  CONSTRAINT fk_form_aliado_qr_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
