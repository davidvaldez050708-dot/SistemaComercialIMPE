-- Convierte convocatorias.gestionar en un permiso paraguas administrativo.
-- Quien lo tenga conserva alcance global, centro de alertas y las capacidades
-- granulares necesarias para administrar el módulo.

UPDATE permisos
SET nombre = 'Administrar convocatorias',
    descripcion = 'Habilita alcance global, alertas y las capacidades administrativas de convocatorias.',
    updated_at = NOW()
WHERE codigo = 'convocatorias.gestionar';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT gestores.rol_id, permisos_hijos.id
FROM (
    SELECT DISTINCT rp.rol_id
    FROM rol_permisos rp
    INNER JOIN permisos p
        ON p.id = rp.permiso_id
    WHERE p.codigo = 'convocatorias.gestionar'
      AND p.estado = 1
) gestores
CROSS JOIN permisos permisos_hijos
WHERE permisos_hijos.codigo IN (
    'convocatorias.ver',
    'convocatorias.crear',
    'convocatorias.editar',
    'convocatorias.descargar',
    'convocatorias.cambiar_estado'
)
  AND permisos_hijos.estado = 1;
