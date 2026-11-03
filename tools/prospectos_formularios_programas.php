<?php
/** Preparación explícita y aditiva. El publicador de código nunca invoca este script. */
if (PHP_SAPI!=='cli'){http_response_code(403);exit;}
$args=getopt('',['site:','check','apply','backup-root:']);
try {
    if (isset($args['check'])===isset($args['apply'])) throw new RuntimeException('Elige --check o --apply.');
    $site=realpath($args['site']??dirname(__DIR__));
    if (!$site||!is_file($site.'/config/db_connection.php'))throw new RuntimeException('Sitio inválido.');
    require $site.'/config/db_connection.php';require $site.'/app/services/ProspectoMetaFormularioService.php';
    $db=(new Database())->connect(false);$svc=new ProspectoMetaFormularioService($db);
    if ($svc->disponible()){echo "COMPATIBLE: asociaciones instaladas; datos conservados.\n";exit;}
    if (isset($args['check'])){echo "PENDIENTE: crear una tabla nueva y vacía para asociaciones versionadas. Comprobación de sólo lectura.\n";exit;}
    $backup=$args['backup-root']??'';
    if ($backup===''||!is_dir($backup))throw new RuntimeException('Indica una carpeta privada de respaldo existente.');
    $backup=realpath($backup);
    if (!$backup||$backup===$site||str_starts_with($backup,$site.DIRECTORY_SEPARATOR))throw new RuntimeException('Respaldo fuera del sitio requerido.');
    umask(0077);
    $path=$backup.'/formularios-previo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    if(file_put_contents($path,json_encode(['sitio'=>$site,'tabla'=>'prospecto_formulario_programas','existia'=>false,'fecha_utc'=>gmdate('c')],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se pudo guardar el respaldo previo.');
    $sql=file_get_contents($site.'/database/migrations/2026_11_03_prospectos_formularios_programas.sql');
    if ($sql===false||!preg_match('/^CREATE TABLE IF NOT EXISTS prospecto_formulario_programas \(/',$sql))throw new RuntimeException('Migración no válida.');
    $db->query($sql);if(!$svc->disponible())throw new RuntimeException('Estructura incompleta.');
    echo "APLICADO: una tabla vacía de configuración. No se modificaron prospectos ni asociaciones existentes. Respaldo: ".$path.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
