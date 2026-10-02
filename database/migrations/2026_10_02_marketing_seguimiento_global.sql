-- Marketing podrá consultar Seguimiento de Vinculación con alcance global,
-- igual que el Administrador para efectos de visualización.
-- No se conceden permisos de creación, edición ni operación.

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT roles.id, permisos.id
FROM roles
INNER JOIN permisos
    ON permisos.codigo = 'seguimientos_vinculacion.ver'
WHERE roles.nombre = 'Marketing'
  AND roles.estado = 1
  AND permisos.estado = 1;
