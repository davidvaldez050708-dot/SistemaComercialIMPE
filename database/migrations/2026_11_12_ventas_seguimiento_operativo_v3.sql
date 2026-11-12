CREATE TABLE IF NOT EXISTS prospecto_embudo_actividad_v3 (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asignacion_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT NOT NULL,
    etapa VARCHAR(40) NOT NULL,
    accion VARCHAR(20) NOT NULL,
    motivo VARCHAR(190) NOT NULL,
    fecha_anterior DATETIME NULL,
    fecha_pendiente DATETIME NULL,
    completada_at DATETIME NULL,
    subetapa_anterior VARCHAR(40) NULL,
    subetapa_nueva VARCHAR(40) NULL,
    creado_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_actividad_asignacion (asignacion_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
