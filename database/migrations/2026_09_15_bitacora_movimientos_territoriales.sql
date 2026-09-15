CREATE TABLE IF NOT EXISTS bitacora_movimientos_territoriales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NOT NULL,
    asignacion_id INT NULL,
    usuario_afectado_id INT NULL,
    tipo_asignacion VARCHAR(40) NOT NULL,
    accion VARCHAR(50) NOT NULL,
    cuenta_clave_asignacion_anterior_id INT NULL,
    cuenta_clave_asignacion_nueva_id INT NULL,
    fecha_efectiva DATE NULL,
    usuario_accion_id INT NULL,
    detalle VARCHAR(255) NULL,
    registrado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bitacora_territorio_estado_fecha (estado_id, registrado_at),
    KEY idx_bitacora_territorio_asignacion (asignacion_id),
    KEY idx_bitacora_territorio_usuario (usuario_afectado_id),
    KEY idx_bitacora_territorio_accion (accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Semilla histórica: conserva lo que sí puede demostrarse con los periodos
-- ya guardados antes de que existiera la bitácora.
INSERT INTO bitacora_movimientos_territoriales (
    estado_id,
    asignacion_id,
    usuario_afectado_id,
    tipo_asignacion,
    accion,
    cuenta_clave_asignacion_nueva_id,
    fecha_efectiva,
    usuario_accion_id,
    detalle,
    registrado_at
)
SELECT
    a.estado_id,
    a.id,
    a.usuario_id,
    a.tipo_asignacion,
    'ASIGNACION',
    a.cuenta_clave_asignacion_id,
    a.fecha_inicio,
    NULL,
    'Evento reconstruido a partir de la asignación histórica.',
    COALESCE(a.created_at, NOW())
FROM asignaciones_territorio a
WHERE NOT EXISTS (
    SELECT 1
    FROM bitacora_movimientos_territoriales b
    WHERE b.asignacion_id = a.id
      AND b.accion = 'ASIGNACION'
);

INSERT INTO bitacora_movimientos_territoriales (
    estado_id,
    asignacion_id,
    usuario_afectado_id,
    tipo_asignacion,
    accion,
    cuenta_clave_asignacion_anterior_id,
    fecha_efectiva,
    usuario_accion_id,
    detalle,
    registrado_at
)
SELECT
    a.estado_id,
    a.id,
    a.usuario_id,
    a.tipo_asignacion,
    'DESASIGNACION',
    a.cuenta_clave_asignacion_id,
    a.fecha_fin,
    NULL,
    'Evento reconstruido a partir de la asignación histórica.',
    COALESCE(a.updated_at, NOW())
FROM asignaciones_territorio a
WHERE a.fecha_fin IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM bitacora_movimientos_territoriales b
      WHERE b.asignacion_id = a.id
        AND b.accion = 'DESASIGNACION'
        AND b.fecha_efectiva <=> a.fecha_fin
  );
