<?php
/** Preparación aditiva de Inscripción. Exclusivo CLI; no altera tablas existentes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function inscripcionTipo(string $tipo): string
{
    return preg_replace('/\b(int|bigint)\([0-9]+\)/', '$1', strtolower($tipo));
}

function inscripcionBase(mysqli $db): void
{
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='formulario_inscripciones'")->fetch_assoc();
    if (!$engine || strtoupper($engine['ENGINE']) !== 'INNODB') throw new RuntimeException('La tabla actual de inscripciones debe existir y ser InnoDB.');
    $campos = array_column($db->query('SHOW COLUMNS FROM formulario_inscripciones')->fetch_all(MYSQLI_ASSOC), null, 'Field');
    $esperados = ['id'=>'int unsigned','nombre'=>'varchar(80)','apellido'=>'varchar(120)',
        'fecha_nacimiento'=>'date','movil'=>'varchar(10)','correo'=>'varchar(190)',
        'perfil_interes'=>'varchar(100)','lugar_laboras'=>'varchar(180)','cargo_puesto'=>'varchar(160)',
        'estado_id'=>'int','municipio_id'=>'int','creado_por'=>'int','origen'=>'varchar(30)',
        'created_at'=>'datetime','updated_at'=>'datetime'];
    foreach ($esperados as $nombre=>$tipo) {
        if (!isset($campos[$nombre]) || inscripcionTipo($campos[$nombre]['Type']) !== $tipo) throw new RuntimeException('Esquema actual diferente: '.$nombre.'. No se cambió nada.');
    }
    if ($campos['id']['Extra'] !== 'auto_increment' || $campos['id']['Key'] !== 'PRI') throw new RuntimeException('Identificador actual diferente.');
    foreach ($campos as $nombre=>$campo) {
        if (!isset($esperados[$nombre]) && $campo['Null'] === 'NO' && $campo['Default'] === null) throw new RuntimeException('Campo adicional obligatorio sin valor predeterminado: '.$nombre);
    }
}

function inscripcionTablaCompleta(mysqli $db): bool
{
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='formulario_inscripciones_completas'")->fetch_assoc();
    if (!$engine) return false;
    if (strtoupper($engine['ENGINE']) !== 'INNODB') throw new RuntimeException('La tabla adicional existente tiene un motor diferente.');
    $esperados = ['inscripcion_id'=>'int unsigned','programa_slug'=>'varchar(80)',
        'solicitud_token'=>'char(64)','identidad_programa'=>'char(64)','datos'=>'longtext',
        'comprobante_archivo'=>'varchar(64)','comprobante_mime'=>'varchar(40)',
        'comprobante_sha256'=>'char(64)','comprobante_tamano'=>'int unsigned',
        'terminos_sha256'=>'char(64)','privacidad_sha256'=>'char(64)','aceptado_at'=>'datetime'];
    $campos = array_column($db->query('SHOW COLUMNS FROM formulario_inscripciones_completas')->fetch_all(MYSQLI_ASSOC), null, 'Field');
    if (array_keys($campos) !== array_keys($esperados)) throw new RuntimeException('La tabla adicional ya existe con campos distintos. No se sustituirá.');
    foreach ($esperados as $nombre=>$tipo) {
        if (inscripcionTipo($campos[$nombre]['Type']) !== $tipo || $campos[$nombre]['Null'] !== 'NO') throw new RuntimeException('Campo adicional incompatible: '.$nombre);
    }
    $indices = [];
    foreach ($db->query('SHOW INDEX FROM formulario_inscripciones_completas')->fetch_all(MYSQLI_ASSOC) as $fila) {
        $indices[$fila['Key_name']]['campos'][(int)$fila['Seq_in_index']] = $fila['Column_name'];
        $indices[$fila['Key_name']]['unico'] = (int)$fila['Non_unique'] === 0;
    }
    foreach (['PRIMARY'=>['inscripcion_id'],'uq_inscripcion_solicitud'=>['solicitud_token'],
        'uq_inscripcion_identidad'=>['identidad_programa'],'idx_inscripcion_programa_fecha'=>['programa_slug','aceptado_at']] as $nombre=>$lista) {
        if (!isset($indices[$nombre])) throw new RuntimeException('Falta el índice '.$nombre);
        ksort($indices[$nombre]['campos']);
        if (array_values($indices[$nombre]['campos']) !== $lista || $indices[$nombre]['unico'] !== ($nombre !== 'idx_inscripcion_programa_fecha')) throw new RuntimeException('Índice incompatible: '.$nombre);
    }
    return true;
}

$db = null; $lock = null;
try {
    $opciones = getopt('', ['site:','backup-root:','schema:','check','apply']);
    if (!isset($opciones['site'],$opciones['backup-root'],$opciones['schema']) || isset($opciones['check']) === isset($opciones['apply'])) throw new RuntimeException('Usa --site RUTA --backup-root RUTA --schema ARCHIVO y --check o --apply.');
    $site = realpath($opciones['site']);
    if (!$site || !is_file($site.'/config/db_connection.php')) throw new RuntimeException('Sitio no reconocido.');
    $sql = file_get_contents($opciones['schema']);
    if (!is_string($sql) || hash('sha256',$sql) !== '7ed8e0affc9874cb04874215669a2dbe97bf834c97951ba4848ad306792e1ed6') throw new RuntimeException('Esquema distinto al aprobado; no se ejecutó SQL de escritura.');
    require $site.'/config/db_connection.php';
    $db = (new Database())->connect(false);
    inscripcionBase($db);
    $existe = inscripcionTablaCompleta($db);
    if (isset($opciones['check'])) {
        echo 'CHECK OK: esquema actual compatible. '.($existe ? 'Tabla adicional ya preparada.' : 'Se creará únicamente formulario_inscripciones_completas.')." Sin escrituras ni lectura de datos personales.\n";
        exit;
    }
    if ($existe) { echo "YA HABILITADO: esquema compatible; sin cambios.\n"; exit; }
    $root = $opciones['backup-root'];
    if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/])~',$root) || strpos($root,'..') !== false || is_link($root)) throw new RuntimeException('Usa una ruta privada absoluta para el respaldo.');
    // Verificar el destino antes de crear carpetas, incluso si sus padres son enlaces.
    $parent = $root;
    while (!file_exists($parent)) $parent = dirname($parent);
    $parent = realpath($parent);
    if (!$parent || $parent === $site || str_starts_with($parent,$site.DIRECTORY_SEPARATOR)) throw new RuntimeException('El respaldo debe quedar fuera de public_html.');
    umask(0077);
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new RuntimeException('No se pudo preparar el respaldo privado.');
    $root = realpath($root);
    if ($root === $site || str_starts_with($root,$site.DIRECTORY_SEPARATOR)) throw new RuntimeException('Respaldo dentro del sitio.');
    $nombre = 'impe-inscripcion-'.substr(hash('sha256',$db->query('SELECT DATABASE() n')->fetch_assoc()['n']),0,32);
    $s = $db->prepare('SELECT GET_LOCK(?,5) adquirido');$s->bind_param('s',$nombre);$s->execute();
    if ((int)$s->get_result()->fetch_assoc()['adquirido'] !== 1) throw new RuntimeException('Otra preparación está en curso.');
    $lock = $nombre;
    inscripcionBase($db);
    if (inscripcionTablaCompleta($db)) {echo "YA HABILITADO: otra ejecución preparó la tabla.\n";exit;}
    $ddl = $db->query('SHOW CREATE TABLE formulario_inscripciones')->fetch_row()[1];
    $respaldo = $root.'/esquema-inscripcion-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
    $json = json_encode(['fecha_utc'=>gmdate('c'),'tabla_existente_ddl'=>$ddl,
        'tabla_nueva_previamente_existente'=>false,'sql_nuevo'=>$sql,
        'alcance'=>'Sólo creación de una tabla adicional. Ninguna fila existente se modifica.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $f = fopen($respaldo,'x');
    if (!$f) throw new RuntimeException('No se pudo crear el respaldo previo.');
    try {if (fwrite($f,$json) !== strlen($json) || !fflush($f)) throw new RuntimeException('Respaldo incompleto; no se creó la tabla.');} finally {fclose($f);}
    $db->query($sql);
    if (!inscripcionTablaCompleta($db) || $db->query('SHOW CREATE TABLE formulario_inscripciones')->fetch_row()[1] !== $ddl) throw new RuntimeException('La comprobación posterior requiere revisión. No se intentará borrar tablas.');
    echo "APLICADO: tabla adicional de inscripciones habilitada. Registros existentes intactos. RESPALDO DE ESQUEMA: ".$respaldo."\n";
} catch (Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n"); exit(1);
} finally {
    if ($lock !== null && $db instanceof mysqli) {
        $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();
    }
}
