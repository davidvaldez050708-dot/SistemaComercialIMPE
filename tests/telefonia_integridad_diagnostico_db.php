<?php
declare(strict_types=1);
// Sólo la instancia aislada explícita. Nunca conecta a la base del CRM.
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if(!$port)throw new RuntimeException('Define IMPE_TEST_MYSQL_PORT de la instancia aislada.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$user=getenv('IMPE_TEST_MYSQL_USER')?:'root';
$password=getenv('IMPE_TEST_MYSQL_PASSWORD')?:'';
$db=new mysqli('127.0.0.1',$user,$password,null,$port);
$name='impe_diag_test_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);
try{
    $db->query('CREATE TABLE telefonia_zadarma_eventos (
        id INT AUTO_INCREMENT PRIMARY KEY,evento VARCHAR(40),pbx_call_id VARCHAR(100),
        internal VARCHAR(12),received_at DATETIME,disposition VARCHAR(40),
        duration INT,is_recorded INT,call_id_with_rec VARCHAR(100),payload_json LONGTEXT
    ) ENGINE=InnoDB');
    foreach(['NOTIFY_INTERNAL','NOTIFY_ANSWER','NOTIFY_END','NOTIFY_RECORD'] as $kind){
        $q=$db->prepare("INSERT INTO telefonia_zadarma_eventos
            (evento,pbx_call_id,internal,received_at,disposition,duration,is_recorded,call_id_with_rec,payload_json)
            VALUES (?,'diag-own-100','100',NOW(),'answered',12,1,'synthetic-private-audio',?)");
        $payload=json_encode(['caller_id'=>'5550009999','name'=>'PERSONA_PRIVADA',
            'recording_url'=>'https://secret.test/audio']);
        $q->bind_param('ss',$kind,$payload);$q->execute();$q->close();
    }
    $before=$db->query('SHOW TABLES')->fetch_all();
    $env=getenv();
    $env['IMPE_DB_HOST']='127.0.0.1:'.$port;
    $env['IMPE_DB_USER']=$user;$env['IMPE_DB_PASSWORD']=$password;$env['IMPE_DB_NAME']=$name;
    $env['IMPE_RUNTIME_ROOT']=dirname(__DIR__);
    $proc=proc_open([PHP_BINARY,__DIR__.'/../tools/diagnosticar_telefonia_integridad.php','100'],
        [1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);
    if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar el diagnóstico aislado.');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
    if($code!==0)throw new RuntimeException('Diagnóstico falló: '.$err);
    $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    $call=$data['llamadas'][0]??[];
    if(($call['atendida_recepcion']??null)!==true || ($call['conectada_historial']??null)!==true ||
        ($call['avisos_grabacion_distintos']??null)!==1)
        throw new RuntimeException('Diagnóstico no conserva el contrato del historial.');
    foreach(['5550009999','PERSONA_PRIVADA','secret.test','synthetic-private-audio'] as $private)
        if(str_contains($out,$private))throw new RuntimeException('Diagnóstico expone datos privados.');
    if($before!==$db->query('SHOW TABLES')->fetch_all() ||
        (int)$db->query('SELECT COUNT(*) FROM telefonia_zadarma_eventos')->fetch_row()[0]!==4)
        throw new RuntimeException('Diagnóstico modificó la base.');
    echo "OK: diagnóstico CLI real, contrato correcto, sin datos privados ni escrituras.\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
