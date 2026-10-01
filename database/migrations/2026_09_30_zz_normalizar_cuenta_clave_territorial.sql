-- Normaliza el alcance territorial de Cuenta Clave.
-- Cuenta Clave consulta sus territorios e información territorial asignada,
-- pero no administra asignaciones ni edita las fuentes territoriales.

DELETE rp
FROM rol_permisos rp
INNER JOIN roles r
    ON r.id = rp.rol_id
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE r.nombre = 'Cuenta Clave'
  AND p.codigo IN (
      'territorios.asignar',
      'data_territorial.editar',
      'data_territorial.actualizar_oficial',
      'data_territorial.gestionar_secretarias',
      'data_territorial.gestionar_municipios',
      'data_territorial.gestionar_indicadores'
  );

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'territorios.ver',
        'data_territorial.ver'
    )
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;
