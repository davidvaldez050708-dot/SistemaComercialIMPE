-- Estado visual de convenios en Marketing > Crear convocatoria.
-- Un convenio se considera "con convocatoria" cuando existe al menos
-- una relación registrada con una convocatoria creada desde ese convenio.

CREATE TABLE IF NOT EXISTS marketing_convenio_convocatorias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id INT NOT NULL,
    convocatoria_id INT NOT NULL,
    creado_por INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_marketing_convenio_convocatoria (
        seguimiento_id,
        convocatoria_id
    ),
    KEY idx_marketing_convenio_estado (
        seguimiento_id,
        created_at
    ),
    CONSTRAINT fk_marketing_convenio_seguimiento
        FOREIGN KEY (seguimiento_id)
        REFERENCES seguimientos_vinculacion(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_marketing_convenio_convocatoria
        FOREIGN KEY (convocatoria_id)
        REFERENCES convocatorias(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_marketing_convenio_usuario
        FOREIGN KEY (creado_por)
        REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;
