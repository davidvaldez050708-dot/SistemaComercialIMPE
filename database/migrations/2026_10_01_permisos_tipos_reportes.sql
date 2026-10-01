-- Permisos granulares para controlar qué familias de reportes puede generar cada rol.
-- El permiso decide la familia disponible; los permisos del módulo de origen
-- siguen limitando el alcance real de los datos.

INSERT INTO permisos (
    modulo,
    codigo,
    nombre,
    descripcion,
    estado
) VALUES
(
    'Reportes',
    'reportes.seguimiento.cartera',
    'Reporte de cartera de seguimiento',
    'Generar reportes sobre el estado actual de la cartera de seguimiento dentro del alcance autorizado.',
    1
),
(
    'Reportes',
    'reportes.seguimiento.actividad',
    'Reporte de actividad de seguimiento',
    'Generar reportes de actividad e interacciones de seguimiento dentro del alcance autorizado.',
    1
),
(
    'Reportes',
    'reportes.seguimiento.institucion',
    'Reporte de institución',
    'Generar el expediente ejecutivo de una institución dentro del alcance autorizado.',
    1
),
(
    'Reportes',
    'reportes.territorial',
    'Reporte de información territorial',
    'Generar reportes de información territorial únicamente sobre territorios autorizados.',
    1
),
(
    'Reportes',
    'reportes.usuarios',
    'Reporte administrativo de usuarios',
    'Generar el reporte transversal de usuarios del sistema. Permiso exclusivo del Administrador.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

-- Conserva el comportamiento existente al migrar:
-- quien ya podía consultar Reportes + Seguimiento recibe inicialmente
-- las tres familias de Seguimiento. Después el Administrador puede revocarlas.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT acceso.rol_id, destino.id
FROM (
    SELECT rp_reportes.rol_id
    FROM rol_permisos rp_reportes
    INNER JOIN permisos p_reportes
        ON p_reportes.id = rp_reportes.permiso_id
       AND p_reportes.codigo = 'reportes.ver'
       AND p_reportes.estado = 1
    INNER JOIN rol_permisos rp_seguimiento
        ON rp_seguimiento.rol_id = rp_reportes.rol_id
    INNER JOIN permisos p_seguimiento
        ON p_seguimiento.id = rp_seguimiento.permiso_id
       AND p_seguimiento.codigo = 'seguimientos_vinculacion.ver'
       AND p_seguimiento.estado = 1
) acceso
CROSS JOIN permisos destino
WHERE destino.codigo IN (
    'reportes.seguimiento.cartera',
    'reportes.seguimiento.actividad',
    'reportes.seguimiento.institucion'
)
  AND destino.estado = 1;

-- Quien ya podía consultar Reportes + Información territorial conserva
-- inicialmente esa familia de reporte.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT acceso.rol_id, destino.id
FROM (
    SELECT rp_reportes.rol_id
    FROM rol_permisos rp_reportes
    INNER JOIN permisos p_reportes
        ON p_reportes.id = rp_reportes.permiso_id
       AND p_reportes.codigo = 'reportes.ver'
       AND p_reportes.estado = 1
    INNER JOIN rol_permisos rp_territorial
        ON rp_territorial.rol_id = rp_reportes.rol_id
    INNER JOIN permisos p_territorial
        ON p_territorial.id = rp_territorial.permiso_id
       AND p_territorial.codigo = 'data_territorial.ver'
       AND p_territorial.estado = 1
) acceso
INNER JOIN permisos destino
    ON destino.codigo = 'reportes.territorial'
   AND destino.estado = 1;

-- Reporte de usuarios: capacidad protegida exclusivamente para Administrador.
DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE p.codigo = 'reportes.usuarios'
  AND rp.rol_id <> 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo IN (
    'reportes.seguimiento.cartera',
    'reportes.seguimiento.actividad',
    'reportes.seguimiento.institucion',
    'reportes.territorial',
    'reportes.usuarios'
)
  AND p.estado = 1;
