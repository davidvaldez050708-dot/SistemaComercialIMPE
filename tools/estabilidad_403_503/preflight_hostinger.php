<?php
declare(strict_types=1);
// Solo lectura. No carga configuración del CRM, no ejecuta modelos ni SQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function pfRun(array $command): array {
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo ejecutar comprobación.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>$out,'stderr'=>$err];
}
try {
    $args=getopt('',['root:']);
    if (empty($args['root']) || !is_string($args['root'])) throw new RuntimeException('Se requiere --root /ruta/real/public_html');
    $root=realpath((string)($args['root']??''));
    if (!$root || !is_dir($root)) throw new RuntimeException('Uso: php preflight_hostinger.php --root /ruta/real/public_html');
    $manifest=json_decode((string)file_get_contents(__DIR__.'/preflight_dependencies.json'),true,512,JSON_THROW_ON_ERROR);
    $result=['time_utc'=>gmdate('c'),'scope'=>'read-only CLI; no CRM includes, SQL or writes',
        'php_cli'=>PHP_VERSION,'sapi'=>PHP_SAPI,'web_context'=>'NOT_VERIFIED',
        'extensions'=>[], 'files'=>[], 'problems'=>[]];
    foreach (['mysqli','json','session','mbstring','openssl','curl'] as $extension) {
        $result['extensions'][$extension]=extension_loaded($extension);
    }
    if (PHP_VERSION_ID<80200) $result['problems'][]='PHP CLI anterior a 8.2';
    if (!extension_loaded('mysqli') || !function_exists('mysqli_stmt_get_result')) $result['problems'][]='mysqli/get_result no disponible en CLI';
    $result['mysqli_client']=extension_loaded('mysqli')?mysqli_get_client_info():null;
    $result['ini_cli']=[];
    foreach (['mysqli.allow_persistent','mysqli.max_persistent','mysqli.max_links','session.save_handler','opcache.enable','opcache.validate_timestamps','opcache.revalidate_freq'] as $key) $result['ini_cli'][$key]=ini_get($key);
    foreach ($manifest['required'] as $name) {
        if (str_contains($name,'..') || !preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D',$name)) throw new RuntimeException('Ruta de manifiesto inválida.');
        $path=$root.'/'.$name;$real=realpath($path);
        $inside=$real && str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$root),'/').'/');
        if (!$inside || !is_file($path) || !is_readable($path) || is_link($path)) {
            $result['files'][$name]=['exists_readable'=>false];$result['problems'][]='Archivo ausente/no regular: '.$name;continue;
        }
        // Lint analiza el archivo, no lo incluye. No exportar errores con texto
        // de código de configuración ni hashes de archivos privados.
        $lint=pfRun([PHP_BINARY,'-l',$path]);
        $row=['exists_readable'=>true,'syntax_ok'=>$lint['exit']===0];
        if (!str_starts_with($name,'config/')) $row['sha256']=hash_file('sha256',$path);
        $result['files'][$name]=$row;
        if ($lint['exit']!==0) $result['problems'][]='Sintaxis incompatible: '.$name;
    }
    $check=pfRun([PHP_BINARY,__DIR__.'/publicar_conciliada.php','--check','--root',$root]);
    $result['release_check']=['exit'=>$check['exit'],'output'=>trim($check['stdout'].$check['stderr'])];
    if ($check['exit']!==0) $result['problems'][]='No coincide la versión actual con los 13 hashes revisados';
    $result['unresolved_dynamic_dependencies']=$manifest['dynamic_checks'];
    $result['status']=$result['problems']?'FILES_CHECK_FAILED':'FILES_CHECK_OK';
    $result['publication_approval']='NOT_GRANTED; requiere staging funcional y verificación web/OPcache';
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    exit($result['problems']?1:0);
} catch (Throwable $error) {
    fwrite(STDERR,'ERROR: '.$error->getMessage().PHP_EOL);exit(1);
}
