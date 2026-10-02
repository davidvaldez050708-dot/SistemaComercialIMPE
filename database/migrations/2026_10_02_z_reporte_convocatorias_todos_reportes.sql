-- El Reporte de Convocatorias forma parte del Centro de Reportes
-- para todos los perfiles que ya tienen acceso al módulo de Reportes.
-- No concede acceso operativo al módulo de Convocatorias.

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
    'Consultar y generar el reporte ejecutivo de Convocatorias desde el Centro de Reportes.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

-- Todo rol que ya tenga acceso al Centro de Reportes recibe
-- la familia de Reporte de Convocatorias.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT rp_reportes.rol_id, p_convocatorias.id
FROM rol_permisos rp_reportes
INNER JOIN permisos p_reportes
    ON p_reportes.id = rp_reportes.permiso_id
   AND p_reportes.codigo = 'reportes.ver'
   AND p_reportes.estado = 1
CROSS JOIN permisos p_convocatorias
WHERE p_convocatorias.codigo = 'reportes.convocatorias'
  AND p_convocatorias.estado = 1;

-- El Administrador conserva el permiso como parte de su acceso completo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'reportes.convocatorias'
  AND p.estado = 1;
