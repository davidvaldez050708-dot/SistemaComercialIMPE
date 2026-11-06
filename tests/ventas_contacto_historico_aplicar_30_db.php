<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/ventas_contacto_historico_aplicar_30.php';
require_once __DIR__.'/../tools/ventas_contacto_historico_restaurar_30.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if($port<=0)throw new RuntimeException('Puerto de base de prueba requerido');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_hist30_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db->select_db($name);$db->set_charset('utf8mb4');
$assert=static function($ok,string $reason):void{if(!$ok)throw new RuntimeException('FAIL: '.$reason);};
try{
 $db->query("CREATE TABLE telefonia_ventas_resultados (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
 pbx_call_id VARCHAR(100) NOT NULL, resultado VARCHAR(36) NOT NULL,
 observacion VARCHAR(1000) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uk_venta_call(pbx_call_id,extension)) ENGINE=InnoDB");
 $db->query("CREATE TABLE telefonia_ventas_resultado_detalles (
 resultado_id BIGINT UNSIGNED PRIMARY KEY,
 resultado_principal VARCHAR(36) NOT NULL,envio_informacion VARCHAR(16) NOT NULL,
 contacto_antes_corte VARCHAR(2) NOT NULL DEFAULT '',
 catalogo_version VARCHAR(10) NOT NULL
 ) ENGINE=InnoDB");
 $db->query("CREATE TABLE telefonia_ventas_resultado_selecciones (
 resultado_id BIGINT UNSIGNED,codigo VARCHAR(36),posicion INT,
 PRIMARY KEY(resultado_id,codigo)) ENGINE=InnoDB");
 $db->query("CREATE TABLE telefonia_ventas_llamadas (
 id BIGINT UNSIGNED PRIMARY KEY,
 usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
 pbx_call_id VARCHAR(100) NOT NULL,fecha DATETIME NOT NULL,
 direccion VARCHAR(10) NOT NULL,
 UNIQUE KEY uk_llamada(pbx_call_id,extension)) ENGINE=InnoDB");
 $db->query("CREATE TABLE telefonia_zadarma_eventos (
 id BIGINT UNSIGNED PRIMARY KEY,internal VARCHAR(20),
 pbx_call_id VARCHAR(100),evento VARCHAR(32),
 is_recorded TINYINT,call_id_with_rec VARCHAR(100)) ENGINE=InnoDB");
 $auditRows=[];
 for($i=1;$i<=118;$i++){
   $pbx='out_LOTE_'.str_pad((string)$i,8,'0',STR_PAD_LEFT);
   $id=250+$i;
   $db->query("INSERT INTO telefonia_ventas_resultados VALUES
     ($id,9,'152','$pbx','ENVIO_WHATSAPP','Original intacta',
      '2026-11-06 10:01:00','2026-11-06 10:01:00')");
   $db->query("INSERT INTO telefonia_ventas_resultado_detalles VALUES
     ($id,'ENVIO_WHATSAPP','WHATSAPP','','3')");
   $db->query("INSERT INTO telefonia_ventas_resultado_selecciones VALUES
     ($id,'WHATSAPP',0)");
   $db->query("INSERT INTO telefonia_ventas_llamadas VALUES
     ($id,9,'152','$pbx','2026-11-06 09:59:00','Saliente')");
   if($i<=116){
     $db->query("INSERT INTO telefonia_zadarma_eventos VALUES
       ($id*10,'152','$pbx','NOTIFY_ANSWER',0,NULL)");
     $db->query("INSERT INTO telefonia_zadarma_eventos VALUES
       ($id*10+1,'152','$pbx','NOTIFY_OUT_END',1,'1500.2600')");
   }
   $auditRows[]=[
    'resultado_id'=>$id,'usuario_id'=>9,'extension'=>'152',
    'pbx_call_id'=>$pbx,'fecha_llamada'=>'2026-11-06 09:59:00',
    'fecha_registro'=>'2026-11-06 10:01:00','direccion'=>'Saliente',
    'categoria'=>$i<=116?'PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA':'SIN_RESPUESTA_PBX_ACREDITADA',
    'conexion_pbx'=>$i<=116,'referencia_grabacion_local'=>$i<=116,
    'revision_humana'=>'PENDIENTE','observacion_revision'=>''
   ];
 }
 $dir=sys_get_temp_dir().'/impe-test-30-'.bin2hex(random_bytes(4));
 mkdir($dir,0700);
 mkdir($dir.'/site',0700);
 file_put_contents($dir.'/site/index.php','<?php');
 mkdir($dir.'/private',0700);
 $auditFile=$dir.'/private/audit.json';
 file_put_contents($auditFile,json_encode([
    'tipo'=>'AUDITORIA_SOLO_LECTURA',
    'resumen'=>['total'=>118],'llamadas'=>$auditRows],JSON_UNESCAPED_UNICODE));
 $auditSha=hash_file('sha256',$auditFile);
 $csvFile=$dir.'/private/revision.csv';
 file_put_contents($csvFile,"CSV revisado de prueba, 30 SI confirmados");
 $csvSha=hash_file('sha256',$csvFile);
 $selected=[];
 foreach(array_slice($auditRows,0,30) as $row){
    $selected[]=[
     'resultado_id'=>$row['resultado_id'],'usuario_id'=>$row['usuario_id'],
     'extension'=>$row['extension'],'pbx_call_id'=>$row['pbx_call_id'],
     'fecha_llamada'=>$row['fecha_llamada'],'categoria'=>$row['categoria'],
     'revision_humana'=>'SI','observacion_revision'=>'',
     'auditoria_sha256'=>$auditSha
    ];
 }
 $planFile=$dir.'/private/plan.json';
 file_put_contents($planFile,json_encode([
    'tipo'=>'PLAN_REVISION_HISTORICA_NO_APLICADO',
    'confirmacion_global_sin_notas'=>true,
    'confirmacion_declarada'=>'El responsable confirma los SI/NO del CSV sin descripciones por llamada.',
    'total'=>118,'confirmadas_por_revision'=>30,
    'descartadas_por_revision'=>0,'pendientes'=>88,
    'auditoria_sha256'=>$auditSha,'csv_sha256'=>$csvSha,'aprobadas'=>$selected
 ],JSON_UNESCAPED_UNICODE));
 $plan=VentasContactoHistorico30::verifyPlan($planFile,$auditFile,$csvFile);
 $db->query('START TRANSACTION READ ONLY');
 $snapshot=VentasContactoHistorico30::inspect($db,$plan,false);
 $db->commit();
 $assert(count($snapshot)===30,'30 ids exactos');
 $first=$db->query('SELECT resultado,updated_at FROM telefonia_ventas_resultados WHERE id=251')->fetch_assoc();
 $assert($first['resultado']==='ENVIO_WHATSAPP','check no escribe');
 $db->begin_transaction();
 $before=VentasContactoHistorico30::inspect($db,$plan,true);
 $backup=VentasContactoHistorico30::privateBackup($dir.'/site',$dir.'/private',[
    'tipo'=>'BACKUP_PREVIO_CONTACTOS_WHATSAPP_30',
    'plan_sha'=>$plan['plan_sha'],'audit_sha'=>$plan['audit_sha'],
    'csv_sha'=>$plan['csv_sha'],'antes'=>$before
 ]);
 $assert(is_file($backup),'backup original');
 $assert(fileperms($backup)&0077 ? false:true,'backup privado 0600');
 VentasContactoHistorico30::apply($db,$before);
 $db->commit();
 $counts=$db->query("SELECT resultado,COUNT(*) n FROM telefonia_ventas_resultados GROUP BY resultado")->fetch_all(MYSQLI_ASSOC);
 $totals=[];foreach($counts as $c)$totals[$c['resultado']]=(int)$c['n'];
 $assert(($totals['CONVERSACION_PERSONA']??0)===30,'exactamente 30 contactos');
 $assert(($totals['ENVIO_WHATSAPP']??0)===88,'88 sin tocar');
 $details=$db->query("SELECT resultado_principal,catalogo_version,COUNT(*) n
    FROM telefonia_ventas_resultado_detalles GROUP BY resultado_principal,catalogo_version")->fetch_all(MYSQLI_ASSOC);
 $labels=[];foreach($details as $row)$labels[$row['resultado_principal'].'|'.$row['catalogo_version']]=(int)$row['n'];
 $assert(($labels['CONVERSACION_PERSONA|4']??0)===30,'detalles v4');
 $assert(($labels['ENVIO_WHATSAPP|3']??0)===88,'detalles V3 intactos');
 $selections=$db->query("SELECT codigo,COUNT(*) n FROM telefonia_ventas_resultado_selecciones GROUP BY codigo")->fetch_assoc();
 $assert($selections['codigo']==='WHATSAPP'&&(int)$selections['n']===118,'WhatsApp preservado');
 $unchanged=$db->query("SELECT observacion,updated_at FROM telefonia_ventas_resultados WHERE id=251")->fetch_assoc();
 $assert($unchanged['observacion']==='Original intacta','observaciones originales intactas');
 $assert($unchanged['updated_at']===$first['updated_at'],'fecha updated_at original intacta');
 $db->begin_transaction();
 try{
    VentasContactoHistorico30::inspect($db,$plan,true);
    throw new RuntimeException('FAIL: inspección no rechazó lote ya aplicado');
 }catch(RuntimeException $e){
    $assert(strpos($e->getMessage(),'Estado vigente cambió')!==false,'idempotencia segura');
    $db->rollback();
 }
 $injected=$plan;
 $injected['accepted'][0]['pbx_call_id']='out_LOTE_99999999';
 $db->query('START TRANSACTION READ ONLY');
 try{
    VentasContactoHistorico30::inspect($db,$injected,false);
    throw new RuntimeException('FAIL: PBX ajeno aceptado');
 }catch(RuntimeException $e){
    $assert(strpos($e->getMessage(),'ausente/ambigua')!==false,'no vincular PBX ajeno');
    $db->rollback();
 }
 $saved=VentasContactoHistoricoRestaurar30::leer($backup,$dir.'/private',$dir.'/site');
 $assert(count($saved['antes'])===30,'30 resultados en respaldo');
 $db->query('START TRANSACTION READ ONLY');
 VentasContactoHistoricoRestaurar30::comprobar($db,$saved,false);
 $db->commit();
 $db->begin_transaction();
 VentasContactoHistoricoRestaurar30::comprobar($db,$saved,true);
 VentasContactoHistoricoRestaurar30::restaurar($db,$saved);
 $db->commit();
 $all=$db->query("SELECT resultado,COUNT(*) n FROM telefonia_ventas_resultados GROUP BY resultado")->fetch_all(MYSQLI_ASSOC);
 $assert(count($all)===1&&$all[0]['resultado']==='ENVIO_WHATSAPP'&&(int)$all[0]['n']===118,
         'Rollback restauró 118 resultados como estaban');
 $d=$db->query("SELECT resultado_principal,catalogo_version,COUNT(*) n
     FROM telefonia_ventas_resultado_detalles
     GROUP BY resultado_principal,catalogo_version")->fetch_all(MYSQLI_ASSOC);
 $assert(count($d)===1&&$d[0]['resultado_principal']==='ENVIO_WHATSAPP'&&$d[0]['catalogo_version']==='3'&&(int)$d[0]['n']===118,
         'Rollback restauró detalles V3 exactos');
 $db->begin_transaction();
 try {
     VentasContactoHistoricoRestaurar30::comprobar($db,$saved,true);
     throw new RuntimeException('FAIL: restore repetido no rechazado');
 } catch(RuntimeException $ex) {
     $assert(strpos($ex->getMessage(),'ya no conserva')!==false,'No restauración duplicada');
     $db->rollback();
 }
 echo "PASS: 30 conversiones exactas, 88 intactas, WhatsApp, respaldo, restauración transaccional, idempotencia y PBX propia.\n";
}finally{
 $db->query("DROP DATABASE `$name`");
 $db->close();
}
