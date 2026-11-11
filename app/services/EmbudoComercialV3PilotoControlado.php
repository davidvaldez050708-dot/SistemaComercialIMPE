<?php
require_once __DIR__.'/EmbudoComercialPlanificadorV3.php';
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Aplicación comercial V3 controlada, NUNCA conectada a Telefonía
 * salvo que un piloto explícito lo habilite para una lista de asesores.
 *
 * Invocada únicamente desde EmbudoComercialResultadoBridge después de
 * validar propiedad, número completo, cierre PBX y guardar la fila comercial,
 * DENTRO de la transacción comercial ya abierta por el llamador.
 *
 * No inicia/termina transacciones ni cambia resultados telefónicos.
 * La falta de evidencias, permisos o etapas conciliadas implica NO MOVER.
 * El formulario V4 y WebRTC no cambian.
 */
final class EmbudoComercialV3PilotoControlado
{
    private mysqli $db;
    private const ACTIVACION = 'AUTORIZADO_PILOTO_V3_TRES_INTENTOS_IMPE_20261111';

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    /**
     * Dos condiciones positivas y explícitas:
     *  1) token de activación exacto en configuración del SERVIDOR,
     *  2) asesor en lista cerrada; no admitir "*" ni IDs de navegador.
     * Por defecto siempre es FALSE. El indicador jamás lo habilita
     * un GET, POST, cookie o parámetro enviado desde el browser.
     */
    public static function habilitadoPara(int $usuarioId): bool
    {
        if ($usuarioId<=0 ||
            getenv('IMPE_VENTAS_V3_PILOTO')!==self::ACTIVACION) {
            return false;
        }
        $lista=getenv('IMPE_VENTAS_V3_PILOTO_ASESORES');
        if (!is_string($lista) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,19}$/D',$lista)) {
            return false;
        }
        foreach (explode(',',$lista) as $id) {
            // No aceptar alias, comodines ni números enteros fuera de rango.
            if (filter_var($id,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]])===$usuarioId) {
                return true;
            }
        }
        return false;
    }

    private static function respuesta(string $etapa,string $accion,int $intentos=0,
        ?string $subetapa=null,bool $movida=false): array
    {
        return [
            'vinculada'=>true,
            'movida'=>$movida,
            'etapa'=>$etapa,
            'v3_piloto'=>true,
            'accion_comercial'=>$accion,
            'intentos_jornada'=>$intentos,
            'subetapa'=>$subetapa
        ];
    }

    private function subetapaActual(int $asignacionId): ?string
    {
        $s=$this->db->prepare("SELECT subetapa
            FROM prospecto_embudo_subetapas WHERE asignacion_id=? FOR UPDATE");
        $s->bind_param('i',$asignacionId);
        $s->execute();
        $row=$s->get_result()->fetch_assoc();
        $s->close();
        return $row ? (string)$row['subetapa'] : null;
    }

    private function guardarSubetapa(int $asignacionId,int $usuarioId,
        string $etapa,?string $anterior,?string $nueva,string $accion): void
    {
        if ($nueva===null || $nueva===$anterior) return;
        if (!EmbudoComercialPoliticaPropuesta::subetapaValida($etapa,$nueva)) {
            throw new DomainException('Subetapa ajena a la etapa actual.');
        }
        // La migración aditiva V3 instala estas dos tablas sin tocar
        // telefonía ni los movimientos previos. Bloquear si falta alguna.
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_subetapas
                (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
             VALUES (?,?,?,UTC_TIMESTAMP(),1)
             ON DUPLICATE KEY UPDATE
                subetapa=VALUES(subetapa),
                actualizado_por=VALUES(actualizado_por),
                actualizado_at=VALUES(actualizado_at),
                version=version+1"
        );
        $s->bind_param('isi',$asignacionId,$nueva,$usuarioId);
        $s->execute();$s->close();
        $origen='PBX_V3_PILOTO';
        $motivo=mb_substr($accion,0,190);
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_subetapas_historial
                (asignacion_id,etapa_en_evento,subetapa_anterior,
                 subetapa_nueva,motivo,origen,usuario_id,creado_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())"
        );
        $s->bind_param('isssssi',$asignacionId,$etapa,$anterior,
            $nueva,$motivo,$origen,$usuarioId);
        $s->execute();$s->close();
    }

    /**
     * Impide declarar prospecto nunca contactado cuando existe una
     * conversación previa V4 o legacy acreditada en la asignación.
     *
     * No deduce contacto de ANSWER PBX ni de un mensaje enviado.
     * Una declaración explícita de resultado humano ya guardado sí exige
     * revisión comercial antes de Recuperación.
     */
    private function huboContactoComercial(int $usuarioId,int $asignacionId): bool
    {
        $sql="SELECT 1 FROM prospecto_embudo_llamadas l
            JOIN telefonia_ventas_resultados r
                ON r.usuario_id=l.usuario_id
                AND BINARY r.extension=BINARY l.extension
                AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                AND BINARY r.resultado=BINARY l.resultado
            WHERE l.asignacion_id=? AND l.usuario_id=?
              AND (
                r.resultado IN (
                    'CONVERSACION_PERSONA','PROSPECTO_POTENCIAL',
                    'INTERESADO','SOLICITA_INFORMACION',
                    'REQUIERE_SEGUIMIENTO','FUERA_PRESUPUESTO',
                    'NO_INTERESADO','NO_CUMPLE_REQUISITOS',
                    'NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA',
                    'INSCRITO','COMUNICACION_INTERRUMPIDA_PERSONA'
                )
                OR EXISTS (
                    SELECT 1 FROM telefonia_ventas_resultado_selecciones sel
                    WHERE sel.resultado_id=r.id
                      AND sel.codigo IN (
                        'CONVERSACION_PERSONA','PROSPECTO_POTENCIAL',
                        'REQUIERE_SEGUIMIENTO','FUERA_PRESUPUESTO',
                        'NO_INTERESADO','NO_CUMPLE_REQUISITOS',
                        'NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA',
                        'INSCRITO'
                      )
                )
                OR EXISTS (
                    SELECT 1 FROM telefonia_ventas_resultado_detalles d
                    WHERE d.resultado_id=r.id
                      AND d.contacto_antes_corte='SI'
                )
              )
            LIMIT 1";
        $st=$this->db->prepare($sql);
        $st->bind_param('ii',$asignacionId,$usuarioId);
        $st->execute();
        $found=$st->get_result()->fetch_assoc()!==null;
        $st->close();
        return $found;
    }

    private function nuevaRevisionLocal(string $diaLocal): string
    {
        $tz=new DateTimeZone('America/Mexico_City');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$diaLocal,$tz);
        if (!$d || $d->format('Y-m-d')!==$diaLocal) {
            throw new DomainException('Día de llamada no verificable.');
        }
        return $d->modify('+1 day')->format('Y-m-d');
    }

    /**
     * El Bridge ya mantiene un FOR UPDATE sobre la asignación y ha insertado
     * el vínculo comercial PBX en la misma transacción antes de este método.
     * No llamar directamente desde HTTP ni desde jobs del proveedor.
     */
    public function aplicarDesdeBridge(int $usuarioId,int $asignacionId,
        string $extension,string $referenciaPbx,string $etapaBloqueada): array
    {
        if (!self::habilitadoPara($usuarioId) ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3) {
            throw new DomainException('Piloto comercial no autorizado.');
        }

        $plan=(new EmbudoComercialPlanificadorV3($this->db))->planificar(
            $usuarioId,$asignacionId,$extension,$referenciaPbx
        );
        $intentos=is_array($plan['intentos']??null)
            ?(int)$plan['intentos']['total']:0;
        if (($plan['etapa_actual']??null)!==$etapaBloqueada) {
            throw new DomainException('La etapa cambió durante la llamada.');
        }
        $accion=(string)($plan['motivo']??'PENDIENTE');
        // Códigos históricos INTERESADO/PAGO_CONFIRMADO o clasificación
        // incompleta: preservar tanto la etapa como el historial, SIN inferir.
        if (($plan['modo']??null)!=='SIMULACION_SOLO_LECTURA' ||
            !empty($plan['requiere_revision']) ||
            !empty($plan['aplicable']) ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            return self::respuesta($etapaBloqueada,$accion,$intentos);
        }

        $propuesta=(string)($plan['etapa_propuesta']??'');
        $sub=(isset($plan['subetapa_propuesta']) &&
              is_string($plan['subetapa_propuesta']))
            ?$plan['subetapa_propuesta']:null;
        $catalogo=EmbudoComercialPoliticaPropuesta::ETAPAS_ACTIVAS+
                  EmbudoComercialPoliticaPropuesta::BANDEJAS;
        if (!isset($catalogo[$etapaBloqueada]) || !isset($catalogo[$propuesta])) {
            return self::respuesta($etapaBloqueada,
                'ETAPA_NO_CONCILIADA',$intentos);
        }
        $resultado=(string)($plan['resultado_interpretado']??'');
        $sinContacto=in_array($resultado,[
            'SIN_RESPUESTA','BUZON_VOZ','OCUPADO',
            'MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO',
            'COMUNICACION_INTERRUMPIDA'
        ],true);
        if ($sinContacto && $etapaBloqueada==='NUEVO' && $intentos<1) {
            return self::respuesta($etapaBloqueada,'PBX_SIN_CONTEO_VERIFICADO');
        }

        $movida=!empty($plan['mover']) && $propuesta!==$etapaBloqueada;
        $permitida=false;
        // Lista blanca de las transiciones automáticas de esta PRIMERA
        // integración. Potenciales y Proceso Inscripción requieren evidencia
        // de calificación y trámite que este servicio NO posee.
        if ($movida) {
            $permiteRecuperacion=($etapaBloqueada==='NUEVO' &&
                $propuesta==='RECUPERACION' && $sinContacto && $intentos>=3 &&
                tienePermiso('ventas.recuperacion.gestionar'));
            if ($permiteRecuperacion &&
                $this->huboContactoComercial($usuarioId,$asignacionId)) {
                return self::respuesta($etapaBloqueada,
                    'CONTACTO_ANTERIOR_REQUIERE_REVISION',$intentos);
            }
            $permitida=$permiteRecuperacion
                ||
                (in_array($etapaBloqueada,['NUEVO','RECUPERACION'],true) &&
                    $propuesta==='CONTACTADO' &&
                    $resultado==='CONVERSACION_PERSONA' &&
                    ($etapaBloqueada!=='RECUPERACION' ||
                        tienePermiso('ventas.recuperacion.gestionar')))
                ||
                ($etapaBloqueada!=='PROCESO_INSCRIPCION' &&
                    $propuesta==='DESCARTADO' &&
                    $resultado==='NO_INTERESADO' &&
                    tienePermiso('ventas.descartados.gestionar'));
            if (!$permitida) {
                return self::respuesta($etapaBloqueada,
                    'TRANSICION_REQUIERE_VALIDACION', $intentos);
            }
        }
        $previa=$this->subetapaActual($asignacionId);
        if ($movida) {
            $s=$this->db->prepare(
                "INSERT INTO prospecto_embudo_etapas
                    (asignacion_id,etapa,cambiado_por,actualizado_at)
                 VALUES (?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),
                    cambiado_por=VALUES(cambiado_por),
                    actualizado_at=VALUES(actualizado_at)"
            );
            $s->bind_param('isi',$asignacionId,$propuesta,$usuarioId);
            $s->execute();$s->close();

            if ($propuesta==='RECUPERACION') {
                // Al tercer intento validado: fecha calendario LOCAL del
                // evento original, no la fecha de captura posterior.
                $fecha=$this->nuevaRevisionLocal(
                    (string)$plan['intentos']['fecha']
                );
                $s=$this->db->prepare(
                    "INSERT INTO prospecto_embudo_agenda
                        (asignacion_id,proxima_accion_at,revision_fecha,
                         actualizado_at,actualizado_por)
                     VALUES (?,NULL,?,UTC_TIMESTAMP(),?)
                     ON DUPLICATE KEY UPDATE
                        revision_fecha=VALUES(revision_fecha),
                        actualizado_at=VALUES(actualizado_at),
                        actualizado_por=VALUES(actualizado_por)"
                );
                $s->bind_param('isi',$asignacionId,$fecha,$usuarioId);
                $s->execute();$s->close();
            }
            $razon=mb_substr(
                'Decisión comercial V3 PBX: '.$resultado.' · '.$referenciaPbx,
                0,190
            );
            $s=$this->db->prepare(
                "INSERT INTO prospecto_embudo_movimientos
                    (asignacion_id,etapa_anterior,etapa_nueva,motivo,
                     usuario_id,creado_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP())"
            );
            $s->bind_param('isssi',$asignacionId,$etapaBloqueada,
                $propuesta,$razon,$usuarioId);
            $s->execute();$s->close();
        }
        $destino=$movida?$propuesta:$etapaBloqueada;
        // No trasladar una subetapa de una etapa distinta: el catálogo
        // permite sólo subetapas del destino, y su historial queda claro.
        if ($sub!==null) {
            $this->guardarSubetapa($asignacionId,$usuarioId,
                $destino,$previa,$sub,$accion);
        }
        return self::respuesta($destino,$accion,$intentos,$sub,$movida);
    }
}
