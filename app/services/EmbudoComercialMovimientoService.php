<?php
require_once __DIR__.'/EmbudoComercialEtapas.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Movimiento explícito (arrastrar tarjeta). No crea resultados de llamadas,
 * no afirma pagos y no cambia propietario ni estado administrativo.
 */
final class EmbudoComercialMovimientoService
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    public static function permisosMovimiento(string $anterior,string $nuevo): array
    {
        $permisos=['ventas.embudo.mover'];
        if ($anterior==='RECUPERACION'||$nuevo==='RECUPERACION') {
            $permisos[]='ventas.recuperacion.gestionar';
        }
        if ($anterior==='DESCARTADO'||$nuevo==='DESCARTADO') {
            $permisos[]='ventas.descartados.gestionar';
        }
        return array_values(array_unique($permisos));
    }

    public static function validarPropuesta(string $anterior,string $nuevo,string $motivo,string $fecha): void
    {
        $etapas=EmbudoComercialEtapas::todas();
        if (!isset($etapas[$anterior]) || !isset($etapas[$nuevo])) {
            throw new DomainException('La etapa seleccionada no está disponible.');
        }
        if ($anterior===$nuevo) throw new DomainException('El prospecto ya está en esa etapa.');
        if ($anterior==='DESCARTADO') {
            throw new DomainException('Un descartado no puede reactivarse sin una revisión autorizada.');
        }
        if ($nuevo==='PAGO_CONFIRMADO') {
            throw new DomainException('Pago confirmado requiere verificación del pago; no se asigna manualmente.');
        }
        if ($anterior==='PAGO_CONFIRMADO') {
            throw new DomainException('La etapa de pago requiere un proceso de rectificación verificable.');
        }
        $tam=mb_strlen($motivo);
        if ($tam>190) throw new DomainException('El motivo no puede exceder 190 caracteres.');
        if (in_array($nuevo,['CONTACTADO','INTERESADO','RECUPERACION','DESCARTADO'],true) && $tam<8) {
            throw new DomainException('Describe brevemente el motivo del movimiento (mínimo 8 caracteres).');
        }
        if ($nuevo==='SEGUIMIENTO' && trim($fecha)==='') {
            throw new DomainException('Selecciona la fecha y hora del próximo seguimiento.');
        }
    }

    public static function fechaSeguimientoUtc(string $fecha): ?string
    {
        if ($fecha==='') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$fecha)) {
            throw new DomainException('Indica una fecha y hora válida.');
        }
        $zone=new DateTimeZone('America/Mexico_City');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$fecha,$zone);
        if (!$date || $date->format('Y-m-d\TH:i')!==$fecha) {
            throw new DomainException('Indica una fecha y hora válida.');
        }
        if ($date <= new DateTimeImmutable('now',$zone)) {
            throw new DomainException('La próxima acción debe tener fecha futura.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function moverManual(int $usuarioId,int $asignacionId,string $esperada,
        string $nueva,string $motivo,string $fecha=''): array
    {
        if ($usuarioId<1 || $asignacionId<1) {
            throw new DomainException('La asignación no está disponible.');
        }
        if ((int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3) {
            throw new DomainException('Esta operación pertenece al asesor asignado.');
        }
        if (!tienePermiso('ventas.embudo.ver')) {
            throw new DomainException('Sin permiso para trabajar en el embudo.');
        }
        if (!isset(EmbudoComercialEtapas::todas()[$nueva]) ||
            !isset(EmbudoComercialEtapas::todas()[$esperada])) {
            throw new DomainException('Etapa no válida.');
        }
        $motivo=trim($motivo);
        $this->db->begin_transaction();
        try {
            $s=$this->db->prepare(
                "SELECT a.asesor_id,a.vigente,COALESCE(e.etapa,'NUEVO') etapa
                   FROM prospecto_asignaciones a
                   LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
                  WHERE a.id=? FOR UPDATE"
            );
            $s->bind_param('i',$asignacionId);
            $s->execute();
            $row=$s->get_result()->fetch_assoc();
            $s->close();
            if (!$row || (int)$row['asesor_id']!==$usuarioId ||
                (int)$row['vigente']!==1) {
                throw new DomainException('El prospecto ya no está asignado a este asesor.');
            }
            $actual=(string)$row['etapa'];
            $visible=EmbudoComercialEtapas::visual($actual);
            if ($esperada!==$visible) {
                throw new DomainException('La tarjeta cambió de etapa; actualiza el tablero.');
            }
            self::validarPropuesta($visible,$nueva,$motivo,$fecha);
            foreach (self::permisosMovimiento($visible,$nueva) as $permiso) {
                if (!tienePermiso($permiso)) {
                    throw new DomainException('No tienes permiso para este movimiento.');
                }
            }
            $siguiente=$nueva==='SEGUIMIENTO'
                ?self::fechaSeguimientoUtc($fecha):null;
            $revision=$nueva==='RECUPERACION'
                ?(new DateTimeImmutable('tomorrow',
                    new DateTimeZone('America/Mexico_City')))->format('Y-m-d')
                :null;
            $s=$this->db->prepare(
                'INSERT INTO prospecto_embudo_etapas
                    (asignacion_id,etapa,cambiado_por,actualizado_at)
                 VALUES (?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),
                    cambiado_por=VALUES(cambiado_por), actualizado_at=VALUES(actualizado_at)'
            );
            $s->bind_param('isi',$asignacionId,$nueva,$usuarioId);
            $s->execute();$s->close();
            $s=$this->db->prepare(
                'INSERT INTO prospecto_embudo_agenda
                    (asignacion_id,proxima_accion_at,revision_fecha,actualizado_at,actualizado_por)
                 VALUES (?,?,?,UTC_TIMESTAMP(),?)
                 ON DUPLICATE KEY UPDATE proxima_accion_at=VALUES(proxima_accion_at),
                    revision_fecha=VALUES(revision_fecha),
                    actualizado_at=VALUES(actualizado_at),
                    actualizado_por=VALUES(actualizado_por)'
            );
            $s->bind_param('issi',$asignacionId,$siguiente,$revision,$usuarioId);
            $s->execute();$s->close();
            $razon=$motivo!==''?$motivo:'Cambio manual de etapa';
            $s=$this->db->prepare(
                'INSERT INTO prospecto_embudo_movimientos
                    (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
            );
            $s->bind_param('isssi',$asignacionId,$actual,$nueva,$razon,$usuarioId);
            $s->execute();$id=(int)$this->db->insert_id;$s->close();
            $this->db->commit();
            return [
                'movimiento_id'=>$id,
                'anterior'=>$visible,
                'etapa'=>$nueva,
                'revision_fecha'=>$revision,
                'proxima_accion_at'=>$siguiente
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
