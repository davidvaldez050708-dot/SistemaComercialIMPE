-- Formulario de Registro · Marketing
-- Campos basados en el formulario de referencia y validaciones del sistema.

CREATE TABLE IF NOT EXISTS formulario_registros (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(120) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    movil VARCHAR(10) NOT NULL,
    movil_secundario VARCHAR(10) NULL,
    correo VARCHAR(190) NOT NULL,
    perfil_interes VARCHAR(100) NOT NULL,
    lugar_laboras VARCHAR(180) NOT NULL,
    cargo_puesto VARCHAR(160) NULL,
    estado_id INT NOT NULL,
    municipio_id INT NOT NULL,
    creado_por INT NULL,
    origen VARCHAR(30) NOT NULL DEFAULT 'MARKETING',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_formulario_registros_correo (correo),
    KEY idx_formulario_registros_movil (movil),
    KEY idx_formulario_registros_estado (estado_id),
    KEY idx_formulario_registros_municipio (municipio_id),
    KEY idx_formulario_registros_creado_por (creado_por),
    KEY idx_formulario_registros_created_at (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
