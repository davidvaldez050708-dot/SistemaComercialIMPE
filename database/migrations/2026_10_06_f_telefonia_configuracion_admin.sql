-- Paso 2 de Telefonía: configuración administrativa de extensiones.
-- Este permiso es de gobierno del sistema y debe permanecer exclusivo
-- del rol Administrador.

INSERT INTO permisos (
    modulo,
    codigo,
    nombre,
    descripcion,
    estado
) VALUES (
    'Telefonía',
    'telefonia.configurar',
    'Configurar telefonía',
    'Asignar y administrar extensiones PBX de los usuarios. Exclusivo del Administrador.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

INSERT IGNORE INTO rol_permisos (
    rol_id,
    permiso_id
)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'telefonia.configurar'
  AND p.estado = 1;

DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE rp.rol_id <> 1
  AND p.codigo = 'telefonia.configurar';
