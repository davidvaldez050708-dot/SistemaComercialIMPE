<?php
require_once __DIR__.'/EmbudoComercialEtapas.php';
/**
 * Fase 2 del kanban: consultas limitadas y exclusivas de las asignaciones vigentes.
 * La etapa no se deduce de telefonía ni del estado administrativo de asignación.
 * No realiza escrituras ni crea automáticamente etapas.
 */
final class EmbudoComercialRepository
{
    public const ETAPAS = [
        'NUEVO' => 'Nuevos',
        'CONTACTADO' => 'Contactados',
        'INTERESADO' => 'Interesados',
        'SEGUIMIENTO' => 'Seguimiento',
        'PAGO_CONFIRMADO' => 'Pago confirmado / Inscripción pendiente',
        'RECUPERACION' => 'En recuperación',
        'DESCARTADO' => 'Descartados'
    ];
    public const ACTIVAS = ['NUEVO','CONTACTADO','INTERESADO','SEGUIMIENTO','PAGO_CONFIRMADO'];
    public const POR_COLUMNA = 20;
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    public function disponible(): bool
    {
        foreach ([
            'prospectos_comerciales','prospecto_asignaciones','prospecto_origenes',
            'prospecto_intereses','prospecto_preferencias_contacto',
            'prospecto_embudo_etapas','prospecto_embudo_movimientos',
            'prospecto_embudo_agenda'
        ] as $nombre) {
            $st=$this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            );
            $st->bind_param('s',$nombre);
            $st->execute();
            $row=$st->get_result()->fetch_assoc();
            $st->close();
            if (!$row || strtoupper((string)$row['ENGINE'])!=='INNODB') return false;
        }
        return true;
    }

    public static function filtros(array $get,bool $supervisor): array
    {
        $buscar=$get['buscar']??'';
        $origen=$get['origen']??'';
        $programa=$get['programa']??'';
        $vencidos=$get['vencidos']??'0';
        $asesor=$get['asesor']??'';
        if (!is_string($buscar)||mb_strlen($buscar)>120 ||
            !is_string($origen)||!in_array($origen,['','META_ADS','ALIADO'],true) ||
            !is_string($programa)||mb_strlen($programa)>80 ||
            !is_string($vencidos)||!in_array($vencidos,['0','1'],true) ||
            !is_string($asesor)||($asesor!==''&&(!ctype_digit($asesor)||(int)$asesor<1))) {
            throw new DomainException('Los filtros del embudo no son válidos.');
        }
        $pagina=[];
        foreach (self::ETAPAS as $etapa=>$etiqueta) {
            $clave='p_'.strtolower($etapa);
            $v=$get[$clave]??'1';
            if (!is_scalar($v)||is_bool($v)||!ctype_digit((string)$v) ||
                (int)$v<1 || (int)$v>100000) {
                throw new DomainException('La página del embudo no es válida.');
            }
            $pagina[$etapa]=(int)$v;
        }
        return [
            'buscar'=>trim($buscar),'origen'=>$origen,'programa'=>$programa,
            'vencidos'=>$vencidos==='1','asesor'=>$supervisor&&$asesor!==''?(int)$asesor:0,
            'paginas'=>$pagina
        ];
    }

    private function rows(string $sql,array $args=[]): array
    {
        $st=$this->db->prepare($sql);
        if ($args) {
            $types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$args));
            $st->bind_param($types,...$args);
        }
        $st->execute();
        $result=$st->get_result();
        if (!$result) {
            $st->close();
            throw new RuntimeException('No fue posible consultar el embudo.');
        }
        $rows=$result->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    private function condiciones(int $usuarioId,bool $supervisor,array $f): array
    {
        $sql=" FROM prospecto_asignaciones a
            JOIN prospectos_comerciales p ON p.id=a.prospecto_id
            LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            LEFT JOIN prospecto_embudo_agenda ag ON ag.asignacion_id=a.id
            WHERE a.vigente=1";
        $args=[];
        if (!$supervisor) {
            $sql.=' AND a.asesor_id=?'; $args[]=$usuarioId;
        } elseif ($f['asesor']>0) {
            $sql.=' AND a.asesor_id=?'; $args[]=$f['asesor'];
        }
        if ($f['origen']!=='') {
            $sql.=" AND EXISTS (
                SELECT 1 FROM prospecto_origenes o
                WHERE o.prospecto_id=p.id AND o.origen_padre_id IS NULL AND o.tipo_origen=?
            )";
            $args[]=$f['origen'];
        }
        if ($f['programa']!=='') {
            $sql.=" AND EXISTS (
                SELECT 1 FROM prospecto_intereses i
                WHERE i.prospecto_id=p.id AND i.programa_codigo=?
            )";
            $args[]=$f['programa'];
        }
        if ($f['buscar']!=='') {
            $pat='%'.strtr($f['buscar'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $sql.=" AND (p.nombre_completo LIKE ? ESCAPE '!'
                        OR p.telefono_original LIKE ? ESCAPE '!'
                        OR p.correo LIKE ? ESCAPE '!')";
            array_push($args,$pat,$pat,$pat);
        }
        if ($f['vencidos']) {
            $sql.=" AND (
                COALESCE(ag.proxima_accion_at,a.proxima_accion_at)<UTC_TIMESTAMP()
                OR ag.revision_fecha<=?
                OR (ag.revision_fecha IS NULL
                    AND COALESCE(ag.proxima_accion_at,a.proxima_accion_at) IS NULL
                    AND a.fecha_trabajo<?))";
            $hoy=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))
                ->format('Y-m-d');
            array_push($args,$hoy,$hoy);
        }
        return [$sql,$args];
    }

    public function opciones(int $usuarioId,bool $supervisor): array
    {
        $where=$supervisor?'':" AND a.asesor_id=?";
        $args=$supervisor?[]:[$usuarioId];
        $programas=$this->rows(
            "SELECT i.programa_codigo codigo, MIN(i.nombre_original) nombre
             FROM prospecto_intereses i
             JOIN prospecto_asignaciones a ON a.prospecto_id=i.prospecto_id AND a.vigente=1
             WHERE i.programa_codigo IS NOT NULL AND i.programa_codigo<>'' $where
             GROUP BY i.programa_codigo ORDER BY nombre,codigo LIMIT 100",
            $args
        );
        $asesores=[];
        if ($supervisor) {
            $asesores=$this->rows(
                "SELECT DISTINCT u.id,CONCAT_WS(' ',u.nombre,u.apellidos) nombre
                 FROM usuarios u
                 JOIN prospecto_asignaciones a ON a.asesor_id=u.id AND a.vigente=1
                 ORDER BY nombre LIMIT 150"
            );
        }
        return ['programas'=>$programas,'asesores'=>$asesores];
    }

    public function listado(int $usuarioId,bool $supervisor,array $f,string $vista): array
    {
        [$from,$args]=$this->condiciones($usuarioId,$supervisor,$f);
        $agregado=$this->rows(
            "SELECT CASE WHEN e.etapa='EN_CONTACTO' THEN 'RECUPERACION'
                         ELSE COALESCE(e.etapa,'NUEVO') END etapa, COUNT(*) total
             $from GROUP BY CASE WHEN e.etapa='EN_CONTACTO' THEN 'RECUPERACION'
                                  ELSE COALESCE(e.etapa,'NUEVO') END",
            $args
        );
        $conteos=[];
        foreach ($agregado as $fila) {
            $clave=(string)$fila['etapa'];
            if (isset(self::ETAPAS[$clave])) $conteos[$clave]=(int)$fila['total'];
        }
        $etapas=$vista==='embudo'
            ?self::ACTIVAS
            :[$vista==='recuperacion'?'RECUPERACION':'DESCARTADO'];
        $datos=[];
        foreach ($etapas as $etapa) {
            $total=$conteos[$etapa]??0;
            $paginas=max(1,(int)ceil($total/self::POR_COLUMNA));
            $pagina=min($paginas,$f['paginas'][$etapa]);
            $offset=($pagina-1)*self::POR_COLUMNA;
            $campos="SELECT a.id asignacion_id,a.prospecto_id,a.asesor_id,
                a.asignado_at,a.fecha_trabajo,
                COALESCE(ag.proxima_accion_at,a.proxima_accion_at) proxima_accion_at,
                ag.revision_fecha,
                p.nombre_completo,p.telefono_original,
                (SELECT GROUP_CONCAT(DISTINCT i.nombre_original SEPARATOR ' · ')
                    FROM prospecto_intereses i WHERE i.prospecto_id=p.id) programas,
                (SELECT o.tipo_origen FROM prospecto_origenes o
                    WHERE o.prospecto_id=p.id AND o.origen_padre_id IS NULL
                    ORDER BY o.id DESC LIMIT 1) tipo_origen,
                (SELECT pref.valor_original FROM prospecto_preferencias_contacto pref
                    JOIN prospecto_origenes o ON o.id=pref.origen_id
                    WHERE o.prospecto_id=p.id ORDER BY o.id DESC LIMIT 1) horario_contacto";
            $items=$this->rows(
                "$campos $from AND CASE WHEN e.etapa='EN_CONTACTO' THEN 'RECUPERACION'
                      ELSE COALESCE(e.etapa,'NUEVO') END=?
                 ORDER BY
                  CASE WHEN COALESCE(ag.proxima_accion_at,a.proxima_accion_at) IS NOT NULL
                           AND COALESCE(ag.proxima_accion_at,a.proxima_accion_at)<UTC_TIMESTAMP()
                          THEN 0
                       WHEN ag.revision_fecha IS NOT NULL AND ag.revision_fecha<? THEN 0
                       ELSE 1 END,
                  COALESCE(ag.proxima_accion_at,a.proxima_accion_at,
                           CONCAT(ag.revision_fecha,' 12:00:00'),
                           CONCAT(a.fecha_trabajo,' 12:00:00'),a.asignado_at),
                  a.id
                 LIMIT ? OFFSET ?",
                [...$args,$etapa,
                (new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d'),
                self::POR_COLUMNA,$offset]
            );
            $datos[$etapa]=[
                'etiqueta'=>self::ETAPAS[$etapa],'total'=>$total,'pagina'=>$pagina,
                'paginas'=>$paginas,'registros'=>$items
            ];
        }
        return ['columnas'=>$datos,'conteos'=>$conteos,'total'=>array_sum($conteos)];
    }

    /**
     * Fase V3: vista de preparación de SOLO LECTURA y con permisos existentes.
     *
     * No modifica el catálogo operativo, filtros del asesor, PBX, movimientos,
     * etapas ni la agenda. Las etapas antiguas INTERESADO y PAGO_CONFIRMADO
     * permanecen como pendientes, nunca se reetiquetan a POTENCIAL.
     *
     * Muestra hasta 20 registros por grupo, con totales completos y truncado
     * explícito. Ninguna tarjeta V3 es arrastrable.
     */
    public static function clasificacionV3Sql(): string
    {
        return "CASE
           WHEN e.etapa IS NULL THEN 'NUEVO'
           WHEN e.etapa IN ('NUEVO','CONTACTADO','POTENCIAL',
                            'SEGUIMIENTO','PROCESO_INSCRIPCION')
                THEN e.etapa
           WHEN e.etapa IN ('RECUPERACION','EN_CONTACTO') THEN 'RECUPERACION'
           WHEN e.etapa='DESCARTADO' THEN 'DESCARTADO'
           ELSE 'REVISION_LEGACY'
         END";
    }

    /**
     * Agrupación SOLO VISUAL para el trabajo del asesor, sin migración SQL.
     * INTERESADO aparece como Potencial (denominación nueva). PAGO_CONFIRMADO
     * aparece en Proceso Inscripción como registro histórico por VALIDAR,
     * sin interpretar que inscripción ni pago fueron acreditados.
     * Las etapas originales y las restricciones de movimiento se conservan.
     */
    public static function clasificacionV3OperativoSql(): string
    {
        return "CASE
           WHEN e.etapa='INTERESADO' THEN 'POTENCIAL'
           WHEN e.etapa='PAGO_CONFIRMADO' THEN 'PROCESO_INSCRIPCION'
           ELSE ".self::clasificacionV3Sql()."
        END";
    }

    public function listadoV3Preparacion(int $usuarioId,bool $supervisor,array $f): array
    {
        $grupos=[
            'NUEVO'=>'Nuevos',
            'CONTACTADO'=>'Contactados',
            'POTENCIAL'=>'Potenciales',
            'SEGUIMIENTO'=>'Seguimiento',
            'PROCESO_INSCRIPCION'=>'Proceso Inscripción',
            'REVISION_LEGACY'=>'Clasificación pendiente'
        ];
        $required=['prospecto_embudo_subetapas','prospecto_embudo_subetapas_historial'];
        foreach ($required as $tabla) {
            $found=$this->rows("SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$tabla]);
            if (!$found || strtoupper((string)$found[0]['ENGINE'])!=='INNODB') {
                throw new DomainException('Faltan estructuras V3 de subetapas.');
            }
        }
        [$from,$args]=$this->condiciones($usuarioId,$supervisor,$f);
        $expr=self::clasificacionV3Sql();
        $totales=[];
        foreach($this->rows("SELECT $expr grupo,COUNT(*) total
              $from GROUP BY $expr",$args) as $fila) {
            $clave=(string)$fila['grupo'];
            $totales[$clave]=(int)$fila['total'];
        }
        $columnas=[];
        foreach ($grupos as $etapa=>$etiqueta) {
            $registros=$this->rows(
                "SELECT a.id asignacion_id,a.prospecto_id,a.asesor_id,
                    p.nombre_completo,
                    (SELECT GROUP_CONCAT(DISTINCT i.nombre_original SEPARATOR ' · ')
                     FROM prospecto_intereses i WHERE i.prospecto_id=p.id) programas,
                    COALESCE(ag.proxima_accion_at,a.proxima_accion_at) proxima_accion_at,
                    ag.revision_fecha,
                    e.etapa etapa_original,
                    (SELECT st.subetapa FROM prospecto_embudo_subetapas st
                     WHERE st.asignacion_id=a.id LIMIT 1) subetapa
                $from AND $expr=?
                ORDER BY COALESCE(ag.proxima_accion_at,a.proxima_accion_at,
                            a.asignado_at),a.id
                LIMIT 21",array_merge($args,[$etapa])
            );
            $columnas[$etapa]=[
                'etiqueta'=>$etiqueta,
                'total'=>$totales[$etapa]??0,
                'registros'=>array_slice($registros,0,20),
                'truncado'=>count($registros)>20
            ];
        }
        // Una sola consulta por lote para los 20 visibles de cada grupo;
        // NO se hacen 120 consultas separadas ni se alteran filas PBX.
        $idsVisibles=[];
        foreach($columnas as $grupo) {
            foreach($grupo['registros'] as $tarjeta) {
                $id=(int)$tarjeta['asignacion_id'];
                if($id>0)$idsVisibles[$id]=$id;
            }
        }
        $diaLocal=(new DateTimeImmutable('today',
            new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        require_once __DIR__.'/EmbudoComercialV3IndicadoresVista.php';
        $indicadores=(new EmbudoComercialV3IndicadoresVista($this->db))
            ->intentosDelDia(array_values($idsVisibles),$diaLocal);
        foreach($columnas as &$grupo) {
            foreach($grupo['registros'] as &$tarjeta) {
                $id=(int)$tarjeta['asignacion_id'];
                $tarjeta['intentos_hoy']=$indicadores[$id]??null;
            }
            unset($tarjeta);
        }
        unset($grupo);
        $externos=($totales['RECUPERACION']??0)+($totales['DESCARTADO']??0);
        return [
            'dia_indicadores'=>$diaLocal,
            'columnas'=>$columnas,
            'externos'=>[
                'RECUPERACION'=>$totales['RECUPERACION']??0,
                'DESCARTADO'=>$totales['DESCARTADO']??0
            ],
            'total'=>array_sum($totales),
            'total_activos'=>array_sum($totales)-$externos,
            'registros_sin_clasificar'=>$totales['REVISION_LEGACY']??0,
            'modo'=>'PREVISUALIZACION_SIN_ESCRITURAS'
        ];
    }

    /**
     * V3 operativo para asesor, DESACTIVADO en el acceso habitual.
     *
     * Mantiene intactas las etapas y los resultados vigentes: es una vista
     * operativa de llamadas, historial y movimientos seguros compatibles
     * con el backend V1. No crea nuevos códigos ni confirma una inscripción.
     *
     * Las consultas pasan por condiciones() para limitar estrictamente la
     * propiedad a las asignaciones vigentes del asesor autenticado.
     */
    public static function paginasV3Operativo(array $get): array
    {
        $grupos=['NUEVO','CONTACTADO','POTENCIAL',
            'SEGUIMIENTO','PROCESO_INSCRIPCION','REVISION_LEGACY'];
        $p=[];
        foreach ($grupos as $grupo) {
            $key='p3_'.strtolower($grupo);
            $value=$get[$key]??'1';
            if (!is_string($value) && !is_int($value)) {
                throw new DomainException('Página de embudo V3 no válida.');
            }
            $value=(string)$value;
            if (!preg_match('/^[1-9][0-9]{0,4}$/D',$value) ||
                (int)$value>10000) {
                throw new DomainException('Página de embudo V3 no válida.');
            }
            $p[$grupo]=(int)$value;
        }
        return $p;
    }

    public function listadoV3Operativo(int $usuarioId,array $f,array $paginas): array
    {
        if($usuarioId<1 || (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver')) {
            throw new DomainException('El embudo operativo pertenece al asesor autenticado.');
        }
        $grupoLabels=[
            'NUEVO'=>'Nuevos',
            'CONTACTADO'=>'Contactados',
            'POTENCIAL'=>'Potenciales',
            'SEGUIMIENTO'=>'Seguimiento',
            'PROCESO_INSCRIPCION'=>'Proceso Inscripción',
            'REVISION_LEGACY'=>'Clasificación pendiente'
        ];
        foreach (['prospecto_embudo_subetapas',
                  'prospecto_embudo_subetapas_historial'] as $tabla) {
            $meta=$this->rows("SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$tabla]);
            if(!$meta || strtoupper((string)$meta[0]['ENGINE'])!=='INNODB') {
                throw new DomainException('La estructura de subetapas V3 no está instalada.');
            }
        }
        [$from,$args]=$this->condiciones($usuarioId,false,$f);
        $expr=self::clasificacionV3OperativoSql();
        $totales=[];
        foreach($this->rows("SELECT $expr grupo, COUNT(*) total
                $from GROUP BY $expr",$args) as $fila) {
            $totales[(string)$fila['grupo']]=(int)$fila['total'];
        }
        $cols=[];
        foreach($grupoLabels as $key=>$label){
            $total=$totales[$key]??0;
            $max=max(1,(int)ceil($total/self::POR_COLUMNA));
            $actual=min($max,(int)($paginas[$key]??1));
            $offset=($actual-1)*self::POR_COLUMNA;
            $data=$this->rows(
                "SELECT a.id asignacion_id,a.prospecto_id,a.asesor_id,
                    a.asignado_at,a.fecha_trabajo,
                    p.nombre_completo,p.telefono_original,
                    COALESCE(ag.proxima_accion_at,a.proxima_accion_at) proxima_accion_at,
                    ag.revision_fecha,e.etapa etapa_original,
                    (SELECT st.subetapa FROM prospecto_embudo_subetapas st
                     WHERE st.asignacion_id=a.id LIMIT 1) subetapa,
                    (SELECT GROUP_CONCAT(DISTINCT i.nombre_original SEPARATOR ' · ')
                     FROM prospecto_intereses i WHERE i.prospecto_id=p.id) programas,
                    (SELECT o.tipo_origen FROM prospecto_origenes o
                     WHERE o.prospecto_id=p.id AND o.origen_padre_id IS NULL
                     ORDER BY o.id DESC LIMIT 1) tipo_origen,
                    (SELECT pref.valor_original FROM prospecto_preferencias_contacto pref
                     JOIN prospecto_origenes o ON o.id=pref.origen_id
                     WHERE o.prospecto_id=p.id ORDER BY o.id DESC LIMIT 1)
                     horario_contacto
                 $from AND $expr=?
                 ORDER BY CASE
                   WHEN COALESCE(ag.proxima_accion_at,a.proxima_accion_at)
                         <UTC_TIMESTAMP() THEN 0
                   WHEN ag.revision_fecha IS NOT NULL
                         AND ag.revision_fecha<? THEN 0
                   ELSE 1 END,
                   COALESCE(ag.proxima_accion_at,a.proxima_accion_at,
                      CONCAT(ag.revision_fecha,' 12:00:00'),
                      CONCAT(a.fecha_trabajo,' 12:00:00'),a.asignado_at),
                   a.id
                 LIMIT ? OFFSET ?",
                array_merge($args,[$key,
                    (new DateTimeImmutable('today',
                     new DateTimeZone('America/Mexico_City')))->format('Y-m-d'),
                    self::POR_COLUMNA,$offset])
            );
            $cols[$key]=[
                'etiqueta'=>$label,'total'=>$total,'pagina'=>$actual,
                'paginas'=>$max,'registros'=>$data
            ];
        }
        $ids=[];
        foreach($cols as $col)foreach($col['registros'] as $item){
            $id=(int)$item['asignacion_id'];
            if($id>0)$ids[$id]=$id;
        }
        require_once __DIR__.'/EmbudoComercialV3IndicadoresVista.php';
        $dia=(new DateTimeImmutable('today',
            new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $indicadores=(new EmbudoComercialV3IndicadoresVista($this->db))
            ->intentosDelDia(array_values($ids),$dia);
        foreach($cols as &$col){
            foreach($col['registros'] as &$item) {
                $item['intentos_hoy']=$indicadores[(int)$item['asignacion_id']]??null;
            }
            unset($item);
        }
        unset($col);
        $fuera=($totales['RECUPERACION']??0)+($totales['DESCARTADO']??0);
        return [
            'columnas'=>$cols,
            'total_activos'=>array_sum($totales)-$fuera,
            'total'=>array_sum($totales),
            'externos'=>[
                'RECUPERACION'=>$totales['RECUPERACION']??0,
                'DESCARTADO'=>$totales['DESCARTADO']??0
            ],
            'dia_indicadores'=>$dia,
            'modo'=>'V3_OPERATIVO_ASESOR_COMPATIBLE_V1'
        ];
    }

    public function detalle(int $usuarioId,bool $supervisor,int $prospectoId,string $vista,
        bool $operativoV3=false): ?array
    {
        if ($prospectoId<1) return null;
        $params=[$prospectoId];
        $sql="SELECT p.id,p.nombre_completo,p.telefono_original,p.correo,
                     p.pais_nombre,p.estado_provincia,p.ciudad,
                     a.id asignacion_id,a.asesor_id,a.asignado_at,a.fecha_trabajo,
                     COALESCE(ag.proxima_accion_at,a.proxima_accion_at) proxima_accion_at,
                     ag.revision_fecha,
                     CASE WHEN e.etapa='EN_CONTACTO' THEN 'RECUPERACION'
                          ELSE COALESCE(e.etapa,'NUEVO') END etapa
              FROM prospecto_asignaciones a
              JOIN prospectos_comerciales p ON p.id=a.prospecto_id
              LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
              LEFT JOIN prospecto_embudo_agenda ag ON ag.asignacion_id=a.id
              WHERE p.id=? AND a.vigente=1";
        if (!$supervisor) {$sql.=' AND a.asesor_id=?';$params[]=$usuarioId;}
        if ($vista==='recuperacion') $sql.=" AND e.etapa IN ('RECUPERACION','EN_CONTACTO')";
        elseif ($vista==='descartados') $sql.=" AND COALESCE(e.etapa,'NUEVO')='DESCARTADO'";
        elseif($operativoV3) $sql.=" AND COALESCE(e.etapa,'NUEVO') IN (
            'NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO',
            'PROCESO_INSCRIPCION','INTERESADO','PAGO_CONFIRMADO')"; 
        else $sql.=" AND COALESCE(e.etapa,'NUEVO') IN (
            'NUEVO','CONTACTADO','INTERESADO','SEGUIMIENTO','PAGO_CONFIRMADO')";
        $sql.=' LIMIT 1';
        $rows=$this->rows($sql,$params);
        if (!$rows) return null;
        $p=$rows[0];
        $p['programas']=$this->rows(
            'SELECT nombre_original FROM prospecto_intereses WHERE prospecto_id=? ORDER BY id DESC LIMIT 20',
            [$prospectoId]
        );
        $p['origenes']=$this->rows(
            'SELECT tipo_origen,aliado_nombre,formulario_nombre,fecha_registro_original
             FROM prospecto_origenes WHERE prospecto_id=? AND origen_padre_id IS NULL
             ORDER BY id DESC LIMIT 10',[$prospectoId]
        );
        $p['preferencias']=$this->rows(
            'SELECT pref.valor_original,pref.zona_horaria,pref.interpretacion_estado
             FROM prospecto_preferencias_contacto pref
             JOIN prospecto_origenes o ON o.id=pref.origen_id
             WHERE o.prospecto_id=? ORDER BY o.id DESC LIMIT 5',[$prospectoId]
        );
        // La autorización de propietario/supervisor quedó confirmada ARRIBA
        // al obtener esta asignación vigente: jamás consultar por ID sin ella.
        // Mostrar como máximo 50 movimientos, más uno para señalar truncamiento.
        // No crear, actualizar ni suprimir eventos de la bitácora.
        $historial=$this->rows(
            "SELECT m.id,m.etapa_anterior,m.etapa_nueva,m.motivo,
                    m.usuario_id,m.creado_at,
                    TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) autor
             FROM prospecto_embudo_movimientos m
             LEFT JOIN usuarios u ON u.id=m.usuario_id
             WHERE m.asignacion_id=?
             ORDER BY m.creado_at DESC,m.id DESC
             LIMIT 51",
            [(int)$p['asignacion_id']]
        );
        $p['historial_truncado']=count($historial)>50;
        $p['historial_movimientos']=array_slice($historial,0,50);
        $p['historial_subetapas']=[];
        $p['historial_subetapas_truncado']=false;
        if($operativoV3 && !$supervisor){
            // La asignación vigente y su propietario ya se verificaron arriba.
            // La bitácora V3 es independiente de los movimientos de etapa.
            $eventos=$this->rows(
                "SELECT h.id,h.etapa_en_evento,h.subetapa_anterior,
                        h.subetapa_nueva,h.motivo,h.origen,h.creado_at,
                        h.usuario_id,
                        TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) autor
                   FROM prospecto_embudo_subetapas_historial h
                   LEFT JOIN usuarios u ON u.id=h.usuario_id
                  WHERE h.asignacion_id=?
                  ORDER BY h.creado_at DESC,h.id DESC LIMIT 51",
                [(int)$p['asignacion_id']]
            );
            $p['historial_subetapas_truncado']=count($eventos)>50;
            $p['historial_subetapas']=array_slice($eventos,0,50);
        }
        return $p;
    }
}
