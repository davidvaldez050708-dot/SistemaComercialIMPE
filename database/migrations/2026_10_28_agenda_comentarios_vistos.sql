-- Sólo registra qué avisos de lectura abrió el autor. No modifica las lecturas.
CREATE TABLE IF NOT EXISTS agenda_comentarios_vistos_avisos (
    comentario_id INT UNSIGNED NOT NULL,
    lector_id INT NOT NULL,
    leido_at DATETIME NOT NULL,
    PRIMARY KEY (comentario_id,lector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
