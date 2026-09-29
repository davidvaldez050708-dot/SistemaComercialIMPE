-- Habilita la consulta del módulo de Convocatorias para perfiles territoriales.
-- La aplicación limita la visualización a los estados asignados a cada usuario.

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT roles.id, permisos.id
FROM roles
INNER JOIN permisos
    ON permisos.codigo = 'convocatorias.ver'
WHERE roles.nombre IN (
    'Cuenta Clave',
    'Analista de Datos',
    'Asesor de Ventas',
    'Asesor'
);
