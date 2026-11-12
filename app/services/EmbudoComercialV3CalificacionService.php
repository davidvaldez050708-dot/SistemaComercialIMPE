<?php
require_once __DIR__.'/EmbudoComercialV3MovimientosPermitidos.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/** Calificación explícita de Contactados, sin tocar agenda ni resultados PBX. */
final class EmbudoComercialV3CalificacionService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    public function registrar(int $usuarioId,int $asignacionId,string $motivo,bool $confirmada): array
    {
        if ($usuarioId<1 || $asignacionId<1 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver') || !tienePermiso('ventas.embudo.mover')) {
            throw new DomainException('No tienes autorización para calificar este prospecto.');
        }
        $motivo=trim($motivo);
        if (!$confirmada || mb_strlen($motivo)<8 || mb_strlen($motivo)>190) {
            throw new DomainException('Confirma la intención de inscribirse y documenta el motivo (8 a 190 caracteres).');
        }

        $this->db->begin_transaction();
        try {
            $s=$this->db->prepare("SELECT a.asesor_id,a.vigente,e.etapa
                FROM prospecto_asignaciones a
                LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
                WHERE a.id=? FOR UPDATE");
            $s->bind_param('i',$asignacionId);
            $s->execute();
            $fila=$s->get_result()->fetch_assoc();
            $s->close();
            if (!$fila || (int)$fila['asesor_id']!==$usuarioId ||
                (int)$fila['vigente']!==1 || $fila['etapa']!=='CONTACTADO') {
                throw new DomainException('El prospecto cambió de etapa o ya no está asignado a tu cuenta.');
            }
            if (!EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada($this->db,$usuarioId,$asignacionId)) {
                throw new DomainException('La calificación requiere una conversación acreditada de esta asignación.');
            }

            $s=$this->db->prepare("SELECT subetapa FROM prospecto_embudo_subetapas
                WHERE asignacion_id=? FOR UPDATE");
            $s->bind_param('i',$asignacionId);
            $s->execute();
            $previa=$s->get_result()->fetch_assoc()['subetapa']??null;
            $s->close();
            if ($previa!==null && $previa!=='PENDIENTE_CALIFICACION') {
                throw new DomainException('La subetapa existente requiere revisión antes de calificar el prospecto.');
            }
            $s=$this->db->prepare("UPDATE prospecto_embudo_etapas SET etapa='POTENCIAL',
                cambiado_por=?,actualizado_at=UTC_TIMESTAMP() WHERE asignacion_id=?");
            $s->bind_param('ii',$usuarioId,$asignacionId);
            $s->execute();$s->close();
            $s=$this->db->prepare("INSERT INTO prospecto_embudo_subetapas
                    (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
                VALUES (?,'CALIFICADO',?,UTC_TIMESTAMP(),1)
                ON DUPLICATE KEY UPDATE subetapa=VALUES(subetapa),actualizado_por=VALUES(actualizado_por),
                    actualizado_at=VALUES(actualizado_at),version=version+1");
            $s->bind_param('ii',$asignacionId,$usuarioId);
            $s->execute();$s->close();
            $s=$this->db->prepare("INSERT INTO prospecto_embudo_subetapas_historial
                    (asignacion_id,etapa_en_evento,subetapa_anterior,subetapa_nueva,motivo,origen,usuario_id,creado_at)
                VALUES (?,'POTENCIAL',?,'CALIFICADO',?,'ASESOR_V3',?,UTC_TIMESTAMP())");
            $s->bind_param('issi',$asignacionId,$previa,$motivo,$usuarioId);
            $s->execute();$s->close();
            $s=$this->db->prepare("INSERT INTO prospecto_embudo_movimientos
                    (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
                VALUES (?,'CONTACTADO','POTENCIAL',?,?,UTC_TIMESTAMP())");
            $s->bind_param('isi',$asignacionId,$motivo,$usuarioId);
            $s->execute();$id=(int)$this->db->insert_id;$s->close();
            $this->db->commit();
            return ['movimiento_id'=>$id,'anterior'=>'CONTACTADO','etapa'=>'POTENCIAL','subetapa'=>'CALIFICADO'];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
