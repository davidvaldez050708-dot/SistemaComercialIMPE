<?php
declare(strict_types=1);
// Sólo lectura por CLI; no muestra nombres, teléfonos, correos ni grabaciones.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$options=getopt('',['site:']);$site=realpath((string)($options['site']??''));
if(!$site||!is_file($site.'/index.php')){fwrite(STDERR,"Uso: php verificar_hora_prospectos.php --site RUTA\n");exit(1);}
try {
 require_once $site.'/config/db_connection.php';
 require_once $site.'/app/helpers/ProspectoFechaHoraHelper.php';
 $db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
 $type=$db->query("SELECT DATA_TYPE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='formulario_registros' AND COLUMN_NAME='created_at'")->fetch_assoc();
 if(($type['DATA_TYPE']??'')!=='datetime')throw new RuntimeException('Tipo de fecha no comprobado');
 $clock=$db->query('SELECT NOW() hora_sql,UTC_TIMESTAMP() hora_utc,@@session.time_zone zona_sql,@@system_time_zone zona_servidor')->fetch_assoc();
 $latest=$db->query('SELECT MAX(created_at) fecha FROM formulario_registros')->fetch_assoc()['fecha'];
 $db->rollback();
 echo json_encode(['zona_presentacion'=>'America/Mexico_City','hora_mexico'=>(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s'),'sql'=>$clock,'tipo_fecha'=>$type,'registro_mas_reciente_utc'=>$latest,'registro_mas_reciente_mexico'=>ProspectoFechaHoraHelper::aMexico($latest),'resultado'=>'CHECK OK: fechas de registro presentadas en hora de México; consulta de sólo lectura.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){if(isset($db))$db->rollback();fwrite(STDERR,'ERROR DE COMPROBACIÓN: '.get_class($e).PHP_EOL);exit(1);}
