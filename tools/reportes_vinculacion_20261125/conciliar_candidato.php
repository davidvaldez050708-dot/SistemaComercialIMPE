<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function reportCandidate(string $stage,array $data):void{echo json_encode(['etapa'=>$stage]+$data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;}
$db=null;$started=false;$stage=null;$temporaryFiles=[];$temporaryDirs=[];$status=0;
try{
    $args=getopt('',['site:','fecha:','actor:']);$root=realpath((string)($args['site']??''));$package=realpath(__DIR__);
    $day=(string)($args['fecha']??'');$actor=(int)($args['actor']??0);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('America/Mexico_City'));
    if(!$root||!$date||$date->format('Y-m-d')!==$day||$actor<=0)throw new InvalidArgumentException('Argumentos inválidos');
    $normalizedRoot=rtrim(str_replace('\\','/',$root),'/');$normalizedPackage=str_replace('\\','/',$package);
    if($normalizedPackage===$normalizedRoot||str_starts_with($normalizedPackage,$normalizedRoot.'/'))throw new RuntimeException('El diagnóstico debe estar fuera del sitio público');
    $manifest=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['domain']??'')!=='fundacionrededumex.org')throw new RuntimeException('Destino incorrecto');
    foreach($manifest['files'] as $file){
        $path=(string)$file['path'];
        if(!preg_match('~^(?:app|public)/[A-Za-z0-9_./-]+$~D',$path)||str_contains($path,'..'))throw new RuntimeException('Ruta inválida');
        if(hash_file('sha256',$root.'/'.$path)!==$file['before_sha256']||hash_file('sha256',__DIR__.'/production-candidate/'.$path)!==$file['after_sha256'])throw new RuntimeException('El sitio o el candidato cambió: '.$path);
    }
    foreach(($manifest['read_only_dependencies']??[]) as $dep){if(hash_file('sha256',$root.'/'.$dep['path'])!==$dep['sha256'])throw new RuntimeException('Una dependencia instalada cambió');}
    $stage=__DIR__.'/.readonly-'.bin2hex(random_bytes(8));
    if(!mkdir($stage,0700))throw new RuntimeException('No se pudo crear la copia privada');$temporaryDirs[]=$stage;
    foreach(['config','app','app/helpers','app/services'] as $dir){if(!mkdir($stage.'/'.$dir,0700))throw new RuntimeException('No se pudo preparar la copia');$temporaryDirs[]=$stage.'/'.$dir;}
    $wrappers=['config/db_connection.php','app/helpers/SeguimientoTerritorialScope.php','app/helpers/TelefoniaFechaHoraHelper.php','app/services/TelefoniaVentasLlamadasService.php'];
    foreach($wrappers as $path){$dest=$stage.'/'.$path;$temporaryFiles[]=$dest;if(file_put_contents($dest,'<?php require_once '.var_export($root.'/'.$path,true).';')===false)throw new RuntimeException('No se pudo preparar una dependencia');chmod($dest,0600);}
    foreach(['app/services/SeguimientoReporteAnaliticaService.php','app/services/SeguimientoAtencionOperativaService.php'] as $path){$dest=$stage.'/'.$path;$temporaryFiles[]=$dest;if(!copy(__DIR__.'/production-candidate/'.$path,$dest))throw new RuntimeException('No se pudo preparar el candidato');chmod($dest,0600);}
    require_once $root.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    if(!$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY))throw new RuntimeException('No fue posible iniciar la transacción de sólo lectura');$started=true;
    require_once $stage.'/app/services/SeguimientoReporteAnaliticaService.php';
    $rows=$db->query('SELECT id FROM seguimientos_vinculacion WHERE activo=1 ORDER BY nombre_entidad,id')->fetch_all(MYSQLI_ASSOC);
    $ids=array_map('intval',array_column($rows,'id'));$service=new SeguimientoReporteAnaliticaService($db);
    foreach(['expedientes'=>false,'global_con_intentos_acreditados'=>true] as $name=>$unlinked){
        $r=$service->construir($ids,$actor,'administrador',$day,$day,'',60,[$actor],$unlinked);
        reportCandidate($name,['fecha'=>$day,'expedientes_considerados'=>$r['seguimientos_considerados'],'interacciones'=>$r['interacciones'],'instituciones_trabajadas'=>$r['seguimientos_con_actividad'],'llamadas'=>$r['llamadas'],'llamadas_sin_expediente'=>$r['llamadas_sin_expediente']??0,
            'diario'=>$r['rendimiento_telefonico_diario'],'por_actor'=>array_map(static fn($a)=>array_diff_key($a,array_flip(['usuario_id','analista_nombre'])),$r['actividad_por_actor'])]);
    }
    reportCandidate('fin',['solo_lectura'=>true,'sitio_sin_reemplazos'=>true,'datos_personales_omitidos'=>true]);
}catch(Throwable $e){reportCandidate('error',['clase'=>get_class($e),'codigo'=>(int)$e->getCode()]);$status=1;}
finally{
    if($started&&$db instanceof mysqli)$db->rollback();
    // Sólo archivos enumerados creados por esta ejecución; nunca se recorre el sitio.
    foreach(array_reverse($temporaryFiles) as $file)if(is_file($file))unlink($file);
    foreach(array_reverse($temporaryDirs) as $dir)if(is_dir($dir))rmdir($dir);
}
exit($status);
