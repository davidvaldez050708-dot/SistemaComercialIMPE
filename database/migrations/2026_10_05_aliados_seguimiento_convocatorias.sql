-- Seguimiento de convocatorias para Cuenta Clave.
-- Cada difusión exitosa abre un seguimiento ligero orientado a WhatsApp:
-- respuesta del aliado, nota breve y próxima fecha de contacto.

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

INSERT INTO permisos (modulo, codigo, nombre, descripcion, estado)
VALUES (
    'Aliados',
    'aliados.seguimiento_convocatorias',
    'Dar seguimiento a convocatorias de aliados',
    'Registrar respuestas, notas y próximas acciones por WhatsApp después de compartir una convocatoria.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1,
    updated_at = NOW();

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'aliados.seguimiento_convocatorias'
WHERE r.nombre = 'Cuenta Clave'
  AND r.estado = 1
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'aliados.seguimiento_convocatorias'
  AND p.estado = 1;

-- Inicializa únicamente la difusión exitosa más reciente de cada
-- aliado + convocatoria. Los reenvíos anteriores quedan como historial,
-- no como pendientes simultáneos.
INSERT IGNORE INTO aliados_convocatorias_seguimientos (
    envio_id,
    seguimiento_id,
    convocatoria_id,
    responsable_usuario_id,
    estado,
    activo,
    created_at,
    updated_at
)
SELECT
    envio.id,
    envio.seguimiento_id,
    envio.convocatoria_id,
    COALESCE(asignacion.cuenta_clave_usuario_id, envio.cuenta_clave_usuario_id),
    'ESPERANDO_RESPUESTA',
    1,
    envio.enviado_at,
    NOW()
FROM aliados_convocatorias_envios envio
LEFT JOIN aliados_asignaciones asignacion
    ON asignacion.seguimiento_id = envio.seguimiento_id
   AND asignacion.activo = 1
WHERE envio.estado_envio IN ('ENVIADO', 'COMPARTIDO')
  AND envio.id = (
      SELECT envio_reciente.id
      FROM aliados_convocatorias_envios envio_reciente
      WHERE envio_reciente.seguimiento_id = envio.seguimiento_id
        AND (
            envio_reciente.convocatoria_id = envio.convocatoria_id
            OR (
                envio_reciente.convocatoria_id IS NULL
                AND envio.convocatoria_id IS NULL
            )
        )
        AND envio_reciente.estado_envio IN ('ENVIADO', 'COMPARTIDO')
      ORDER BY envio_reciente.enviado_at DESC, envio_reciente.id DESC
      LIMIT 1
  );

INSERT INTO aliados_convocatorias_seguimiento_eventos (
    seguimiento_convocatoria_id,
    usuario_id,
    estado_anterior,
    estado_nuevo,
    nota,
    proximo_seguimiento_at,
    created_at
)
SELECT
    seguimiento.id,
    envio.cuenta_clave_usuario_id,
    NULL,
    'ESPERANDO_RESPUESTA',
    'Seguimiento iniciado a partir de una convocatoria compartida.',
    NULL,
    seguimiento.created_at
FROM aliados_convocatorias_seguimientos seguimiento
INNER JOIN aliados_convocatorias_envios envio
    ON envio.id = seguimiento.envio_id
WHERE NOT EXISTS (
    SELECT 1
    FROM aliados_convocatorias_seguimiento_eventos evento
    WHERE evento.seguimiento_convocatoria_id = seguimiento.id
);
