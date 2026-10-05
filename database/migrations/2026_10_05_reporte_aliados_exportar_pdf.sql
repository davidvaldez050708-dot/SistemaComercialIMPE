-- Habilita la exportación PDF del reporte de Aliados para Cuenta Clave.
-- El Administrador puede revocar posteriormente este permiso desde Roles y permisos.

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'reportes.exportar'
WHERE r.nombre = 'Cuenta Clave'
  AND r.estado = 1
  AND p.estado = 1;

-- El Administrador conserva la capacidad de exportación.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'reportes.exportar'
  AND p.estado = 1;
