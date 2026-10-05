-- Agrega el reporte ejecutivo de Aliados al Centro de Reportes.
-- La visibilidad se controla por permisos y conserva el alcance territorial
-- definido para cada Cuenta Clave.

INSERT INTO permisos (
    modulo,
    codigo,
    nombre,
    descripcion,
    estado
) VALUES (
    'Reportes',
    'reportes.aliados.panorama',
    'Panorama de aliados',
    'Generar el panorama ejecutivo de aliados, cobertura territorial, difusión y seguimiento dentro del alcance autorizado.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

-- Cuenta Clave recibe acceso al Centro de Reportes y al nuevo panorama.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'reportes.ver',
        'reportes.aliados.panorama'
    )
WHERE r.nombre = 'Cuenta Clave'
  AND r.estado = 1
  AND p.estado = 1;

-- El Administrador conserva acceso al nuevo tipo de reporte.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'reportes.aliados.panorama'
  AND p.estado = 1;
