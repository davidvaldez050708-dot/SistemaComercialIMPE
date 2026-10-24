<?php
// Migración aditiva: no reescribe filas, procedencias, convocatorias ni enlaces.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
try {
    $mode=null; $site=dirname(__DIR__);
    for ($i=1;$i<$argc;$i++) {
        if (in_array($argv[$i],['--check','--apply'],true) && $mode===null) $mode=$argv[$i];
        elseif ($argv[$i]==='--site' && isset($argv[$i+1])) $site=$argv[++$i];
        else throw new RuntimeException('Usa --site RUTA --check o --apply.');
    }
    if (!$mode || !is_file($site.'/config/db_connection.php')) throw new RuntimeException('Falta el sitio o el modo de ejecución.');
    require_once $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $cols=$db->query('SHOW COLUMNS FROM formulario_registros')->fetch_all(MYSQLI_ASSOC);
    $column=array_column($cols,null,'Field')['horario_contacto']??null;
    if ($column) {
        if (strtolower($column['Type'])!=='varchar(255)' || $column['Null']!=='YES' || $column['Default']!==null || $column['Extra']!=='') {
            throw new RuntimeException('La columna de horario tiene otra definición; no se modificó.');
        }
        echo "OK: horario_contacto instalado; registros y enlaces conservados.\n"; exit;
    }
    if ($mode==='--check') {
        echo "PENDIENTE: horario_contacto. SITIO INTACTO. SQL de escritura no ejecutado.\n"; exit;
    }
    $db->query('ALTER TABLE formulario_registros ADD COLUMN horario_contacto VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
    echo "APLICADO: columna opcional de horario de contacto. Registros anteriores sin horario; enlaces y QR conservados.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'ERROR: '.$error->getMessage().PHP_EOL); exit(1);
}
