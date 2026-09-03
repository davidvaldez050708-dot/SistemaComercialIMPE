CREATE TABLE IF NOT EXISTS recordatorios_vinculacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    usuario_id INT NOT NULL,
    accion VARCHAR(120) NOT NULL,
    proxima_accion_at DATETIME NOT NULL,
    aviso_3h_at DATETIME DEFAULT NULL,
    aviso_1h_at DATETIME DEFAULT NULL,
    aviso_10m_at DATETIME DEFAULT NULL,
    aviso_vencida_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_recordatorio_ciclo (seguimiento_id, usuario_id, accion, proxima_accion_at),
    KEY idx_recordatorio_usuario (usuario_id),
    KEY idx_recordatorio_fecha (proxima_accion_at),
    CONSTRAINT fk_recordatorio_seguimiento
        FOREIGN KEY (seguimiento_id)
        REFERENCES seguimientos_vinculacion (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_recordatorio_usuario
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
