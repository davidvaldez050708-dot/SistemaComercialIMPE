<?php
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';

/**
 * Operaciones internas dentro de la transacción del llamador.
 * La asignación debe estar bloqueada y autorizada antes de invocarlas.
 */
final class EmbudoComercialV3ProximaAccion
{
    public static function subetapa(string $situacion): string
    {
        return match ($situacion) {
            'REUNIENDO_DOCUMENTOS' => 'ESPERANDO_DOCUMENTOS',
            'DOCUMENTACION_LISTA', 'PAGO_PENDIENTE', 'LISTO_PARA_TRAMITE' => 'ESPERANDO_COMPROBANTE',
            'PRESUPUESTO_PENDIENTE' => 'POR_PRESUPUESTO',
            default => 'CONTACTO_ACORDADO',
        };
    }

    public static function promover(mysqli $db, int $usuario, int $id, string $etapa,
        string $subetapa, string $motivo): array
    {
        if (!in_array($etapa, ['CONTACTADO','POTENCIAL','INTERESADO'], true)) {
            return ['etapa'=>$etapa, 'anterior'=>null, 'subetapa'=>null];
        }
        if (!EmbudoComercialPoliticaPropuesta::subetapaValida('SEGUIMIENTO', $subetapa)) {
            throw new DomainException('Selecciona una situación válida de Seguimiento.');
        }
        $q=$db->prepare('SELECT subetapa FROM prospecto_embudo_subetapas WHERE asignacion_id=? FOR UPDATE');
        $q->bind_param('i',$id);
        $q->execute();
        $previa=$q->get_result()->fetch_assoc()['subetapa']??null;
        $q->close();
        if ($previa==='NO_CONTACTAR') {
            throw new DomainException('Este prospecto tiene una restricción de contacto.');
        }
        $q=$db->prepare("INSERT INTO prospecto_embudo_etapas
            (asignacion_id,etapa,cambiado_por,actualizado_at) VALUES (?,'SEGUIMIENTO',?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),cambiado_por=VALUES(cambiado_por),actualizado_at=VALUES(actualizado_at)");
        $q->bind_param('ii',$id,$usuario);
        $q->execute();$q->close();
        $q=$db->prepare("INSERT INTO prospecto_embudo_subetapas
            (asignacion_id,subetapa,actualizado_por,actualizado_at,version) VALUES (?,?,?,UTC_TIMESTAMP(),1)
            ON DUPLICATE KEY UPDATE subetapa=VALUES(subetapa),actualizado_por=VALUES(actualizado_por),
                actualizado_at=VALUES(actualizado_at),version=version+1");
        $q->bind_param('isi',$id,$subetapa,$usuario);
        $q->execute();$q->close();
        $q=$db->prepare("INSERT INTO prospecto_embudo_subetapas_historial
            (asignacion_id,etapa_en_evento,subetapa_anterior,subetapa_nueva,motivo,origen,usuario_id,creado_at)
            VALUES (?,'SEGUIMIENTO',?,?,?,'PROXIMA_ACCION_V3',?,UTC_TIMESTAMP())");
        $q->bind_param('isssi',$id,$previa,$subetapa,$motivo,$usuario);
        $q->execute();$q->close();
        $q=$db->prepare("INSERT INTO prospecto_embudo_movimientos
            (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
            VALUES (?,?,'SEGUIMIENTO',?,?,UTC_TIMESTAMP())");
        $q->bind_param('issi',$id,$etapa,$motivo,$usuario);
        $q->execute();$q->close();
        return ['etapa'=>'SEGUIMIENTO','anterior'=>$previa,'subetapa'=>$subetapa];
    }

    public static function programar(mysqli $db, int $usuario, int $id, string $etapa,
        string $fechaUtc, string $motivo, string $situacion=''): array
    {
        $q=$db->prepare("SELECT fecha_pendiente FROM prospecto_embudo_actividad_v3
            WHERE asignacion_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->bind_param('i',$id);$q->execute();
        $anterior=$q->get_result()->fetch_assoc()['fecha_pendiente']??null;
        $q->close();
        $movimiento=self::promover($db,$usuario,$id,$etapa,self::subetapa($situacion),$motivo);
        $accion=$anterior===null?'PROGRAMAR':'REPROGRAMAR';
        $q=$db->prepare("INSERT INTO prospecto_embudo_actividad_v3
            (asignacion_id,usuario_id,etapa,accion,motivo,fecha_anterior,fecha_pendiente,
                completada_at,subetapa_anterior,subetapa_nueva,creado_at)
            VALUES (?,?,?,?,?,?,?,NULL,?,?,UTC_TIMESTAMP())");
        $q->bind_param('iisssssss',$id,$usuario,$movimiento['etapa'],$accion,$motivo,$anterior,
            $fechaUtc,$movimiento['anterior'],$movimiento['subetapa']);
        $q->execute();$q->close();
        return $movimiento;
    }
}
