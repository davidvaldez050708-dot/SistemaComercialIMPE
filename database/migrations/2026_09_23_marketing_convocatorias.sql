-- Módulo de Marketing: convocatorias/publicaciones.
-- Cambio aditivo, sin modificar estructuras existentes.

INSERT INTO roles (nombre, descripcion,estado)
SELECT 'Marketing', 'Gestión de convocatorias y publicaciones.', 1
WHERE NOT EXISTS (
    SELECT 1
    FROM roles
    WHERE nombre = 'Marketing'
);

CREATE TABLE IF NOT EXISTS convocatorias (
    id INT NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(255) NOT NULL,
    imagen VARCHAR(500) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_termino DATE NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    actualizado_por INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_convocatorias_estado (estado),
    KEY idx_convocatorias_periodo (fecha_inicio, fecha_termino),
    KEY idx_convocatorias_creado_por (creado_por),
    KEY idx_convocatorias_actualizado_por (actualizado_por),
    CONSTRAINT fk_convocatorias_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_convocatorias_actualizado_por
        FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS convocatoria_estados (
    convocatoria_id INT NOT NULL,
    estado_id INT NOT NULL,
    PRIMARY KEY (convocatoria_id, estado_id),
    KEY idx_convocatoria_estados_estado (estado_id),
    CONSTRAINT fk_convocatoria_estados_convocatoria
        FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_convocatoria_estados_estado
        FOREIGN KEY (estado_id) REFERENCES estados(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO permisos (modulo,codigo,nombre,descripcion,estado)
VALUES
('Convocatorias','convocatorias.ver','Ver convocatorias','Consultar convocatorias registradas.',1),
('Convocatorias','convocatorias.crear','Crear convocatorias','Registrar nuevas convocatorias.',1),
('Convocatorias','convocatorias.editar','Editar convocatorias','Actualizar información de convocatorias.',1),
('Convocatorias','convocatorias.gestionar','Gestionar convocatorias','Administrar el estado y operación general de las convocatorias.',1),
('Convocatorias','convocatorias.descargar','Descargar imágenes','Descargar la imagen asociada a una convocatoria.',1),
('Convocatorias','convocatorias.cambiar_estado','Activar / desactivar convocatorias','Modificar el estado lógico de una convocatoria.',1)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT roles.id, permisos.id
FROM roles
INNER JOIN permisos
    ON permisos.codigo IN (
        'convocatorias.ver',
        'convocatorias.crear',
        'convocatorias.editar',
        'convocatorias.gestionar',
        'convocatorias.descargar',
        'convocatorias.cambiar_estado'
    )
WHERE roles.nombre = 'Marketing';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, permisos.id
FROM permisos
WHERE permisos.codigo IN (
    'convocatorias.ver',
    'convocatorias.crear',
    'convocatorias.editar',
    'convocatorias.gestionar',
    'convocatorias.descargar',
    'convocatorias.cambiar_estado'
);
