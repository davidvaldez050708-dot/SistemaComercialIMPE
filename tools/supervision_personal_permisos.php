<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/services/SupervisionPersonalPermisos.php';
require_once __DIR__.'/../app/services/SupervisionPersonalRepository.php';
$o=getopt('',['site:','check','apply','autorizar','actor:','respaldo:']);$site=realpath((string)($o['site']??''));
if(!$site||!is_file($site.'/index.php'))exit("SITE_NO_VALIDO\n");$db=null;$locked=false;
try{
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);require $site.'/config/db_connection.php';$db=(new Database())->connect(false);
 if(isset($o['apply'])&&isset($o['check']))throw new RuntimeException('Selecciona CHECK o APPLY.');
 $apply=isset($o['apply']);
 if($apply){
  if(!isset($o['autorizar']))throw new RuntimeException('Falta autorizar la instalación de permisos.');
  (new SupervisionPersonalRepository($db))->autorizarAdministrador((int)($o['actor']??0));
  $f=(string)($o['respaldo']??'');$dir=realpath(dirname($f));
  if(!$f||!$dir||str_starts_with(str_replace('\\','/',$dir).'/',str_replace('\\','/',$site).'/')||file_exists($f)||is_link($f))throw new RuntimeException('Usa un respaldo privado nuevo fuera del sitio.');
  if((int)$db->query("SELECT GET_LOCK('impe_supervision_personal_v1',5) ok")->fetch_assoc()['ok']!==1)throw new RuntimeException('Hay otra migración en curso.');$locked=true;
 }else{$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);}
 $rows=$db->query("SELECT p.id,p.codigo,p.estado,rp.rol_id FROM permisos p LEFT JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE p.codigo IN ('personal.supervision.ver','personal.supervision.gestionar','personal.supervision.bitacora') ORDER BY p.id,rp.rol_id")->fetch_all(MYSQLI_ASSOC);
 if(!$apply){$db->commit();echo 'PERMISOS_CHECK: existentes='.count(array_unique(array_column($rows,'codigo'))).' de 3. Sin modificaciones.'.PHP_EOL;exit;}
 $snapshot=json_encode(['utc'=>gmdate('c'),'actor'=>(int)$o['actor'],'permisos_antes'=>$rows],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
 $h=fopen($f,'x');if(!$h)throw new RuntimeException('No se pudo crear el respaldo.');chmod($f,0600);try{if(fwrite($h,$snapshot)!==strlen($snapshot)||!fflush($h))throw new RuntimeException('Respaldo incompleto.');}finally{fclose($h);}
 $db->begin_transaction();try{$added=SupervisionPersonalPermisos::agregarNuevos($db);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
 echo 'PERMISOS_OK: nuevos='.count($added).'; sólo asignados al administrador. Concesiones existentes conservadas.'.PHP_EOL;
 echo "Supervisiones, territorios y política de activación sin cambios.\n";
}catch(Throwable $e){fwrite(STDERR,'PERMISOS_NO_COMPLETADOS: '.$e->getMessage().PHP_EOL);exit(1);}
finally{if($db instanceof mysqli){if($locked)$db->query("SELECT RELEASE_LOCK('impe_supervision_personal_v1')");$db->close();}}
