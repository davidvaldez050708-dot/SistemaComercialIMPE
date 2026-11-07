<?php
require_once __DIR__.'/ProspectoMetaClasificacion.php';

/**
 * Bitácora inmutable por archivo Meta COMPLETADO.
 * No conserva contactos, teléfonos, correos, IDs individuales ni el archivo.
 * Los registros anteriores a su instalación no se inventan.
 */
final class ProspectoMetaImportacionesHistorial
{
    private mysqli $db;
    private const POR_PAGINA=15;
    private const MAX_FORMULARIOS_DETALLE=30;

    public function __construct(mysqli $db) {$this->db=$db;}

    private function consulta(string $sql,array $params=[]): mysqli_result
    {
        $st=$this->db->prepare($sql);
        if ($params) {
            $types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$params));
            $st->bind_param($types,...$params);
        }
        $st->execute();
        $r=$st->get_result();
        $st->close();
        if (!$r) throw new RuntimeException('No se pudo consultar el historial de Meta.');
        return $r;
    }

    public function disponible(): bool
    {
        $rs=$this->consulta(
            "SELECT COUNT(*) n FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='prospecto_meta_importaciones_historial'
               AND ENGINE='InnoDB'"
        )->fetch_assoc();
        return (int)$rs['n']===1;
    }

    /**
     * Atributos de procedencia exclusivamente: una descripción por formulario.
     * La categoría programática se toma de la clasificación CONFIRMADA,
     * incluidas excepciones explícitas de campañas; nunca del nombre de campaña.
     */
    public static function resumenFormularios(array $data,array $programas): array
    {
        $forms=[];
        foreach (($data['formularios']??[]) as $f) {
            if (!is_array($f)||!isset($f['clave'])) continue;
            $forms[(string)$f['clave']]=[
                'id'=>mb_substr((string)($f['id']??''),0,190),
                'nombre'=>mb_substr((string)($f['nombre']??''),0,255),
                'total'=>0,'programas'=>[]
            ];
        }
        foreach (($data['filas']??[]) as $row) {
            $key=(string)($row['formulario_clave']??'');
            if (!isset($forms[$key])) continue;
            $forms[$key]['total']++;
            $pr=ProspectoMetaClasificacion::programaFila($programas,$row);
            $codigo=(string)($pr['codigo']??'');
            $nombre=(string)($pr['nombre']??'');
            if ($codigo!==''&&$nombre!=='' && count($forms[$key]['programas'])<10) {
                $forms[$key]['programas'][$codigo]=mb_substr($nombre,0,255);
            }
        }
        $total=count($forms);
        $items=array_values($forms);
        usort($items,static fn($a,$b)=>$b['total']<=>$a['total']);
        $items=array_slice($items,0,self::MAX_FORMULARIOS_DETALLE);
        foreach ($items as &$f) $f['programas']=array_values($f['programas']);
        unset($f);
        return ['total'=>$total,'mostrados'=>count($items),'formularios'=>$items];
    }

    /**
     * Se invoca DESPUÉS del último lote y en la MISMA transacción que
     * confirmarLoteMeta(). El índice primario evita duplicados por reintento.
     */
    public function registrarCompletada(array $meta,array $data): void
    {
        if (!$this->disponible()) {
            throw new RuntimeException(
                'La estructura del historial de importaciones no está instalada.'
            );
        }
        $token=(string)($meta['token']??'');
        $archivo=(string)($meta['archivo']??'');
        $usuario=(int)($meta['usuario_id']??0);
        if (!preg_match('/^[a-f0-9]{32}$/D',$token) || $usuario<=0 ||
            $archivo==='' || mb_strlen($archivo)>140 ||
            !mb_check_encoding($archivo,'UTF-8')) {
            throw new DomainException('Identificación del archivo de Meta inválida.');
        }
        $total=(int)($data['total_filas']??-1);
        $validas=(int)($data['validas']??-1);
        $nuevos=(int)($meta['nuevos']??-1);
        $repetidos=(int)($meta['repetidos']??-1);
        $repArchivo=(int)($data['repetidas_archivo']??-1);
        $rechazados=(int)($data['rechazadas']??-1);
        if (min($total,$validas,$nuevos,$repetidos,$repArchivo,$rechazados)<0 ||
            $nuevos+$repetidos!==$validas ||
            $validas+$repArchivo+$rechazados!==$total ||
            (int)($meta['avance']??-1)!==$validas) {
            throw new RuntimeException('Las cifras finales de Meta no concuerdan.');
        }
        $forms=self::resumenFormularios($data,$meta['programas']??[]);
        $json=json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        // Un archivo de hasta 5,000 leads puede contener muchos formularios
        // con nombres Unicode extensos. El historial no debe bloquear la
        // confirmación por exceder TEXT: conservar los totales y recortar
        // sólo el detalle mostrado, empezando por los formularios menores.
        while (strlen($json)>60000 && $forms['formularios']) {
            array_pop($forms['formularios']);
            $forms['mostrados']=count($forms['formularios']);
            $json=json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }
        if (strlen($json)>60000) {
            throw new RuntimeException('No se pudo acotar el resumen de formularios.');
        }
        $campanias=count($data['campanias']??[]);
        $anterior=$this->consulta(
            'SELECT usuario_id,nombre_archivo,filas_leidas,filas_importables,
                    nuevos,repetidos_crm,repetidos_archivo,rechazados
             FROM prospecto_meta_importaciones_historial
             WHERE lote_ref=? FOR UPDATE',[$token]
        )->fetch_assoc();
        if ($anterior) {
            if ((int)$anterior['usuario_id']!==$usuario ||
                $anterior['nombre_archivo']!==$archivo ||
                (int)$anterior['filas_leidas']!==$total ||
                (int)$anterior['filas_importables']!==$validas ||
                (int)$anterior['nuevos']!==$nuevos ||
                (int)$anterior['repetidos_crm']!==$repetidos ||
                (int)$anterior['repetidos_archivo']!==$repArchivo ||
                (int)$anterior['rechazados']!==$rechazados) {
                throw new DomainException('Este lote de Meta ya se confirmó con otras cifras.');
            }
            return; // Reintento idéntico: fecha y contadores originales intactos.
        }
        $st=$this->db->prepare(
            'INSERT INTO prospecto_meta_importaciones_historial
             (lote_ref,usuario_id,nombre_archivo,filas_leidas,filas_importables,
              nuevos,repetidos_crm,repetidos_archivo,rechazados,formularios_total,
              campanias_total,formularios_resumen_json,terminado_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $st->bind_param('sisiiiiiiiis',
            $token,$usuario,$archivo,$total,$validas,
            $nuevos,$repetidos,$repArchivo,$rechazados,$forms['total'],
            $campanias,$json
        );
        $st->execute();
        $st->close();
    }

    private static function fechaLocal($str,bool $fin): ?string
    {
        if ($str===null||$str==='') return null;
        if (!is_string($str)||!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$str)) {
            throw new DomainException('Fecha de historial inválida.');
        }
        $parts=explode('-',$str);
        if (!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0])) {
            throw new DomainException('Fecha de historial inválida.');
        }
        $v=new DateTimeImmutable($str.' 00:00:00',new DateTimeZone('America/Mexico_City'));
        if ($fin) $v=$v->modify('+1 day');
        return $v->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function listar(array $filtros=[]): array
    {
        if (!$this->disponible()) throw new RuntimeException(
            'La estructura del historial de importaciones no está instalada.'
        );
        $page=filter_var($filtros['pagina']??1,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]])?:1;
        $buscar=$filtros['buscar']??'';
        if (!is_string($buscar)||mb_strlen($buscar)>100) throw new DomainException(
            'Búsqueda del historial inválida.'
        );
        $desde=self::fechaLocal($filtros['desde']??null,false);
        $hasta=self::fechaLocal($filtros['hasta']??null,true);
        if ($desde!==null&&$hasta!==null&&$desde>=$hasta) {
            throw new DomainException('El intervalo de fechas no es válido.');
        }
        $where=' WHERE 1=1';$args=[];
        if (trim($buscar)!=='') {
            $texto='%'.strtr(trim($buscar),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where.=" AND (h.nombre_archivo LIKE ? ESCAPE '!'
                     OR h.formularios_resumen_json LIKE ? ESCAPE '!')";
            $args[]=$texto;$args[]=$texto;
        }
        if ($desde!==null) {$where.=' AND h.terminado_at>=?';$args[]=$desde;}
        if ($hasta!==null) {$where.=' AND h.terminado_at<?';$args[]=$hasta;}
        $aggregate=$this->consulta(
            'SELECT COUNT(*) total,COALESCE(SUM(h.filas_leidas),0) filas,
                    COALESCE(SUM(h.nuevos),0) nuevos,
                    COALESCE(SUM(h.repetidos_crm+h.repetidos_archivo),0) repetidos,
                    COALESCE(SUM(h.rechazados),0) rechazados
             FROM prospecto_meta_importaciones_historial h'.$where,$args
        )->fetch_assoc();
        $total=(int)$aggregate['total'];
        $pages=max(1,(int)ceil($total/self::POR_PAGINA));
        $page=min($pages,$page);
        $offset=($page-1)*self::POR_PAGINA;
        $rows=$this->consulta(
            "SELECT h.*,CONCAT_WS(CHAR(32),u.nombre,u.apellidos) responsable
             FROM prospecto_meta_importaciones_historial h
             LEFT JOIN usuarios u ON u.id=h.usuario_id
             $where ORDER BY h.terminado_at DESC,h.lote_ref DESC LIMIT ? OFFSET ?",
            [...$args,self::POR_PAGINA,$offset]
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $resumen=json_decode((string)$r['formularios_resumen_json'],true);
            $r['formularios_detalle']=is_array($resumen['formularios']??null)
                ?$resumen['formularios']:[];
            unset($r['formularios_resumen_json']);
        }
        unset($r);
        return ['registros'=>$rows,'total'=>$total,'pagina'=>$page,'paginas'=>$pages,
            'limite'=>self::POR_PAGINA,'indicadores'=>[
                'filas'=>(int)$aggregate['filas'],
                'nuevos'=>(int)$aggregate['nuevos'],
                'repetidos'=>(int)$aggregate['repetidos'],
                'rechazados'=>(int)$aggregate['rechazados']
            ]];
    }
}
