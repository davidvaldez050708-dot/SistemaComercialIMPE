-- Recepción de respuestas de correo desde Hostinger Mail API.
-- El webhook guarda el evento y genera destinatarios de notificación por usuario.
-- Es seguro ejecutarlo una sola vez por base de datos.

CREATE TABLE IF NOT EXISTS correo_respuestas_entrantes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    evento_hash CHAR(64) NOT NULL,
    proveedor VARCHAR(40) NOT NULL DEFAULT 'HOSTINGER_MAIL_API',
    evento VARCHAR(60) NOT NULL DEFAULT 'message.received',
    mensaje_externo_id VARCHAR(191) DEFAULT NULL,
    thread_id VARCHAR(191) DEFAULT NULL,
    mailbox VARCHAR(255) DEFAULT NULL,
    remitente VARCHAR(255) NOT NULL,
    asunto VARCHAR(500) DEFAULT NULL,
    vista_previa TEXT DEFAULT NULL,
    recibido_at DATETIME NOT NULL,

    seguimiento_id INT DEFAULT NULL,
    reunion_id INT DEFAULT NULL,
    contexto VARCHAR(20) NOT NULL DEFAULT 'OFERTA',
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',

    raw_payload LONGTEXT DEFAULT NULL,
    procesado_at DATETIME DEFAULT NULL,
    procesado_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_correo_respuesta_evento_hash (evento_hash),
    KEY idx_correo_respuesta_seguimiento (seguimiento_id),
    KEY idx_correo_respuesta_reunion (reunion_id),
    KEY idx_correo_respuesta_remitente (remitente),
    KEY idx_correo_respuesta_estado (estado),
    KEY idx_correo_respuesta_recibido (recibido_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS correo_respuestas_destinatarios (
    respuesta_id BIGINT NOT NULL,
    usuario_id INT NOT NULL,
    rol_id INT NOT NULL,
    notificado_at DATETIME DEFAULT NULL,
    leido_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (respuesta_id, usuario_id),
    KEY idx_correo_destinatario_usuario (usuario_id, leido_at),
    KEY idx_correo_destinatario_notificado (usuario_id, notificado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
