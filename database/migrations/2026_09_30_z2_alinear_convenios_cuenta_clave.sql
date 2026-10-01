-- Cuenta Clave consulta la documentación de convenio, pero la gestión
-- documental actual pertenece al Analista responsable del seguimiento.

DELETE rp
FROM rol_permisos rp
INNER JOIN roles r
    ON r.id = rp.rol_id
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE r.nombre = 'Cuenta Clave'
  AND p.codigo = 'convenios.gestionar';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'convenios.ver'
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;
