-- Contactos de difusión para Aliados.
-- Mantiene separados los datos originales del seguimiento de los canales
-- administrados posteriormente por Cuenta Clave.

CREATE TABLE IF NOT EXISTS aliados_contactos (
    id INT NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    numero VARCHAR(40) NOT NULL,
    numero_normalizado VARCHAR(20) NOT NULL,
    etiqueta VARCHAR(80) NOT NULL DEFAULT 'Difusión',
    origen VARCHAR(40) NOT NULL DEFAULT 'CUENTA_CLAVE',
    confirmado_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    preferido_difusion TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    actualizado_por INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_aliado_contacto_numero (seguimiento_id, numero_normalizado),
    KEY idx_aliado_contacto_preferido (seguimiento_id, preferido_difusion, activo),
    CONSTRAINT fk_aliado_contacto_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_contacto_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_aliado_contacto_actualizado_por
        FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO permisos (modulo, codigo, nombre, descripcion, estado)
VALUES (
    'Aliados',
    'aliados.gestionar_contactos',
    'Gestionar contactos de aliados',
    'Agregar y administrar números de difusión sin modificar los datos originales del seguimiento.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'aliados.gestionar_contactos'
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'aliados.gestionar_contactos'
  AND p.estado = 1;
