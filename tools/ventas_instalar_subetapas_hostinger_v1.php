<?php
/**
 * Instalador aditivo exclusivo de DOS tablas nuevas de subetapas.
 *
 * CLI exclusivamente. --check nunca escribe; --install exige confirmación,
 * archivos verificados, metadatos privados y que ambas tablas estén AUSENTES.
 * Nunca modifica tablas históricas, teléfonos, resultados o etapas existentes.
 *
 * NO realiza DROP ni rollback destructivo: la reversión funcional consiste
 * en dejar ambas tablas sin uso; sólo se pueden eliminar con otro plan y una
 * inspección humana cuando se compruebe que siguen vacías.
 */
if (PHP_SAPI!=='cli') {
    http_response_code(403);
    exit("BLOQUEADO: solo CLI.\n");
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
const SQL_BLOB = 'fb8c3028003ddf0e243bb2515d00498728f04306';
const CONFIRMAR = 'INSTALAR_SOLO_SUBETAPAS_IMPE_V1';
const TABLAS = [
    'prospecto_embudo_subetapas',
    'prospecto_embudo_subetapas_historial'
];

function bloquear(string $motivo): void {
    throw new DomainException($motivo);
}
function archivoSeguro(string $ruta): string {
    if (!is_file($ruta) || is_link($ruta)) bloquear('Fuente inexistente o enlazada.');
    $real=realpath($ruta);
    if (!$real) bloquear('Fuente no verificable.');
    return $real;
}
function shaGit(string $contenido): string {
    return sha1('blob '.strlen($contenido)."\0".$contenido);
}
function validarSql(string $ruta): array {
    $contenido=file_get_contents(archivoSeguro($ruta));
    if ($contenido===false || shaGit($contenido)!==SQL_BLOB) {
        bloquear('La migración no coincide con el archivo autorizado por GitHub.');
    }
    $limpio=preg_replace('/^\s*--.*$/m','',$contenido);
    $sentencias=array_values(array_filter(array_map('trim',explode(';',$limpio)),
        static fn($s)=>$s!==''));
    if (count($sentencias)!==2) bloquear('La fuente SQL no contiene exactamente dos tablas.');
    foreach (TABLAS as $i=>$nombre) {
        if (!preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+'.
            preg_quote($nombre,'/').'\s*\(/i',$sentencias[$i])) {
            bloquear('La fuente SQL no contiene la tabla esperada: '.$nombre);
        }
    }
    return $sentencias;
}
function dbConnect(string $site): mysqli {
    $root=realpath($site);
    if (!$root || !is_dir($root) || !is_file($root.'/config/db_connection.php')) {
        bloquear('Raíz del sitio inválida.');
    }
    if (strpos(realpath(__FILE__),$root.DIRECTORY_SEPARATOR)===0) {
        bloquear('El instalador debe permanecer fuera de public_html.');
    }
    require_once $root.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $db->set_charset('utf8mb4');
    return $db;
}
function tablasExistentes(mysqli $db): array {
    $q=$db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN (
          'prospecto_asignaciones',
          'prospecto_embudo_etapas',
          'prospecto_embudo_subetapas',
          'prospecto_embudo_subetapas_historial'
        )");
    $result=[];
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        $result[(string)$r['TABLE_NAME']]=(string)$r['ENGINE'];
    }
    return $result;
}
function verificarColumnas(mysqli $db,string $tabla,array $esperadas): void {
    // Solo nombres y tipos de datos; ningún dato personal.
    $s=$db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->bind_param('s',$tabla);$s->execute();
    $campos=[];
    foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
        $campos[(string)$fila['COLUMN_NAME']]=strtolower((string)$fila['COLUMN_TYPE']);
    }
    $s->close();
    if (array_keys($campos)!==array_keys($esperadas)) {
        bloquear('Columnas incompatibles en '.$tabla.'; no se modifica nada.');
    }
    foreach ($esperadas as $col=>$tipo) {
        if ($campos[$col]!==$tipo) {
            bloquear('Tipo de columna incompatible en '.$tabla.'.'.$col);
        }
    }
}
function verificarIndices(mysqli $db,string $tabla,array $esperados): void {
    $s=$db->prepare("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
      ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    $s->bind_param('s',$tabla);$s->execute();
    $indices=[];
    foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $fila)
        $indices[(string)$fila['INDEX_NAME']][]=(string)$fila['COLUMN_NAME'];
    $s->close();
    ksort($indices);ksort($esperados);
    if ($indices!==$esperados) bloquear('Índices incompatibles en '.$tabla);
}
function verificarFk(mysqli $db,string $tabla,string $nombreFk): void {
    $s=$db->prepare("SELECT CONSTRAINT_NAME, COLUMN_NAME,
        REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
        AND REFERENCED_TABLE_NAME IS NOT NULL");
    $s->bind_param('s',$tabla);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($rows)!==1 || $rows[0]['CONSTRAINT_NAME']!==$nombreFk ||
       $rows[0]['COLUMN_NAME']!=='asignacion_id' ||
       $rows[0]['REFERENCED_TABLE_NAME']!=='prospecto_asignaciones' ||
       $rows[0]['REFERENCED_COLUMN_NAME']!=='id') {
        bloquear('Clave foránea incompatible en '.$tabla);
    }
}
function comprobarNuevas(mysqli $db): void {
    verificarColumnas($db,TABLAS[0],[
        'asignacion_id'=>'bigint unsigned','subetapa'=>'varchar(40)',
        'actualizado_por'=>'int','actualizado_at'=>'datetime',
        'version'=>'int unsigned'
    ]);
    verificarIndices($db,TABLAS[0],[
        'PRIMARY'=>['asignacion_id'],
        'idx_subetapas_subetapa'=>['subetapa','actualizado_at']
    ]);
    verificarFk($db,TABLAS[0],'fk_embudo_subetapas_asignacion');
    verificarColumnas($db,TABLAS[1],[
        'id'=>'bigint unsigned','asignacion_id'=>'bigint unsigned',
        'etapa_en_evento'=>'varchar(40)',
        'subetapa_anterior'=>'varchar(40)',
        'subetapa_nueva'=>'varchar(40)',
        'motivo'=>'varchar(190)','origen'=>'varchar(20)',
        'usuario_id'=>'int','creado_at'=>'datetime'
    ]);
    verificarIndices($db,TABLAS[1],[
        'PRIMARY'=>['id'],
        'idx_embudo_subetapa_historial'=>['asignacion_id','creado_at','id']
    ]);
    verificarFk($db,TABLAS[1],'fk_embudo_subetapa_historial_asignacion');
    foreach(TABLAS as $tabla){
        $res=$db->query('SELECT COUNT(*) n FROM '.$tabla)->fetch_assoc();
        printf("VERIFICADA: %s (%d filas)\n",$tabla,(int)$res['n']);
    }
}
function revisar(mysqli $db): array {
    $t=tablasExistentes($db);
    foreach(['prospecto_asignaciones','prospecto_embudo_etapas'] as $requerida) {
        if (($t[$requerida]??'')!=='InnoDB') {
            bloquear('Estructura base no compatible: '.$requerida);
        }
    }
    verificarColumnasBase($db);
    $a=isset($t[TABLAS[0]]);$b=isset($t[TABLAS[1]]);
    if ($a!==$b) bloquear('Instalación parcial detectada; no continuar automáticamente.');
    if ($a) {
        if($t[TABLAS[0]]!=='InnoDB' || $t[TABLAS[1]]!=='InnoDB')
            bloquear('Una tabla de subetapas no es InnoDB.');
        comprobarNuevas($db);
        echo "YA INSTALADO: dos tablas compatibles; no requieren cambios.\n";
    } else {
        echo "PREPARADO: solo se crearían dos tablas vacías, sin cambiar registros existentes.\n";
    }
    $conteos=[];
    $res=$db->query("SELECT etapa,COUNT(*) n FROM prospecto_embudo_etapas
        GROUP BY etapa ORDER BY etapa");
    foreach($res->fetch_all(MYSQLI_ASSOC) as $fila){
        $e=(string)$fila['etapa'];
        if (!preg_match('/^[A-Z0-9_]{1,40}$/D',$e)) bloquear('Etapa desconocida.');
        $conteos[$e]=(int)$fila['n'];
    }
    echo "ETAPAS PREEXISTENTES (solo conteos):\n";
    foreach($conteos as $etapa=>$total)printf("  %s: %d\n",$etapa,$total);
    return ['instalado'=>$a,'conteos'=>$conteos];
}
function verificarColumnasBase(mysqli $db): void {
    // FK BIGINT UNSIGNED a la PK real; evita crear tablas con referencias falsas.
    $s=$db->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prospecto_asignaciones'
        AND COLUMN_NAME='id' LIMIT 1")->fetch_assoc();
    if(!$s || strtolower((string)$s['COLUMN_TYPE'])!=='bigint unsigned')
        bloquear('La PK de asignaciones no es BIGINT UNSIGNED.');
}
function carpetaRespaldo(string $root,string $siteRoot): string {
    $base=rtrim($root,DIRECTORY_SEPARATOR);
    if ($base==='' || $base==='/') bloquear('Ruta de respaldo no válida.');
    if(!is_dir($base) && !mkdir($base,0700,true)) bloquear('No se pudo crear respaldo.');
    $base=realpath($base);
    $site=realpath($siteRoot);
    if (!$base || !$site ||
        $base===$site || strpos($base,$site.DIRECTORY_SEPARATOR)===0) {
        bloquear('El respaldo debe estar fuera de public_html.');
    }
    if(is_link($base)) bloquear('No se admiten rutas de respaldo enlazadas.');
    chmod($base,0700);
    $sub=$base.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if(!mkdir($sub,0700)) bloquear('No se pudo reservar respaldo privado.');
    chmod($sub,0700);
    return $sub;
}
function guardarRespaldo(mysqli $db,string $dir,array $state): void {
    $metadata=[
        'fecha_utc'=>gmdate('c'),
        'esquema'=>SQL_BLOB,
        'antes'=>$state,
        'alcance'=>'CREAR DOS TABLAS NUEVAS VACIAS; NO RESPALDO COMPLETO DE LA BD'
    ];
    foreach(['prospecto_asignaciones','prospecto_embudo_etapas'] as $t){
        $res=$db->query('SHOW CREATE TABLE '.$t)->fetch_assoc();
        $metadata['create_base'][$t]=(string)($res['Create Table']??'');
    }
    $f=$dir.'/preinstalacion.json';
    if (file_put_contents($f,json_encode($metadata,JSON_PRETTY_PRINT|
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)
        bloquear('Falló el respaldo privado; no se instalará.');
    chmod($f,0600);
    if(!is_file($f) || filesize($f)<200)
        bloquear('Respaldo no verificable; no se instalará.');
}
function opciones(array $argv): array {
    $result=['--check'=>false,'--install'=>false];
    foreach($argv as $i=>$arg){
        if($i===0)continue;
        if($arg==='--check'||$arg==='--install'){
            if($result[$arg])bloquear('Modo duplicado.');
            $result[$arg]=true;
            continue;
        }
        if(in_array($arg,['--sql','--backup-root','--confirm','--site'],true)){
            if(!isset($argv[$i+1]) || strpos($argv[$i+1],'--')===0)
                bloquear('Falta valor para '.$arg);
            $result[$arg]=$argv[$i+1];
            continue;
        }
        if($i>1 && in_array($argv[$i-1],[
            '--sql','--backup-root','--confirm','--site'
        ],true)) continue;
        bloquear('Argumento no reconocido.');
    }
    if($result['--check']===$result['--install']) bloquear('Elige solo --check o --install.');
    if(empty($result['--site']) || empty($result['--sql']))
        bloquear('Indica --site y --sql.');
    if($result['--install'] && (
        ($result['--confirm']??'')!==CONFIRMAR ||
        empty($result['--backup-root'])
    )) bloquear('La instalación requiere confirmación y respaldo privados.');
    return $result;
}
$db=null;
try{
    $opts=opciones($argv);
    $sql=validarSql($opts['--sql']);
    $db=dbConnect($opts['--site']);
    $antes=revisar($db);
    if($opts['--check']){
        echo "CHECK OK: solo lectura, sin SQL de modificación.\n";
    }elseif($antes['instalado']){
        echo "SIN CAMBIOS: subetapas ya instaladas y verificadas.\n";
    }else{
        $dir=carpetaRespaldo($opts['--backup-root'],$opts['--site']);
        guardarRespaldo($db,$dir,$antes);
        // DDL MySQL se autoconfirma; nunca fingir transacción reversible.
        // Si falla el segundo CREATE, no borrar el primero sin inspección.
        foreach(TABLAS as $i=>$nombre){
            $ahora=tablasExistentes($db);
            if(isset($ahora[$nombre]))
                bloquear('La estructura cambió antes de crear '.$nombre);
            $db->query($sql[$i]);
            echo "CREADA: ".$nombre."\n";
        }
        $despues=revisar($db);
        if(!$despues['instalado'])bloquear('La verificación posterior falló.');
        echo "RESPALDO PRIVADO DE ESQUEMA: ".$dir."\n";
        echo "POST-CHECK OK: solo dos tablas nuevas; sin cambios en datos existentes.\n";
    }
}catch(Throwable $e){
    fwrite(STDERR,"BLOQUEADO: ".($e instanceof DomainException?
        $e->getMessage():'Error en esquema o conexión. Revisar salida; NO reintentar instalación a ciegas.')."\n");
    exit(2);
}finally{
    if($db instanceof mysqli){
        try{$db->close();}catch(Throwable $ignored){}
    }
}
