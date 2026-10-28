<?php

require_once __DIR__ . '/../../config/db_connection.php';

/** Almacenamiento independiente; no modifica reuniones ni seguimientos. */
class AgendaComentarioRepository
{
    private $db;
    private $disponible;

    public function __construct($db = null)
    {
        $this->db = $db ?: (new Database())->connect();
    }

    public function disponible(): bool
    {
        if ($this->disponible !== null) { return $this->disponible; }
        try {
            $this->db->query('SELECT id,fecha,recordatorio_at,comentario,creado_por,autor_nombre,creado_at,eliminado_at,eliminado_por FROM agenda_comentarios LIMIT 0');
            $this->db->query('SELECT comentario_id,usuario_id,novedad_leida_at,recordatorio_atendido_at FROM agenda_comentarios_lecturas LIMIT 0');
            return $this->disponible = true;
        } catch (Throwable $e) { return $this->disponible = false; }
    }

    public function listar(string $desde, string $hasta, int $usuario): array
    {
        $stmt = $this->db->prepare('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at FROM agenda_comentarios c
            LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            WHERE c.eliminado_at IS NULL AND c.fecha >= ? AND c.fecha < ? ORDER BY c.fecha,c.creado_at,c.id LIMIT 501');
        $stmt->bind_param('iss', $usuario, $desde, $hasta);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function obtener(int $id)
    {
        $stmt = $this->db->prepare('SELECT * FROM agenda_comentarios WHERE id=? AND eliminado_at IS NULL');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function crear(int $usuario, string $fecha, ?string $hora, string $texto, string $ahora): int
    {
        $stmt = $this->db->prepare("SELECT TRIM(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellidos,''))) AS autor FROM usuarios WHERE id=?");
        $stmt->bind_param('i', $usuario);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        if (!$fila) { throw new RuntimeException('AGENDA_AUTOR_NO_DISPONIBLE'); }
        $autor = mb_substr(trim((string)$fila['autor']) ?: 'Usuario', 0, 255, 'UTF-8');
        $recordatorio = $hora === null ? null : $fecha . ' ' . $hora . ':00';
        $stmt = $this->db->prepare('INSERT INTO agenda_comentarios (fecha,recordatorio_at,comentario,creado_por,autor_nombre,creado_at) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('sssiss', $fecha, $recordatorio, $texto, $usuario, $autor, $ahora);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function eliminar(int $id, int $usuario, string $ahora): bool
    {
        $stmt = $this->db->prepare('UPDATE agenda_comentarios SET eliminado_at=?,eliminado_por=? WHERE id=? AND creado_por=? AND eliminado_at IS NULL');
        $stmt->bind_param('siii', $ahora, $usuario, $id, $usuario);
        $stmt->execute();
        return $stmt->affected_rows === 1;
    }

    public function marcarLeido(int $id, int $usuario, string $ahora): void
    {
        // Leer antes de la hora no cancela el recordatorio futuro. Atenderlo
        // después de su hora afecta sólo al usuario que abrió el comentario.
        $stmt = $this->db->prepare('INSERT INTO agenda_comentarios_lecturas
            (comentario_id,usuario_id,novedad_leida_at,recordatorio_atendido_at)
            SELECT id,?,?,IF(recordatorio_at IS NOT NULL AND recordatorio_at<=?,?,NULL)
            FROM agenda_comentarios WHERE id=? AND eliminado_at IS NULL
            ON DUPLICATE KEY UPDATE novedad_leida_at=VALUES(novedad_leida_at),
              recordatorio_atendido_at=COALESCE(VALUES(recordatorio_atendido_at),recordatorio_atendido_at)');
        $stmt->bind_param('isssi', $usuario, $ahora, $ahora, $ahora, $id);
        $stmt->execute();
    }

    public function pendientes(int $usuario, string $ahora): array
    {
        $stmt = $this->db->prepare('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at
            FROM agenda_comentarios c LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            WHERE c.eliminado_at IS NULL AND (l.novedad_leida_at IS NULL OR
              (c.recordatorio_at IS NOT NULL AND c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL))
            ORDER BY CASE WHEN c.recordatorio_at IS NOT NULL AND c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL THEN 0 ELSE 1 END,
              c.recordatorio_at ASC,c.creado_at DESC,c.id DESC LIMIT 20');
        $stmt->bind_param('iss', $usuario, $ahora, $ahora);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
