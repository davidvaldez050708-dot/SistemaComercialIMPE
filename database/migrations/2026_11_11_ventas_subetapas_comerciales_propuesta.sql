-- MIGRACIÓN ADITIVA: instalación exclusivamente con publicador CLI verificado
-- tools/ventas_instalar_subetapas_hostinger_v1.php
-- NO ejecutar directamente en phpMyAdmin ni cargar desde aplicaciones web.
-- No activa el motor de decisiones, no toca Telefonía, no migra los
-- códigos legacy INTERESADO o PAGO_CONFIRMADO y no altera filas históricas.
--
-- El contador 3/3 NO tiene tabla ni columna mutable: se calcula con
-- prospecto_embudo_llamadas + telefonia_ventas_llamadas + resultados.
-- La subetapa y su auditoría sí necesitan persistencia para trámites.
--
-- Ejecutar únicamente tras comprobación de estructura, respaldo privado
-- de metadatos/esquemas originales y autorización explícita.
CREATE TABLE IF NOT EXISTS prospecto_embudo_subetapas (
  asignacion_id BIGINT UNSIGNED NOT NULL,
  subetapa VARCHAR(40) NOT NULL,
  actualizado_por INT NULL,
  actualizado_at DATETIME NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (asignacion_id),
  KEY idx_subetapas_subetapa (subetapa, actualizado_at),
  CONSTRAINT fk_embudo_subetapas_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES prospecto_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_embudo_subetapas_historial (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asignacion_id BIGINT UNSIGNED NOT NULL,
  etapa_en_evento VARCHAR(40) NOT NULL,
  subetapa_anterior VARCHAR(40) NULL,
  subetapa_nueva VARCHAR(40) NULL,
  motivo VARCHAR(190) NOT NULL,
  origen VARCHAR(20) NOT NULL,
  usuario_id INT NULL,
  creado_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_embudo_subetapa_historial (asignacion_id, creado_at, id),
  CONSTRAINT fk_embudo_subetapa_historial_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES prospecto_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NO INSERT, UPDATE, DELETE, ALTER, permisos, triggers o cron.
-- Una subetapa se validará contra la etapa actual en la misma transacción.
-- Los historiales existentes permanecerán con su código original.
