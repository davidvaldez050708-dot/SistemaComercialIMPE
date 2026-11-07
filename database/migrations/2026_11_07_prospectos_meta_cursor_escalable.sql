-- Fase 1B.1: recorrido reanudable de TODOS los lotes Meta confirmados.
-- Aditiva, repetible, sin DML sobre prospectos ni asignaciones.
-- No activa motores, no configura cron, no elimina pendientes.
CREATE TABLE IF NOT EXISTS prospecto_distribucion_meta_cursor (
    id TINYINT UNSIGNED NOT NULL,
    ultimo_origen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    fecha_jornada DATE NULL,
    ciclo_terminado TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO prospecto_distribucion_meta_cursor
(id,ultimo_origen_id,fecha_jornada,ciclo_terminado,updated_at)
VALUES (1,0,NULL,0,UTC_TIMESTAMP());
