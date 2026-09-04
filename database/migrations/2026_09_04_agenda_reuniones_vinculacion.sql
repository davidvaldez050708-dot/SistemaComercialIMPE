-- Agenda compartida Analista -> Cuenta Clave (KAM) para coordinar reuniones.
-- Ejecutar una sola vez por base de datos.

CREATE TABLE IF NOT EXISTS reuniones_vinculacion (
    id INT NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    analista_id INT NOT NULL,
    cuenta_clave_id INT DEFAULT NULL,

    fecha_propuesta DATETIME NOT NULL,
    duracion_minutos SMALLINT NOT NULL DEFAULT 60,
    modalidad VARCHAR(20) NOT NULL DEFAULT 'VIRTUAL',
    objetivo VARCHAR(500) NOT NULL,
    notas_analista TEXT DEFAULT NULL,

    estado VARCHAR(30) NOT NULL DEFAULT 'SOLICITADA',

    cambio_motivo TEXT DEFAULT NULL,
    cambio_solicitado_at DATETIME DEFAULT NULL,
    cambio_solicitado_por INT DEFAULT NULL,

    zoom_url VARCHAR(600) DEFAULT NULL,
    ubicacion VARCHAR(500) DEFAULT NULL,
    notas_kam TEXT DEFAULT NULL,
    confirmada_at DATETIME DEFAULT NULL,
    confirmada_por INT DEFAULT NULL,

    correo_confirmacion_asunto VARCHAR(255) DEFAULT NULL,
    correo_confirmacion_cuerpo TEXT DEFAULT NULL,
    correo_confirmacion_at DATETIME DEFAULT NULL,
    correo_confirmacion_por INT DEFAULT NULL,

    notificado_kam_at DATETIME DEFAULT NULL,
    notificado_analista_at DATETIME DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_reunion_seguimiento (seguimiento_id),
    KEY idx_reunion_analista (analista_id),
    KEY idx_reunion_cuenta_clave (cuenta_clave_id),
    KEY idx_reunion_fecha (fecha_propuesta),
    KEY idx_reunion_estado (estado),
    KEY idx_reunion_estado_fecha (estado, fecha_propuesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
