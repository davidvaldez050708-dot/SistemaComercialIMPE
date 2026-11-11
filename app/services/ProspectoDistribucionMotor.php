<?php
require_once __DIR__.'/ProspectoDistribucionReglas.php';

/**
 * Distribución central: nunca toca telefonía, agenda, embudo ni formularios.
 * Sólo inserta en prospecto_asignaciones y tablas de auditoría nuevas.
 * Todos los cambios requieren esquema instalado y GET_LOCK global.
 */
final class ProspectoDistribucionMotor
{
    private mysqli $db;
    private const LOCK_NAME='impe_prospectos_distribucion_v1';
    private const REQUIRED=[
        'prospecto_distribucion_control','prospecto_distribucion_meta_cursor',
        'prospectos_comerciales','prospecto_origenes','prospecto_intereses',
        'prospecto_asignaciones','prospecto_asesores_config',
        'prospecto_asesores_programas','prospecto_asesores_horarios',
        'prospecto_meta_lotes_confirmados','prospecto_distribucion_registros',
        'prospecto_distribucion_pendientes','prospecto_distribucion_corridas'
    ];

    public function __construct(mysqli $db) {$this->db=$db;}

    public function disponible(): bool
    {
        $rs=$this->consulta(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()'
        )->fetch_all(MYSQLI_ASSOC);
        $names=array_column($rs,'TABLE_NAME');
        return !array_diff(self::REQUIRED,$names);
    }

    private function consulta(string $sql,array $params=[]): mysqli_result
    {
        $s=$this->db->prepare($sql);
        if ($params) {
            $types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$params));
            $s->bind_param($types,...$params);
        }
        $s->execute();
        $rs=$s->get_result();
        $s->close();
        if (!$rs) throw new RuntimeException('Consulta de distribución inválida.');
        return $rs;
    }

    private function ejecutar(string $sql,array $params=[]): int
    {
        $s=$this->db->prepare($sql);
        if ($params) {
            $types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$params));
            $s->bind_param($types,...$params);
        }
        $s->execute();
        $id=(int)$this->db->insert_id;
        $s->close();
        return $id;
    }

    public function habilitado(string $tipo): bool
    {
        $this->exigirEsquema();
        if (!in_array($tipo,['META','ALIADO'],true)) throw new DomainException('Motor no válido.');
        $campo=$tipo==='META'?'meta_habilitado':'aliados_habilitado';
        $fila=$this->consulta('SELECT '.$campo.' activo FROM prospecto_distribucion_control WHERE id=1')->fetch_assoc();
        return (int)($fila['activo']??0)===1;
    }

    private function exigirEsquema(): void
    {
        if (!$this->disponible()) throw new RuntimeException(
            'Estructura de distribución no instalada; no se asignó ningún prospecto.'
        );
    }

    private function lock(): void
    {
        $row=$this->consulta('SELECT GET_LOCK(?,5) conseguido',[self::LOCK_NAME])->fetch_assoc();
        if ((int)($row['conseguido']??0)!==1) throw new RuntimeException(
            'El motor de asignación está ocupado; se puede reintentar.'
        );
    }

    private function unlock(): void
    {
        $this->consulta('SELECT RELEASE_LOCK(?) liberado',[self::LOCK_NAME]);
    }

    /** El importador llama sólo DESPUÉS de confirmar TODOS los lotes. */
    public function confirmarLoteMeta(string $token,int $usuarioId,int $nuevos): void
    {
        $this->exigirEsquema();
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)||$usuarioId<1||$nuevos<0) {
            throw new DomainException('Lote Meta inválido.');
        }
        $this->ejecutar(
            'INSERT IGNORE INTO prospecto_meta_lotes_confirmados
             (lote_ref,confirmado_at,nuevos,importado_por)
             VALUES (?,UTC_TIMESTAMP(),?,?)',
            [$token,$nuevos,$usuarioId]
        );
    }

    private function asesorProgramado(string $programa,int $dia,?string $fechaMeta): array
    {
        $rows=$this->consulta(
            "SELECT c.usuario_id,h.dia_semana,h.hora_desde,h.hora_hasta,
              (SELECT COUNT(*) FROM prospecto_distribucion_registros d
               WHERE d.asesor_id=c.usuario_id AND d.tipo_origen='META'
                     AND d.fecha_jornada=?) meta_dia,
              (SELECT COUNT(*) FROM prospecto_asignaciones a
               WHERE a.asesor_id=c.usuario_id AND a.vigente=1) total_activos
             FROM prospecto_asesores_config c
             JOIN usuarios u ON u.id=c.usuario_id AND u.estado=1
             JOIN roles r ON r.id=u.rol_id AND r.nombre='Asesor de Ventas' AND r.estado=1
             JOIN prospecto_asesores_programas ap
               ON ap.usuario_id=c.usuario_id AND ap.programa_codigo=?
             JOIN formulario_programas fp ON fp.codigo=ap.programa_codigo AND fp.activo=1
             JOIN prospecto_asesores_horarios h ON h.usuario_id=c.usuario_id
             WHERE c.activo=1 AND h.dia_semana=?
             ORDER BY c.usuario_id",
            [$fechaMeta??'1000-01-01',$programa,$dia]
        )->fetch_all(MYSQLI_ASSOC);
        return $rows;
    }

    private function horariosAliado(string $programa): array
    {
        $rows=$this->consulta(
            "SELECT c.usuario_id,h.dia_semana,h.hora_desde,h.hora_hasta,
               (SELECT COUNT(*) FROM prospecto_asignaciones a
                WHERE a.asesor_id=c.usuario_id AND a.vigente=1) total_activos
             FROM prospecto_asesores_config c
             JOIN usuarios u ON u.id=c.usuario_id AND u.estado=1
             JOIN roles r ON r.id=u.rol_id AND r.nombre='Asesor de Ventas' AND r.estado=1
             JOIN prospecto_asesores_programas ap ON ap.usuario_id=c.usuario_id AND ap.programa_codigo=?
             JOIN formulario_programas fp ON fp.codigo=ap.programa_codigo AND fp.activo=1
             JOIN prospecto_asesores_horarios h ON h.usuario_id=c.usuario_id
             WHERE c.activo=1 ORDER BY c.usuario_id,h.dia_semana",
            [$programa]
        )->fetch_all(MYSQLI_ASSOC);
        $out=[];
        foreach ($rows as $r) {
            $id=(int)$r['usuario_id'];
            if (!isset($out[$id])) $out[$id]=[
                'usuario_id'=>$id,'total_activos'=>(int)$r['total_activos'],'horarios'=>[]
            ];
            $out[$id]['horarios'][(int)$r['dia_semana']]=[
                'desde'=>substr($r['hora_desde'],0,5),
                'hasta'=>substr($r['hora_hasta'],0,5)
            ];
        }
        return array_values($out);
    }

    private function programaOrigen(int $origenId): array
    {
        // El programa siempre procede del formulario/origen confirmado.
        $filas=$this->consulta(
            "SELECT DISTINCT programa_codigo FROM prospecto_intereses
             WHERE origen_id=? AND programa_codigo IS NOT NULL AND programa_codigo<>''",
            [$origenId]
        )->fetch_all(MYSQLI_ASSOC);
        return array_column($filas,'programa_codigo');
    }

    private function programaAliado(int $prospectoId): array
    {
        $filas=$this->consulta(
            "SELECT DISTINCT i.programa_codigo FROM prospecto_intereses i
             JOIN prospecto_origenes o ON o.id=i.origen_id
             WHERE o.prospecto_id=? AND i.programa_codigo IS NOT NULL
               AND i.programa_codigo<>'' AND o.tipo_origen='ALIADO'",
            [$prospectoId]
        )->fetch_all(MYSQLI_ASSOC);
        return array_column($filas,'programa_codigo');
    }

    private function identidadEnRevision(int $id): bool
    {
        require_once __DIR__.'/ProspectoCentralRepository.php';
        return (new ProspectoCentralRepository($this->db))->tieneCoincidencias($id);
    }

    private function noAsignado(int $prospectoId): bool
    {
        return $this->consulta(
            'SELECT id FROM prospecto_asignaciones WHERE prospecto_id=? LIMIT 1',
            [$prospectoId]
        )->num_rows===0 &&
        $this->consulta(
            'SELECT id FROM prospecto_distribucion_registros WHERE prospecto_id=? LIMIT 1',
            [$prospectoId]
        )->num_rows===0;
    }

    private function pendiente(int $id,int $origen,string $tipo,string $motivo): void
    {
        $this->ejecutar(
            'INSERT INTO prospecto_distribucion_pendientes
             (prospecto_id,origen_id,tipo_origen,motivo,revisado_at)
             VALUES (?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE motivo=VALUES(motivo),
                  origen_id=VALUES(origen_id),tipo_origen=VALUES(tipo_origen),
                  revisado_at=UTC_TIMESTAMP()',
            [$id,$origen,$tipo,$motivo]
        );
    }

    private function asignar(
        int $id,int $origen,int $asesor,string $tipo,string $fecha,
        ?string $proximaUtc,?string $lote
    ): void {
        // Unique (prospecto_id,vigente) de esquema central también protege
        // contra cualquier proceso externo que intente asignar dos responsables.
        $asignacion=$this->ejecutar(
            "INSERT INTO prospecto_asignaciones
             (prospecto_id,asesor_id,asignado_por,asignado_at,fecha_trabajo,
              proxima_accion_at,finalizado_at,vigente,estado)
             VALUES (?,?,NULL,UTC_TIMESTAMP(),?,?,NULL,1,'NUEVO')",
            [$id,$asesor,$fecha,$proximaUtc]
        );
        $this->ejecutar(
            'INSERT INTO prospecto_distribucion_registros
             (prospecto_id,asignacion_id,asesor_id,origen_id,tipo_origen,
              fecha_jornada,lote_ref,asignado_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [$id,$asignacion,$asesor,$origen,$tipo,$fecha,$lote]
        );
        $this->ejecutar(
            'DELETE FROM prospecto_distribucion_pendientes WHERE prospecto_id=?',[$id]
        );
    }

    private static function soloUnPrograma(array $codigos): ?string
    {
        return count($codigos)===1?(string)$codigos[0]:null;
    }

    /**
     * Reparto nocturno: sólo nuevos de lotes de Meta completamente confirmados.
     * Puede simular sin escribir. Varias cargas de Tania se combinan en la cola.
     */
    public function distribuirMeta(string $fechaJornada,int $limite=500,bool $aplicar=false): array
    {
        $this->exigirEsquema();
        if ($aplicar && !$this->habilitado('META')) {
            throw new DomainException('Motor nocturno Meta desactivado; sin asignaciones.');
        }
        if (!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$fechaJornada)||
            !checkdate((int)substr($fechaJornada,5,2),(int)substr($fechaJornada,8,2),(int)substr($fechaJornada,0,4)) ||
            $limite<1||$limite>2000) throw new DomainException('Jornada o límite no válido.');
        $dia=(int)(new DateTimeImmutable($fechaJornada,new DateTimeZone(
            ProspectoDistribucionReglas::ZONA_ASESORES)))->format('N');
        $resumen=['fecha_jornada'=>$fechaJornada,'modo'=>$aplicar?'APLICAR':'SIMULAR',
            'inspeccionados'=>0,'asignados'=>0,'pendientes'=>0,
            'motivos'=>[],'lotes_confirmados'=>0];
        if ($aplicar) $this->lock();
        $virtual=[]; // Simulación fiel: reservar cupo virtualmente sin escribir BD.
        try {
            // Cursor persistente por ID de origen, no OFFSET: los pendientes
            // anteriores no pueden bloquear indefinidamente los nuevos.
            $estadoCursor=$this->consulta(
                'SELECT ultimo_origen_id,fecha_jornada,ciclo_terminado
                 FROM prospecto_distribucion_meta_cursor WHERE id=1'
            )->fetch_assoc();
            if (!$estadoCursor) throw new RuntimeException(
                'La migración de cursor Meta no se completó.'
            );
            $cursor=(int)$estadoCursor['ultimo_origen_id'];
            // Ciclo completo se reinicia en una NUEVA jornada para revisar
            // los pendientes que quedaron por cupo o falta de asesor.
            // Si no terminó, se continúa aunque cambie de día para no
            // quedarse siempre en los primeros dos mil.
            if ((int)$estadoCursor['ciclo_terminado']===1 &&
                (string)$estadoCursor['fecha_jornada']!==$fechaJornada) {
                $cursor=0;
            }
            $resumen['lotes_confirmados']=(int)$this->consulta(
                'SELECT COUNT(*) n FROM prospecto_meta_lotes_confirmados'
            )->fetch_assoc()['n'];
            $solicitudes=$this->consulta(
                "SELECT o.id origen_id,o.prospecto_id,o.lote_importacion_ref
                 FROM prospecto_origenes o
                 JOIN prospecto_meta_lotes_confirmados l ON l.lote_ref=o.lote_importacion_ref
                 WHERE o.tipo_origen='META_ADS' AND o.origen_padre_id IS NULL
                   AND o.id>?
                   AND NOT EXISTS (SELECT 1 FROM prospecto_asignaciones a WHERE a.prospecto_id=o.prospecto_id)
                   AND NOT EXISTS (SELECT 1 FROM prospecto_distribucion_registros d WHERE d.prospecto_id=o.prospecto_id)
                 ORDER BY o.id LIMIT ?",
                [$cursor,$limite]
            )->fetch_all(MYSQLI_ASSOC);
            $ultimoVisitado=$cursor;
            foreach ($solicitudes as $row) {
                $id=(int)$row['prospecto_id'];$origen=(int)$row['origen_id'];
                $ultimoVisitado=$origen;
                $resumen['inspeccionados']++;
                if ($aplicar) $this->db->begin_transaction();
                try {
                    if ($aplicar) {
                        $this->consulta('SELECT id FROM prospectos_comerciales WHERE id=? FOR UPDATE',[$id]);
                        if (!$this->noAsignado($id)) {$this->db->commit();continue;}
                    }
                    $programas=$this->programaOrigen($origen);
                    $codigo=self::soloUnPrograma($programas);
                    $motivo=null;$elegido=null;
                    if ($this->identidadEnRevision($id)) $motivo='IDENTIDAD_EN_REVISION';
                    else if ($codigo===null) $motivo=count($programas)===0?'SIN_PROGRAMA':'PROGRAMA_AMBIGUO';
                    else {
                        $opciones=$this->asesorProgramado($codigo,$dia,$fechaJornada);
                        if (!$aplicar) foreach ($opciones as &$op) {
                            $delta=$virtual[(int)$op['usuario_id']]??0;
                            $op['meta_dia']=(int)$op['meta_dia']+$delta;
                            $op['total_activos']=(int)$op['total_activos']+$delta;
                        }
                        unset($op);
                        $opciones=array_values(array_filter($opciones,
                            static fn($a)=>(int)$a['meta_dia']<
                            ProspectoDistribucionReglas::META_POR_ASESOR_DIA));
                        if (!$opciones) $motivo='SIN_ASESOR_O_CUPO';
                        else $elegido=ProspectoDistribucionReglas::elegir($opciones,$id,'META');
                    }
                    if ($motivo===null && $elegido) {
                        $resumen['asignados']++;
                        if (!$aplicar) {
                            $a=(int)$elegido['usuario_id'];
                            $virtual[$a]=($virtual[$a]??0)+1;
                        }
                        if ($aplicar) $this->asignar(
                            $id,$origen,(int)$elegido['usuario_id'],'META',
                            $fechaJornada,null,(string)$row['lote_importacion_ref']
                        );
                    } else {
                        $resumen['pendientes']++;
                        $resumen['motivos'][$motivo]=($resumen['motivos'][$motivo]??0)+1;
                        if ($aplicar) $this->pendiente($id,$origen,'META',$motivo);
                    }
                    if ($aplicar) $this->db->commit();
                } catch (Throwable $e) {
                    if ($aplicar) $this->db->rollback();
                    throw $e;
                }
            }
            // El lote puede terminar exactamente al alcanzar el límite.
            // Una consulta mínima confirma si existe otra página pendiente.
            $mas=false;
            if (count($solicitudes)===$limite) {
                $mas=$this->consulta(
                    "SELECT o.id FROM prospecto_origenes o
                     JOIN prospecto_meta_lotes_confirmados l
                       ON l.lote_ref=o.lote_importacion_ref
                     WHERE o.tipo_origen='META_ADS' AND o.origen_padre_id IS NULL
                       AND o.id>?
                       AND NOT EXISTS (SELECT 1 FROM prospecto_asignaciones a
                           WHERE a.prospecto_id=o.prospecto_id)
                       AND NOT EXISTS (SELECT 1 FROM prospecto_distribucion_registros d
                           WHERE d.prospecto_id=o.prospecto_id)
                     ORDER BY o.id LIMIT 1",
                    [$ultimoVisitado]
                )->num_rows>0;
            }
            $resumen['terminado']=!$mas;
            $resumen['continuar_desde']=$ultimoVisitado;
            if ($aplicar) {
                // La transacción de asignación ya se confirmó por registro;
                // el cursor se guarda únicamente tras terminar el lote.
                // Si ocurre un fallo antes, repetir es seguro: la unicidad
                // de asignaciones evita asignar dos veces un prospecto.
                $this->ejecutar(
                    'UPDATE prospecto_distribucion_meta_cursor
                     SET ultimo_origen_id=?,fecha_jornada=?,ciclo_terminado=?,
                         updated_at=UTC_TIMESTAMP() WHERE id=1',
                    [$ultimoVisitado,$fechaJornada,$mas?0:1]
                );
                $this->ejecutar(
                    "INSERT INTO prospecto_distribucion_corridas
                    (tipo_origen,fecha_jornada,inicio_at,fin_at,asignados,pendientes,inspeccionados,modo)
                    VALUES ('META',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,?,'APLICADO')",
                    [$fechaJornada,$resumen['asignados'],$resumen['pendientes'],$resumen['inspeccionados']]
                );
            }
        } finally {if ($aplicar) $this->unlock();}
        return $resumen;
    }

    /**
     * Reconsiderar registros de Aliados que llegaron sin compatibilidad.
     * Por defecto se consulta sin escribir; solo afecta asignaciones pendientes.
     */
    public function reintentarAliados(int $limite=200,bool $aplicar=false): array
    {
        $this->exigirEsquema();
        if ($limite<1||$limite>2000) throw new DomainException('Límite no válido.');
        $ids=$this->consulta(
            "SELECT prospecto_id FROM prospecto_distribucion_pendientes
             WHERE tipo_origen='ALIADO' ORDER BY revisado_at,prospecto_id LIMIT ?",
            [$limite]
        )->fetch_all(MYSQLI_ASSOC);
        $resumen=['inspeccionados'=>0,'asignados'=>0,'pendientes'=>0,'omitidos'=>0];
        foreach ($ids as $row) {
            $resultado=$this->asignarAliado((int)$row['prospecto_id'],$aplicar);
            $resumen['inspeccionados']++;
            if (in_array($resultado['estado'],['ASIGNADO','SIMULACION'],true)) $resumen['asignados']++;
            elseif ($resultado['estado']==='PENDIENTE') $resumen['pendientes']++;
            else $resumen['omitidos']++;
        }
        return $resumen;
    }

    /**
     * Salvaguarda cuando el disparador inmediato no pudo obtener el bloqueo.
     * Guarda una tarea pendiente sin impedir el registro original.
     */
    public function encolarAliado(int $prospectoId): void
    {
        if (!$this->disponible() || $prospectoId<1) return;
        $row=$this->consulta(
            "SELECT id FROM prospecto_origenes
             WHERE prospecto_id=? AND tipo_origen='ALIADO' AND origen_padre_id IS NULL
             LIMIT 1",[$prospectoId]
        )->fetch_assoc();
        if ($row && $this->noAsignado($prospectoId)) {
            $this->pendiente($prospectoId,(int)$row['id'],'ALIADO','REINTENTO_PENDIENTE');
        }
    }

    /** Se invoca DESPUÉS del COMMIT del formulario original y su incorporación. */
    public function asignarAliado(int $prospectoId,bool $aplicar=true,?DateTimeImmutable $ahora=null): array
    {
        $this->exigirEsquema();
        if ($prospectoId<1) throw new DomainException('Prospecto inválido.');
        if ($aplicar && !$this->habilitado('ALIADO')) {
            $this->encolarAliado($prospectoId);
            return ['estado'=>'DESACTIVADO'];
        }
        $ahora=$ahora??new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if ($aplicar) $this->lock();
        try {
            if ($aplicar) $this->db->begin_transaction();
            try {
                $p=$this->consulta(
                    "SELECT o.id origen_id,p.estado_provincia,p.pais_nombre,
                            e.nombre estado_catalogo,p.id prospecto_id
                     FROM prospecto_origenes o
                     JOIN prospectos_comerciales p ON p.id=o.prospecto_id
                     LEFT JOIN estados e ON e.id=p.estado_id
                     WHERE p.id=? AND o.tipo_origen='ALIADO'
                       AND o.origen_padre_id IS NULL LIMIT 1",
                    [$prospectoId]
                )->fetch_assoc();
                if (!$p) {
                    if ($aplicar) $this->db->commit();
                    return ['estado'=>'IGNORADO_NO_ALIADO'];
                }
                if ($aplicar) $this->consulta(
                    'SELECT id FROM prospectos_comerciales WHERE id=? FOR UPDATE',[$prospectoId]
                );
                if (!$this->noAsignado($prospectoId)) {
                    if ($aplicar) $this->db->commit();
                    return ['estado'=>'YA_ASIGNADO'];
                }
                $origen=(int)$p['origen_id'];
                $programas=$this->programaAliado($prospectoId);
                $codigo=self::soloUnPrograma($programas);
                $motivo=null;$elegido=null;$turno=null;
                if ($this->identidadEnRevision($prospectoId)) $motivo='IDENTIDAD_EN_REVISION';
                else if ($codigo===null) $motivo=count($programas)===0?'SIN_PROGRAMA':'PROGRAMA_AMBIGUO';
                else {
                    $preferencia=$this->consulta(
                        'SELECT valor_original,dias_semana,hora_desde,hora_hasta,
                                zona_horaria,interpretacion_estado
                         FROM prospecto_preferencias_contacto WHERE origen_id=?',
                        [$origen]
                    )->fetch_assoc()??['interpretacion_estado'=>'NO_INDICADO'];
                    $preferencia['pais_nombre']=$p['pais_nombre'];
                    $preferencia['estado_provincia']=$p['estado_provincia']?:$p['estado_catalogo'];
                    $pref=ProspectoDistribucionReglas::preferencia($preferencia);
                    if ($pref['tipo']==='BLOQUEADA') $motivo=$pref['motivo'];
                    else {
                        $candidatos=[];
                        foreach ($this->horariosAliado($codigo) as $asesor) {
                            $franja=ProspectoDistribucionReglas::turno($asesor['horarios'],$pref,$ahora);
                            if ($franja) {$asesor['turno']=$franja;$candidatos[]=$asesor;}
                        }
                        if (!$candidatos) $motivo='SIN_ASESOR_COMPATIBLE';
                        else {
                            $elegido=ProspectoDistribucionReglas::elegir(
                                $candidatos,$prospectoId,'ALIADO'
                            );
                            $turno=$elegido['turno'];
                        }
                    }
                }
                if ($motivo!==null) {
                    if ($aplicar) $this->pendiente($prospectoId,$origen,'ALIADO',$motivo);
                    if ($aplicar) $this->db->commit();
                    return ['estado'=>'PENDIENTE','motivo'=>$motivo];
                }
                if ($aplicar) $this->asignar(
                    $prospectoId,$origen,(int)$elegido['usuario_id'],'ALIADO',
                    $turno['fecha_jornada'],$turno['proxima_accion_utc'],null
                );
                if ($aplicar) $this->db->commit();
                return ['estado'=>$aplicar?'ASIGNADO':'SIMULACION',
                    'asesor_id'=>(int)$elegido['usuario_id'],
                    'fecha_jornada'=>$turno['fecha_jornada']];
            } catch (Throwable $e) {
                if ($aplicar) $this->db->rollback();
                throw $e;
            }
        } finally {if ($aplicar) $this->unlock();}
    }
}
