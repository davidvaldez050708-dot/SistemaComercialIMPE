<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/services/SupervisionPersonalMigracion.php';
$options=getopt('',['site:','auditar','salida:','preparar','plan:','respaldo:','activar','actor:','decisiones:','revision-autorizada:','revisar','desactivar','autorizar-reversion','autorizar-migracion']);
$modes=array_intersect(['auditar','revisar','preparar','activar','desactivar'],array_keys($options));
if(count($modes)!==1||empty($options['site']))exit("Uso: --site RUTA --auditar --salida ARCHIVO_PRIVADO\nLos modos de preparación, activación y reversión son explícitos y requieren revisión previa.\n");
$site=realpath($options['site']);if(!$site||!is_file($site.'/index.php'))exit("RAIZ_NO_VALIDA\n");
function privado(string $path,string $site,bool $exists=false):string{
 if($path==='')throw new RuntimeException('Especifica un archivo privado.');
 $dir=realpath(dirname($path));if(!$dir)throw new RuntimeException('La carpeta privada no existe.');
 $target=$dir.DIRECTORY_SEPARATOR.basename($path);$normalized=str_replace('\\','/',$target);$public=rtrim(str_replace('\\','/',$site),'/').'/';
 if(str_starts_with(strtolower($normalized.'/' ),strtolower($public))||is_link($target))throw new RuntimeException('El archivo debe estar fuera del sitio público y no ser un enlace.');
 if($exists&&!is_file($target))throw new RuntimeException('Falta el archivo privado.');return $target;
}
function leerPlan(string $path,string $site):array{
 $path=privado($path,$site,true);$d=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($d))throw new RuntimeException('El archivo privado no contiene un objeto válido.');return $d;
}
function escribirNuevo(string $path,array $datos):void{
 $json=json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
 $h=fopen($path,'x');if(!$h)throw new RuntimeException('No se pudo crear el archivo privado.');chmod($path,0600);
 try{if(fwrite($h,$json)!==strlen($json)||!fflush($h))throw new RuntimeException('Escritura privada incompleta; revisar antes de continuar.');}
 finally{fclose($h);}
}
$db=null;
try{
 $mode=reset($modes);
 if(in_array($mode,['preparar','activar'],true)&&!array_key_exists('autorizar-migracion',$options))throw new RuntimeException('La preparación y activación requieren autorización expresa de migración.');
 if($mode==='revisar'){
  $plan=leerPlan((string)($options['plan']??''),$site);$decisions=empty($options['decisiones'])?[]:leerPlan($options['decisiones'],$site);
  $pares=SupervisionPersonalAuditoria::resolver($plan,$decisions);
  echo 'RELACIONES_REVISADAS='.count($pares).PHP_EOL.'REVISION_HASH='.SupervisionPersonalAuditoria::hashRevision($plan,$decisions).PHP_EOL;
  echo "Revisión de archivos únicamente; sin conexión ni modificaciones a MySQL.\n";exit;
 }
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);require_once $site.'/config/db_connection.php';$db=(new Database())->connect(false);
 $migration=new SupervisionPersonalMigracion($db);
 if($mode==='auditar'){
  $output=privado((string)($options['salida']??''),$site);
  if(file_exists($output))throw new RuntimeException('La auditoría ya existe. Utiliza otro nombre para no sobrescribirla.');
  $plan=SupervisionPersonalAuditoria::leer($db);escribirNuevo($output,$plan);
  echo 'AUDITORIA_SOLO_LECTURA_OK RELACIONES='.$plan['resumen']['relaciones_explicitas'].' AMBIGUEDADES='.$plan['resumen']['ambiguedades'].PHP_EOL;
  echo 'PLAN_HASH='.$plan['plan_hash'].PHP_EOL;echo "No se modificaron tablas, permisos, territorios ni supervisiones.\n";
 }elseif($mode==='preparar'){
  $plan=leerPlan((string)($options['plan']??''),$site);$backup=privado((string)($options['respaldo']??''),$site);
  $migration->preparar($plan,static function($snapshot)use($backup){if(file_exists($backup))throw new RuntimeException('El respaldo ya existe; no se sobrescribe.');escribirNuevo($backup,$snapshot);},(int)($options['actor']??0));
  echo "PREPARACION_OK: autorización nueva desactivada; asignaciones operativas intactas.\n";
 }elseif($mode==='activar'){
  $plan=leerPlan((string)($options['plan']??''),$site);
  $decisions=empty($options['decisiones'])?[]:leerPlan($options['decisiones'],$site);
  $migration->activar((int)($options['actor']??0),$plan,$decisions,(string)($options['revision-autorizada']??''));
  echo "ACTIVACION_OK: supervisión independiente; territorios intactos.\n";
 }else{
  if(!array_key_exists('autorizar-reversion',$options))throw new RuntimeException('Falta autorizar expresamente la reversión.');
  $migration->desactivar((int)($options['actor']??0));echo "REVERSION_OK: alcance anterior restaurado; relaciones nuevas y bitácora conservadas.\n";
 }
}catch(Throwable $e){fwrite(STDERR,'OPERACION_NO_COMPLETADA: '.$e->getMessage().PHP_EOL);exit(1);}
finally{if($db instanceof mysqli)$db->close();}
