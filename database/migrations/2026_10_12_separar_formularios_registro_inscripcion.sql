-- Separación de Formularios · Registro e Inscripción
-- Crea formulario_inscripciones y mueve las inscripciones históricas
-- que anteriormente se almacenaban dentro de formulario_registros.

CREATE TABLE IF NOT EXISTS formulario_inscripciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(120) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    movil VARCHAR(10) NOT NULL,
    movil_secundario VARCHAR(10) NULL,
    correo VARCHAR(190) NOT NULL,
    perfil_interes VARCHAR(100) NOT NULL,
    lugar_laboras VARCHAR(180) NOT NULL DEFAULT '',
    cargo_puesto VARCHAR(160) NULL,
    estado_id INT NOT NULL,
    municipio_id INT NOT NULL,
    creado_por INT NULL,
    origen VARCHAR(30) NOT NULL DEFAULT 'MARKETING',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_formulario_inscripciones_correo (correo),
    KEY idx_formulario_inscripciones_movil (movil),
    KEY idx_formulario_inscripciones_estado (estado_id),
    KEY idx_formulario_inscripciones_municipio (municipio_id),
    KEY idx_formulario_inscripciones_creado_por (creado_por),
    KEY idx_formulario_inscripciones_created_at (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO formulario_inscripciones (
    id,
    nombre,
    apellido,
    fecha_nacimiento,
    movil,
    movil_secundario,
    correo,
    perfil_interes,
    lugar_laboras,
    cargo_puesto,
    estado_id,
    municipio_id,
    creado_por,
    origen,
    created_at,
    updated_at
)
SELECT
    id,
    nombre,
    apellido,
    fecha_nacimiento,
    movil,
    movil_secundario,
    correo,
    perfil_interes,
    lugar_laboras,
    cargo_puesto,
    estado_id,
    municipio_id,
    creado_por,
    CASE
        WHEN origen = 'PUBLICO_INSCRIPCION' THEN 'PUBLICO'
        ELSE 'MARKETING'
    END,
    created_at,
    updated_at
FROM formulario_registros
WHERE origen IN (
    'MARKETING_INSCRIPCION',
    'PUBLICO_INSCRIPCION'
)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    apellido = VALUES(apellido),
    fecha_nacimiento = VALUES(fecha_nacimiento),
    movil = VALUES(movil),
    movil_secundario = VALUES(movil_secundario),
    correo = VALUES(correo),
    perfil_interes = VALUES(perfil_interes),
    lugar_laboras = VALUES(lugar_laboras),
    cargo_puesto = VALUES(cargo_puesto),
    estado_id = VALUES(estado_id),
    municipio_id = VALUES(municipio_id),
    creado_por = VALUES(creado_por),
    origen = VALUES(origen),
    created_at = VALUES(created_at),
    updated_at = VALUES(updated_at);

DELETE fr
FROM formulario_registros AS fr
INNER JOIN formulario_inscripciones AS fi
    ON fi.id = fr.id
WHERE fr.origen IN (
    'MARKETING_INSCRIPCION',
    'PUBLICO_INSCRIPCION'
);

COMMIT;
