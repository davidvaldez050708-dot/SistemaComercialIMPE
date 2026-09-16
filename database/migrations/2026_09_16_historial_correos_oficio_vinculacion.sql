CREATE TABLE IF NOT EXISTS correos_oficio_vinculacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    oficio_id INT NULL,
    usuario_id INT NULL,
    folio VARCHAR(100) NULL,
    destinatario VARCHAR(255) NOT NULL,
    destinatario_nombre VARCHAR(255) NULL,
    asunto VARCHAR(255) NOT NULL,
    cuerpo MEDIUMTEXT NOT NULL,
    adjunto_nombre VARCHAR(255) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ENVIADO',
    error_envio TEXT NULL,
    fecha_envio DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correo_oficio_seguimiento (seguimiento_id, fecha_envio),
    KEY idx_correo_oficio_oficio (oficio_id),
    KEY idx_correo_oficio_usuario (usuario_id),
    UNIQUE KEY uq_correo_oficio_envio (oficio_id, fecha_envio, destinatario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*
 * Si la primera ejecución alcanzó a crear la tabla con otra collation antes
 * de fallar en el backfill, la normalizamos al estándar usado por las tablas
 * existentes de vinculación. Esto hace la migración segura al reejecutarla.
 */
ALTER TABLE correos_oficio_vinculacion
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

/*
 * Recupera los envíos que ya estaban registrados antes de crear la bitácora.
 * Es idempotente: no duplica un envío ya migrado.
 */
INSERT INTO correos_oficio_vinculacion (
    seguimiento_id,
    oficio_id,
    usuario_id,
    folio,
    destinatario,
    destinatario_nombre,
    asunto,
    cuerpo,
    adjunto_nombre,
    estado,
    error_envio,
    fecha_envio,
    created_at
)
SELECT
    oficio.seguimiento_id,
    oficio.id,
    oficio.enviado_por,
    oficio.folio,
    COALESCE(oficio.destinatario_correo, ''),
    oficio.destinatario_nombre,
    COALESCE(oficio.asunto_correo, ''),
    COALESCE(oficio.cuerpo_correo, ''),
    SUBSTRING_INDEX(
        REPLACE(COALESCE(oficio.archivo_pdf, ''), '\\', '/'),
        '/',
        -1
    ),
    'ENVIADO',
    NULL,
    oficio.fecha_envio,
    oficio.fecha_envio
FROM oficios_vinculacion oficio
WHERE oficio.fecha_envio IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM correos_oficio_vinculacion historial
      WHERE historial.oficio_id = oficio.id
        AND historial.fecha_envio = oficio.fecha_envio
        AND historial.destinatario COLLATE utf8mb4_general_ci =
            COALESCE(oficio.destinatario_correo, '') COLLATE utf8mb4_general_ci
  );
