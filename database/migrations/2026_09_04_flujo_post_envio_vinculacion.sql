-- Flujo posterior al envío del oficio: respuesta, seguimiento, reunión y convenio.
-- Es seguro ejecutarlo una sola vez en cada base de datos.

CREATE TABLE IF NOT EXISTS seguimientos_vinculacion_post_envio (
    seguimiento_id INT NOT NULL,

    respuesta_tipo VARCHAR(40) DEFAULT NULL,
    respuesta_canal VARCHAR(20) DEFAULT NULL,
    respuesta_texto TEXT DEFAULT NULL,
    respuesta_at DATETIME DEFAULT NULL,
    respuesta_por INT DEFAULT NULL,
    contactar_despues_at DATETIME DEFAULT NULL,

    seguimiento_correo_notas TEXT DEFAULT NULL,
    seguimiento_correo_at DATETIME DEFAULT NULL,
    seguimiento_correo_por INT DEFAULT NULL,

    reunion_fecha DATETIME DEFAULT NULL,
    reunion_modalidad VARCHAR(20) DEFAULT NULL,
    reunion_lugar_enlace VARCHAR(500) DEFAULT NULL,
    reunion_notas TEXT DEFAULT NULL,
    reunion_agendada_at DATETIME DEFAULT NULL,
    reunion_agendada_por INT DEFAULT NULL,

    reunion_resultado VARCHAR(40) DEFAULT NULL,
    reunion_resultado_notas TEXT DEFAULT NULL,
    reunion_realizada_at DATETIME DEFAULT NULL,
    reunion_realizada_por INT DEFAULT NULL,

    convenio_fecha DATE DEFAULT NULL,
    convenio_referencia VARCHAR(180) DEFAULT NULL,
    convenio_notas TEXT DEFAULT NULL,
    convenio_formalizado_at DATETIME DEFAULT NULL,
    convenio_formalizado_por INT DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (seguimiento_id),
    KEY idx_post_envio_respuesta_at (respuesta_at),
    KEY idx_post_envio_reunion_fecha (reunion_fecha),
    KEY idx_post_envio_convenio_at (convenio_formalizado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
