<?php
/**
 * Preflight privado y SOLO LECTURA de Recuperación V3.
 * Sin HTTP, sin SQL de escritura, sin IDs ni datos personales en la salida.
 * No instala nada: muestra distribución de estados sobre una muestra acotada.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
if (!function_exists('getopt')) {
    fwrite(STDERR, "BLOQUEADO: PHP CLI incompatible.\n");
    exit(2);
}
$opts=getopt('', ['site:','dia::','asesor-id::','limite::','offset::']);
if (!is_array($opts) || empty($opts['site']) || !is_string($opts['site'])) {
    fwrite(STDERR, "Uso: php auditoria.php --site=PUBLIC_HTML [--dia=YYYY-MM-DD] [--asesor-id=N] [--limite=120] [--offset=0]\n");
    exit(2);
}
function paramInt(array $opts, string $nombre, int $defecto, int $min, int $max): int
{
    if (!array_key_exists($nombre,$opts)) return $defecto;
    $valor=filter_var($opts[$nombre], FILTER_VALIDATE_INT);
    if ($valor===false || $valor<$min || $valor>$max) {
        throw new InvalidArgumentException('Parámetro inválido: '.$nombre);
    }
    return $valor;
}
function blobGit(string $path): string
{
    $raw=file_get_contents($path);
    if ($raw===false) throw new RuntimeException('Archivo no disponible.');
    return sha1('blob '.strlen($raw)."\0".$raw);
}
function rutaSegura(string $raiz, string $rel): string
{
    $ruta=realpath($raiz.'/'.$rel);
    if ($ruta===false || strpos($ruta,$raiz.'/')!==0 ||
        is_link($raiz.'/'.$rel) || !is_file($ruta)) {
        throw new RuntimeException('Dependencia ausente o insegura: '.$rel);
    }
    return $ruta;
}
try {
    $site=realpath($opts['site']);
    if (!$site || !is_dir($site) || basename($site)!=='public_html') {
        throw new RuntimeException('El sitio debe ser public_html existente.');
    }
    $dia=$opts['dia']??(new DateTimeImmutable('today',
        new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    if (!is_string($dia)) throw new InvalidArgumentException('Día inválido.');
    $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$dia,
        new DateTimeZone('America/Mexico_City'));
    if (!$fecha || $fecha->format('Y-m-d')!==$dia) {
        throw new InvalidArgumentException('Día local inválido.');
    }
    $limite=paramInt($opts,'limite',120,1,120);
    $offset=paramInt($opts,'offset',0,0,2000000);
    $asesor=paramInt($opts,'asesor-id',0,1,2000000000);
    $guardias=[
        'app/services/EmbudoComercialV3IndicadoresVista.php'=>'c7419b2521392c48a0eebefb54bc9c7fd1cfda1c',
        'app/services/EmbudoComercialEtapas.php'=>'a2cc9493b3d5b980096c768b15fecbfa9af710a8',
        'app/services/EmbudoComercialResultadoBridge.php'=>'f03f8da725dfe3645db9ce9e1a76e11c38c81427',
    ];
    foreach ($guardias as $archivo=>$esperado) {
        if (blobGit(rutaSegura($site,$archivo))!==$esperado) {
            throw new RuntimeException('Dependencia personalizada, revisar antes de auditar: '.$archivo);
        }
    }
    require_once rutaSegura($site,'config/db_connection.php');
    require_once rutaSegura($site,'app/services/EmbudoComercialV3IndicadoresVista.php');
    require_once __DIR__.'/../app/services/EmbudoComercialV3RecuperacionAuditoria.php';
    $db=(new Database())->connect(false);
    $db->query('START TRANSACTION READ ONLY');
    try {
        $tablas=[
            'prospecto_asignaciones','prospecto_embudo_etapas',
            'prospecto_embudo_llamadas','telefonia_ventas_llamadas',
            'telefonia_ventas_resultados','telefonia_ventas_resultado_detalles'
        ];
        foreach ($tablas as $tabla) {
            $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $s->bind_param('s',$tabla);
            $s->execute();
            $fila=$s->get_result()->fetch_assoc();
            $s->close();
            if (!$fila || strtoupper((string)$fila['ENGINE'])!=='INNODB') {
                throw new RuntimeException('Estructura incompatible: '.$tabla);
            }
        }
        $where='a.vigente=1';
        if ($asesor>0) $where.=' AND a.asesor_id=?';
        $q=$db->prepare("SELECT COUNT(*) n FROM prospecto_asignaciones a WHERE ".$where);
        if ($asesor>0) $q->bind_param('i',$asesor);
        $q->execute();
        $total=(int)$q->get_result()->fetch_assoc()['n'];
        $q->close();
        $q=$db->prepare("SELECT a.id,COALESCE(e.etapa,'NUEVO') etapa
            FROM prospecto_asignaciones a
            LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            WHERE ".$where." ORDER BY a.id DESC LIMIT ? OFFSET ?");
        if ($asesor>0) $q->bind_param('iii',$asesor,$limite,$offset);
        else $q->bind_param('ii',$limite,$offset);
        $q->execute();
        $filas=$q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
        $ids=array_map(static function($r){return (int)$r['id'];},$filas);
        $indicadores=(new EmbudoComercialV3IndicadoresVista($db))
            ->intentosDelDia($ids,$dia);
        $resumen=[];
        foreach($filas as $r) {
            $id=(int)$r['id'];
            $n=(int)($indicadores[$id]['intentos']??0);
            $clasificacion=EmbudoComercialV3RecuperacionAuditoria::clasificar((string)$r['etapa'],$n);
            $codigo=$clasificacion['codigo'];
            $resumen[$codigo]=($resumen[$codigo]??0)+1;
        }
        ksort($resumen);
        $db->rollback();
        $db->close();
        echo "AUDITORIA_RECUPERACION_V3_SOLO_LECTURA\n";
        echo "FECHA_LOCAL: ".$dia."\n";
        echo "ALCANCE: ".($asesor>0?'UN_ASESOR':'TODOS_LOS_ASESORES')."\n";
        echo "ASIGNACIONES_VIGENTES_TOTALES: ".$total."\n";
        echo "MUESTRA_REVISADA: ".count($filas)."\n";
        echo "OFFSET: ".$offset."\n";
        echo "HAY_MAS: ".($offset+count($filas)<$total?'SI':'NO')."\n";
        foreach($resumen as $codigo=>$n) echo $codigo.": ".$n."\n";
        echo "RIESGO_REGLA_ANTIGUA: primera llamada sin respuesta puede proponer RECUPERACION; requiere conciliacion, no se aplican cambios.\n";
        echo "SIN_SQL_ESCRITURA_SIN_MOVIMIENTOS_SIN_CONTACTOS\n";
    } catch(Throwable $e) {
        $db->rollback();
        throw $e;
    }
} catch(Throwable $e) {
    fwrite(STDERR,"BLOQUEADO: ".$e->getMessage()."\n");
    exit(2);
}
