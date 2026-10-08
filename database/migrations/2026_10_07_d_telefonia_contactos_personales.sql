-- Agenda privada de teléfonos para el perfil de Asesor de Ventas.
-- No crea oportunidades, instituciones ni seguimientos comerciales.
CREATE TABLE IF NOT EXISTS telefonia_contactos_personales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    nombre VARCHAR(90) NOT NULL,
    telefono VARCHAR(18) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_telefono_contacto_usuario (usuario_id, telefono),
    KEY idx_telefono_contactos_usuario (usuario_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
