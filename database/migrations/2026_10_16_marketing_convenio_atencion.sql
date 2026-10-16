-- Estado de atención de convenios para Marketing.
-- Pendiente = sin registro.
-- Con convocatoria = se pulsó "Crear convocatoria".

CREATE TABLE IF NOT EXISTS marketing_convenio_atencion (
    seguimiento_id INT NOT NULL,
    atendido_por INT NOT NULL,
    atendido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (seguimiento_id),
    KEY idx_marketing_convenio_atendido_at (atendido_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;
