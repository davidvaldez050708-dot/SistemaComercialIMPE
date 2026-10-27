<?php
declare(strict_types=1);
// Comprobación CLI de sólo lectura; no imprime usuarios, correos o teléfonos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options=getopt('',['site:']);$site=realpath((string)($options['site']??''));
if(!$site||!is_file($site.'/index.php')){fwrite(STDERR,"Uso: php verificar_hora_usuarios.php --site RUTA\n");exit(1);}
try{
 require_once $site.'/config/db_connection.php';
 require_once $site.'/app/helpers/UsuarioFechaHoraHelper.php';
 $db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
 $clock=$db->query('SELECT NOW() hora_sql,UTC_TIMESTAMP() hora_utc,@@session.time_zone zona_sql,@@system_time_zone zona_servidor')->fetch_assoc();
 $max=$db->query('SELECT MAX(ultimo_acceso) ultimo_acceso_utc FROM usuarios')->fetch_assoc()['ultimo_acceso_utc'];
 $types=$db->query("SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME IN ('ultimo_acceso','created_at','updated_at') ORDER BY COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
 $db->rollback();
 echo json_encode(['php_zona'=>date_default_timezone_get(),'php_local'=>date('Y-m-d H:i:s'),'sql'=>$clock,'ultimo_acceso_mas_reciente_utc'=>$max,'ultimo_acceso_mas_reciente_mexico'=>UsuarioFechaHoraHelper::aMexico($max),'tipos'=>$types,'resultado'=>'CHECK OK: consulta de sólo lectura; acceso presentado en hora de México.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if(isset($db))$db->rollback();fwrite(STDERR,'ERROR DE COMPROBACIÓN: '.get_class($e).PHP_EOL);exit(1);}
