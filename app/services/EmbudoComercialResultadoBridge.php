<?php
require_once __DIR__.'/EmbudoComercialEtapas.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Puente opt-in a la llamada PBX YA validada por TelefoniaResultadoVentasService.
 *
 * Importante: no se vincula por coincidencias heurísticas, nombres, últimos
 * dígitos ni datos del navegador. Solo se utiliza una asignación explícita
 * del asesor y coincidencia telefónica exacta tras quitar separadores.
 *
 * Este método corre DENTRO de la transacción del resultado telefónico.
 * Nunca llama begin_transaction, commit ni rollback.
 */
final class EmbudoComercialResultadoBridge
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    public static function numeroCanonico(string $num): string
    {
        // Rechazar extensiones y números demasiado breves; nunca usar últimos 10.
        $digits=preg_replace('/\D+/', '',$num);
        return strlen($digits)>=10 && strlen($digits)<=15 ? $digits : '';
    }

    public function aplicar(int $usuarioId,int $asignacionId,
        array $call,string $resultado,?string $proximaAccion): array
    {
        if ($usuarioId<1 || $asignacionId<1 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3) {
            throw new DomainException('No se puede vincular esa llamada a un prospecto.');
        }
        $ext=(string)($call['extension']??'');
        $pbx=(string)($call['pbx_call_id']??'');
        if (!preg_match('/^\d{3,6}$/D',$ext) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx) ||
            empty($call['finalizada'])) {
            throw new DomainException('No se ha confirmado una llamada PBX válida.');
        }
        $s=$this->db->prepare(
            "SELECT a.asesor_id,a.vigente,
                    p.telefono_normalizado,
                    COALESCE(e.etapa,'NUEVO') etapa
             FROM prospecto_asignaciones a
             JOIN prospectos_comerciales p ON p.id=a.prospecto_id
             LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
             WHERE a.id=? FOR UPDATE"
        );
        $s->bind_param('i',$asignacionId);
        $s->execute();
        $row=$s->get_result()->fetch_assoc();
        $s->close();
        if (!$row || (int)$row['asesor_id']!==$usuarioId ||
            (int)$row['vigente']!==1) {
            throw new DomainException('La asignación ya no pertenece a tu usuario.');
        }
        $telProspecto=self::numeroCanonico((string)$row['telefono_normalizado']);
        $telLlamada=self::numeroCanonico((string)($call['numero']??''));
        if ($telProspecto==='' || $telLlamada==='' || !hash_equals($telProspecto,$telLlamada)) {
            throw new DomainException(
                'El número PBX no coincide exactamente con el del prospecto asignado. ' .
                'El resultado no se vinculó al embudo.'
            );
        }
        // No reatribuir un tramo comercial ya vinculado.
        $s=$this->db->prepare(
            'INSERT INTO prospecto_embudo_llamadas
                (asignacion_id,usuario_id,extension,pbx_call_id,resultado,creado_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $s->bind_param('iisss',$asignacionId,$usuarioId,$ext,$pbx,$resultado);
        try {
            $s->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode()===1062) {
                throw new DomainException('Ese tramo telefónico ya fue vinculado a un prospecto.');
            }
            throw $e;
        } finally {
            $s->close();
        }
        $etapaAnterior=EmbudoComercialEtapas::visual((string)$row['etapa']);
        $nueva=EmbudoComercialEtapas::sugerenciaPorResultado($resultado,$proximaAccion);
        if ($nueva===null || in_array($etapaAnterior,['PAGO_CONFIRMADO','DESCARTADO'],true)) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }
        // Una conversación sin manifestar interés NO disminuye una etapa avanzada.
        if ($nueva==='CONTACTADO' && !in_array($etapaAnterior,['NUEVO','RECUPERACION'],true)) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }
        if ($nueva===$etapaAnterior) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }
        // Nunca promocionar con base exclusivamente en ANSWERED de Zadarma:
        // el servicio de resultados ya validó el resultado humano y conexión.
        if (!tienePermiso('ventas.embudo.mover') ||
            ($nueva==='RECUPERACION' && !tienePermiso('ventas.recuperacion.gestionar')) ||
            !tienePermiso('ventas.embudo.ver')) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }

        $utc=null;
        if ($nueva==='SEGUIMIENTO') {
            $zone=new DateTimeZone('America/Mexico_City');
            $fecha=DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',(string)$proximaAccion,$zone
            );
            if (!$fecha) {
                throw new DomainException('Seguimiento sin fecha telefónica válida.');
            }
            $utc=$fecha->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $revision=$nueva==='RECUPERACION'
            ?(new DateTimeImmutable('tomorrow',new DateTimeZone('America/Mexico_City')))
                ->format('Y-m-d')
            :null;
        $s=$this->db->prepare(
            'INSERT INTO prospecto_embudo_etapas
                (asignacion_id,etapa,cambiado_por,actualizado_at)
             VALUES (?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),
                cambiado_por=VALUES(cambiado_por),actualizado_at=VALUES(actualizado_at)'
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
        $s->bind_param('issi',$asignacionId,$utc,$revision,$usuarioId);
        $s->execute();$s->close();
        $razon='Resultado PBX verificado: '.$resultado.' · ref '.$pbx;
        $razon=mb_substr($razon,0,190);
        $s=$this->db->prepare(
            'INSERT INTO prospecto_embudo_movimientos
                (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $anteriorReal=(string)$row['etapa'];
        $s->bind_param('isssi',$asignacionId,$anteriorReal,$nueva,$razon,$usuarioId);
        $s->execute();$s->close();
        return [
            'vinculada'=>true,
            'movida'=>true,
            'etapa'=>$nueva,
            'revision_fecha'=>$revision,
            'proxima_accion_at'=>$utc
        ];
    }
}
