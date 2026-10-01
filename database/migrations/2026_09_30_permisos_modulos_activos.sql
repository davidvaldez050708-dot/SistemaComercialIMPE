-- Mantiene en Roles y permisos únicamente capacidades con implementación
-- efectiva y completa en la aplicación actual.

-- El Analista gestiona la documentación del convenio dentro de su propia ruta.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN ('convenios.ver', 'convenios.gestionar')
WHERE r.nombre = 'Analista de Datos'
  AND p.estado = 1;

-- Permisos previstos/legacy sin módulo funcional actual.
DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE p.codigo IN (
    'prospectos.ver_todos',
    'prospectos.ver_propios',
    'prospectos.editar',
    'prospectos.asignar',
    'seguimientos_comerciales.ver_todos',
    'seguimientos_comerciales.ver_propios',
    'seguimientos_comerciales.crear',
    'seguimientos_comerciales.editar',
    'seguimientos_comerciales.editar_propios',
    'pagos.ver',
    'pagos.validar',
    'organizaciones.ver',
    'organizaciones.crear',
    'organizaciones.editar',
    'organizaciones.validar',
    'difusion.ver',
    'difusion.crear',
    'difusion.enviar',
    'difusion.gestionar',
    'respaldos.generar',
    'respaldos.restaurar',
    'configuracion.ver',
    'configuracion.editar'
);

UPDATE permisos
SET estado = 0,
    updated_at = NOW()
WHERE codigo IN (
    'prospectos.ver_todos',
    'prospectos.ver_propios',
    'prospectos.editar',
    'prospectos.asignar',
    'seguimientos_comerciales.ver_todos',
    'seguimientos_comerciales.ver_propios',
    'seguimientos_comerciales.crear',
    'seguimientos_comerciales.editar',
    'seguimientos_comerciales.editar_propios',
    'pagos.ver',
    'pagos.validar',
    'organizaciones.ver',
    'organizaciones.crear',
    'organizaciones.editar',
    'organizaciones.validar',
    'difusion.ver',
    'difusion.crear',
    'difusion.enviar',
    'difusion.gestionar',
    'respaldos.generar',
    'respaldos.restaurar',
    'configuracion.ver',
    'configuracion.editar'
);
