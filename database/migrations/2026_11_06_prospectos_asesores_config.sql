-- Entrega 1A. Ejecutar manualmente, únicamente tras respaldo y validación.
-- Sin reparto, sin escritura sobre prospectos, telefonía, formularios o usuarios.
-- La cuota Meta es fija (80); Aliados sin límite en esta fase.
CREATE TABLE IF NOT EXISTS prospecto_asesores_config (
    usuario_id INT NOT NULL,
    programa_codigo VARCHAR(64) NOT NULL,
    jornada VARCHAR(20) NOT NULL,
    activo TINYINT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    actualizado_por INT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (usuario_id),
    KEY idx_pac_programa (programa_codigo,activo),
    CONSTRAINT fk_pac_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_pac_programa FOREIGN KEY (programa_codigo) REFERENCES formulario_programas(codigo),
    CONSTRAINT fk_pac_actor FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_asesores_horarios (
    usuario_id INT NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    hora_desde TIME NOT NULL,
    hora_hasta TIME NOT NULL,
    PRIMARY KEY (usuario_id,dia_semana),
    CONSTRAINT fk_pah_usuario FOREIGN KEY (usuario_id) REFERENCES prospecto_asesores_config(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_asesores_auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    actor_id INT NOT NULL,
    antes_json LONGTEXT NOT NULL,
    despues_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_paa_asesor_fecha (usuario_id,created_at),
    CONSTRAINT fk_paa_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_paa_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permiso independiente; su concesión posterior es revocable desde Roles.
-- No reasignar permisos revocados ni alterar catálogo de otros módulos.
INSERT IGNORE INTO permisos (modulo,codigo,nombre,descripcion,estado)
VALUES ('Prospectos','prospectos.configurar_asesores',
    'Configurar asesores comerciales',
    'Administrar un programa, jornada y horarios de asesores de Ventas.',1);

-- Solo Administrador obtiene permiso inicial. Coordinador: autorización explícita en Roles.
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p
 ON p.codigo='prospectos.configurar_asesores'
WHERE r.nombre='Administrador' AND p.estado=1;
