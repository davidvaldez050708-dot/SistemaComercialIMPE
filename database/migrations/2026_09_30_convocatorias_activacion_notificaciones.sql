-- Activación automática y centro de notificaciones para Convocatorias.
-- Cambio aditivo: conserva el flujo actual y agrega programación por fecha.

ALTER TABLE convocatorias
    ADD COLUMN IF NOT EXISTS activacion_automatica TINYINT(1) NOT NULL DEFAULT 0
    AFTER estado;

-- Toda convocatoria futura queda programada e inactiva hasta su fecha de inicio.
UPDATE convocatorias
SET estado = 0,
    activacion_automatica = 1
WHERE fecha_inicio > CURDATE()
  AND fecha_termino >= fecha_inicio;

CREATE TABLE IF NOT EXISTS notificaciones_convocatorias (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    convocatoria_id INT NULL,
    titulo VARCHAR(255) NOT NULL,
    mensaje VARCHAR(700) NOT NULL,
    url VARCHAR(1000) DEFAULT NULL,
    tipo_evento VARCHAR(60) NOT NULL DEFAULT 'activacion_automatica',
    leida TINYINT(1) NOT NULL DEFAULT 0,
    leida_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_conv_usuario_leida (usuario_id, leida, created_at),
    KEY idx_notif_conv_convocatoria (convocatoria_id),
    CONSTRAINT fk_notif_conv_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notif_conv_convocatoria
        FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
