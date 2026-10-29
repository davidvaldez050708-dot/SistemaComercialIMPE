<?php

require_once __DIR__ . '/../../config/db_connection.php';

/** Almacenamiento independiente; no modifica reuniones ni seguimientos. */
class AgendaComentarioRepository
{
    private $db;
    private $disponible;
    private $avisosVistosDisponibles;
    private $privacidadDisponible;
    private $destinatariosDisponibles;

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
        $visibilidad = $this->privacidadDisponible() ? ' AND (c.privado=0 OR c.creado_por=?)' : '';
        $destinatarios = $this->destinatariosDisponibles();
        $stmt = $this->db->prepare('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at'.($destinatarios?",TRIM(CONCAT(COALESCE(d.nombre,''),' ',COALESCE(d.apellidos,''))) AS destinatario_nombre":'').' FROM agenda_comentarios c
            LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            '.($destinatarios?'LEFT JOIN usuarios d ON d.id=c.destinatario_id':'').'
            WHERE c.eliminado_at IS NULL AND c.fecha >= ? AND c.fecha < ?'.$visibilidad.' ORDER BY c.fecha,c.creado_at,c.id LIMIT 501');
        if ($visibilidad) $stmt->bind_param('issi', $usuario, $desde, $hasta, $usuario);
        else $stmt->bind_param('iss', $usuario, $desde, $hasta);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function obtener(int $id, ?int $usuario = null)
    {
        $filtrar = $usuario !== null && $this->privacidadDisponible();
        $destinatarios = $this->destinatariosDisponibles();
        $stmt = $this->db->prepare('SELECT c.*'.($destinatarios?",TRIM(CONCAT(COALESCE(d.nombre,''),' ',COALESCE(d.apellidos,''))) AS destinatario_nombre":'').' FROM agenda_comentarios c '.($destinatarios?'LEFT JOIN usuarios d ON d.id=c.destinatario_id':'').' WHERE c.id=? AND c.eliminado_at IS NULL'.($filtrar?' AND (c.privado=0 OR c.creado_por=?)':''));
        if ($filtrar) $stmt->bind_param('ii', $id, $usuario);
        else $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function privacidadDisponible(): bool
    {
        if ($this->privacidadDisponible !== null) return $this->privacidadDisponible;
        try {
            $this->db->query('SELECT privado FROM agenda_comentarios LIMIT 0');
            return $this->privacidadDisponible = true;
        } catch (Throwable $e) {
            // Sólo una columna aún no instalada permite compatibilidad con
            // comentarios antiguos. Un fallo de BD nunca omite el filtro.
            if ((int)$e->getCode() !== 1054) throw $e;
            return $this->privacidadDisponible = false;
        }
    }

    public function destinatariosDisponibles(): bool
    {
        if ($this->destinatariosDisponibles !== null) return $this->destinatariosDisponibles;
        try {
            $this->db->query('SELECT destinatario_id FROM agenda_comentarios LIMIT 0');
            return $this->destinatariosDisponibles = true;
        } catch (Throwable $e) {
            if ((int)$e->getCode() !== 1054) throw $e;
            return $this->destinatariosDisponibles = false;
        }
    }

    public function destinatarios(): array
    {
        // La misma autorización de Agenda: permiso reuniones.ver. No se
        // conceden permisos nuevos ni se limita a nombres de rol concretos.
        return $this->db->query("SELECT u.id,TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS nombre,r.nombre AS rol
            FROM usuarios u INNER JOIN roles r ON r.id=u.rol_id AND r.estado=1
            WHERE u.estado=1 AND EXISTS (SELECT 1 FROM rol_permisos rp
                INNER JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1
                WHERE rp.rol_id=u.rol_id AND p.codigo='reuniones.ver')
            ORDER BY u.nombre,u.apellidos,u.id")->fetch_all(MYSQLI_ASSOC);
    }

    public function destinatarioHabilitado(int $id): bool
    {
        foreach ($this->destinatarios() as $usuario) if ((int)$usuario['id'] === $id) return true;
        return false;
    }

    public function crear(int $usuario, string $fecha, ?string $hora, string $texto, string $ahora, bool $privado = false, ?int $destinatario = null): int
    {
        $privacidad = $this->privacidadDisponible();
        if ($privado && !$privacidad) throw new RuntimeException('AGENDA_PRIVACIDAD_NO_DISPONIBLE');
        $dirigidos = $this->destinatariosDisponibles();
        if ($destinatario !== null && (!$dirigidos || $privado || !$this->destinatarioHabilitado($destinatario))) throw new RuntimeException('AGENDA_DESTINATARIO_NO_DISPONIBLE');
        $stmt = $this->db->prepare("SELECT TRIM(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellidos,''))) AS autor FROM usuarios WHERE id=?");
        $stmt->bind_param('i', $usuario);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        if (!$fila) { throw new RuntimeException('AGENDA_AUTOR_NO_DISPONIBLE'); }
        $autor = mb_substr(trim((string)$fila['autor']) ?: 'Usuario', 0, 255, 'UTF-8');
        $recordatorio = $hora === null ? null : $fecha . ' ' . $hora . ':00';
        $stmt = $this->db->prepare('INSERT INTO agenda_comentarios (fecha,recordatorio_at,comentario,creado_por,autor_nombre,creado_at'.($privacidad?',privado':'').($dirigidos?',destinatario_id':'').') VALUES (?,?,?,?,?,?'.($privacidad?',?':'').($dirigidos?',?':'').')');
        $visibilidad = (int)$privado;
        if ($privacidad && $dirigidos) $stmt->bind_param('sssissii', $fecha, $recordatorio, $texto, $usuario, $autor, $ahora, $visibilidad, $destinatario);
        elseif ($dirigidos) $stmt->bind_param('sssissi', $fecha, $recordatorio, $texto, $usuario, $autor, $ahora, $destinatario);
        elseif ($privacidad) $stmt->bind_param('sssissi', $fecha, $recordatorio, $texto, $usuario, $autor, $ahora, $visibilidad);
        else $stmt->bind_param('sssiss', $fecha, $recordatorio, $texto, $usuario, $autor, $ahora);
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
        $visibilidad = $this->privacidadDisponible() ? ' AND (privado=0 OR creado_por=?)' : '';
        // Leer antes de la hora no cancela el recordatorio futuro. Atenderlo
        // después de su hora afecta sólo al usuario que abrió el comentario.
        $stmt = $this->db->prepare('INSERT INTO agenda_comentarios_lecturas
            (comentario_id,usuario_id,novedad_leida_at,recordatorio_atendido_at)
            SELECT id,?,?,IF(recordatorio_at IS NOT NULL AND recordatorio_at<=?,?,NULL)
            FROM agenda_comentarios WHERE id=? AND eliminado_at IS NULL'.$visibilidad.'
            ON DUPLICATE KEY UPDATE novedad_leida_at=VALUES(novedad_leida_at),
              recordatorio_atendido_at=COALESCE(VALUES(recordatorio_atendido_at),recordatorio_atendido_at)');
        if ($visibilidad) $stmt->bind_param('isssii', $usuario, $ahora, $ahora, $ahora, $id, $usuario);
        else $stmt->bind_param('isssi', $usuario, $ahora, $ahora, $ahora, $id);
        $stmt->execute();
    }

    public function pendientes(int $usuario, string $ahora): array
    {
        $visibilidad = $this->privacidadDisponible() ? ' AND (c.privado=0 OR c.creado_por=?)' : '';
        $destinatario = $this->destinatariosDisponibles() ? ' AND (c.destinatario_id IS NULL OR c.destinatario_id=?)' : '';
        $stmt = $this->db->prepare('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at
            FROM agenda_comentarios c LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            WHERE c.eliminado_at IS NULL AND (l.novedad_leida_at IS NULL OR
              (c.recordatorio_at IS NOT NULL AND c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL))'.$visibilidad.$destinatario.'
            ORDER BY CASE WHEN c.recordatorio_at IS NOT NULL AND c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL THEN 0 ELSE 1 END,
              c.recordatorio_at ASC,c.creado_at DESC,c.id DESC LIMIT 20');
        $types='is';$params=[$usuario,$ahora];
        if ($visibilidad) {$types.='i';$params[]=$usuario;}
        if ($destinatario) {$types.='i';$params[]=$usuario;}
        $types.='s';$params[]=$ahora;
        $stmt->bind_param($types,...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function avisosVistosDisponibles(): bool
    {
        if ($this->avisosVistosDisponibles !== null) return $this->avisosVistosDisponibles;
        try {
            $this->db->query('SELECT comentario_id,lector_id,leido_at FROM agenda_comentarios_vistos_avisos LIMIT 0');
            return $this->avisosVistosDisponibles=true;
        } catch (Throwable $e) { return $this->avisosVistosDisponibles=false; }
    }

    public function vistosPendientes(int $autor): array
    {
        if (!$this->avisosVistosDisponibles()) return [];
        $visibilidad = $this->privacidadDisponible() ? ' AND c.privado=0' : '';
        $stmt=$this->db->prepare("SELECT c.id,c.fecha,c.comentario,l.usuario_id AS lector_id,l.novedad_leida_at AS visto_at,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS lector_nombre
            FROM agenda_comentarios c INNER JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id
            INNER JOIN usuarios u ON u.id=l.usuario_id
            LEFT JOIN agenda_comentarios_vistos_avisos a ON a.comentario_id=c.id AND a.lector_id=l.usuario_id
            WHERE c.creado_por=? AND l.usuario_id<>c.creado_por AND c.eliminado_at IS NULL
              AND l.novedad_leida_at IS NOT NULL AND a.comentario_id IS NULL".$visibilidad."
            ORDER BY l.novedad_leida_at DESC,l.usuario_id,c.id LIMIT 20");
        $stmt->bind_param('i',$autor);$stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function leerAvisoVisto(int $autor,int $comentario,int $lector,string $ahora): bool
    {
        $visibilidad = $this->privacidadDisponible() ? ' AND c.privado=0' : '';
        $stmt=$this->db->prepare('INSERT INTO agenda_comentarios_vistos_avisos (comentario_id,lector_id,leido_at)
            SELECT c.id,l.usuario_id,? FROM agenda_comentarios c
            INNER JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id
            WHERE c.id=? AND c.creado_por=? AND l.usuario_id=? AND l.usuario_id<>c.creado_por
              AND c.eliminado_at IS NULL AND l.novedad_leida_at IS NOT NULL'.$visibilidad.'
            ON DUPLICATE KEY UPDATE leido_at=VALUES(leido_at)');
        $stmt->bind_param('siii',$ahora,$comentario,$autor,$lector);$stmt->execute();
        // Una lectura idempotente puede devolver 0 filas afectadas. Comprobar
        // siempre el destinatario y la pareja real de comentario/lector.
        $check=$this->db->prepare('SELECT a.comentario_id FROM agenda_comentarios_vistos_avisos a
            INNER JOIN agenda_comentarios c ON c.id=a.comentario_id
            WHERE a.comentario_id=? AND a.lector_id=? AND c.creado_por=? AND c.eliminado_at IS NULL');
        $check->bind_param('iii',$comentario,$lector,$autor);$check->execute();
        return (bool)$check->get_result()->fetch_assoc();
    }
}
