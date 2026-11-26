<?php
require_once __DIR__.'/EmbudoComercialIntentosPbxRepository.php';
require_once __DIR__.'/../helpers/PermissionHelper.php';
require_once __DIR__.'/EmbudoComercialV3MovimientosPermitidos.php';
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';

/**
 * Bandejas V3 con decisiones acreditadas y recuperación tras tres intentos PBX.
 * No crear ni desactivar contacto, envío de campañas, tareas o pago.
 */
final class EmbudoComercialV3BandejasService
{
    private mysqli $db;
    public const DESCARTES_HABILITADOS = [
        'NO_INTERESADO'=>'El prospecto manifestó no estar interesado',
        'NUMERO_INVALIDO'=>'Número inexistente acreditado por PBX',
        'NO_CONTACTAR'=>'El prospecto solicitó expresamente no volver a ser contactado'
    ];

    public function __construct(mysqli $db) { $this->db=$db; }

    private static function autorizado(int $usuario, int $asignacion, string $permiso): void
    {
        if ($usuario<1 || $asignacion<1 ||
            (int)($_SESSION['usuario_id']??0)!==$usuario ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover') ||
            !tienePermiso($permiso) ||
            !tienePermiso($permiso==='ventas.recuperacion.gestionar'
                ?'ventas.recuperacion.ver':'ventas.descartados.ver')) {
            throw new DomainException('No tienes permiso para gestionar esta bandeja.');
        }
    }

    private static function motivo(string $motivo): string
    {
        $motivo=trim($motivo);
        if (mb_strlen($motivo)<8 || mb_strlen($motivo)>125) {
            throw new DomainException('Describe el motivo (8 a 125 caracteres).');
        }
        return $motivo;
    }

    private function etapaBloqueada(int $usuario,int $asignacion): string
    {
        $s=$this->db->prepare(
            "SELECT a.asesor_id,a.vigente,COALESCE(e.etapa,'NUEVO') etapa
             FROM prospecto_asignaciones a
             LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
             WHERE a.id=? FOR UPDATE");
        try {
            $s->bind_param('i',$asignacion);
            $s->execute();
            $r=$s->get_result()->fetch_assoc();
        } finally {$s->close();}
        if (!$r || (int)$r['asesor_id']!==$usuario ||
            (int)$r['vigente']!==1) {
            throw new DomainException('La asignación ya no está vigente o no te pertenece.');
        }
        return (string)$r['etapa'];
    }

    /**
     * Defensa de la propia bandeja. NO sustituye el bloqueo general de
     * consentimiento en Zadarma, WhatsApp, SMS ni las importaciones.
     */
    private function exigirContactoPermitido(int $asignacion): void
    {
        $s=$this->db->prepare(
            'SELECT subetapa FROM prospecto_embudo_subetapas WHERE asignacion_id=? FOR UPDATE');
        try {
            $s->bind_param('i',$asignacion);
            $s->execute();
            $sub=(string)($s->get_result()->fetch_assoc()['subetapa']??'');
        } finally {$s->close();}
        if ($sub==='NO_CONTACTAR') {
            throw new DomainException('Esta asignación tiene restricción No contactar; requiere revisión autorizada.');
        }
    }

    public static function validarJornada(string $dia): string
    {
        $tz=new DateTimeZone('America/Mexico_City');
        $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$dia,$tz);
        $hoy=new DateTimeImmutable('today',$tz);
        if (!$fecha || $fecha->format('Y-m-d')!==$dia ||
            $fecha>$hoy || $fecha<$hoy->modify('-7 days')) {
            throw new DomainException('Selecciona una jornada acreditable de los últimos siete días.');
        }
        return $dia;
    }

    private function verificarDescarte(int $usuario,int $asignacion,string $subetapa): void
    {
        if ($subetapa==='NO_CONTACTAR') {
            // Requiere una conversación humana atribuida exactamente a la
            // asignación y confirmación expresa, no "No interesado" genérico.
            if (!EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
                $this->db,$usuario,$asignacion
            )) {
                throw new DomainException(
                    'Para registrar No contactar se requiere una conversación PBX acreditada de esta asignación.');
            }
            return;
        }
        $evidencia=[
            'NO_INTERESADO'=>['NO_INTERESADO'],
            'NUMERO_INVALIDO'=>['NUMERO_INEXISTENTE'],
        ][$subetapa]??[];
        if (!$evidencia) {
            throw new DomainException('Ese motivo todavía no admite cierre sin evidencia institucional.');
        }
        $marcas=implode(',',array_fill(0,count($evidencia),'?'));
        // Tres registros enlazados con identidad PBX exacta. No atribuir
        // resultados del teléfono de otra asignación ni del usuario ajeno.
        $s=$this->db->prepare(
            "SELECT 1 FROM prospecto_embudo_llamadas l
             JOIN telefonia_ventas_llamadas c ON c.usuario_id=l.usuario_id
               AND BINARY c.extension=BINARY l.extension
               AND BINARY c.pbx_call_id=BINARY l.pbx_call_id
               AND c.direccion='Saliente'
             JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
               AND BINARY r.extension=BINARY l.extension
               AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
               AND BINARY r.resultado=BINARY l.resultado
             WHERE l.usuario_id=? AND l.asignacion_id=?
               AND r.resultado IN ($marcas)
             LIMIT 1");
        try {
            $args=array_merge([$usuario,$asignacion],$evidencia);
            $s->bind_param('ii'.str_repeat('s',count($evidencia)),...$args);
            $s->execute();
            $acreditada=(bool)$s->get_result()->fetch_assoc();
        } finally {$s->close();}
        if (!$acreditada) {
            throw new DomainException('No existe resultado PBX acreditado para ese motivo de descarte.');
        }
    }

    /**
     * La reactivación exige una conversación PBX cuyo evento ocurrió DESPUÉS
     * del último ingreso auditado en Recuperación, no una llamada histórica.
     * telefonia_ventas_llamadas.fecha es hora local de Ciudad de México;
     * el movimiento auditado está en UTC.
     */
    private function registrarEtapa(int $usuario,int $asignacion,string $anterior,
        string $nueva,string $subNueva,string $razon): int
    {
        $s=$this->db->prepare(
            "SELECT subetapa FROM prospecto_embudo_subetapas
             WHERE asignacion_id=? FOR UPDATE");
        $s->bind_param('i',$asignacion);
        $s->execute();
        $previa=$s->get_result()->fetch_assoc()['subetapa']??null;
        $s->close();

        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_etapas
                 (asignacion_id,etapa,cambiado_por,actualizado_at)
             VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
                 etapa=VALUES(etapa),cambiado_por=VALUES(cambiado_por),
                 actualizado_at=VALUES(actualizado_at)");
        $s->bind_param('isi',$asignacion,$nueva,$usuario);
        $s->execute();$s->close();
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_subetapas
                 (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
             VALUES (?,?,?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE
                 subetapa=VALUES(subetapa),actualizado_por=VALUES(actualizado_por),
                 actualizado_at=VALUES(actualizado_at),version=version+1");
        $s->bind_param('isi',$asignacion,$subNueva,$usuario);
        $s->execute();$s->close();
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_subetapas_historial
                (asignacion_id,etapa_en_evento,subetapa_anterior,subetapa_nueva,motivo,
                 origen,usuario_id,creado_at)
             VALUES (?,?,?,?,?,'ASESOR_V3',?,UTC_TIMESTAMP())");
        $s->bind_param('issssi',$asignacion,$nueva,$previa,$subNueva,$razon,$usuario);
        $s->execute();$s->close();
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_movimientos
                (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
        $s->bind_param('isssi',$asignacion,$anterior,$nueva,$razon,$usuario);
        $s->execute();
        $id=(int)$this->db->insert_id;
        $s->close();
        return $id;
    }

    private function revision(int $usuario,int $asignacion,?string $fecha): void
    {
        // NO sobrescribir ag.proxima_accion_at: es la agenda legacy.
        $s=$this->db->prepare(
            "INSERT INTO prospecto_embudo_agenda
               (asignacion_id,proxima_accion_at,revision_fecha,actualizado_at,actualizado_por)
             VALUES (?,NULL,?,UTC_TIMESTAMP(),?)
             ON DUPLICATE KEY UPDATE revision_fecha=VALUES(revision_fecha),
               actualizado_at=VALUES(actualizado_at),
               actualizado_por=VALUES(actualizado_por)");
        $s->bind_param('isi',$asignacion,$fecha,$usuario);
        $s->execute();$s->close();
    }

    /** Se ejecuta dentro de la transacción del puente, con la asignación bloqueada. */
    public function recuperarTrasLlamada(int $usuario,int $asignacion,string $dia,string $tramo): ?array
    {
        if (!tienePermiso('ventas.embudo.ver') || !tienePermiso('ventas.embudo.mover') ||
            !tienePermiso('ventas.recuperacion.ver') || !tienePermiso('ventas.recuperacion.gestionar')) return null;
        $zona=new DateTimeZone('America/Mexico_City');
        $hoy=new DateTimeImmutable('today',$zona);
        if ($dia!==$hoy->format('Y-m-d')) return null;
        $intentos=(new EmbudoComercialIntentosPbxRepository($this->db))
            ->resumenJornada($usuario,$asignacion,$dia,null,null,$tramo);
        if (!$intentos['completo'] || EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
            $this->db,$usuario,$asignacion
        )) return null;
        if ($this->etapaBloqueada($usuario,$asignacion)!=='NUEVO') return null;
        try {
            $this->exigirContactoPermitido($asignacion);
        } catch (DomainException $restriccion) {
            return null;
        }
        $id=$this->registrarEtapa($usuario,$asignacion,'NUEVO','RECUPERACION',
            'PENDIENTE_REVISION','Ingreso automático: tres llamadas PBX distintas sin contacto ('.$dia.').');
        $revision=$hoy->modify('+1 day')->format('Y-m-d');
        $this->revision($usuario,$asignacion,$revision);
        return ['movimiento_id'=>$id,'revision_fecha'=>$revision];
    }

    public function ingresarRecuperacion(int $usuario,int $asignacion,string $etapa,
        string $dia,string $motivo,bool $confirmacion): array
    {
        self::autorizado($usuario,$asignacion,'ventas.recuperacion.gestionar');
        $motivo=self::motivo($motivo);
        $dia=self::validarJornada($dia);
        if (!$confirmacion || $etapa!=='NUEVO') {
            throw new DomainException('La revisión requiere confirmación y un prospecto en Nuevos.');
        }
        $this->db->begin_transaction();
        try {
            if ($this->etapaBloqueada($usuario,$asignacion)!=='NUEVO') {
                throw new DomainException('La etapa cambió. Actualiza el tablero.');
            }
            $this->exigirContactoPermitido($asignacion);
            $intentos=(new EmbudoComercialIntentosPbxRepository($this->db))
                ->resumenJornada($usuario,$asignacion,$dia);
            if ((int)$intentos['total']<3) {
                throw new DomainException('No existen tres intentos PBX distintos sin contacto en esa jornada.');
            }
            if (EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
                $this->db,$usuario,$asignacion
            )) {
                throw new DomainException('Existe conversación acreditada; revisa primero la calificación.');
            }
            $razon=mb_substr('Revisión manual de 3 intentos PBX ('.
                $dia.'): '.$motivo,0,190);
            $id=$this->registrarEtapa($usuario,$asignacion,
                'NUEVO','RECUPERACION','PENDIENTE_REVISION',$razon);
            $revision=(new DateTimeImmutable('tomorrow',
                new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
            $this->revision($usuario,$asignacion,$revision);
            $this->db->commit();
            return ['movimiento_id'=>$id,'etapa'=>'RECUPERACION',
                'revision_fecha'=>$revision,'intentos_confirmados'=>$intentos['total']];
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
    }

    /** El puente ya vinculó la llamada y mantiene bloqueada la asignación. */
    public function descartarTrasLlamada(int $usuario,int $asignacion,string $etapa): ?array
    {
        if (!in_array($etapa,['NUEVO','CONTACTADO','SEGUIMIENTO','POTENCIAL','RECUPERACION'],true)) return null;
        try {
            self::autorizado($usuario,$asignacion,'ventas.descartados.gestionar');
            $this->exigirContactoPermitido($asignacion);
            $this->verificarDescarte($usuario,$asignacion,'NO_INTERESADO');
        } catch (DomainException $e) {
            return null;
        }
        return ['movimiento_id'=>$this->registrarEtapa($usuario,$asignacion,$etapa,
            'DESCARTADO','NO_INTERESADO','Descarte automático: No interesado en llamada PBX vinculada.')];
    }

    public function descartar(int $usuario,int $asignacion,string $etapa,
        string $subetapa,string $motivo,bool $confirmacion,
        bool $solicitudExpresaNoContactar=false): array
    {
        self::autorizado($usuario,$asignacion,'ventas.descartados.gestionar');
        $motivo=self::motivo($motivo);
        $whatsapp=$subetapa==='NO_INTERESADO_WHATSAPP';
        if ($whatsapp) {
            if (!preg_match('/^(20[0-9]{2})-([0-9]{2})-([0-9]{2}) +[^\s].{5,}$/uD',$motivo,$fecha) ||
                !checkdate((int)$fecha[2],(int)$fecha[3],(int)$fecha[1]) ||
                substr($motivo,0,10)>(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d')) {
                throw new DomainException('Indica la fecha del mensaje (AAAA-MM-DD), seguida de una referencia verificable.');
            }
            $subetapa='NO_INTERESADO';
        }
        if (!$confirmacion || !isset(self::DESCARTES_HABILITADOS[$subetapa]) ||
            ($subetapa==='NO_CONTACTAR' && !$solicitudExpresaNoContactar) ||
            !in_array($etapa,['NUEVO','CONTACTADO','SEGUIMIENTO',
                'POTENCIAL','RECUPERACION'],true)) {
            throw new DomainException('El descarte requiere un motivo acreditado y confirmación expresa.');
        }
        $this->db->begin_transaction();
        try {
            if ($this->etapaBloqueada($usuario,$asignacion)!==$etapa) {
                throw new DomainException('La etapa cambió. Actualiza el tablero.');
            }
            $this->exigirContactoPermitido($asignacion);
            if (!$whatsapp) $this->verificarDescarte($usuario,$asignacion,$subetapa);
            $razon=mb_substr(
                ($subetapa==='NO_CONTACTAR'
                    ?'Solicitud expresa No contactar, asesor confirma evidencia PBX: '
                    :($whatsapp?'Respuesta recibida por WhatsApp, declarada por el asesor: '
                        :'Descarte verificado ('.$subetapa.'): ')).$motivo,0,190);
            $id=$this->registrarEtapa($usuario,$asignacion,
                $etapa,'DESCARTADO',$subetapa,$razon);
            // Conservar la agenda y las actividades V3 históricas: el
            // cierre solo marca la etapa, nunca suprime resultados ni tareas.
            $this->db->commit();
            return ['movimiento_id'=>$id,'etapa'=>'DESCARTADO','subetapa'=>$subetapa];
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
    }

    public function devolverRecuperacionesVencidas(int $usuario): void
    {
        if (!tienePermiso('ventas.embudo.mover') || !tienePermiso('ventas.recuperacion.ver') ||
            !tienePermiso('ventas.recuperacion.gestionar')) return;
        self::autorizado($usuario,1,'ventas.recuperacion.gestionar');
        $hoy=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $sql="SELECT a.id FROM prospecto_asignaciones a
            JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id AND e.etapa='RECUPERACION'
            JOIN prospecto_embudo_agenda ag ON ag.asignacion_id=a.id AND ag.revision_fecha<=?
            JOIN prospecto_embudo_subetapas st ON st.asignacion_id=a.id AND st.subetapa='PENDIENTE_REVISION'
            WHERE a.asesor_id=? AND a.vigente=1
            AND EXISTS (SELECT 1 FROM prospecto_embudo_subetapas_historial h
                WHERE h.asignacion_id=a.id AND h.etapa_en_evento='RECUPERACION'
                  AND h.subetapa_nueva='PENDIENTE_REVISION' AND h.origen='ASESOR_V3')
            ORDER BY a.id";
        $s=$this->db->prepare($sql);
        $s->bind_param('si',$hoy,$usuario);
        $s->execute();
        $ids=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'id');
        $s->close();
        foreach ($ids as $id) {
            $id=(int)$id;
            $this->db->begin_transaction();
            try {
                if ($this->etapaBloqueada($usuario,$id)==='RECUPERACION') {
                    // Revalidar fecha y subetapa sólo para la ficha bloqueada.
                    $s=$this->db->prepare("SELECT ag.revision_fecha,st.subetapa FROM prospecto_embudo_agenda ag
                        JOIN prospecto_embudo_subetapas st ON st.asignacion_id=ag.asignacion_id
                        WHERE ag.asignacion_id=? FOR UPDATE");
                    $s->bind_param('i',$id);
                    $s->execute();
                    $fila=$s->get_result()->fetch_assoc();
                    $s->close();
                    if (($fila['subetapa']??'')==='PENDIENTE_REVISION' &&
                        !empty($fila['revision_fecha']) && $fila['revision_fecha']<=$hoy) {
                        $this->registrarEtapa($usuario,$id,'RECUPERACION','NUEVO','SIN_GESTION',
                            'Regreso automático a Nuevos al llegar la fecha de revisión.');
                        $this->revision($usuario,$id,null);
                    }
                }
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        }
    }

    public function reactivar(int $usuario,int $asignacion,string $etapa,
        string $motivo,bool $confirmacion): array
    {
        self::autorizado($usuario,$asignacion,'ventas.recuperacion.gestionar');
        throw new DomainException('La ficha volverá a Nuevos al llegar su fecha de revisión.');
    }
}
