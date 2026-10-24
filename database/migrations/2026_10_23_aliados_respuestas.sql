-- Respuestas humanas por convocatoria. Cada actualización conserva un evento.
-- No cambia los envíos ni crea expedientes de Vinculación.
CREATE TABLE IF NOT EXISTS aliados_manuales_respuestas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    manual_id BIGINT UNSIGNED NOT NULL,
    convocatoria_id INT NOT NULL,
    estado VARCHAR(32) NOT NULL,
    nota VARCHAR(1000) NOT NULL,
    usuario_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_respuesta_manual_convocatoria (manual_id,convocatoria_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estructuras institucionales previas: sólo se crean cuando faltan.
CREATE TABLE IF NOT EXISTS aliados_convocatorias_seguimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    envio_id INT NOT NULL,
    seguimiento_id INT NOT NULL,
    convocatoria_id INT NULL,
    responsable_usuario_id INT NOT NULL,
    estado VARCHAR(32) NOT NULL DEFAULT 'ESPERANDO_RESPUESTA',
    nota VARCHAR(1000) NULL,
    proximo_seguimiento_at DATETIME NULL,
    cerrado_at DATETIME NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_aliado_conv_seguimiento_envio (envio_id),
    KEY idx_aliado_conv_seguimiento_responsable (
        responsable_usuario_id,
        activo,
        proximo_seguimiento_at
    ),
    KEY idx_aliado_conv_seguimiento_aliado (
        seguimiento_id,
        convocatoria_id,
        activo
    ),
    CONSTRAINT fk_aliado_conv_seg_envio
        FOREIGN KEY (envio_id) REFERENCES aliados_convocatorias_envios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_conv_seg_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_conv_seg_convocatoria
        FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_aliado_conv_seg_responsable
        FOREIGN KEY (responsable_usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS aliados_convocatorias_seguimiento_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_convocatoria_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT NOT NULL,
    estado_anterior VARCHAR(32) NULL,
    estado_nuevo VARCHAR(32) NOT NULL,
    nota VARCHAR(1000) NULL,
    proximo_seguimiento_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aliado_conv_eventos_seguimiento (
        seguimiento_convocatoria_id,
        created_at
    ),
    CONSTRAINT fk_aliado_conv_evento_seguimiento
        FOREIGN KEY (seguimiento_convocatoria_id)
        REFERENCES aliados_convocatorias_seguimientos(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_aliado_conv_evento_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
