-- Conserva destinatario_id y los comentarios existentes sin modificarlos.
CREATE TABLE IF NOT EXISTS agenda_comentarios_destinatarios (
    comentario_id INT NOT NULL,
    usuario_id INT NOT NULL,
    PRIMARY KEY (comentario_id, usuario_id),
    KEY idx_agenda_destinatarios_usuario (usuario_id, comentario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
