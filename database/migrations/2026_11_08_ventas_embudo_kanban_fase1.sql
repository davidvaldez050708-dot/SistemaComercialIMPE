-- Fase 1: tablero comercial de SOLO LECTURA.
-- No mueve prospectos, no toca la tabla de asignaciones ni activa motores.
-- La etapa de un prospecto sin fila específica es NUEVO (solo presentación).
-- Los permisos se conceden una única vez y no se restauran tras revocación.
CREATE TABLE IF NOT EXISTS prospecto_embudo_etapas (
    asignacion_id BIGINT UNSIGNED NOT NULL,
    etapa VARCHAR(30) NOT NULL,
    cambiado_por INT NULL,
    actualizado_at DATETIME NOT NULL,
    PRIMARY KEY (asignacion_id),
    KEY idx_embudo_etapa_fecha (etapa,actualizado_at),
    CONSTRAINT fk_embudo_etapa_asignacion FOREIGN KEY (asignacion_id)
        REFERENCES prospecto_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserva para el registro íntegro de transiciones de próximas fases.
CREATE TABLE IF NOT EXISTS prospecto_embudo_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asignacion_id BIGINT UNSIGNED NOT NULL,
    etapa_anterior VARCHAR(30) NOT NULL,
    etapa_nueva VARCHAR(30) NOT NULL,
    motivo VARCHAR(190) NOT NULL,
    usuario_id INT NOT NULL,
    creado_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_embudo_mov_asignacion (asignacion_id,creado_at),
    CONSTRAINT fk_embudo_mov_asignacion FOREIGN KEY (asignacion_id)
        REFERENCES prospecto_asignaciones(id),
    CONSTRAINT fk_embudo_mov_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @impe_embudo_ver_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.embudo.ver');
SET @impe_embudo_recuperacion_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.recuperacion.ver');
SET @impe_embudo_descartados_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.descartados.ver');
SET @impe_embudo_supervisar_nuevo := (SELECT COUNT(*)=0 FROM permisos WHERE codigo='ventas.embudo.supervisar');

INSERT IGNORE INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES
('Ventas','ventas.embudo.ver','Consultar embudo comercial propio',
 'Consultar las etapas y prospectos vigentes asignados a la propia cuenta de Asesor de Ventas. Sólo lectura.',1),
('Ventas','ventas.recuperacion.ver','Consultar recuperación propia',
 'Consultar la vista separada de recuperación de prospectos asignados a la propia cuenta. Sólo lectura.',1),
('Ventas','ventas.descartados.ver','Consultar descartados propios',
 'Consultar la vista separada de descartados de prospectos asignados a la propia cuenta. Sólo lectura.',1),
('Ventas','ventas.embudo.supervisar','Supervisar embudo comercial',
 'Consulta global de tablero, recuperación y descartados, exclusiva del Administrador. No permite ejecutar gestiones.',1);

-- Asesor de Ventas: concesión inicial, exclusivamente a permisos nuevos.
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p ON p.estado=1
WHERE r.nombre='Asesor de Ventas' AND r.estado=1 AND (
    (p.codigo='ventas.embudo.ver' AND @impe_embudo_ver_nuevo=1) OR
    (p.codigo='ventas.recuperacion.ver' AND @impe_embudo_recuperacion_nuevo=1) OR
    (p.codigo='ventas.descartados.ver' AND @impe_embudo_descartados_nuevo=1)
);

-- Supervisión transversal del Administrador. La política de RolModel
-- ya conserva permisos de consulta del Administrador.
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p
    ON p.codigo='ventas.embudo.supervisar' AND p.estado=1
WHERE r.id=1 AND r.estado=1 AND @impe_embudo_supervisar_nuevo=1;

SET @impe_embudo_ver_nuevo := NULL;
SET @impe_embudo_recuperacion_nuevo := NULL;
SET @impe_embudo_descartados_nuevo := NULL;
SET @impe_embudo_supervisar_nuevo := NULL;
