-- Motor de conversaciones WhatsApp Business Platform.
-- Preparado para Cloud API de Meta con uno o varios números empresariales.
-- Las credenciales nunca se guardan en base de datos.

CREATE TABLE IF NOT EXISTS whatsapp_cuentas (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    phone_number_id VARCHAR(80) NOT NULL,
    numero_mostrado VARCHAR(40) NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'EMPRESARIAL',
    es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    actualizado_por INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_whatsapp_phone_number_id (phone_number_id),
    KEY idx_whatsapp_cuenta_usuario (usuario_id, activo),
    CONSTRAINT fk_whatsapp_cuenta_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_cuenta_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_cuenta_actualizado_por
        FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS whatsapp_conversaciones (
    id INT NOT NULL AUTO_INCREMENT,
    cuenta_id INT NOT NULL,
    telefono_contacto VARCHAR(40) NOT NULL,
    telefono_normalizado VARCHAR(20) NOT NULL,
    nombre_contacto VARCHAR(180) NULL,
    aliado_seguimiento_id INT NULL,
    responsable_usuario_id INT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ABIERTA',
    ventana_servicio_hasta DATETIME NULL,
    no_leidos INT NOT NULL DEFAULT 0,
    ultimo_mensaje_at DATETIME NULL,
    ultimo_mensaje_preview VARCHAR(300) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_whatsapp_conversacion_contacto (cuenta_id, telefono_normalizado),
    KEY idx_whatsapp_conversacion_responsable (responsable_usuario_id, estado, ultimo_mensaje_at),
    KEY idx_whatsapp_conversacion_aliado (aliado_seguimiento_id),
    CONSTRAINT fk_whatsapp_conversacion_cuenta
        FOREIGN KEY (cuenta_id) REFERENCES whatsapp_cuentas(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_whatsapp_conversacion_aliado
        FOREIGN KEY (aliado_seguimiento_id) REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_conversacion_responsable
        FOREIGN KEY (responsable_usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS whatsapp_mensajes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    conversacion_id INT NOT NULL,
    wamid VARCHAR(255) NULL,
    direccion VARCHAR(12) NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'TEXT',
    contenido MEDIUMTEXT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'RECIBIDO',
    error_codigo VARCHAR(80) NULL,
    error_detalle VARCHAR(700) NULL,
    usuario_id INT NULL,
    mensaje_meta LONGTEXT NULL,
    enviado_at DATETIME NULL,
    entregado_at DATETIME NULL,
    leido_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_whatsapp_mensaje_wamid (wamid),
    KEY idx_whatsapp_mensaje_conversacion (conversacion_id, id),
    KEY idx_whatsapp_mensaje_estado (estado, created_at),
    CONSTRAINT fk_whatsapp_mensaje_conversacion
        FOREIGN KEY (conversacion_id) REFERENCES whatsapp_conversaciones(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_mensaje_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS whatsapp_webhook_eventos (
    id BIGINT NOT NULL AUTO_INCREMENT,
    evento_hash CHAR(64) NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    payload LONGTEXT NOT NULL,
    procesado TINYINT(1) NOT NULL DEFAULT 0,
    error_detalle VARCHAR(700) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_whatsapp_evento_hash (evento_hash),
    KEY idx_whatsapp_evento_procesado (procesado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO permisos (modulo, codigo, nombre, descripcion, estado)
VALUES
('WhatsApp', 'whatsapp.ver', 'Ver conversaciones de WhatsApp', 'Consultar conversaciones de WhatsApp autorizadas.', 1),
('WhatsApp', 'whatsapp.enviar', 'Enviar mensajes por WhatsApp', 'Enviar mensajes mediante cuentas de WhatsApp Business autorizadas.', 1),
('WhatsApp', 'whatsapp.gestionar_conversaciones', 'Gestionar conversaciones de WhatsApp', 'Asignar responsables y administrar el estado de conversaciones.', 1),
('WhatsApp', 'whatsapp.gestionar_cuentas', 'Gestionar cuentas de WhatsApp', 'Configurar números empresariales y asignarlos a usuarios. Exclusivo de administración.', 1)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1;

-- Cuenta Clave puede atender aliados desde WhatsApp.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN ('whatsapp.ver', 'whatsapp.enviar')
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;

-- Ventas queda preparado para el mismo motor de conversaciones.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN ('whatsapp.ver', 'whatsapp.enviar')
WHERE r.nombre = 'Asesor de Ventas'
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo IN (
        'whatsapp.ver',
        'whatsapp.enviar',
        'whatsapp.gestionar_conversaciones'
    )
WHERE r.nombre = 'Coordinador Comercial'
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo IN (
    'whatsapp.ver',
    'whatsapp.enviar',
    'whatsapp.gestionar_conversaciones',
    'whatsapp.gestionar_cuentas'
)
  AND p.estado = 1;
