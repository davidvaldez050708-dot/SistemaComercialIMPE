<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'--check';if(!in_array($mode,['--check','--apply'],true)||count($argv)>2){fwrite(STDERR,"Uso: --check|--apply\n");exit(2);}
$root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');require_once $root.'/config/db_connection.php';
try{$db=(new Database())->connect(false);
 if($mode==='--apply')foreach(explode(';',(string)file_get_contents(__DIR__.'/../database/migrations/2026_10_20_ventas_etapa2.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
 foreach(['telefonia_ventas_evidencia'=>['usuario_id','extension','pbx_call_id','consultado_at','datos_json'],'telefonia_ventas_prospecto_llamada'=>['usuario_id','extension','pbx_call_id','version','datos_json']] as $table=>$columns){
  $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();$row=$s->get_result()->fetch_assoc();
  if(!$row||strtoupper($row['ENGINE'])!=='INNODB')throw new RuntimeException('Falta estructura InnoDB: '.$table);
  $actual=array_column($db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC),'Field');
  if(array_diff($columns,$actual))throw new RuntimeException('Estructura no compatible: '.$table);
  $fields=array_column($db->query("SHOW FULL COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC),null,'Field');
  foreach(['extension'=>12,'pbx_call_id'=>100] as $field=>$length)if(strtolower($fields[$field]['Type'])!=="varchar($length)"||$fields[$field]['Collation']!=='utf8mb4_bin')throw new RuntimeException('Tipo o colación no compatible: '.$table.'.'.$field);
  $indexes=$db->query("SHOW INDEX FROM $table WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);usort($indexes,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);$pk=array_column($indexes,'Column_name');
  if($pk!==['usuario_id','extension','pbx_call_id'])throw new RuntimeException('Clave no compatible: '.$table);
 }
 echo "OK: evidencias comerciales preparadas. Sin cambios a eventos, resultados o grabaciones existentes.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
