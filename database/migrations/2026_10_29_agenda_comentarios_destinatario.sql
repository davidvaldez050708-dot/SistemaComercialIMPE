ALTER TABLE agenda_comentarios
    ADD COLUMN destinatario_id INT UNSIGNED NULL DEFAULT NULL,
    ADD INDEX idx_agenda_comentarios_destinatario (destinatario_id, eliminado_at);
