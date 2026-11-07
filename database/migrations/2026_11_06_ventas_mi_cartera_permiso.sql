-- Fase 1B-0: habilitar únicamente la CONSULTA de cartera propia.
-- Migración idempotente y aditiva. No crea asignaciones, no altera prospectos
-- ni tablas existentes; no concede prospectos.ver_todos a Ventas.
-- Roles y permisos detectará el permiso en la tabla activa, sin modificar RolModel.
SET @impe_cartera_permiso_nuevo := (
 SELECT COUNT(*)=0 FROM permisos WHERE codigo='prospectos.mi_cartera'
);

INSERT IGNORE INTO permisos (modulo,codigo,nombre,descripcion,estado)
VALUES (
 'Prospectos','prospectos.mi_cartera','Ver Mi cartera',
 'Consultar únicamente los prospectos con asignación vigente a la propia cuenta. No permite consultar el almacén general ni modificar asignaciones.',1
);

-- Concesión inicial de una sola vez a Asesor de Ventas.
-- Si se revoca después desde Roles y permisos, repetir la migración NO la restaura.
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id
FROM roles r JOIN permisos p ON p.codigo='prospectos.mi_cartera'
WHERE r.nombre='Asesor de Ventas' AND r.estado=1
 AND p.estado=1 AND @impe_cartera_permiso_nuevo=1;
