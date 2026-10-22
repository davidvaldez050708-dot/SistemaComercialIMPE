<?php
declare(strict_types=1);
/**
 * Prueba MySQL 8 y MariaDB 10.11 de las tres fuentes con collations distintas.
 * Usa exclusivamente una BD desechable si IMPE_TEST_MYSQL_PORT está definido.
 */
$source=file_get_contents(__DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php');
function must($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
must(strpos($source,"CONVERT(\$field USING utf8mb4) COLLATE utf8mb4_general_ci")!==false,
    'Todas las proyecciones del expediente deben usar el mismo charset/collation');
foreach(["\$utf8(\$campo)","\$utf8('destino_extension')","\$utf8('telefono10')",
         "\$utf8('d.referencia')","\$utf8('d.destino_extension')",
         "\$utf8(\"RIGHT(REPLACE("] as $token){
    must(strpos($source,$token)!==false,'Falta normalización de una fuente: '.$token);
}
$port=(int)(getenv('IMPE_TEST_MYSQL_PORT')?:0);
if($port<=0){echo "OK: estructura SQL de tres fuentes normalizada; sin instancia de prueba MySQL.\n";exit(0);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',
    getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_collation_test_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db->select_db($name);$db->set_charset('utf8mb4');
try{
    $db->query("CREATE TABLE telefonia_transferencia_contextos_v2 (
      referencia_llamada VARCHAR(100) COLLATE utf8mb4_unicode_ci,
      destino_usuario_id INT,destino_extension VARCHAR(12) COLLATE utf8mb4_unicode_ci,
      seguimiento_id INT,telefono10 CHAR(10) COLLATE utf8mb4_unicode_ci,created_at DATETIME)");
    $db->query("CREATE TABLE telefonia_transferencia_contextos (
      pbx_call_id VARCHAR(100) COLLATE utf8mb4_general_ci,
      destino_usuario_id INT,destino_extension VARCHAR(12) COLLATE utf8mb4_general_ci,
      seguimiento_id INT,telefono10 CHAR(10) COLLATE utf8mb4_general_ci,created_at DATETIME)");
    $db->query("CREATE TABLE telefonia_disponibilidad (
      referencia VARCHAR(100) COLLATE utf8mb4_bin,
      destino_usuario INT,destino_extension VARCHAR(12) COLLATE utf8mb4_bin,
      numero VARCHAR(40) COLLATE utf8mb4_bin,ruta_json TEXT COLLATE utf8mb4_bin,
      estado VARCHAR(20),created_at DATETIME)");
    $pbx='out_'.str_repeat('a',32);$foreign='out_'.str_repeat('b',32);
    $db->query("INSERT INTO telefonia_transferencia_contextos_v2 VALUES
      ('$pbx',10,'101',337,'5551112233',NOW())");
    $db->query("INSERT INTO telefonia_transferencia_contextos VALUES
      ('$foreign',10,'101',337,'5550001122',NOW())");
    $route=json_encode(['tipo'=>'INSTITUCION','seguimiento_id'=>337,
       'responsable_id'=>10,'extension'=>'101','referencia_llamada'=>$pbx]);
    $q=$db->prepare("INSERT INTO telefonia_disponibilidad
      VALUES (?,10,'101','+525551112233',?,'TRANSFERIDA',NOW())");
    $q->bind_param('ss',$pbx,$route);$q->execute();$q->close();
    $utf8=static fn(string $field):string=>
      "CONVERT($field USING utf8mb4) COLLATE utf8mb4_general_ci";
    $sources=[
      "SELECT ".$utf8('referencia_llamada')." AS pbx_call_id,destino_usuario_id,".
       $utf8('destino_extension')." AS destino_extension,seguimiento_id,".
       $utf8('telefono10')." AS telefono10,created_at FROM telefonia_transferencia_contextos_v2",
      "SELECT ".$utf8('pbx_call_id')." AS pbx_call_id,destino_usuario_id,".
       $utf8('destino_extension')." AS destino_extension,seguimiento_id,".
       $utf8('telefono10')." AS telefono10,created_at FROM telefonia_transferencia_contextos",
      "SELECT ".$utf8('d.referencia')." AS pbx_call_id,d.destino_usuario AS destino_usuario_id,".
       $utf8('d.destino_extension')." AS destino_extension,
       CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.seguimiento_id')) AS UNSIGNED) AS seguimiento_id,".
       $utf8("RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(d.numero,'+',''),' ',''),'-',''),'(',''),')',''),10)")." AS telefono10,
       d.created_at FROM telefonia_disponibilidad d WHERE JSON_VALID(d.ruta_json) AND d.estado IN
       ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')"
    ];
    $union='('.implode(' UNION ALL ',$sources).') AS contextos_verificados';
    $sql="SELECT pbx_call_id,created_at FROM $union
      WHERE destino_usuario_id=? AND destino_extension=? AND seguimiento_id=?
        AND telefono10=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY)";
    $q=$db->prepare($sql);
    $user=10;$ext='101';$id=337;$phone='5551112233';
    $q->bind_param('isis',$user,$ext,$id,$phone);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    must(count($rows)===2,'Contexto histórico y disponibilidad coinciden en la MISMA llamada');
    foreach($rows as $row)must($row['pbx_call_id']===$pbx,'No asociar PBX ajeno del mismo destino');
    $q=$db->prepare("SELECT DISTINCT destino_usuario_id,destino_extension,seguimiento_id
      FROM $union WHERE pbx_call_id=? LIMIT 2");
    $q->bind_param('s',$pbx);$q->execute();
    $owners=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    must(count($owners)===1,'Las fuentes no duplican propietarios de una referencia PBX');
    echo "OK: UNION sin MySQL 1271 (tres collations mezcladas), mismo PBX y usuario en MySQL/MariaDB.\n";
}finally{
    $db->query("DROP DATABASE `$name`");
    $db->close();
}
