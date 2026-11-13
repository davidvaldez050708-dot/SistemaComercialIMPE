<?php
require_once __DIR__.'/EmbudoComercialEtapas.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Puente opt-in a la llamada PBX YA validada por TelefoniaResultadoVentasService.
 *
 * Importante: no se vincula por nombres, últimos dígitos arbitrarios
 * ni datos de identificación del navegador. Requiere asignación explícita
 * y el número completo. La PBX mexicana puede entregar 10 dígitos nacionales
 * aunque el almacén conserve el mismo destino con +52; solo se permite
 * ese caso, exclusivamente si el país declarado es México.
 *
 * TelefoniaResultadoVentasService confirma y guarda primero el resultado PBX.
 * Este puente opera dentro de una transacción comercial separada,
 * sin modificar el resultado telefónico ante errores del embudo.
 */
final class EmbudoComercialResultadoBridge
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    /**
     * La pantalla V3 no habilita movimientos PBX automáticos hacia Recuperación.
     * La política de tres intentos solo propone candidatos a revisión: todavía
     * no está autorizada como una escritura automática.
     * Los asesores sin V3 mantienen la ruta telefónica anterior.
     */
    public static function bloquearRecuperacionAutomaticaV3(
        string $etapaPropuesta, bool $asesorV3, string $etapaActual=''): bool
    {
        return $asesorV3 &&
            ($etapaPropuesta==='RECUPERACION' ||
             ($etapaActual==='RECUPERACION' && $etapaPropuesta==='CONTACTADO'));
    }

    public static function numeroCanonico(string $num): string
    {
        // Rechazar extensiones y números demasiado breves; nunca usar últimos 10.
        $digits=preg_replace('/\D+/', '',$num);
        return strlen($digits)>=10 && strlen($digits)<=15 ? $digits : '';
    }

    /**
     * Comparación estricta para una asignación YA autorizada y un resultado PBX
     * definitivo. La única equivalencia adicional aceptada es:
     * "+52" + 10 dígitos (prospecto mexicano) == 10 dígitos (PBX).
     *
     * No se aceptan sufijos, números de otras naciones, números ambiguos,
     * extensiones, tramos truncados ni números con letras.
     */
    public static function mismoNumeroAsignado(string $normalizado,
        string $numeroPbx,?string $pais): bool
    {
        $guardado=self::numeroCanonico($normalizado);
        $marcado=self::numeroCanonico($numeroPbx);
        if ($guardado==='' || $marcado==='') return false;
        if (hash_equals($guardado,$marcado)) return true;

        $pais=mb_strtolower(trim((string)$pais),'UTF-8');
        if (!in_array($pais,['méxico','mexico'],true) ||
            !preg_match('/^\\+52[0-9]{10}$/D',trim($normalizado)) ||
            !preg_match('/^[0-9 ()\\.\\-]+$/D',trim($numeroPbx)) ||
            strlen($marcado)!==10) {
            return false;
        }
        return hash_equals(substr($guardado,2),$marcado);
    }

    public function aplicar(int $usuarioId,int $asignacionId,
        array $call,string $resultado,?string $proximaAccion,
        bool $cierreApiVerificado=false): array
    {
        if ($usuarioId<1 || $asignacionId<1 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3) {
            throw new DomainException('No se puede vincular esa llamada a un prospecto.');
        }
        $ext=(string)($call['extension']??'');
        $pbx=(string)($call['pbx_call_id']??'');
        // Cierre saliente acreditado por API: no es un evento NOTIFY_OUT_END.
        $finalizada = !empty($call['finalizada']) ||
            ($cierreApiVerificado &&
             ($call['direccion']??'')==='Saliente' &&
             preg_match('/^out_[A-Za-z0-9_-]{6,100}$/D',$pbx));
        if (!preg_match('/^\d{3,6}$/D',$ext) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx) ||
            !$finalizada) {
            throw new DomainException('No se ha confirmado una llamada PBX válida.');
        }
        $s=$this->db->prepare(
            "SELECT a.asesor_id,a.vigente,
                    p.telefono_normalizado,p.pais_nombre,
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
        if (!self::mismoNumeroAsignado(
            (string)$row['telefono_normalizado'],
            (string)($call['numero']??''),
            (string)($row['pais_nombre']??'')
        )) {
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
        require_once __DIR__.'/EmbudoComercialV3AccesoControlado.php';
        $v3=EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuarioId);
        if ($v3) {
            require_once __DIR__.'/EmbudoComercialV3MovimientosPermitidos.php';
            $nueva=EmbudoComercialV3MovimientosPermitidos::destinoPorConversacion(
                $etapaAnterior,$resultado
            );
        }
        // Proteger etapas avanzadas e históricos de la matriz V1: una
        // llamada no degrada Potenciales a INTERESADO, ni Seguimiento a una
        // gestión menos avanzada; una inscripción nunca se confirma aquí.
        // La llamada sigue vinculada y su resultado permanece intacto.
        if ($nueva===null || in_array($etapaAnterior,[
            'POTENCIAL','INTERESADO','PROCESO_INSCRIPCION',
            'PAGO_CONFIRMADO','DESCARTADO'
        ],true)) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }
        // Una conversación sin manifestar interés NO disminuye una etapa avanzada.
        if ($nueva==='CONTACTADO' && !in_array($etapaAnterior,['NUEVO','RECUPERACION'],true)) {
            return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior];
        }
        // En SEGUIMIENTO una conversación calificada no debe promoverse
        // automáticamente a un código INTERESADO legado. La V3 requerirá
        // evidencia comercial confirmada antes de avanzar a Potenciales.
        if ($etapaAnterior==='SEGUIMIENTO' && $nueva==='INTERESADO') {
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
        if (!$v3) {
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
        }
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
