-- Enlaces públicos para formularios
-- Permite generar un enlace persistente desde Marketing y reutilizarlo
-- para compartir el formulario o crear un código QR.

CREATE TABLE IF NOT EXISTS formulario_enlaces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_formulario VARCHAR(30) NOT NULL,
    token CHAR(64) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ultimo_uso_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_formulario_enlaces_token (token),
    KEY idx_formulario_enlaces_tipo_activo (
        tipo_formulario,
        activo,
        created_at
    ),
    KEY idx_formulario_enlaces_creado_por (creado_por)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
