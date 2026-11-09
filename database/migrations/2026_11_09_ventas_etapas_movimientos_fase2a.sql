-- Fase 2A. Seguimiento y transiciones manuales auditadas.
-- NO activa distribución, NO modifica Telefonía, Pagos ni Inscritos.
-- Todas las escrituras comerciales requieren permisos independientes.
CREATE TABLE IF NOT EXISTS prospecto_embudo_agenda (
  asignacion_id BIGINT UNSIGNED NOT NULL,
  proxima_accion_at DATETIME NULL COMMENT 'UTC; solo seguimiento comercial',
  revision_fecha DATE NULL COMMENT 'Fecha local Mexico_City; sin envío automático',
  actualizado_at DATETIME NOT NULL,
  actualizado_por INT NULL,
  PRIMARY KEY (asignacion_id),
  KEY idx_embudo_revision (revision_fecha),
  KEY idx_embudo_proxima (proxima_accion_at),
  CONSTRAINT fk_embudo_agenda_asignacion
    FOREIGN KEY (asignacion_id) REFERENCES prospecto_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo resultados PBX explícitamente asociados a la asignación por el asesor.
-- Esta relación se inserta dentro de la transacción del resultado ya validado.
CREATE TABLE IF NOT EXISTS prospecto_embudo_llamadas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asignacion_id BIGINT UNSIGNED NOT NULL,
  usuario_id INT NOT NULL,
  extension VARCHAR(20) NOT NULL,
  pbx_call_id VARCHAR(100) NOT NULL,
  resultado VARCHAR(36) NOT NULL,
  creado_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_embudo_llamada_pbx (extension,pbx_call_id),
  KEY idx_embudo_llamada_asignacion (asignacion_id,creado_at),
  CONSTRAINT fk_embudo_llamada_asignacion FOREIGN KEY (asignacion_id)
    REFERENCES prospecto_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @perm_mover_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.embudo.mover');
SET @perm_recuperar_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.recuperacion.gestionar');
SET @perm_descartar_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.descartados.gestionar');

INSERT IGNORE INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES
('Ventas','ventas.embudo.mover','Mover prospectos propios entre etapas',
 'Actualizar etapas propias por arrastre, con bitácora, validación y sin confirmar pagos.',1),
('Ventas','ventas.recuperacion.gestionar','Enviar y recuperar prospectos propios',
 'Gestionar recuperación propia sin automatizar mensajes ni llamadas.',1),
('Ventas','ventas.descartados.gestionar','Descartar prospectos propios',
 'Descartar con motivo obligatorio; no se eliminan registros ni se contacta sin permiso.',1);

INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id
FROM roles r JOIN permisos p ON p.estado=1
WHERE r.nombre='Asesor de Ventas' AND r.estado=1 AND (
 (p.codigo='ventas.embudo.mover' AND @perm_mover_nuevo=1) OR
 (p.codigo='ventas.recuperacion.gestionar' AND @perm_recuperar_nuevo=1) OR
 (p.codigo='ventas.descartados.gestionar' AND @perm_descartar_nuevo=1)
);
SET @perm_mover_nuevo := NULL;
SET @perm_recuperar_nuevo := NULL;
SET @perm_descartar_nuevo := NULL;
