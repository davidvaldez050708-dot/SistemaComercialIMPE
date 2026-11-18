-- Ampliación aditiva. No modifica prospectos, etapas, mensajes ni asignaciones.
CREATE TABLE IF NOT EXISTS whatsapp_conversacion_prospectos (
    conversacion_id INT NOT NULL,
    prospecto_id BIGINT UNSIGNED NOT NULL,
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(conversacion_id),
    KEY idx_whatsapp_prospecto(prospecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sin asignaciones automáticas: administración concede cada permiso expresamente.
INSERT IGNORE INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES
('WhatsApp','whatsapp.ver_todas','Consultar todas las conversaciones','Consulta global. No habilita envío ni configuración.',1),
('WhatsApp','whatsapp.responder_todas','Responder conversaciones del equipo','Requiere además whatsapp.ver y whatsapp.enviar.',1);
