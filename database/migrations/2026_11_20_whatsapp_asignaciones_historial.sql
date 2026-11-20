CREATE TABLE IF NOT EXISTS whatsapp_asignaciones_historial (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversacion_id INT NOT NULL,
    responsable_anterior_id INT NULL,
    responsable_nuevo_id INT NOT NULL,
    actor_usuario_id INT NULL,
    origen VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wa_asignacion_conversacion (conversacion_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
