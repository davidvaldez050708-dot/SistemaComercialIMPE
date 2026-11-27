<?php
declare(strict_types=1);
/** Sólo CLI; metadatos y estado, sin DDL, llamadas, API ni correcciones. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$opts=getopt('',['site:','salida:']);$site=realpath((string)($opts['site']??''));
if(!$site||!is_file($site.'/config/db_connection.php'))exit("SITE_NO_VALIDO\n");
$db=null;
try{
 $out=(string)($opts['salida']??'');$dir=realpath(dirname($out));
 if(!$out||!$dir||str_starts_with(str_replace('\\','/',$dir).'/',str_replace('\\','/',$site).'/')||file_exists($out)||is_link($out))throw new RuntimeException('Usa una salida privada nueva fuera del sitio.');
 $required=[
  'usuarios'=>['id','rol_id','estado','nombre','apellidos','foto_perfil'],
  'roles'=>['id','estado'],
  'permisos'=>['id','modulo','codigo','nombre','descripcion','estado'],
  'rol_permisos'=>['rol_id','permiso_id'],
  'estados'=>['id','estado','nombre','nombre_corto','clave_inegi'],
  'asignaciones_territorio'=>['id','usuario_id','estado_id','tipo_asignacion','cuenta_clave_asignacion_id','activo','fecha_inicio','fecha_fin'],
  'seguimientos_vinculacion'=>['id','estado_id','analista_id','activo','municipio_id','nombre_entidad'],
  'interacciones_vinculacion'=>['id','seguimiento_id','usuario_id','canal','fecha_inicio'],
  'municipios'=>['id','nombre'],
 ];
 $new=[
  'supervision_personal'=>['kam_id','colaborador_id','activo','origen','actualizado_por','creado_at','actualizado_at'],
  'supervision_personal_estado'=>['id','activo','revision','version_schema','plan_hash','preparado_at'],
  'supervision_personal_bitacora'=>['id','responsable_id','kam_id','tipo','agregados_json','retirados_json','antes_json','despues_json','solicitud_id','responsable_nombre','kam_nombre','nombres_json','creado_at'],
 ];
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);require $site.'/config/db_connection.php';$db=(new Database())->connect(false);
 $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
 $tables=$db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_all(MYSQLI_ASSOC);
 $engines=array_column($tables,'ENGINE','TABLE_NAME');$names=array_keys($required+$new);$quoted=implode(',',array_map(static fn($s)=>"'".$s."'",$names));
 $cols=$db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($quoted) ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
 $indexes=$db->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($quoted) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC);
 $byTable=[];foreach($cols as $c)$byTable[$c['TABLE_NAME']][$c['COLUMN_NAME']]=$c;
 $unique=[];foreach($indexes as $i)if(!(int)$i['NON_UNIQUE'])$unique[$i['TABLE_NAME']][$i['INDEX_NAME']][]=$i['COLUMN_NAME'];
 $hasUnique=static function(string $table,array $columns)use($unique):bool{foreach($unique[$table]??[] as $key){sort($key);$expected=$columns;sort($expected);if($key===$expected)return true;}return false;};
 $errors=[];if(PHP_VERSION_ID<80200)$errors[]='La versión validada requiere PHP 8.2 o posterior';foreach($required as $t=>$fields)foreach($fields as $f)if(!isset($byTable[$t][$f]))$errors[]='Falta '.$t.'.'.$f;
 foreach(['permisos'=>['codigo'],'rol_permisos'=>['rol_id','permiso_id']] as $t=>$fields)if(!$hasUnique($t,$fields))$errors[]='Falta índice único esperado: '.$t;
 foreach(['permisos','rol_permisos'] as $t)if(strtoupper($engines[$t]??'')!=='INNODB')$errors[]='Motor no transaccional: '.$t;
 $idType=$byTable['usuarios']['id']['COLUMN_TYPE']??'';
 if(!preg_match('/^(?:bigint|int)(?:\([0-9]+\))?(?: unsigned)?$/D',$idType))$errors[]='usuarios.id no es entero compatible';
 $present=array_values(array_intersect(array_keys($new),array_keys($engines)));$state=null;
 if($present&&count($present)!==3)$errors[]='Preparación parcial: revisar antes de continuar';
 if(count($present)===3){
  foreach($new as $t=>$fields){foreach($fields as $f)if(!isset($byTable[$t][$f]))$errors[]='Falta '.$t.'.'.$f;if(strtoupper($engines[$t]??'')!=='INNODB')$errors[]='Motor no transaccional: '.$t;}
  foreach(['supervision_personal'=>['kam_id','colaborador_id'],'supervision_personal_estado'=>['id'],'supervision_personal_bitacora'=>['solicitud_id']] as $t=>$fields)if(!$hasUnique($t,$fields))$errors[]='Falta índice único esperado: '.$t;
  if(!$errors){$state=$db->query('SELECT activo,revision,version_schema,plan_hash FROM supervision_personal_estado WHERE id=1')->fetch_assoc();if(!$state||(int)$state['version_schema']!==1)$errors[]='Estado/esquema de supervisión incompatible';}
 }
 $db->commit();
 $report=['version'=>1,'utc'=>gmdate('c'),'compatible'=>!$errors,'errores'=>$errors,'tablas_nuevas_presentes'=>$present,'estado_supervision'=>$state,'motores'=>array_intersect_key($engines,array_flip($names)),'columnas'=>$cols,'indices'=>$indexes,'php'=>PHP_VERSION];
 $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$h=fopen($out,'x');if(!$h)throw new RuntimeException('No se pudo escribir el informe privado.');chmod($out,0600);
 try{if(fwrite($h,$json)!==strlen($json)||!fflush($h))throw new RuntimeException('Informe privado incompleto.');}finally{fclose($h);}
 echo 'ESTRUCTURA_'.($errors?'REVISAR':'COMPATIBLE').PHP_EOL.'TABLAS_NUEVAS_PRESENTES='.count($present).PHP_EOL.'ERRORES='.count($errors).PHP_EOL;
 foreach($errors as $e)echo 'REVISAR: '.$e.PHP_EOL;
 echo "Sólo SELECT y transacción READ ONLY; sin cambios de esquema, asignaciones ni permisos.\n";
 if($errors)exit(2);
}catch(Throwable $e){fwrite(STDERR,'PREFLIGHT_NO_COMPLETADO_CODIGO='.$e->getCode().PHP_EOL);exit(1);}
finally{if($db instanceof mysqli)$db->close();}
