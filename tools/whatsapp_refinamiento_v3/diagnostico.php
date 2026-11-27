<?php
if(PHP_SAPI!=='cli'||$argc!==2)exit(2);
require rtrim($argv[1],'/').'/config/db_connection.php';
$db=(new Database())->connect(false);
$db->query('START TRANSACTION READ ONLY');
try{
 $r=$db->query("SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS desfase")->fetch_assoc();
 echo 'SQL_UTC | '.$r['desfase']." segundos\n";
 if(abs((int)$r['desfase'])>1)throw new RuntimeException('La sesión SQL ya no está en UTC');
 $r=$db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_mensajes' AND COLUMN_NAME IN ('created_at','enviado_at') ORDER BY COLUMN_NAME");
 while($v=$r->fetch_assoc())echo 'COLUMNA | '.$v['COLUMN_NAME'].' | '.$v['DATA_TYPE'].' | '.$v['COLUMN_DEFAULT']."\n";
 // Aggregate evidence only: no phone numbers, names, IDs or messages.
 $r=$db->query("SELECT direccion,COUNT(*) total,
 SUM(enviado_at IS NULL) sin_fecha,
 SUM(ABS(TIMESTAMPDIFF(SECOND,enviado_at,created_at))<120) cercana_utc,
 SUM(ABS(TIMESTAMPDIFF(SECOND,enviado_at,created_at)-21600)<120) cercana_mexico
 FROM whatsapp_mensajes GROUP BY direccion");
 while($v=$r->fetch_assoc())echo 'FECHAS | '.json_encode($v,JSON_UNESCAPED_UNICODE)."\n";
 echo "DIAGNOSTICO_WA_OK | solo lectura\n";
}finally{$db->rollback();$db->close();}
