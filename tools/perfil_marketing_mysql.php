<?php
/** Perfil pasivo de Marketing: sólo archivos privados; nunca abre MySQL. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$modo=$argv[1]??'';$sitio=realpath($argv[2]??'');
if(!in_array($modo,['activar','leer'],true)||!$sitio||!is_file($sitio.'/index.php'))exit("USO: php perfil_marketing_mysql.php activar|leer /ruta/public_html\n");
$privado=dirname($sitio).'/impe-private';$marker=$privado.'/perfil-convocatorias-inicio.json';
foreach([$marker,$privado.'/perfil-peticiones-activo.json',$privado.'/perfil-peticiones.jsonl'] as $f)if(is_link($f))exit("PERFIL_NO_DISPONIBLE: enlace no permitido\n");
if($modo==='activar'){
    if(!is_dir($privado))exit("PERFIL_NO_ACTIVADO: falta directorio privado\n");
    $inicio=time();
    foreach(['perfil-peticiones-activo.json'=>['version'=>1,'hasta'=>$inicio+300],'perfil-convocatorias-inicio.json'=>['inicio'=>$inicio]] as $nombre=>$datos){
        $f=$privado.'/'.$nombre;
        if(file_put_contents($f,json_encode($datos),LOCK_EX)===false)exit("PERFIL_NO_ACTIVADO\n");
        chmod($f,0600);
    }
    echo "PERFIL_MARKETING_ACTIVO_5_MINUTOS: usa Marketing normalmente; caduca solo.\n";
    echo 'LECTURA: php '.escapeshellarg(__FILE__).' leer '.escapeshellarg($sitio).PHP_EOL;exit;
}
$m=is_readable($marker)?json_decode(file_get_contents($marker),true):[];$inicio=(int)($m['inicio']??0);
if(!$inicio)exit("SIN_MARCADOR\n");if(time()<$inicio+300)exit('FALTAN_SEGUNDOS='.($inicio+300-time()).PHP_EOL);
$g=[];$fallos=[];$f=is_readable($privado.'/perfil-peticiones.jsonl')?fopen($privado.'/perfil-peticiones.jsonl','r'):false;
while($f&&($linea=fgets($f))!==false){
    $d=json_decode($linea,true);if(!is_array($d))continue;$t=strtotime((string)($d['utc']??''));
    if($t===false||$t<$inicio||$t>$inicio+300)continue;
    $ruta=(string)($d['ruta']??'');$http=(int)($d['http']??0);
    if($http>=500&&preg_match('/^[A-Za-z0-9_.]+$/D',$ruta)){$key=$ruta.' HTTP='.$http;$fallos[$key]=($fallos[$key]??0)+1;}
    if($ruta==='convocatoriaNotificacion.pendientes')$g[$http][]=$d;
}
if($f)fclose($f);
if(!$g)echo "SIN_MUESTRAS_CONVOCATORIAS\n";
ksort($g);
foreach($g as $http=>$filas){
    $sql=array_map('intval',array_column($filas,'sql_envios'));sort($sql);
    $ms=array_map('floatval',array_column($filas,'total_ms'));sort($ms);
    $n=array_map('intval',array_column($filas,'conexiones'));
    echo 'HTTP='.$http.' N='.count($filas).' SQL_MEDIANA='.($sql[intdiv(count($sql),2)]??0)
        .' MEDIANA_MS='.($ms[intdiv(count($ms),2)]??0).' CONEXIONES_NUEVAS_TOTAL='.array_sum($n)
        .' NUEVAS_MAX_POR_PETICION='.($n?max($n):0).PHP_EOL;
}
foreach($fallos as $key=>$n)echo 'ERROR_EN_MUESTRA '.$key.' N='.$n.PHP_EOL;
if(!$fallos)echo "SIN_RESPUESTAS_5XX_EN_ESTA_MUESTRA\n";
