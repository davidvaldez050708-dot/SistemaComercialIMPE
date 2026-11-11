<?php
/** Test sobre una base NUEVA y desechable. Nunca usa una base preexistente. */
if (getenv('IMPE_EMBUDO_PBX_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: falta IMPE_EMBUDO_PBX_TEST_DB=YES.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/EmbudoComercialIntentosPbxRepository.php';
function checkPbx($ok,$message){if(!$ok)throw new RuntimeException('FALLO PBX: '.$message);}
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_test_embudo_pbx_'.bin2hex(random_bytes(6));
$created=false;
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;$db->select_db($name);
    foreach([
       'CREATE TABLE prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY, asesor_id INT NOT NULL, vigente TINYINT NOT NULL) ENGINE=InnoDB',
       'CREATE TABLE prospecto_embudo_llamadas(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED NOT NULL,usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(36) NOT NULL,creado_at DATETIME NOT NULL,UNIQUE KEY uq_embudo_ref(extension,pbx_call_id),FOREIGN KEY(asignacion_id) REFERENCES prospecto_asignaciones(id)) ENGINE=InnoDB',
       'CREATE TABLE telefonia_ventas_llamadas(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,direccion VARCHAR(10) NOT NULL,numero VARCHAR(40) NOT NULL,fecha DATETIME NOT NULL,UNIQUE KEY uq_call(pbx_call_id,extension)) ENGINE=InnoDB',
       'CREATE TABLE telefonia_ventas_resultados(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(36) NOT NULL,UNIQUE KEY uq_result(pbx_call_id,extension)) ENGINE=InnoDB',
       'CREATE TABLE telefonia_ventas_resultado_detalles(resultado_id BIGINT UNSIGNED PRIMARY KEY,catalogo_version VARCHAR(10) NOT NULL,contacto_antes_corte VARCHAR(2) NOT NULL DEFAULT \'\',FOREIGN KEY(resultado_id) REFERENCES telefonia_ventas_resultados(id)) ENGINE=InnoDB'
    ] as $sql)$db->query($sql);
    $db->query('INSERT INTO prospecto_asignaciones VALUES(101,8,1),(102,9,1),(103,8,1),(104,8,0)');
    $read=new EmbudoComercialIntentosPbxRepository($db);
    $summary=function($uid,$asig,$dia)use($read){return $read->resumenJornada($uid,$asig,$dia);};
    // Cada llamada genera piezas independientes con el mismo ID PBX.
    $add=function($asig,$user,$ref,$result,$day,$dir='Saliente',
        $withLink=true,$withResult=true,$version='',$cut='',
        $withCall=true,$linkResult='')use($db){
        $ext=$user===9?'151':'150';$phone='7771234567';
        if($withCall){
            $s=$db->prepare('INSERT INTO telefonia_ventas_llamadas(usuario_id,extension,pbx_call_id,direccion,numero,fecha) VALUES(?,?,?,?,?,?)');
            $s->bind_param('isssss',$user,$ext,$ref,$dir,$phone,$day);$s->execute();$s->close();
        }
        if($withResult){
            $s=$db->prepare('INSERT INTO telefonia_ventas_resultados(usuario_id,extension,pbx_call_id,resultado) VALUES(?,?,?,?)');
            $s->bind_param('isss',$user,$ext,$ref,$result);$s->execute();$id=$db->insert_id;$s->close();
            if($version!==''){
                $s=$db->prepare('INSERT INTO telefonia_ventas_resultado_detalles(resultado_id,catalogo_version,contacto_antes_corte) VALUES(?,?,?)');
                $s->bind_param('iss',$id,$version,$cut);$s->execute();$s->close();
            }
        }
        if($withLink){
            $s=$db->prepare('INSERT INTO prospecto_embudo_llamadas(asignacion_id,usuario_id,extension,pbx_call_id,resultado,creado_at) VALUES(?,?,?,?,?,?)');
            // La vinculación se guarda DESPUÉS, al día siguiente.
            $saved='2026-11-12 15:00:00';$code=$linkResult?:$result;
            $s->bind_param('iissss',$asig,$user,$ext,$ref,$code,$saved);$s->execute();$s->close();
        }
    };
    checkPbx($summary(8,101,'2026-11-11')['total']===0,'sin llamadas 0/3');
    $add(101,8,'out_1011111','BUZON_VOZ','2026-11-11 09:00:00');
    checkPbx($summary(8,101,'2026-11-11')['pendientes']===2,'buzón 1/3');
    $add(101,8,'out_1022222','SIN_RESPUESTA','2026-11-11 12:00:00');
    checkPbx($summary(8,101,'2026-11-11')['pendientes']===1,'no contestó 2/3');
    $add(101,8,'out_1033333','OCUPADO','2026-11-11 23:58:00');
    checkPbx($summary(8,101,'2026-11-11')['completo']===true,'tres PBX = 3/3');
    checkPbx($summary(8,101,'2026-11-12')['total']===0,
        'fecha de la llamada y no momento de captura');
    // Sin vinculación, sin resultado, sin PBX, entrante, usuario ajeno, etc.
    $add(102,9,'out_2011111','BUZON_VOZ','2026-11-11 10:00:00');
    $add(103,8,'out_3011111','SIN_RESPUESTA','2026-11-11 10:01:00');
    $add(104,8,'out_4011111','SIN_RESPUESTA','2026-11-11 10:02:00');
    $add(101,8,'out_5011111','SIN_RESPUESTA','2026-11-11 11:00:00','Saliente',false);
    $add(101,8,'out_5022222','SIN_RESPUESTA','2026-11-11 11:00:00','Saliente',true,false);
    $add(101,8,'out_5033333','SIN_RESPUESTA','2026-11-11 11:00:00','Saliente',true,true,'','',false);
    $add(101,8,'in_1111111','BUZON_VOZ','2026-11-11 11:00:00','Entrante');
    $add(101,8,'out_5044444','CONVERSACION_PERSONA','2026-11-11 11:00:00');
    $add(101,8,'out_5055555','NUMERO_INEXISTENTE','2026-11-11 11:00:00');
    $add(101,8,'out_5066666','SIN_RESPUESTA','2026-11-11 11:00:00','Saliente',true,true,'','',true,'BUZON_VOZ');
    checkPbx($summary(8,101,'2026-11-11')['total']===3,'exclusiones completas');
    checkPbx($summary(8,104,'2026-11-11')['total']===0,'asignación inactiva');
    checkPbx($summary(9,101,'2026-11-11')['total']===0,'propietario diferente');

    $add(101,8,'out_6011111','COMUNICACION_INTERRUMPIDA','2026-11-12 08:00:00','Saliente',true,true,'4','NO');
    $add(101,8,'out_6022222','COMUNICACION_INTERRUMPIDA','2026-11-12 09:00:00','Saliente',true,true,'4','SI');
    $add(101,8,'out_6033333','COMUNICACION_INTERRUMPIDA','2026-11-12 10:00:00','Saliente',true,true,'4','');
    checkPbx($summary(8,101,'2026-11-12')['total']===1,'corte sólo sin conversación');
    $add(101,8,'out_7011111','ENVIO_WHATSAPP','2026-11-12 11:00:00','Saliente',true,true,'4');
    $add(101,8,'out_7022222','ENVIO_SMS','2026-11-12 12:00:00','Saliente',true,true,'3');
    checkPbx($summary(8,101,'2026-11-12')['total']===2,'WhatsApp V4 NO conversación, V3 ambiguo excluido');

    $before=(int)$db->query('SELECT COUNT(*) n FROM prospecto_embudo_llamadas')->fetch_assoc()['n'];
    for($i=0;$i<4;$i++)$summary(8,101,'2026-11-11');
    checkPbx($before===(int)$db->query('SELECT COUNT(*) n FROM prospecto_embudo_llamadas')->fetch_assoc()['n'],
        'lectura no modifica resultados');
    foreach(['2026-02-30','2026-11-11;DROP','11/11/2026'] as $date){
        try{$summary(8,101,$date);throw new RuntimeException('fecha inválida aceptada');}
        catch(InvalidArgumentException $ok){}
    }
    $sql=file_get_contents(__DIR__.'/../database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql');
    checkPbx(strpos($sql,'INSERT INTO')===false && strpos($sql,'ALTER TABLE')===false,
        'migración no puede alterar históricos');
    $statements=array_filter(array_map('trim',explode(';',preg_replace('/^\s*--.*$/m','',$sql))));
    foreach($statements as $statement){if($statement!=='')$db->query($statement);}
    foreach($statements as $statement){if($statement!=='')$db->query($statement);}
    foreach(['prospecto_embudo_subetapas','prospecto_embudo_subetapas_historial'] as $table)
        checkPbx($db->query("SHOW TABLES LIKE '".$table."'")->num_rows===1,'tabla aditiva '.$table);
    checkPbx($summary(8,101,'2026-11-11')['total']===3,'migración conserva PBX');
    echo "CHECK OK: MySQL temporal, 0/3 a 3/3, fecha México, llamadas vinculadas, propietario, duplicados, cortes, mensajes V4, lectura pura y subetapas aditivas.\n";
} finally {
    if($created)$db->query('DROP DATABASE '.$name);
    $db->close();
}
