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
    private $multiplesDisponibles;

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
        $dirigido = $destinatarios ? ' AND (c.destinatario_id IS NULL OR c.destinatario_id=? OR c.creado_por=?)' : '';
        $stmt = $this->preparar('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at'.($destinatarios?",TRIM(CONCAT(COALESCE(d.nombre,''),' ',COALESCE(d.apellidos,''))) AS destinatario_nombre":'').' FROM agenda_comentarios c
            LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            '.($destinatarios?'LEFT JOIN usuarios d ON d.id=c.destinatario_id':'').'
            WHERE c.eliminado_at IS NULL AND c.fecha >= ? AND c.fecha < ?'.$visibilidad.$dirigido.$this->filtroTodos((string)$usuario).' ORDER BY c.fecha,c.creado_at,c.id LIMIT 501');
        $types='iss';$params=[$usuario,$desde,$hasta];
        if ($visibilidad) {$types.='i';$params[]=$usuario;}
        if ($dirigido) {$types.='ii';$params[]=$usuario;$params[]=$usuario;}
        $stmt->bind_param($types,...$params);
        $stmt->execute();
        return $this->presentarDestinatarios($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function obtener(int $id, ?int $usuario = null)
    {
        $filtrar = $usuario !== null && $this->privacidadDisponible();
        $destinatarios = $this->destinatariosDisponibles();
        $dirigido = $usuario !== null && $destinatarios;
        $stmt = $this->db->prepare('SELECT c.*'.($destinatarios?",TRIM(CONCAT(COALESCE(d.nombre,''),' ',COALESCE(d.apellidos,''))) AS destinatario_nombre":'').' FROM agenda_comentarios c '.($destinatarios?'LEFT JOIN usuarios d ON d.id=c.destinatario_id':'').' WHERE c.id=? AND c.eliminado_at IS NULL'.($filtrar?' AND (c.privado=0 OR c.creado_por=?)':'').($dirigido?' AND (c.destinatario_id IS NULL OR ? IN (SELECT c.destinatario_id'.($this->multiplesDisponibles()?' UNION SELECT usuario_id FROM agenda_comentarios_destinatarios WHERE comentario_id=c.id':'').') OR c.creado_por=?)':'').($usuario===null?'':$this->filtroTodos((string)$usuario)));
        $types='i';$params=[$id];
        if ($filtrar) {$types.='i';$params[]=$usuario;}
        if ($dirigido) {$types.='ii';$params[]=$usuario;$params[]=$usuario;}
        $stmt->bind_param($types,...$params);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return $fila ? $this->presentarDestinatarios([$fila])[0] : null;
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

    public function multiplesDisponibles(): bool
    {
        if ($this->multiplesDisponibles !== null) return $this->multiplesDisponibles;
        try {
            $this->db->query('SELECT comentario_id,usuario_id FROM agenda_comentarios_destinatarios LIMIT 0');
            return $this->multiplesDisponibles = true;
        } catch (Throwable $e) {
            if ((int)$e->getCode() !== 1146) throw $e;
            return $this->multiplesDisponibles = false;
        }
    }

    // Mantener el primer destinatario en la columna histórica permite una
    // actualización gradual sin convertir comentarios dirigidos en públicos.
    private function incluirMultiples(string $sql): string
    {
        if (!$this->multiplesDisponibles()) return $sql;
        $sql = str_replace('c.destinatario_id=?', '? IN (SELECT c.destinatario_id UNION SELECT usuario_id FROM agenda_comentarios_destinatarios WHERE comentario_id=c.id)', $sql);
        foreach (['l.usuario_id', 'a.lector_id'] as $lector) {
            $sql = str_replace('c.destinatario_id='.$lector, '(c.destinatario_id='.$lector.' OR EXISTS (SELECT 1 FROM agenda_comentarios_destinatarios md WHERE md.comentario_id=c.id AND md.usuario_id='.$lector.'))', $sql);
        }
        return $sql;
    }

    private function preparar(string $sql)
    {
        return $this->db->prepare($this->incluirMultiples($sql));
    }

    private function presentarDestinatarios(array $filas): array
    {
        $indices = [];
        foreach ($filas as $indice => &$fila) {
            $fila['destinatarios_ids'] = empty($fila['destinatario_id']) ? [] : [(int)$fila['destinatario_id']];
            if ($fila['destinatarios_ids']) $indices[(int)$fila['id']] = $indice;
        }
        unset($fila);
        if ($indices && $this->multiplesDisponibles()) {
            $ids = array_keys($indices);
            $stmt = $this->db->prepare("SELECT d.comentario_id,d.usuario_id,TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS nombre FROM agenda_comentarios_destinatarios d LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE d.comentario_id IN (".implode(',',array_fill(0,count($ids),'?')).") ORDER BY d.usuario_id");
            $stmt->bind_param(str_repeat('i',count($ids)), ...$ids);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $destino) {
                $indice = $indices[(int)$destino['comentario_id']];
                if (!in_array((int)$destino['usuario_id'], $filas[$indice]['destinatarios_ids'], true)) {
                    $filas[$indice]['destinatarios_ids'][] = (int)$destino['usuario_id'];
                    $filas[$indice]['destinatario_nombre'] = (trim((string)($filas[$indice]['destinatario_nombre'] ?? '')) ?: 'Usuario seleccionado').', '.(trim((string)$destino['nombre']) ?: 'Usuario seleccionado');
                }
            }
        }
        return $filas;
    }

    public function destinatarios(int $autor = 0): array
    {
        return $this->consultarDestinatarios($autor);
    }

    private function consultarDestinatarios(int $autor, ?int $destinatario = null): array
    {
        $sql = "SELECT u.id,TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS nombre,r.nombre AS rol
            FROM usuarios u INNER JOIN roles r ON r.id=u.rol_id AND r.estado=1
            WHERE u.estado=1 AND (
                EXISTS (
                    SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                    WHERE rp.rol_id=r.id AND p.estado=1 AND p.codigo='agenda.comentarios.recibir_dirigidos'
                ) OR 2 = (
                    SELECT COUNT(DISTINCT p.codigo) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                    WHERE rp.rol_id=r.id AND p.estado=1 AND p.codigo IN ('agenda.ver','agenda.comentarios.ver')
                )
            ) AND (r.id <> 1 OR EXISTS (
                SELECT 1 FROM usuarios a INNER JOIN roles ra ON ra.id=a.rol_id AND ra.estado=1
                INNER JOIN rol_permisos ap ON ap.rol_id=ra.id
                INNER JOIN permisos p ON p.id=ap.permiso_id
                WHERE a.id=? AND a.estado=1
                    AND p.estado=1 AND p.codigo='agenda.comentarios.enviar_administrador'
            ))";
        $stmt = $this->db->prepare($sql . ($destinatario === null
            ? ' ORDER BY u.nombre,u.apellidos,u.id' : ' AND u.id=?'));
        if ($destinatario === null) {
            $stmt->bind_param('i', $autor);
        } else {
            $stmt->bind_param('ii', $autor, $destinatario);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function destinatarioHabilitado(int $id, int $autor): bool
    {
        return $this->consultarDestinatarios($autor, $id) !== [];
    }

    private function permisoAdministradorSql(string $autor): string
    {
        return "EXISTS (
            SELECT 1 FROM usuarios autor_agenda
            INNER JOIN roles rol_autor ON rol_autor.id=autor_agenda.rol_id AND rol_autor.estado=1
            INNER JOIN rol_permisos permiso_rol ON permiso_rol.rol_id=rol_autor.id
            INNER JOIN permisos permiso_agenda ON permiso_agenda.id=permiso_rol.permiso_id
            WHERE autor_agenda.id=".$autor." AND autor_agenda.estado=1
              AND permiso_agenda.estado=1 AND permiso_agenda.codigo='agenda.comentarios.enviar_administrador'
        )";
    }

    public function puedeEnviarAdministrador(int $autor): bool
    {
        $stmt=$this->db->prepare('SELECT '.$this->permisoAdministradorSql('?').' AS permitido');
        $stmt->bind_param('i',$autor);
        $stmt->execute();
        return !empty($stmt->get_result()->fetch_assoc()['permitido']);
    }

    // El lector y el alias son expresiones SQL internas, nunca datos de la petición.
    // Aplicar también a comentarios existentes evita que "Todos" salte el permiso.
    private function filtroTodos(string $lector, string $alias = 'c'): string
    {
        $col=$alias.'.';
        $dirigido=$this->destinatariosDisponibles() ? $col.'destinatario_id IS NOT NULL OR ' : '';
        return ' AND ('.$dirigido.$col.'creado_por=('.$lector.') OR NOT EXISTS (
            SELECT 1 FROM usuarios lector_agenda WHERE lector_agenda.id=('.$lector.') AND lector_agenda.rol_id=1
        ) OR '.$this->permisoAdministradorSql($col.'creado_por').')';
    }

    public function crear(int $usuario, string $fecha, ?string $hora, string $texto, string $ahora, bool $privado = false, ?int $destinatario = null, array $destinatarios = []): int
    {
        $privacidad = $this->privacidadDisponible();
        if ($privado && !$privacidad) throw new RuntimeException('AGENDA_PRIVACIDAD_NO_DISPONIBLE');
        $dirigidos = $this->destinatariosDisponibles();
        if ($destinatario !== null && (!$dirigidos || $privado || !$this->destinatarioHabilitado($destinatario, $usuario))) throw new RuntimeException('AGENDA_DESTINATARIO_NO_DISPONIBLE');
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
        if (count($destinatarios) > 1 && !$this->multiplesDisponibles()) throw new RuntimeException('AGENDA_MULTIPLES_NO_DISPONIBLES');
        $this->db->begin_transaction();
        try {
            $stmt->execute();
            $id = (int)$this->db->insert_id;
            if ($destinatarios && $this->multiplesDisponibles()) {
                $insert = $this->db->prepare('INSERT INTO agenda_comentarios_destinatarios (comentario_id,usuario_id) VALUES (?,?)');
                foreach ($destinatarios as $destino) {
                    $insert->bind_param('ii', $id, $destino);
                    $insert->execute();
                }
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function editar(int $id, int $usuario, string $fecha, ?string $hora, string $texto, bool $privado = false, ?int $destinatario = null, array $destinatarios = []): bool
    {
        $privacidad=$this->privacidadDisponible();
        $dirigidos=$this->destinatariosDisponibles();
        $multiples=$this->multiplesDisponibles();
        if ($privado && !$privacidad) throw new RuntimeException('AGENDA_PRIVACIDAD_NO_DISPONIBLE');
        if ($privado && ($destinatario!==null || $destinatarios)) throw new RuntimeException('AGENDA_DESTINATARIO_NO_DISPONIBLE');
        if (count($destinatarios)>1 && !$multiples) throw new RuntimeException('AGENDA_MULTIPLES_NO_DISPONIBLES');
        foreach (array_unique(array_merge($destinatarios,$destinatario===null?[]:[$destinatario])) as $destino) {
            if (!$dirigidos || !$this->destinatarioHabilitado((int)$destino,$usuario)) throw new RuntimeException('AGENDA_DESTINATARIO_NO_DISPONIBLE');
        }
        $recordatorio=$hora===null?null:$fecha.' '.$hora.':00';
        $this->db->begin_transaction();
        try {
            $lock=$this->db->prepare('SELECT recordatorio_at FROM agenda_comentarios WHERE id=? AND creado_por=? AND eliminado_at IS NULL FOR UPDATE');
            $lock->bind_param('ii',$id,$usuario);$lock->execute();
            $anterior=$lock->get_result()->fetch_assoc();
            if (!$anterior) {$this->db->rollback();return false;}
            $sql='UPDATE agenda_comentarios SET fecha=?,recordatorio_at=?,comentario=?'.($privacidad?',privado=?':'').($dirigidos?',destinatario_id=?':'').' WHERE id=? AND creado_por=? AND eliminado_at IS NULL';
            $params=[$fecha,$recordatorio,$texto];$types='sss';
            if ($privacidad) {$params[]=(int)$privado;$types.='i';}
            if ($dirigidos) {$params[]=$destinatario;$types.='i';}
            $params[]=$id;$params[]=$usuario;$types.='ii';
            $stmt=$this->db->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();
            if ($multiples) {
                $stmt=$this->db->prepare('DELETE FROM agenda_comentarios_destinatarios WHERE comentario_id=?');
                $stmt->bind_param('i',$id);$stmt->execute();
                $insert=$this->db->prepare('INSERT INTO agenda_comentarios_destinatarios (comentario_id,usuario_id) VALUES (?,?)');
                foreach ($destinatarios as $destino) {$insert->bind_param('ii',$id,$destino);$insert->execute();}
            }
            // Editar el texto conserva las lecturas y el recordatorio atendido.
            // Sólo una hora distinta vuelve a dejar pendiente el recordatorio.
            if ($anterior['recordatorio_at']!==$recordatorio) {
                $stmt=$this->db->prepare('UPDATE agenda_comentarios_lecturas SET recordatorio_atendido_at=NULL WHERE comentario_id=?');
                $stmt->bind_param('i',$id);$stmt->execute();
            }
            if ($privado || $destinatario!==null) {
                $permitidos=array_values(array_unique(array_merge([$usuario],$privado?[]:($destinatarios?:[$destinatario]))));
                foreach (['agenda_comentarios_lecturas'=>'usuario_id','agenda_comentarios_vistos_avisos'=>'lector_id'] as $tabla=>$columna) {
                    if ($tabla==='agenda_comentarios_vistos_avisos' && !$this->avisosVistosDisponibles()) continue;
                    $params=array_merge([$id],$permitidos);
                    $stmt=$this->db->prepare('DELETE FROM '.$tabla.' WHERE comentario_id=? AND '.$columna.' NOT IN ('.implode(',',array_fill(0,count($permitidos),'?')).')');
                    $stmt->bind_param(str_repeat('i',count($params)),...$params);$stmt->execute();
                }
            }
            $this->db->commit();return true;
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
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
        $dirigido = $this->destinatariosDisponibles() ? ' AND (destinatario_id IS NULL OR ? IN (SELECT destinatario_id'.($this->multiplesDisponibles()?' UNION SELECT usuario_id FROM agenda_comentarios_destinatarios WHERE comentario_id=agenda_comentarios.id':'').') OR creado_por=?)' : '';
        // Leer antes de la hora no cancela el recordatorio futuro. Atenderlo
        // después de su hora afecta sólo al usuario que abrió el comentario.
        $stmt = $this->db->prepare('INSERT INTO agenda_comentarios_lecturas
            (comentario_id,usuario_id,novedad_leida_at,recordatorio_atendido_at)
            SELECT id,?,?,IF(recordatorio_at IS NOT NULL AND recordatorio_at<=?,?,NULL)
            FROM agenda_comentarios WHERE id=? AND eliminado_at IS NULL'.$visibilidad.$dirigido.$this->filtroTodos((string)$usuario,'agenda_comentarios').'
            ON DUPLICATE KEY UPDATE novedad_leida_at=VALUES(novedad_leida_at),
              recordatorio_atendido_at=COALESCE(VALUES(recordatorio_atendido_at),recordatorio_atendido_at)');
        $types='isssi';$params=[$usuario,$ahora,$ahora,$ahora,$id];
        if ($visibilidad) {$types.='i';$params[]=$usuario;}
        if ($dirigido) {$types.='ii';$params[]=$usuario;$params[]=$usuario;}
        $stmt->bind_param($types,...$params);
        $stmt->execute();
    }

    public function pendientes(int $usuario, string $ahora, bool $soloDirigidos = false): array
    {
        $visibilidad = $this->privacidadDisponible() ? ' AND (c.privado=0 OR c.creado_por=?)' : '';
        $dirigidos = $this->destinatariosDisponibles();
        if ($soloDirigidos && !$dirigidos) return [];
        $destinatario = $soloDirigidos ? ' AND c.destinatario_id=?'
            : ($dirigidos ? ' AND (c.destinatario_id IS NULL OR c.destinatario_id=?)' : '');
        $hasta = (new DateTimeImmutable($ahora))->modify('+30 minutes')->format('Y-m-d H:i:s');
        $stmt = $this->preparar('SELECT c.*,l.novedad_leida_at,l.recordatorio_atendido_at
            FROM agenda_comentarios c LEFT JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id AND l.usuario_id=?
            WHERE c.eliminado_at IS NULL AND (l.novedad_leida_at IS NULL OR
              (c.recordatorio_at IS NOT NULL AND c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL))'.$visibilidad.$destinatario.$this->filtroTodos((string)$usuario).'
            ORDER BY CASE WHEN c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL THEN 0
                          WHEN c.recordatorio_at<=? AND l.recordatorio_atendido_at IS NULL THEN 1 ELSE 2 END,
              c.recordatorio_at ASC,c.creado_at DESC,c.id DESC LIMIT 20');
        $types='is';$params=[$usuario,$hasta];
        if ($visibilidad) {$types.='i';$params[]=$usuario;}
        if ($destinatario) {$types.='i';$params[]=$usuario;}
        $types.='ss';$params[]=$ahora;$params[]=$hasta;
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
        $dirigido = $this->destinatariosDisponibles() ? ' AND (c.destinatario_id IS NULL OR c.destinatario_id=l.usuario_id)' : '';
        $stmt=$this->preparar("SELECT c.id,c.fecha,c.comentario,l.usuario_id AS lector_id,l.novedad_leida_at AS visto_at,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) AS lector_nombre
            FROM agenda_comentarios c INNER JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id
            INNER JOIN usuarios u ON u.id=l.usuario_id
            LEFT JOIN agenda_comentarios_vistos_avisos a ON a.comentario_id=c.id AND a.lector_id=l.usuario_id
            WHERE c.creado_por=? AND l.usuario_id<>c.creado_por AND c.eliminado_at IS NULL
              AND l.novedad_leida_at IS NOT NULL AND a.comentario_id IS NULL".$visibilidad.$dirigido.$this->filtroTodos('l.usuario_id')."
            ORDER BY l.novedad_leida_at DESC,l.usuario_id,c.id LIMIT 20");
        $stmt->bind_param('i',$autor);$stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function leerAvisoVisto(int $autor,int $comentario,int $lector,string $ahora): bool
    {
        $visibilidad = $this->privacidadDisponible() ? ' AND c.privado=0' : '';
        $dirigido = $this->destinatariosDisponibles() ? ' AND (c.destinatario_id IS NULL OR c.destinatario_id=l.usuario_id)' : '';
        $stmt=$this->preparar('INSERT INTO agenda_comentarios_vistos_avisos (comentario_id,lector_id,leido_at)
            SELECT c.id,l.usuario_id,? FROM agenda_comentarios c
            INNER JOIN agenda_comentarios_lecturas l ON l.comentario_id=c.id
            WHERE c.id=? AND c.creado_por=? AND l.usuario_id=? AND l.usuario_id<>c.creado_por
              AND c.eliminado_at IS NULL AND l.novedad_leida_at IS NOT NULL'.$visibilidad.$dirigido.$this->filtroTodos('l.usuario_id').'
            ON DUPLICATE KEY UPDATE leido_at=VALUES(leido_at)');
        $stmt->bind_param('siii',$ahora,$comentario,$autor,$lector);$stmt->execute();
        // Una lectura idempotente puede devolver 0 filas afectadas. Comprobar
        // siempre el destinatario y la pareja real de comentario/lector.
        $check=$this->preparar('SELECT a.comentario_id FROM agenda_comentarios_vistos_avisos a
            INNER JOIN agenda_comentarios c ON c.id=a.comentario_id
            WHERE a.comentario_id=? AND a.lector_id=? AND c.creado_por=? AND c.eliminado_at IS NULL'.($dirigido?' AND (c.destinatario_id IS NULL OR c.destinatario_id=a.lector_id)':'').$this->filtroTodos('a.lector_id'));
        $check->bind_param('iii',$comentario,$lector,$autor);$check->execute();
        return (bool)$check->get_result()->fetch_assoc();
    }
}
