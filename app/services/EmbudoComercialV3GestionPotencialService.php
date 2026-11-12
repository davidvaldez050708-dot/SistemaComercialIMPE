<?php
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/EmbudoComercialMovimientoService.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Gestión V3 de un Potencial sin falsificar un cambio de etapa.
 *
 * Un INTERESADO histórico se presenta en Potenciales, pero su etapa SQL
 * permanece intacta hasta una conciliación explícita. Programar una tarea
 * es transversal: no significa "degradar" a Seguimiento.
 *
 * No crea resultados PBX, pagos, inscritos, reasignaciones ni contactos.
 */
final class EmbudoComercialV3GestionPotencialService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    public static function esPotencial(string $etapa): bool
    {
        return in_array($etapa,['POTENCIAL','INTERESADO'],true);
    }

    public function registrar(
        int $usuarioId,
        int $asignacionId,
        string $etapaEsperada,
        string $subetapaEsperada,
        string $subetapaNueva,
        string $motivo,
        string $fecha=''
    ): array {
        if ($usuarioId<1 || $asignacionId<1 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            throw new DomainException('No tienes autorización para gestionar este prospecto.');
        }
        if (!self::esPotencial($etapaEsperada) ||
            !EmbudoComercialPoliticaPropuesta::subetapaValida('POTENCIAL',$subetapaNueva)) {
            throw new DomainException('La etapa o subetapa no es válida.');
        }
        $motivo=trim($motivo);
        if (mb_strlen($motivo)<8 || mb_strlen($motivo)>190) {
            throw new DomainException('Describe el motivo entre 8 y 190 caracteres.');
        }
        $fechaUtc=trim($fecha)!==''
            ?EmbudoComercialMovimientoService::fechaSeguimientoUtc($fecha):null;

        $this->db->begin_transaction();
        try {
            // Bloquea la asignación para serializar cambios de etapa, estado
            // vigente y subetapas; ningún ID recibido del navegador acredita
            // propiedad o vigencia.
            $s=$this->db->prepare(
                "SELECT a.asesor_id,a.vigente,COALESCE(e.etapa,'NUEVO') etapa
                   FROM prospecto_asignaciones a
                   LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
                  WHERE a.id=? FOR UPDATE"
            );
            $s->bind_param('i',$asignacionId);
            $s->execute();
            $fila=$s->get_result()->fetch_assoc();
            $s->close();
            if (!$fila || (int)$fila['asesor_id']!==$usuarioId ||
                (int)$fila['vigente']!==1 ||
                (string)$fila['etapa']!==$etapaEsperada ||
                !self::esPotencial((string)$fila['etapa'])) {
                throw new DomainException('El prospecto cambió o ya no está asignado a tu cuenta.');
            }
            $s=$this->db->prepare(
                "SELECT subetapa FROM prospecto_embudo_subetapas
                  WHERE asignacion_id=? FOR UPDATE"
            );
            $s->bind_param('i',$asignacionId);
            $s->execute();
            $actual=$s->get_result()->fetch_assoc();
            $s->close();
            $previa=$actual?(string)$actual['subetapa']:'';
            if ($previa!==$subetapaEsperada) {
                throw new DomainException('La subetapa cambió; recarga el embudo.');
            }
            if ($previa===$subetapaNueva && $fechaUtc===null) {
                throw new DomainException('Selecciona otra subetapa o programa una acción futura.');
            }

            if ($previa!==$subetapaNueva) {
                $s=$this->db->prepare(
                    "INSERT INTO prospecto_embudo_subetapas
                       (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
                     VALUES (?,?,?,UTC_TIMESTAMP(),1)
                     ON DUPLICATE KEY UPDATE subetapa=VALUES(subetapa),
                         actualizado_por=VALUES(actualizado_por),
                         actualizado_at=VALUES(actualizado_at),
                         version=version+1"
                );
                $s->bind_param('isi',$asignacionId,$subetapaNueva,$usuarioId);
                $s->execute();$s->close();
            }

            if ($fechaUtc!==null) {
                // Una nueva cita no borra revisión/agenda histórica si el
                // asesor solamente edita la subetapa.
                $s=$this->db->prepare(
                    "INSERT INTO prospecto_embudo_agenda
                       (asignacion_id,proxima_accion_at,revision_fecha,actualizado_at,actualizado_por)
                     VALUES (?,?,NULL,UTC_TIMESTAMP(),?)
                     ON DUPLICATE KEY UPDATE
                       proxima_accion_at=VALUES(proxima_accion_at),
                       actualizado_at=VALUES(actualizado_at),
                       actualizado_por=VALUES(actualizado_por)"
                );
                $s->bind_param('isi',$asignacionId,$fechaUtc,$usuarioId);
                $s->execute();$s->close();
            }

            $origen='ASESOR_V3';
            $preSub=$previa!==''?$previa:null;
            $s=$this->db->prepare(
                "INSERT INTO prospecto_embudo_subetapas_historial
                   (asignacion_id,etapa_en_evento,subetapa_anterior,
                    subetapa_nueva,motivo,origen,usuario_id,creado_at)
                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())"
            );
            $s->bind_param('isssssi',$asignacionId,$etapaEsperada,
                $preSub,$subetapaNueva,$motivo,$origen,$usuarioId);
            $s->execute();$id=(int)$this->db->insert_id;$s->close();

            $this->db->commit();
            return [
                'evento_id'=>$id,
                'etapa_conservada'=>$etapaEsperada,
                'subetapa'=>$subetapaNueva,
                'proxima_accion_at'=>$fechaUtc
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
