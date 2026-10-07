-- Paso 1 de Telefonía: identidad PBX multiusuario.
-- Cada usuario que utilice llamadas tendrá una extensión Zadarma propia.
-- La extensión se mantiene desacoplada del perfil general del usuario.

CREATE TABLE IF NOT EXISTS telefonia_extensiones (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    proveedor VARCHAR(20) NOT NULL DEFAULT 'ZADARMA',
    extension VARCHAR(12) NOT NULL,
    caller_id VARCHAR(40) NULL,
    permite_salientes TINYINT(1) NOT NULL DEFAULT 1,
    permite_entrantes TINYINT(1) NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    actualizado_por INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_telefonia_usuario_proveedor (
        usuario_id,
        proveedor
    ),
    UNIQUE KEY uk_telefonia_extension_proveedor (
        proveedor,
        extension
    ),
    KEY idx_telefonia_extension_activa (
        proveedor,
        activo,
        extension
    ),
    CONSTRAINT fk_telefonia_extension_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_telefonia_extension_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_telefonia_extension_actualizado_por
        FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
