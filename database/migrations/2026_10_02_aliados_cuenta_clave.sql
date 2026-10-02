-- Módulo Aliados para Cuenta Clave.
-- Un aliado nace exclusivamente de un seguimiento con convenio formalizado.
-- No duplica datos institucionales: conserva seguimiento_id como fuente de verdad.

CREATE TABLE IF NOT EXISTS aliados_asignaciones (
    id INT NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    cuenta_clave_usuario_id INT NULL,
    cuenta_clave_asignacion_id INT NULL,
    origen_asignacion VARCHAR(30) NOT NULL DEFAULT 'FORMALIZACION',
    asignado_por INT NULL,
    asignado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_aliado_seguimiento (seguimiento_id),
    KEY idx_aliado_cuenta_clave (cuenta_clave_usuario_id, activo),
    KEY idx_aliado_asignacion_territorio (cuenta_clave_asignacion_id),
    CONSTRAINT fk_aliado_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_cuenta_clave_usuario
        FOREIGN KEY (cuenta_clave_usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_aliado_cuenta_clave_asignacion
        FOREIGN KEY (cuenta_clave_asignacion_id) REFERENCES asignaciones_territorio(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_aliado_asignado_por
        FOREIGN KEY (asignado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS aliados_convocatorias_envios (
    id INT NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    convocatoria_id INT NULL,
    cuenta_clave_usuario_id INT NOT NULL,
    canal VARCHAR(20) NOT NULL DEFAULT 'CORREO',
    destinatario VARCHAR(180) NOT NULL,
    asunto VARCHAR(255) NULL,
    mensaje MEDIUMTEXT NOT NULL,
    convocatoria_titulo VARCHAR(255) NOT NULL,
    convocatoria_imagen VARCHAR(500) NULL,
    convocatoria_fecha_inicio DATE NULL,
    convocatoria_fecha_termino DATE NULL,
    estado_envio VARCHAR(20) NOT NULL DEFAULT 'ENVIADO',
    proveedor VARCHAR(80) NULL,
    error_detalle VARCHAR(700) NULL,
    enviado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aliado_envio_seguimiento (seguimiento_id, enviado_at),
    KEY idx_aliado_envio_convocatoria (convocatoria_id, enviado_at),
    KEY idx_aliado_envio_usuario (cuenta_clave_usuario_id, enviado_at),
    KEY idx_aliado_envio_duplicado (seguimiento_id, convocatoria_id, canal, estado_envio),
    CONSTRAINT fk_aliado_envio_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_envio_convocatoria
        FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_aliado_envio_usuario
        FOREIGN KEY (cuenta_clave_usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Conserva la Cuenta Clave responsable al momento de convertir seguimientos ya
-- formalizados en aliados. Se toma la asignación territorial vigente/principal
-- del Analista y su Cuenta Clave asociada.
INSERT IGNORE INTO aliados_asignaciones (
    seguimiento_id,
    cuenta_clave_usuario_id,
    cuenta_clave_asignacion_id,
    origen_asignacion,
    asignado_por,
    asignado_at,
    activo
)
SELECT
    s.id,
    cuenta.usuario_id,
    cuenta.id,
    'MIGRACION',
    p.convenio_formalizado_por,
    COALESCE(p.convenio_formalizado_at, NOW()),
    1
FROM seguimientos_vinculacion s
INNER JOIN seguimientos_vinculacion_post_envio p
    ON p.seguimiento_id = s.id
LEFT JOIN asignaciones_territorio analista
    ON analista.id = (
        SELECT a2.id
        FROM asignaciones_territorio a2
        WHERE a2.usuario_id = s.analista_id
          AND a2.estado_id = s.estado_id
          AND a2.tipo_asignacion = 'ANALISTA_DATOS'
          AND a2.activo = 1
          AND (a2.fecha_inicio IS NULL OR a2.fecha_inicio <= CURDATE())
          AND (a2.fecha_fin IS NULL OR a2.fecha_fin >= CURDATE())
        ORDER BY a2.es_principal DESC, a2.id DESC
        LIMIT 1
    )
LEFT JOIN asignaciones_territorio cuenta
    ON cuenta.id = analista.cuenta_clave_asignacion_id
   AND cuenta.tipo_asignacion = 'CUENTA_CLAVE'
   AND cuenta.activo = 1
   AND (cuenta.fecha_inicio IS NULL OR cuenta.fecha_inicio <= CURDATE())
   AND (cuenta.fecha_fin IS NULL OR cuenta.fecha_fin >= CURDATE())
WHERE p.convenio_formalizado_at IS NOT NULL;

INSERT INTO permisos (modulo, codigo, nombre, descripcion, estado)
VALUES
('Aliados', 'aliados.ver', 'Ver aliados', 'Consultar instituciones con convenio formalizado dentro del alcance autorizado.', 1),
('Aliados', 'aliados.ver_historial', 'Ver historial de aliados', 'Consultar el historial de convocatorias compartidas con aliados autorizados.', 1),
('Aliados', 'aliados.compartir_correo', 'Compartir convocatorias por correo', 'Enviar convocatorias vigentes por correo a aliados autorizados.', 1)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1;

-- Cuenta Clave recibe la operación del módulo; convocatorias.ver es una
-- dependencia de fuente para seleccionar publicaciones vigentes.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'aliados.ver',
        'aliados.ver_historial',
        'aliados.compartir_correo',
        'convocatorias.ver'
    )
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;

-- Administrador conserva acceso completo al catálogo activo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo IN (
    'aliados.ver',
    'aliados.ver_historial',
    'aliados.compartir_correo'
)
  AND p.estado = 1;
