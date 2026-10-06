-- Módulo de Desempeño y Reconocimientos.
-- Los permisos separan consulta propia, supervisión de equipo, vista global y exportación.

INSERT INTO permisos (
    modulo,
    codigo,
    nombre,
    descripcion,
    estado
) VALUES
(
    'Desempeño',
    'desempeno.ver',
    'Ver desempeño',
    'Acceder al módulo de Desempeño y Reconocimientos.',
    1
),
(
    'Desempeño',
    'desempeno.ver_propio',
    'Ver desempeño propio',
    'Consultar las métricas e historial propios dentro del módulo de desempeño.',
    1
),
(
    'Desempeño',
    'desempeno.ver_equipo',
    'Ver desempeño del equipo',
    'Consultar métricas y ranking de las personas vinculadas al alcance de supervisión del usuario.',
    1
),
(
    'Desempeño',
    'desempeno.ver_global',
    'Ver desempeño global',
    'Consultar rankings y métricas globales de las áreas habilitadas.',
    1
),
(
    'Desempeño',
    'desempeno.exportar',
    'Exportar desempeño',
    'Generar PDF de desempeño y reconocimientos respetando el alcance autorizado.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

-- Analista: únicamente su desempeño.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'desempeno.ver',
        'desempeno.ver_propio',
        'desempeno.exportar'
    )
WHERE r.nombre = 'Analista de Datos'
  AND r.estado = 1
  AND p.estado = 1;

-- Marketing: únicamente su desempeño personal; no participa en rankings globales o de equipo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'desempeno.ver',
        'desempeno.ver_propio',
        'desempeno.exportar'
    )
WHERE r.nombre = 'Marketing'
  AND r.estado = 1
  AND p.estado = 1;

-- Cuenta Clave: su desempeño y la vista global de su propio equipo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'desempeno.ver',
        'desempeno.ver_propio',
        'desempeno.ver_equipo',
        'desempeno.exportar'
    )
WHERE r.nombre = 'Cuenta Clave'
  AND r.estado = 1
  AND p.estado = 1;

-- La vista global organizacional se reserva al Administrador.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo IN (
    'desempeno.ver',
    'desempeno.ver_propio',
    'desempeno.ver_equipo',
    'desempeno.ver_global',
    'desempeno.exportar'
)
  AND p.estado = 1;
