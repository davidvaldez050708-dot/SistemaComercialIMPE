<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$args=getopt('',['site:','backup-root:','check','apply']);
try {
    $root=realpath($args['site']??'');$backup=$args['backup-root']??'';
    if (!$root || !is_file($root.'/index.php') || $backup==='' || count(array_intersect(['check','apply'],array_keys($args)))!==1) throw new RuntimeException('Indica --site, --backup-root y --check o --apply.');
    $resolved=realpath($backup)?:realpath(dirname($backup));
    if (!$resolved || $resolved===$root || str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)) throw new RuntimeException('El respaldo debe quedar fuera del sitio.');
    require $root.'/config/db_connection.php';require $root.'/app/services/VentasActividadPermisosService.php';
    $svc=new VentasActividadPermisosService((new Database())->connect(false));
    if (isset($args['check'])) { echo ($svc->comprobar()?'ACTUALIZADO':'PENDIENTE').": catálogo de permisos. Sin escrituras.\n";exit; }
    $applied=$svc->aplicar(function(array $data) use ($backup,$root) {
        if (!is_dir($backup) && !mkdir($backup,0700,true)) throw new RuntimeException('No se pudo preparar el respaldo.');
        $real=realpath($backup);
        if (!$real || $real===$root || str_starts_with($real,$root.DIRECTORY_SEPARATOR)) throw new RuntimeException('Respaldo no autorizado.');
        chmod($real,0700);$file=$real.'/permisos-asesores-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $fp=fopen($file,'x');if (!$fp) throw new RuntimeException('No se pudo respaldar.');chmod($file,0600);
        $json=json_encode(['site'=>$root,'antes'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (fwrite($fp,$json)!==strlen($json) || !fflush($fp)) throw new RuntimeException('Respaldo incompleto.');fclose($fp);
        echo "RESPALDO DE PERMISOS: $file\n";
    });
    echo $applied?"APLICADO: reportes y desempeño de asesores para los roles 1, 2, 3 y 6 según su alcance.\n":"SIN CAMBIOS: instalación existente; permisos revocados conservados.\n";
} catch (Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1); }
