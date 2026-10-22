-- Relación por convocatoria y programa para aliados manuales o de Vinculación.
-- convocation.id = INT firmado en Hostinger: respetar su tipo sin ALTER.
CREATE TABLE IF NOT EXISTS marketing_aliado_convocatorias_origen (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    convocatoria_id INT NOT NULL,
    origen_tipo VARCHAR(20) NOT NULL,
    origen_id INT NOT NULL,
    programa_codigo VARCHAR(64) NOT NULL,
    creado_por INT NOT NULL,
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_marketing_aliado_convocatoria (convocatoria_id),
    KEY idx_marketing_aliado_programa (origen_tipo, origen_id, programa_codigo),
    CONSTRAINT fk_marketing_aliado_convocatoria
      FOREIGN KEY (convocatoria_id) REFERENCES convocatorias (id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
