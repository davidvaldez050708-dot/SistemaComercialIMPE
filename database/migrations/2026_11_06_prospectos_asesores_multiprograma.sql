-- Entrega 1A.2: ampliar la configuración de asesores a varios programas.
-- Aditiva: no cambia prospectos, asignaciones, usuarios, llamadas ni permisos.
-- Mantiene prospecto_asesores_config.programa_codigo como compatibilidad histórica.
-- Para el próximo motor de reparto, consultar esta tabla relacional completa.
CREATE TABLE IF NOT EXISTS prospecto_asesores_programas (
    usuario_id INT NOT NULL,
    programa_codigo VARCHAR(64) NOT NULL,
    PRIMARY KEY (usuario_id, programa_codigo),
    KEY idx_pap_programa_asesor (programa_codigo, usuario_id),
    CONSTRAINT fk_pap_config FOREIGN KEY (usuario_id)
        REFERENCES prospecto_asesores_config(usuario_id),
    CONSTRAINT fk_pap_catalogo FOREIGN KEY (programa_codigo)
        REFERENCES formulario_programas(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La migración se puede repetir: solo importa el programa previo cuando
-- el asesor todavía no tiene ningún programa registrado en la nueva tabla.
-- Ningún asesor pierde su programa previamente configurado.
INSERT IGNORE INTO prospecto_asesores_programas (usuario_id, programa_codigo)
SELECT c.usuario_id, c.programa_codigo
FROM prospecto_asesores_config c
WHERE NOT EXISTS (
    SELECT 1 FROM prospecto_asesores_programas p
    WHERE p.usuario_id = c.usuario_id
);
