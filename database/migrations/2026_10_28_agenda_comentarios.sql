-- Integración aditiva de comentarios. No altera tablas de reuniones/usuarios.
CREATE TABLE IF NOT EXISTS agenda_comentarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fecha DATE NOT NULL,
    recordatorio_at DATETIME NULL,
    comentario VARCHAR(250) NOT NULL,
    creado_por INT NOT NULL,
    autor_nombre VARCHAR(255) NOT NULL,
    creado_at DATETIME NOT NULL,
    eliminado_at DATETIME NULL,
    eliminado_por INT NULL,
    PRIMARY KEY (id),
    KEY idx_agenda_comentario_fecha (fecha,eliminado_at),
    KEY idx_agenda_comentario_hora (recordatorio_at,eliminado_at),
    KEY idx_agenda_comentario_autor (creado_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS agenda_comentarios_lecturas (
    comentario_id INT UNSIGNED NOT NULL,
    usuario_id INT NOT NULL,
    novedad_leida_at DATETIME NULL,
    recordatorio_atendido_at DATETIME NULL,
    PRIMARY KEY (comentario_id,usuario_id),
    KEY idx_agenda_comentario_lectura_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
