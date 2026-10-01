-- El permiso seguimientos_vinculacion.editar dejó de representar una
-- capacidad independiente. La creación, operación propia y supervisión tienen
-- permisos específicos y comprobaciones de alcance.

DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE p.codigo = 'seguimientos_vinculacion.editar';

UPDATE permisos
SET estado = 0,
    updated_at = NOW()
WHERE codigo = 'seguimientos_vinculacion.editar';
