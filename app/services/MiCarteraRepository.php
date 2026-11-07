<?php
/**
 * Bandeja exclusiva de prospectos que tienen asignación vigente a la sesión.
 *
 * No reutiliza el listado ni la ficha administrativas: cada consulta empieza
 * por asesor_id=? y vigente=1. Solo lectura; no reparte ni llama al proveedor.
 */
final class MiCarteraRepository
{
    private mysqli $db;
    public const TAMANO_PAGINA = 20;

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    public function disponible(): bool
    {
        $tablas=[
            'prospectos_comerciales',
            'prospecto_asignaciones',
            'prospecto_origenes',
            'prospecto_intereses',
            'prospecto_preferencias_contacto'
        ];
        $stmt=$this->db->prepare(
            'SELECT COUNT(*) n FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        foreach ($tablas as $tabla) {
            $stmt->bind_param('s',$tabla);
            $stmt->execute();
            if ((int)$stmt->get_result()->fetch_assoc()['n']!==1) {
                $stmt->close();
                return false;
            }
        }
        $stmt->close();
        return true;
    }

    private function consulta(string $sql,array $argumentos=[])
    {
        $stmt=$this->db->prepare($sql);
        if ($argumentos) {
            $tipos='';
            foreach ($argumentos as $argumento) $tipos.=is_int($argumento)?'i':'s';
            $referencias=[$tipos];
            foreach ($argumentos as $indice=>&$argumento) $referencias[]=&$argumento;
            unset($argumento);
            if (!call_user_func_array([$stmt,'bind_param'],$referencias)) {
                throw new RuntimeException('Parámetros de consulta de cartera incorrectos.');
            }
        }
        $stmt->execute();
        $resultado=$stmt->get_result();
        $stmt->close();
        return $resultado;
    }

    public static function filtros(array $entrada): array
    {
        $vista=$entrada['vista']??'todos';
        $buscar=$entrada['buscar']??'';
        $pagina=$entrada['pagina']??1;
        if (!is_string($vista)||!in_array($vista,['todos','hoy','proximos','sin_fecha'],true)) {
            throw new DomainException('Selecciona un filtro de cartera válido.');
        }
        if (!is_string($buscar)||mb_strlen($buscar,'UTF-8')>120) {
            throw new DomainException('La búsqueda es demasiado extensa.');
        }
        $buscar=trim($buscar);
        if (!is_scalar($pagina)||is_bool($pagina)||!ctype_digit((string)$pagina) ||
            (int)$pagina<1) {
            throw new DomainException('La página solicitada no es válida.');
        }
        return ['vista'=>$vista,'buscar'=>$buscar,'pagina'=>min(100000,(int)$pagina)];
    }

    private static function limitesHorario(): array
    {
        $tz=new DateTimeZone('America/Mexico_City');
        $hoy=new DateTimeImmutable('today',$tz);
        $manana=$hoy->modify('+1 day');
        return [
            'hoy'=>$hoy->format('Y-m-d'),
            'inicio_utc'=>$hoy->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'manana_utc'=>$manana->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        ];
    }

    private static function filtroSql(array $f): array
    {
        $partes=[];$args=[];
        if ($f['buscar']!=='') {
            $partes[]='(p.nombre_completo LIKE ? OR p.telefono_original LIKE ? OR p.correo LIKE ?)';
            $busqueda='%'.$f['buscar'].'%';
            array_push($args,$busqueda,$busqueda,$busqueda);
        }
        $limites=self::limitesHorario();
        switch ($f['vista']) {
            case 'hoy':
                // Incluye acciones vencidas, sin inventar horarios.
                $partes[]='(a.fecha_trabajo<=? OR a.proxima_accion_at<?)';
                array_push($args,$limites['hoy'],$limites['manana_utc']);
                break;
            case 'proximos':
                $partes[]='(a.fecha_trabajo>? OR a.proxima_accion_at>=?)';
                array_push($args,$limites['hoy'],$limites['manana_utc']);
                break;
            case 'sin_fecha':
                $partes[]='a.fecha_trabajo IS NULL AND a.proxima_accion_at IS NULL';
                break;
        }
        return [$partes?(' AND '.implode(' AND ',$partes)):'',$args,$limites];
    }

    public function listar(int $usuarioId,array $filtros): array
    {
        if ($usuarioId<1) throw new DomainException('Sesión de usuario no válida.');
        $f=self::filtros($filtros);
        [$extra,$extraArgs,$limites]=self::filtroSql($f);
        $where=' FROM prospecto_asignaciones a
                 INNER JOIN prospectos_comerciales p ON p.id=a.prospecto_id
                 WHERE a.asesor_id=? AND a.vigente=1'.$extra;
        $arg=array_merge([$usuarioId],$extraArgs);
        $total=(int)$this->consulta('SELECT COUNT(*) n'.$where,$arg)->fetch_assoc()['n'];
        $paginas=max(1,(int)ceil($total/self::TAMANO_PAGINA));
        $pagina=min($f['pagina'],$paginas);
        $sql='SELECT a.id asignacion_id,a.prospecto_id,a.asignado_at,
                     a.fecha_trabajo,a.proxima_accion_at,a.estado estado_asignacion,
                     p.nombre_completo,p.telefono_original,p.correo,
                     (SELECT GROUP_CONCAT(DISTINCT i.nombre_original SEPARATOR \' · \')
                        FROM prospecto_intereses i WHERE i.prospecto_id=p.id) programas,
                     (SELECT COALESCE(NULLIF(o.aliado_nombre,\'\'),
                                      NULLIF(o.formulario_nombre,\'\'),
                                      CASE o.tipo_origen
                                       WHEN \'META_ADS\' THEN \'Meta Ads\'
                                       WHEN \'ALIADO\' THEN \'Aliado\'
                                       WHEN \'FORMULARIO_GENERAL\' THEN \'Formulario general\'
                                       ELSE \'Registro\' END)
                        FROM prospecto_origenes o
                        WHERE o.prospecto_id=p.id AND o.origen_padre_id IS NULL
                        ORDER BY o.fecha_registro_original DESC,o.id DESC LIMIT 1
                     ) procedencia'
                     .$where.' ORDER BY a.asignado_at DESC,a.id DESC LIMIT ? OFFSET ?';
        $registros=$this->consulta($sql,array_merge($arg,[
            self::TAMANO_PAGINA,($pagina-1)*self::TAMANO_PAGINA
        ]))->fetch_all(MYSQLI_ASSOC);
        $resumen=$this->consulta(
            'SELECT COUNT(*) total,
                COALESCE(SUM(CASE WHEN a.fecha_trabajo<=? OR a.proxima_accion_at<? THEN 1 ELSE 0 END),0) atencion,
                COALESCE(SUM(CASE WHEN a.fecha_trabajo IS NULL AND a.proxima_accion_at IS NULL THEN 1 ELSE 0 END),0) sin_fecha
             FROM prospecto_asignaciones a WHERE a.asesor_id=? AND a.vigente=1',
            [$limites['hoy'],$limites['manana_utc'],$usuarioId]
        )->fetch_assoc();
        return [
            'registros'=>$registros,'total'=>$total,'pagina'=>$pagina,
            'paginas'=>$paginas,'filtros'=>$f,
            'resumen'=>[
                'total'=>(int)$resumen['total'],
                'atencion'=>(int)$resumen['atencion'],
                'sin_fecha'=>(int)$resumen['sin_fecha']
            ]
        ];
    }

    public function detalle(int $usuarioId,int $prospectoId): ?array
    {
        if ($usuarioId<1||$prospectoId<1) return null;
        // Es obligatorio autorizar la persona ANTES de consultar sus fuentes.
        $p=$this->consulta(
            'SELECT p.id,p.nombre_completo,p.nombre,p.apellidos,
                    p.telefono_original,p.correo,p.pais_nombre,p.estado_provincia,p.ciudad,
                    p.verificacion_estado,a.asignado_at,a.fecha_trabajo,
                    a.proxima_accion_at,a.estado estado_asignacion
             FROM prospecto_asignaciones a
             INNER JOIN prospectos_comerciales p ON p.id=a.prospecto_id
             WHERE a.asesor_id=? AND a.vigente=1 AND p.id=? LIMIT 1',
            [$usuarioId,$prospectoId]
        )->fetch_assoc();
        if (!$p) return null;
        $p['programas']=$this->consulta(
            'SELECT nombre_original,clasificacion_estado FROM prospecto_intereses
             WHERE prospecto_id=? ORDER BY id DESC LIMIT 15',
            [$prospectoId]
        )->fetch_all(MYSQLI_ASSOC);
        $p['origenes']=$this->consulta(
            'SELECT tipo_origen,aliado_nombre,formulario_nombre,fecha_registro_original
             FROM prospecto_origenes WHERE prospecto_id=? AND origen_padre_id IS NULL
             ORDER BY fecha_registro_original DESC,id DESC LIMIT 10',
            [$prospectoId]
        )->fetch_all(MYSQLI_ASSOC);
        $p['preferencias']=$this->consulta(
            'SELECT pref.valor_original,pref.dias_semana,pref.hora_desde,pref.hora_hasta,
                    pref.zona_horaria,pref.interpretacion_estado
             FROM prospecto_preferencias_contacto pref
             INNER JOIN prospecto_origenes o ON o.id=pref.origen_id
             WHERE o.prospecto_id=?
             ORDER BY o.fecha_registro_original DESC,o.id DESC LIMIT 3',
            [$prospectoId]
        )->fetch_all(MYSQLI_ASSOC);
        return $p;
    }
}
