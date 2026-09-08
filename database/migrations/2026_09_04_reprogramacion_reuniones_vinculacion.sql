-- Reprogramación formal de reuniones de Vinculación.
-- Conserva el historial y permite volver temporalmente al paso 11.

ALTER TABLE reuniones_vinculacion
    ADD COLUMN IF NOT EXISTS es_reprogramacion TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
    ADD COLUMN IF NOT EXISTS reprogramacion_motivo TEXT DEFAULT NULL AFTER es_reprogramacion,
    ADD COLUMN IF NOT EXISTS reprogramacion_solicitada_at DATETIME DEFAULT NULL AFTER reprogramacion_motivo,
    ADD COLUMN IF NOT EXISTS reprogramacion_solicitada_por INT DEFAULT NULL AFTER reprogramacion_solicitada_at;

CREATE TABLE IF NOT EXISTS reuniones_vinculacion_reprogramaciones (
    id INT NOT NULL AUTO_INCREMENT,
    reunion_id INT NOT NULL,
    seguimiento_id INT NOT NULL,

    fecha_anterior DATETIME NOT NULL,
    fecha_nueva DATETIME DEFAULT NULL,
    duracion_anterior SMALLINT NOT NULL DEFAULT 60,
    duracion_nueva SMALLINT DEFAULT NULL,
    modalidad_anterior VARCHAR(20) NOT NULL,
    modalidad_nueva VARCHAR(20) DEFAULT NULL,

    zoom_anterior VARCHAR(600) DEFAULT NULL,
    zoom_nuevo VARCHAR(600) DEFAULT NULL,
    ubicacion_anterior VARCHAR(500) DEFAULT NULL,
    ubicacion_nueva VARCHAR(500) DEFAULT NULL,

    motivo TEXT NOT NULL,
    solicitado_por INT NOT NULL,
    solicitado_por_rol VARCHAR(30) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE_KAM',

    confirmada_at DATETIME DEFAULT NULL,
    confirmada_por INT DEFAULT NULL,
    correo_enviado_at DATETIME DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_reprogramacion_reunion (reunion_id),
    KEY idx_reprogramacion_seguimiento (seguimiento_id),
    KEY idx_reprogramacion_estado (estado),
    KEY idx_reprogramacion_fecha_nueva (fecha_nueva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
