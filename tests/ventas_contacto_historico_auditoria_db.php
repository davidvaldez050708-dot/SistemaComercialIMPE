<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/tools/ventas_contacto_historico_auditar.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if($port<=0)throw new RuntimeException('Falta puerto de DB aislada para las pruebas.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',
    getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_whatsapp_historico_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db->select_db($name);
$db->set_charset('utf8mb4');
$check=static function($value,string $name):void{if(!$value)throw new RuntimeException('FAIL: '.$name);};
try{
    $db->query("CREATE TABLE telefonia_ventas_resultados(
      id BIGINT UNSIGNED PRIMARY KEY,usuario_id INT,extension VARCHAR(20),
      pbx_call_id VARCHAR(100),resultado VARCHAR(36),created_at DATETIME)");
    $db->query("CREATE TABLE telefonia_ventas_resultado_detalles(
      resultado_id BIGINT UNSIGNED PRIMARY KEY,catalogo_version VARCHAR(10),
      envio_informacion VARCHAR(16))");
    $db->query("CREATE TABLE telefonia_ventas_resultado_selecciones(
      resultado_id BIGINT UNSIGNED,codigo VARCHAR(36),posicion INT)");
    $db->query("CREATE TABLE telefonia_ventas_llamadas(
      id BIGINT UNSIGNED PRIMARY KEY,usuario_id INT,extension VARCHAR(20),
      pbx_call_id VARCHAR(100),fecha DATETIME,direccion VARCHAR(10))");
    $db->query("CREATE TABLE telefonia_zadarma_eventos(
      id BIGINT UNSIGNED PRIMARY KEY,internal VARCHAR(20),
      pbx_call_id VARCHAR(100),evento VARCHAR(32),
      is_recorded TINYINT,call_id_with_rec VARCHAR(100))");
    $add=static function(int $id,string $result,string $version,string $envio,
                         array $selections,bool $answer,bool $record,
                         bool $foreignAudio=false)use($db):void{
        $pbx='out_CANDIDATO_'.str_pad((string)$id,8,'0',STR_PAD_LEFT);
        $db->query("INSERT INTO telefonia_ventas_resultados VALUES
            ($id,8,'152','$pbx','$result','2026-11-06 10:00:00')");
        $db->query("INSERT INTO telefonia_ventas_resultado_detalles VALUES
            ($id,'$version','$envio')");
        foreach($selections as $n=>$code)
            $db->query("INSERT INTO telefonia_ventas_resultado_selecciones VALUES ($id,'$code',$n)");
        $db->query("INSERT INTO telefonia_ventas_llamadas VALUES
            ($id,8,'152','$pbx','2026-11-06 09:59:00','Saliente')");
        if($answer)
            $db->query("INSERT INTO telefonia_zadarma_eventos VALUES
                ($id*10,'152','$pbx','NOTIFY_ANSWER',0,NULL)");
        if($record){
            $ext=$foreignAudio?'154':'152';
            $event=$id*10+1;
            $db->query("INSERT INTO telefonia_zadarma_eventos VALUES
                ($event,'$ext','$pbx','NOTIFY_OUT_END',1,'149123456.160002')");
        }
    };
    $add(1,'ENVIO_WHATSAPP','3','WHATSAPP',['WHATSAPP'],true,true);
    $add(2,'ENVIO_WHATSAPP','3','WHATSAPP',['WHATSAPP'],true,false);
    $add(3,'ENVIO_WHATSAPP','3','WHATSAPP',['WHATSAPP'],false,true);
    $add(4,'ENVIO_WHATSAPP','3','WHATSAPP',['WHATSAPP'],true,true,true);
    $add(5,'ENVIO_WHATSAPP','4','WHATSAPP',['WHATSAPP'],true,true);
    $add(6,'PROSPECTO_POTENCIAL','3','WHATSAPP',['PROSPECTO_POTENCIAL','WHATSAPP'],true,true);
    $add(7,'ENVIO_WHATSAPP','3','WHATSAPP',['WHATSAPP','SMS'],true,true);
    $add(8,'ENVIO_SMS','3','SMS',['SMS'],true,true);
    $before=$db->query("SELECT COUNT(*) n FROM telefonia_ventas_resultados")->fetch_assoc()['n'];
    $db->query('START TRANSACTION READ ONLY');
    $items=VentasContactoHistoricoAuditoria::consultar($db);
    $db->commit();
    $check(count($items)===5,'Lista WhatsApp-only V3 y muestra también selecciones contradictorias');
    $status=[];
    foreach($items as $r)$status[$r['resultado_id']]=$r['categoria'];
    $check($status[1]==='PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA','1 con audio y ANSWER');
    $check($status[2]==='SIN_REFERENCIA_LOCAL_DE_AUDIO','2 sin audio');
    $check($status[3]==='SIN_RESPUESTA_PBX_ACREDITADA','3 grabación sin conexión humana acreditada');
    $check($status[4]==='SIN_REFERENCIA_LOCAL_DE_AUDIO','4 audio de otra extensión no cuenta');
    $check($status[7]==='NO_ES_ENVIO_WHATSAPP_UNICO_V3','7 WhatsApp + SMS no es único');
    $summary=VentasContactoHistoricoAuditoria::resumen($items);
    $check($summary['total']===5&&$summary['por_usuario']['8']['pendientes_escucha']===1,'resumen');
    $dir=sys_get_temp_dir().'/impe_historico_'.bin2hex(random_bytes(3));
    mkdir($dir,0700);
    mkdir($dir.'/public_html');
    file_put_contents($dir.'/public_html/index.php','<?php');
    $report=VentasContactoHistoricoAuditoria::guardar($dir.'/public_html',$dir.'/privado',$items);
    $check(is_file($report),'informe privado');
    $document=json_decode(file_get_contents($report),true,16,JSON_THROW_ON_ERROR);
    $check($document['llamadas'][0]['revision_humana']==='PENDIENTE','ninguna confirmación automática');
    $check(($document['resumen']['total']??0)===5,'JSON resumen');
    try {
       VentasContactoHistoricoAuditoria::guardar($dir.'/public_html',$dir.'/public_html/publico',$items);
       throw new RuntimeException('FAIL: No debe escribir dentro de sitio');
    } catch (RuntimeException $err) {
       $check(strpos($err->getMessage(),'fuera de public_html')!==false,'protección privada');
    }
    $after=$db->query("SELECT COUNT(*) n FROM telefonia_ventas_resultados")->fetch_assoc()['n'];
    $check($before===$after,'BD intacta');
    foreach($db->query("SELECT resultado FROM telefonia_ventas_resultados ORDER BY id")->fetch_all(MYSQLI_ASSOC) as $r)
       $check(in_array($r['resultado'],['ENVIO_WHATSAPP','ENVIO_SMS','PROSPECTO_POTENCIAL'],true),'sin mutaciones');
    echo "PASS: auditoría WhatsApp: referencia exacta, 1 propia, 3 sin elegibilidad, 1 selección múltiple, privado, BD intacta.\n";
}finally{
    $db->query("DROP DATABASE `$name`");
    $db->close();
}
