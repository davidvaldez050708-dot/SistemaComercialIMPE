<?php

require_once __DIR__ . '/AgendaComentarioRepository.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class AgendaComentarioService
{
    private $repo;
    private $reloj;

    public function __construct($repo = null, ?DateTimeImmutable $reloj = null)
    {
        $this->repo = $repo ?: new AgendaComentarioRepository();
        $this->reloj = $reloj;
    }

    public static function puedeAcceder(int $usuario): bool
    {
        return $usuario > 0 && $usuario === (int)($_SESSION['usuario_id'] ?? 0) && tienePermiso('reuniones.ver');
    }

    private function ahora(): string
    {
        return ($this->reloj ?: new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function error(string $mensaje, int $codigo = 422): array
    {
        return ['ok'=>false,'mensaje'=>$mensaje,'codigo_http'=>$codigo];
    }

    private function acceso(int $usuario): ?array
    {
        if (!self::puedeAcceder($usuario)) { return $this->error('No tienes acceso a los comentarios de la agenda.', 403); }
        if (!$this->repo->disponible()) { return $this->error('Los comentarios de la agenda aún no están disponibles.', 503); }
        return null;
    }

    private function fechaValida($fecha): bool
    {
        if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) { return false; }
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        return $dia && $dia->format('Y-m-d') === $fecha && $dia->format('Y') >= '2000' && $dia->format('Y') <= '2100';
    }

    private function presentar(array $fila, int $usuario): array
    {
        return ['id'=>(int)$fila['id'], 'fecha'=>(string)$fila['fecha'],
            'hora'=>empty($fila['recordatorio_at']) ? null : substr($fila['recordatorio_at'],11,5),
            'comentario'=>(string)$fila['comentario'], 'autor'=>(string)$fila['autor_nombre'],
            'creado_at'=>(string)$fila['creado_at'], 'puede_eliminar'=>(int)$fila['creado_por']===$usuario,
            'leido'=>!empty($fila['novedad_leida_at']), 'atendido'=>!empty($fila['recordatorio_atendido_at']),
            'recordatorio_pendiente'=>!empty($fila['recordatorio_at']) && $fila['recordatorio_at']<=$this->ahora() && empty($fila['recordatorio_atendido_at'])];
    }

    public function listar(int $usuario, $desde, $hasta): array
    {
        if ($error = $this->acceso($usuario)) { return $error; }
        if (!$this->fechaValida($desde) || !$this->fechaValida($hasta)) { return $this->error('Selecciona un intervalo de fechas válido.'); }
        $dias = (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta));
        if ($dias->invert || $dias->days < 1 || $dias->days > 62) { return $this->error('Consulta hasta 62 días de la agenda.'); }
        $filas = $this->repo->listar($desde, $hasta, $usuario);
        return ['ok'=>true,'comentarios'=>array_map(fn($f)=>$this->presentar($f,$usuario),array_slice($filas,0,500)), 'hay_mas'=>count($filas)>500];
    }

    public function crear(int $usuario, array $datos): array
    {
        if ($error = $this->acceso($usuario)) { return $error; }
        $fecha = $datos['fecha'] ?? '';
        $hora = $datos['hora'] ?? '';
        $texto = $datos['comentario'] ?? '';
        if (!$this->fechaValida($fecha)) { return $this->error('Selecciona un día válido de la agenda.'); }
        if (!is_string($hora) || ($hora !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$hora))) { return $this->error('La hora debe tener el formato HH:MM.'); }
        if (!is_string($texto) || !mb_check_encoding($texto,'UTF-8')) { return $this->error('Escribe un comentario válido.'); }
        $texto = trim(str_replace(["\r\n","\r"],"\n",$texto));
        if ($texto === '' || mb_strlen($texto,'UTF-8')>250 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$texto)) { return $this->error('Escribe entre 1 y 250 caracteres.'); }
        $id = $this->repo->crear($usuario,$fecha,$hora===''?null:$hora,$texto,$this->ahora());
        return ['ok'=>true,'mensaje'=>'Comentario añadido a la agenda.','comentario'=>$this->presentar($this->repo->obtener($id),$usuario)];
    }

    public function eliminar(int $usuario, int $id): array
    {
        if ($error = $this->acceso($usuario)) { return $error; }
        $fila = $id>0 ? $this->repo->obtener($id) : null;
        if (!$fila) { return $this->error('El comentario ya no está disponible.',404); }
        if ((int)$fila['creado_por']!==$usuario) { return $this->error('Sólo quien escribió el comentario puede borrarlo.',403); }
        if (!$this->repo->eliminar($id,$usuario,$this->ahora())) { return $this->error('El comentario ya no está disponible.',409); }
        return ['ok'=>true,'mensaje'=>'Comentario eliminado de la agenda.'];
    }

    public function leer(int $usuario, int $id): array
    {
        if ($error = $this->acceso($usuario)) { return $error; }
        if ($id<=0 || !$this->repo->obtener($id)) { return $this->error('El comentario ya no está disponible.',404); }
        $this->repo->marcarLeido($id,$usuario,$this->ahora());
        return ['ok'=>true];
    }

    public function notificaciones(int $usuario): array
    {
        $salida = ['recordatorios'=>[], 'avisos'=>[]];
        if (!self::puedeAcceder($usuario) || !$this->repo->disponible()) { return $salida; }
        $ahora = $this->ahora();
        foreach ($this->repo->pendientes($usuario,$ahora) as $fila) {
            $vencido = !empty($fila['recordatorio_at']) && $fila['recordatorio_at'] <= $ahora && empty($fila['recordatorio_atendido_at']);
            $evento = $fila['id'].'|'.($vencido?'hora':'nuevo');
            $item = ['id'=>0,'agenda_comentario_id'=>(int)$fila['id'],'agenda_comentario_evento'=>$evento,
                'nombre_entidad'=>'Comentario de '.$fila['autor_nombre'],
                'accion'=>$vencido?'Recordatorio de agenda: '.$fila['comentario']:'Añadió un comentario a la agenda: '.$fila['comentario'],
                'fecha'=>$vencido?$fila['recordatorio_at']:$fila['creado_at'],
                'estado'=>$vencido?'vencida':'normal', 'etiqueta'=>$vencido?'Hora programada':(empty($fila['recordatorio_at'])?'Sin hora':'Comentario nuevo'),
                'icono'=>'bi-chat-left-text','prioridad'=>$vencido?0:4,
                'url'=>'index.php?controller=agendaReunion&action=index&mes='.substr($fila['fecha'],0,7).'&comentario_id='.(int)$fila['id'].'&dia='.$fila['fecha']];
            $salida['recordatorios'][] = $item;
            if (!isset($_SESSION['agenda_comentarios_avisos'][$usuario.'|'.$evento])) {
                $salida['avisos'][] = array_merge($item,['tipo'=>'AGENDA_COMENTARIO','titulo'=>$vencido?'Recordatorio de agenda':'Nuevo comentario en la agenda',
                    'mensaje'=>$fila['autor_nombre'].': '.$fila['comentario']]);
            }
        }
        foreach ($this->repo->vistosPendientes($usuario) as $fila) {
            $lector=trim((string)$fila['lector_nombre']) ?: 'Usuario';
            $evento=$fila['id'].'|visto|'.$fila['lector_id'];
            $mensaje=$lector.' marcó como leído tu comentario: '.$fila['comentario'];
            $item=['id'=>0,'agenda_comentario_id'=>(int)$fila['id'],
                'agenda_comentario_evento'=>$evento,'agenda_comentario_visto_por'=>(int)$fila['lector_id'],
                'nombre_entidad'=>'Tu comentario ya fue visto','accion'=>$mensaje,'mensaje'=>$mensaje,
                'titulo'=>'Tu comentario ya fue visto','fecha'=>$fila['visto_at'],'estado'=>'normal','etiqueta'=>'Visto',
                'icono'=>'bi-check2-all','prioridad'=>4,
                'url'=>'index.php?controller=agendaReunion&action=index&mes='.substr($fila['fecha'],0,7).
                    '&comentario_id='.(int)$fila['id'].'&dia='.$fila['fecha'].'&comentario_visto_por='.(int)$fila['lector_id']];
            $salida['recordatorios'][]=$item;
            if (!isset($_SESSION['agenda_comentarios_avisos'][$usuario.'|'.$evento])) {
                $salida['avisos'][]=array_merge($item,['tipo'=>'AGENDA_COMENTARIO']);
            }
        }
        return $salida;
    }

    public function leerVisto(int $usuario,int $id,int $lector): array
    {
        if ($error=$this->acceso($usuario)) return $error;
        if (!$this->repo->avisosVistosDisponibles()) return $this->error('Los avisos de lectura aún no están disponibles.',503);
        $fila=$id>0?$this->repo->obtener($id):null;
        if (!$fila || $lector<=0 || $lector===$usuario) return $this->error('El aviso ya no está disponible.',404);
        if ((int)$fila['creado_por']!==$usuario) return $this->error('Este aviso sólo está disponible para el autor.',403);
        if (!$this->repo->leerAvisoVisto($usuario,$id,$lector,$this->ahora())) return $this->error('El aviso ya no está disponible.',404);
        return ['ok'=>true,'mensaje'=>'Notificación de lectura marcada como leída.'];
    }

    /** Sólo consume los eventos que sobrevivieron al límite de la campana. */
    public function registrarEntregados(int $usuario, array $items): void
    {
        foreach ($items as $item) {
            $evento = $item['agenda_comentario_evento'] ?? '';
            if (preg_match('/^\d+\|(nuevo|hora|visto\|\d+)$/D',$evento)) { $_SESSION['agenda_comentarios_avisos'][$usuario.'|'.$evento] = time(); }
        }
        $memoria = $_SESSION['agenda_comentarios_avisos'] ?? [];
        arsort($memoria);
        $_SESSION['agenda_comentarios_avisos'] = array_slice($memoria,0,500,true);
    }
}
