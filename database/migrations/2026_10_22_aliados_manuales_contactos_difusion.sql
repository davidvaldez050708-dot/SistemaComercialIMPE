-- Ampliación aditiva de relaciones manuales; no crea expedientes ni toca registros de Vinculación.
CREATE TABLE IF NOT EXISTS aliados_manuales_contactos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 aliado_id BIGINT UNSIGNED NOT NULL,
 nombre VARCHAR(120) NOT NULL,
 cargo VARCHAR(120) NOT NULL DEFAULT '',
 correo VARCHAR(180) NOT NULL DEFAULT '',
 telefono VARCHAR(30) NOT NULL DEFAULT '',
 whatsapp_autorizado TINYINT(1) NOT NULL DEFAULT 0,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_aliado_contactos (aliado_id,activo),
 FOREIGN KEY (aliado_id) REFERENCES aliados_manuales(id),
 FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de actuaciones declaradas por Cuenta Clave; no representa un envío automático.
CREATE TABLE IF NOT EXISTS aliados_manuales_difusiones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 aliado_id BIGINT UNSIGNED NOT NULL,
 canal ENUM('CORREO','WHATSAPP','OTRO') NOT NULL,
 nota VARCHAR(500) NOT NULL DEFAULT '',
 registrado_por INT NOT NULL,
 realizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_historial (aliado_id,realizado_at),
 FOREIGN KEY (aliado_id) REFERENCES aliados_manuales(id),
 FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
