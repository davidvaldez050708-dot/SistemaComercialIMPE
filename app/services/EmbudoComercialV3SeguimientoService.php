<?php
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/EmbudoComercialMovimientoService.php';
require_once __DIR__.'/EmbudoComercialV3MovimientosPermitidos.php';

final class EmbudoComercialV3SeguimientoService
{
    public const ETAPAS = ['NUEVO','CONTACTADO','SEGUIMIENTO','INTERESADO','POTENCIAL','PROCESO_INSCRIPCION'];
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    public static function instalada(mysqli $db): bool
    {
        $r=$db->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prospecto_embudo_actividad_v3'");
        return strtoupper((string)($r->fetch_assoc()['ENGINE']??''))==='INNODB';
    }

    private function consultar(string $sql,int $id): array
    {
        $s=$this->db->prepare($sql);
        try {
            $s->bind_param('i',$id);
            $s->execute();
            return $s->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally { $s->close(); }
    }

    private function asignacion(int $usuario,int $id,bool $bloquear=false): string
    {
        if ($id<1 || $usuario<1 || (int)($_SESSION['usuario_id']??0)!==$usuario ||
            (int)($_SESSION['rol_id']??0)!==3 || !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            throw new DomainException('No tienes autorización para gestionar esta asignación.');
        }
        $fila=$this->consultar("SELECT a.asesor_id,a.vigente,COALESCE(e.etapa,'NUEVO') etapa
            FROM prospecto_asignaciones a LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            WHERE a.id=?".($bloquear?' FOR UPDATE':''),$id)[0]??null;
        if (!$fila || (int)$fila['asesor_id']!==$usuario || (int)$fila['vigente']!==1) {
            throw new DomainException('La asignación cambió o ya no pertenece a tu cuenta.');
        }
        return $fila['etapa'];
    }

    public function historial(int $usuario,int $id,int $antes=0): array
    {
        $this->asignacion($usuario,$id);
        $limite=max(0,$antes);
        return $this->consultar("SELECT id,usuario_id,etapa,accion,motivo,fecha_anterior,
            fecha_pendiente,completada_at,subetapa_anterior,subetapa_nueva,creado_at
            FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=?".
            ($limite?' AND id<'.$limite:'')." ORDER BY id DESC LIMIT 20",$id);
    }

    private function motivoValido(string $motivo): string
    {
        $motivo=trim($motivo);
        if (mb_strlen($motivo)<8 || mb_strlen($motivo)>190) {
            throw new DomainException('Describe la gestión entre 8 y 190 caracteres.');
        }
        return $motivo;
    }

    private function ultimo(int $id): array
    {
        return $this->consultar("SELECT id,fecha_pendiente,completada_at
            FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE",$id)[0]??[];
    }

    private function guardar(int $id,int $usuario,string $etapa,string $accion,string $motivo,
        ?string $anterior,?string $pendiente,?string $completada,?string $subAnterior,?string $subNueva): int
    {
        $s=$this->db->prepare("INSERT INTO prospecto_embudo_actividad_v3
            (asignacion_id,usuario_id,etapa,accion,motivo,fecha_anterior,fecha_pendiente,
             completada_at,subetapa_anterior,subetapa_nueva,creado_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        try {
            $s->bind_param('iissssssss',$id,$usuario,$etapa,$accion,$motivo,$anterior,$pendiente,
                $completada,$subAnterior,$subNueva);
            $s->execute();
            return (int)$this->db->insert_id;
        } finally { $s->close(); }
    }

    private function cambiarSubetapa(int $id,int $usuario,string $etapa,string $subetapa,string $motivo): ?string
    {
        $previa=$this->consultar("SELECT subetapa FROM prospecto_embudo_subetapas
            WHERE asignacion_id=? FOR UPDATE",$id)[0]['subetapa']??null;
        if ($previa===$subetapa) return $previa;
        $s=$this->db->prepare("INSERT INTO prospecto_embudo_subetapas
            (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
            VALUES (?,?,?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE
            subetapa=VALUES(subetapa),actualizado_por=VALUES(actualizado_por),
            actualizado_at=VALUES(actualizado_at),version=version+1");
        $s->bind_param('isi',$id,$subetapa,$usuario);
        $s->execute();$s->close();
        $s=$this->db->prepare("INSERT INTO prospecto_embudo_subetapas_historial
            (asignacion_id,etapa_en_evento,subetapa_anterior,subetapa_nueva,motivo,origen,usuario_id,creado_at)
            VALUES (?,?,?,?,?,'ASESOR_V3',?,UTC_TIMESTAMP())");
        $s->bind_param('issssi',$id,$etapa,$previa,$subetapa,$motivo,$usuario);
        $s->execute();$s->close();
        return $previa;
    }

    public function registrar(int $usuario,int $id,string $etapa,int $version,string $accion,
        string $motivo,string $fecha,string $subetapa): int
    {
        $motivo=$this->motivoValido($motivo);
        if (!in_array($etapa,self::ETAPAS,true) ||
            !in_array($accion,['REGISTRAR','PROGRAMAR','REPROGRAMAR','COMPLETAR'],true)) {
            throw new DomainException('Actividad comercial no válida.');
        }
        if ($subetapa!=='' && ($etapa!=='SEGUIMIENTO' ||
            !EmbudoComercialPoliticaPropuesta::subetapaValida($etapa,$subetapa))) {
            throw new DomainException('Selecciona una subetapa válida de Seguimiento.');
        }
        $programar=in_array($accion,['PROGRAMAR','REPROGRAMAR'],true);
        if ($programar!==($fecha!=='')) throw new DomainException('Indica la fecha de la próxima actividad.');
        $this->db->begin_transaction();
        try {
            if ($this->asignacion($usuario,$id,true)!==$etapa) throw new DomainException('La etapa cambió. Actualiza el tablero.');
            $actual=$this->ultimo($id);
            if ((int)($actual['id']??0)!==$version) throw new DomainException('La actividad cambió. Actualiza el tablero.');
            $anterior=$actual['fecha_pendiente']??null;
            if (($accion==='PROGRAMAR' && $anterior!==null) ||
                (in_array($accion,['REPROGRAMAR','COMPLETAR'],true) && $anterior===null)) {
                throw new DomainException('La actividad pendiente no coincide con la operación.');
            }
            $pendiente=$programar?EmbudoComercialMovimientoService::fechaSeguimientoUtc($fecha):
                ($accion==='COMPLETAR'?null:$anterior);
            $completada=$accion==='COMPLETAR'?gmdate('Y-m-d H:i:s'):($actual['completada_at']??null);
            $previa=$subetapa!==''?$this->cambiarSubetapa($id,$usuario,$etapa,$subetapa,$motivo):null;
            $evento=$this->guardar($id,$usuario,$etapa,$accion,$motivo,$anterior,$pendiente,
                $completada,$previa,$subetapa!==''?$subetapa:null);
            $this->db->commit();
            return $evento;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function mover(int $usuario,int $id,string $origen,string $destino,string $motivo,string $fecha): array
    {
        EmbudoComercialV3MovimientosPermitidos::validar($origen,$destino);
        if ($destino==='POTENCIAL') throw new DomainException('Usa la calificación comercial.');
        $motivo=$this->motivoValido($motivo);
        $this->db->begin_transaction();
        try {
            if ($this->asignacion($usuario,$id,true)!==$origen) throw new DomainException('La etapa cambió. Actualiza el tablero.');
            if ($destino==='CONTACTADO' &&
                !EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada($this->db,$usuario,$id)) {
                throw new DomainException('El movimiento requiere conversación acreditada de esta asignación.');
            }
            if ($origen==='PROCESO_INSCRIPCION') {
                if ($this->consultar('SELECT asignacion_id FROM prospecto_embudo_inscripciones_v3 WHERE asignacion_id=? FOR UPDATE',$id)) {
                    throw new DomainException('La solicitud ya está vinculada. Requiere revisión institucional.');
                }
                require_once __DIR__.'/EmbudoComercialV3InscripcionService.php';
                $consulta=(new EmbudoComercialV3InscripcionService($this->db))->candidato($usuario,$id);
                if ($consulta['estado']==='CANDIDATA') {
                    throw new DomainException('Ya se recibió el formulario completo. Revisa y vincula la solicitud antes de mover el prospecto.');
                }
                if ($consulta['estado']==='REVISION_INSTITUCIONAL') {
                    throw new DomainException($consulta['mensaje']);
                }
            }
            $actual=$destino==='SEGUIMIENTO'?$this->ultimo($id):null;
            $pendiente=$actual['fecha_pendiente']??null;
            if ($destino==='SEGUIMIENTO' && $pendiente!==null && $fecha!=='') {
                throw new DomainException('Ya hay una actividad pendiente. Reprográmala desde Actividades comerciales.');
            }
            if ($destino==='SEGUIMIENTO' && $pendiente===null && $fecha==='') {
                throw new DomainException('Selecciona la fecha y hora del seguimiento.');
            }
            if ($destino!=='SEGUIMIENTO') {
                EmbudoComercialMovimientoService::validarPropuesta($origen,$destino,$motivo,$fecha);
            }
            $subNueva=$destino==='SEGUIMIENTO'?'CONTACTO_ACORDADO':'PENDIENTE_CALIFICACION';
            $subAnterior=$this->cambiarSubetapa($id,$usuario,$destino,$subNueva,$motivo);
            if ($destino==='SEGUIMIENTO' && $pendiente===null) {
                $this->guardar($id,$usuario,$destino,'PROGRAMAR',$motivo,null,
                    EmbudoComercialMovimientoService::fechaSeguimientoUtc($fecha),$actual['completada_at']??null,$subAnterior,$subNueva);
            }
            $s=$this->db->prepare("INSERT INTO prospecto_embudo_etapas
                (asignacion_id,etapa,cambiado_por,actualizado_at) VALUES (?,?,?,UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),cambiado_por=VALUES(cambiado_por),
                actualizado_at=VALUES(actualizado_at)");
            $s->bind_param('isi',$id,$destino,$usuario);
            $s->execute();$s->close();
            $s=$this->db->prepare("INSERT INTO prospecto_embudo_movimientos
                (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
            $s->bind_param('isssi',$id,$origen,$destino,$motivo,$usuario);
            $s->execute();$movimiento=(int)$this->db->insert_id;$s->close();
            $this->db->commit();
            return ['movimiento_id'=>$movimiento,'anterior'=>$origen,'etapa'=>$destino];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
