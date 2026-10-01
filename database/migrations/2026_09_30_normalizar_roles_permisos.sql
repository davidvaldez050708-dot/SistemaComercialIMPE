-- Normalización central de roles y permisos.
-- Después de esta migración, la configuración realizada en Roles y permisos
-- no debe ser revertida por inicializadores ni por relaciones heredadas.

CREATE TABLE IF NOT EXISTS auditoria_roles_permisos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rol_id INT NOT NULL,
    actor_usuario_id INT NULL,
    accion VARCHAR(60) NOT NULL,
    permisos_anteriores JSON NULL,
    permisos_nuevos JSON NULL,
    permisos_agregados JSON NULL,
    permisos_removidos JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_roles_permisos_rol (rol_id, created_at),
    KEY idx_auditoria_roles_permisos_actor (actor_usuario_id, created_at),
    CONSTRAINT fk_auditoria_roles_permisos_rol
        FOREIGN KEY (rol_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_auditoria_roles_permisos_actor
        FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Elimina concesiones heredadas de Cuenta Clave que pertenecen a la operación
-- exclusiva del Analista.
DELETE rp
FROM rol_permisos rp
INNER JOIN roles r
    ON r.id = rp.rol_id
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE r.nombre = 'Cuenta Clave'
  AND p.codigo IN (
      'seguimientos_vinculacion.crear',
      'seguimientos_vinculacion.editar',
      'seguimientos_vinculacion.operar_propios'
  );

-- El Analista conserva la operación de sus propios expedientes, mientras
-- Cuenta Clave conserva supervisión y observaciones.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'seguimientos_vinculacion.ver',
        'seguimientos_vinculacion.crear',
        'seguimientos_vinculacion.editar',
        'seguimientos_vinculacion.operar_propios',
        'reuniones.ver',
        'reuniones.solicitar',
        'reportes.ver',
        'reportes.exportar'
    )
WHERE r.nombre = 'Analista de Datos'
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'seguimientos_vinculacion.ver',
        'seguimientos_vinculacion.supervisar',
        'seguimientos_vinculacion.comentar',
        'reuniones.ver',
        'reuniones.gestionar',
        'reportes.ver',
        'reportes.exportar'
    )
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;

-- Convocatorias ya no depende de un bypass por rol. Conservamos explícitamente
-- el acceso territorial que antes era concedido por código.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'convocatorias.ver'
WHERE r.nombre IN (
    'Cuenta Clave',
    'Analista de Datos',
    'Asesor de Ventas',
    'Asesor'
)
  AND p.estado = 1;

-- El Administrador es un rol protegido: conserva todos los permisos activos.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permisos p
WHERE r.id = 1
  AND p.estado = 1;

-- Relaciones obsoletas que ya no deben participar en autorización.
DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE p.codigo IN (
    'seguimientos.ver',
    'seguimientos.crear',
    'seguimientos.editar',
    'territorios.editar',
    'territorios.actualizar_ficha'
);

UPDATE permisos
SET estado = 0,
    updated_at = NOW()
WHERE codigo IN (
    'seguimientos.ver',
    'seguimientos.crear',
    'seguimientos.editar',
    'territorios.editar',
    'territorios.actualizar_ficha'
);
