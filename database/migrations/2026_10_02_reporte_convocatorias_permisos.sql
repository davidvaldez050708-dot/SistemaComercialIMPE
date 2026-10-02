-- Integra el reporte de Convocatorias al Centro de Reportes
-- y habilita por defecto al rol Marketing para consultarlo y exportarlo.

INSERT INTO permisos (
    modulo,
    codigo,
    nombre,
    descripcion,
    estado
) VALUES (
    'Reportes',
    'reportes.convocatorias',
    'Reporte de convocatorias',
    'Consultar y generar el reporte ejecutivo del módulo de Convocatorias.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

-- Marketing conserva acceso al módulo y recibe las capacidades de Reportes
-- necesarias para consultar el Centro de Reportes y exportar a PDF.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'convocatorias.ver',
        'reportes.ver',
        'reportes.convocatorias',
        'reportes.exportar'
    )
WHERE r.nombre = 'Marketing'
  AND r.estado = 1
  AND p.estado = 1;

-- El Administrador conserva todos los permisos activos.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'reportes.convocatorias'
WHERE r.id = 1
  AND p.estado = 1;
