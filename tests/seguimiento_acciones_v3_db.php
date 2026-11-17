<?php
if (PHP_SAPI!=='cli' || getenv('IMPE_SEGUIMIENTO_ACCIONES_TEST')!=='YES') exit(2);
require __DIR__.'/../app/services/EmbudoComercialV3SeguimientoService.php';
require __DIR__.'/../app/services/EmbudoComercialV3GestionPotencialService.php';
require __DIR__.'/../app/services/EmbudoComercialV3InscripcionService.php';
require __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function verificarAccion(bool $ok,string $caso): void {
    if (!$ok) throw new RuntimeException($caso);
}
function rechazarAccion(callable $run): void {
    try {$run();} catch (DomainException $ok) {return;}
    throw new RuntimeException('Se esperaba rechazo');
}
putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
putenv('IMPE_VENTAS_V3_UI_ASESORES=22');
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$name='impe_test_acciones_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $name");$db->select_db($name);
try {
    foreach ([
        "prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,prospecto_id INT,asesor_id INT,vigente INT,asignado_at DATETIME)",
        "prospectos_comerciales(id INT PRIMARY KEY,correo VARCHAR(190),telefono_normalizado VARCHAR(20),pais_nombre VARCHAR(30))",
        "prospecto_embudo_etapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME)",
        "prospecto_embudo_movimientos(id INT AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED,etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),motivo VARCHAR(190),usuario_id INT,creado_at DATETIME)",
        "prospecto_embudo_agenda(asignacion_id BIGINT UNSIGNED PRIMARY KEY,proxima_accion_at DATETIME,revision_fecha DATE,actualizado_at DATETIME,actualizado_por INT)",
        "prospecto_embudo_llamadas(asignacion_id BIGINT UNSIGNED,usuario_id INT,extension VARCHAR(10),pbx_call_id VARCHAR(100) PRIMARY KEY,resultado VARCHAR(40),creado_at DATETIME)",
    ] as $tabla) $db->query("CREATE TABLE $tabla ENGINE=InnoDB");
    foreach (['2026_11_11_ventas_subetapas_comerciales_propuesta.sql','2026_11_12_ventas_seguimiento_operativo_v3.sql'] as $file) {
        $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/'.$file));
        foreach (explode(';',$sql) as $part) if(trim($part)!=='')$db->query($part);
    }
    $etapas=['NUEVO','CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO','PROCESO_INSCRIPCION'];
    for($id=1;$id<=18;$id++){
        $etapa=$etapas[($id-1)%6];
        $db->query("INSERT INTO prospecto_asignaciones VALUES($id,$id,22,1,UTC_TIMESTAMP())");
        $db->query("INSERT INTO prospectos_comerciales VALUES($id,'fixture$id@example.test','+525500000001','Mexico')");
        $db->query("INSERT INTO prospecto_embudo_etapas VALUES($id,'$etapa',22,UTC_TIMESTAMP())");
        $db->query("INSERT INTO prospecto_embudo_agenda VALUES($id,'2099-01-01 16:00:00',NULL,UTC_TIMESTAMP(),22)");
    }
    $etapaDe=fn($id)=>$db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_row()[0];
    $svc=new EmbudoComercialV3SeguimientoService($db);
    $inscripcion=new EmbudoComercialV3InscripcionService($db);
    foreach($etapas as $i=>$etapa){
        $id=$i+1;
        $evento=$svc->registrar(22,$id,$etapa,0,'REGISTRAR','Observación de prueba.','','');
        verificarAccion($etapaDe($id)===$etapa,'una nota conserva etapa');
        $evento=$svc->registrar(22,$id,$etapa,$evento,'PROGRAMAR','Próxima acción acordada.','2099-01-02T10:00','');
        $esperada=in_array($etapa,['CONTACTADO','POTENCIAL','INTERESADO'],true)?'SEGUIMIENTO':$etapa;
        verificarAccion($etapaDe($id)===$esperada,'programar '.$etapa);
        $antes=$svc->historial(22,$id);
        $subAntes=$db->query("SELECT * FROM prospecto_embudo_subetapas WHERE asignacion_id=$id")->fetch_all(MYSQLI_ASSOC);
        $movAntes=$db->query("SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=$id")->fetch_row()[0];
        $subHistAntes=$db->query("SELECT COUNT(*) FROM prospecto_embudo_subetapas_historial WHERE asignacion_id=$id")->fetch_row()[0];
        rechazarAccion(fn()=>$svc->registrar(22,$id,$esperada,$evento,'REPROGRAMAR','Sin cambio real de cita.','2099-01-02T10:00',
            $esperada==='SEGUIMIENTO'?'ESPERANDO_RESPUESTA':''));
        verificarAccion($svc->historial(22,$id)===$antes && $etapaDe($id)===$esperada &&
            $db->query("SELECT * FROM prospecto_embudo_subetapas WHERE asignacion_id=$id")->fetch_all(MYSQLI_ASSOC)===$subAntes &&
            $db->query("SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=$id")->fetch_row()[0]===$movAntes &&
            $db->query("SELECT COUNT(*) FROM prospecto_embudo_subetapas_historial WHERE asignacion_id=$id")->fetch_row()[0]===$subHistAntes,
            'misma fecha no registra actividad, etapa ni subetapa');
        rechazarAccion(fn()=>$svc->registrar(22,$id,$esperada,$evento-1,'REPROGRAMAR','Conflicto de prueba.','2099-01-03T10:00',''));
        $evento=$svc->registrar(22,$id,$esperada,$evento,'REPROGRAMAR','Reprogramación acordada.','2099-01-03T10:00','');
        $svc->registrar(22,$id,$esperada,$evento,'COMPLETAR','Actividad atendida.','','');
        verificarAccion($etapaDe($id)===$esperada,'completar conserva etapa');
        $h=$svc->historial(22,$id);
        verificarAccion($h[0]['fecha_pendiente']===null && $h[0]['completada_at']!==null && count($h)===4,'agenda y auditoría');
    }
    $potencial=new EmbudoComercialV3GestionPotencialService($db);
    $potencial->registrar(22,9,'POTENCIAL','','REUNIENDO_DOCUMENTOS','Documentos para mañana.','2099-01-02T10:00');
    verificarAccion($etapaDe(9)==='SEGUIMIENTO','Gestionar potencial con fecha mueve');
    verificarAccion($db->query("SELECT subetapa FROM prospecto_embudo_subetapas WHERE asignacion_id=9")->fetch_row()[0]==='ESPERANDO_DOCUMENTOS','subetapa documentos');
    rechazarAccion(fn()=>$potencial->registrar(22,9,'POTENCIAL','','CALIFICADO','Reintento de prueba.','2099-01-02T10:00'));
    rechazarAccion(fn()=>$inscripcion->iniciar(22,9,true,false));
    $inscripcion->iniciar(22,9,true,true);
    verificarAccion($etapaDe(9)==='PROCESO_INSCRIPCION','inicio desde seguimiento');
    verificarAccion($db->query("SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=9 AND etapa_anterior='SEGUIMIENTO' AND etapa_nueva='PROCESO_INSCRIPCION'")->fetch_row()[0]==1,'origen auditado');
    $bridge=new EmbudoComercialResultadoBridge($db);
    foreach($etapas as $i=>$etapa){
        $id=$i+13;
        $call=['extension'=>'157','pbx_call_id'=>'out_acciones_'.$id,'direccion'=>'Saliente','finalizada'=>true,'conectada'=>true,'numero'=>'5500000001'];
        $db->begin_transaction();
        $result=$bridge->aplicar(22,$id,$call,'CONVERSACION_PERSONA','2099-01-02 10:00:00');
        $db->commit();
        $esperada=$etapa==='PROCESO_INSCRIPCION'?$etapa:'SEGUIMIENTO';
        verificarAccion($result['etapa']===$esperada && $etapaDe($id)===$esperada,'llamada con próxima acción '.$etapa);
        verificarAccion($svc->historial(22,$id)[0]['fecha_pendiente']==='2099-01-02 16:00:00','fecha PBX y actividad');
    }
    $call=['extension'=>'157','pbx_call_id'=>'out_nuevo_sin_respuesta','direccion'=>'Saliente','finalizada'=>true,'conectada'=>false,'numero'=>'5500000001'];
    $db->begin_transaction();
    $result=$bridge->aplicar(22,7,$call,'SIN_RESPUESTA','2099-01-02 10:00:00');
    $db->commit();
    verificarAccion($etapaDe(7)==='NUEVO' && !$result['movida'] &&
        $svc->historial(22,7)[0]['fecha_pendiente']==='2099-01-02 16:00:00','intento sin conversación conserva Nuevos');
    $db->begin_transaction();
    try {rechazarAccion(fn()=>$bridge->aplicar(22,7,$call,'SIN_RESPUESTA','2099-01-02 10:00:00'));}
    finally {$db->rollback();}
    verificarAccion(count($svc->historial(22,7))===1,'reintento PBX no duplica actividad');
    $_SESSION['permisos']=['ventas.embudo.ver'];
    rechazarAccion(fn()=>$svc->registrar(22,8,'CONTACTADO',0,'PROGRAMAR','Sin autorización.','2099-01-02T10:00',''));
    $_SESSION['permisos'][]='ventas.embudo.mover';
    rechazarAccion(fn()=>$svc->registrar(22,8,'CONTACTADO',0,'PROGRAMAR','Fecha incorrecta.','2000-01-01T10:00',''));
    $db->query("CREATE TRIGGER falla_actividad BEFORE INSERT ON prospecto_embudo_actividad_v3 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    try {
        $svc->registrar(22,8,'CONTACTADO',0,'PROGRAMAR','Fallo transaccional.','2099-01-02T10:00','');
        throw new RuntimeException('Se esperaba fallo SQL');
    } catch(mysqli_sql_exception $ok) {}
    verificarAccion($etapaDe(8)==='CONTACTADO' && $db->query('SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=8')->fetch_row()[0]==0,'fallo revierte etapa y auditoría');
    $db->query('DROP TRIGGER falla_actividad');
    $worker=tempnam(sys_get_temp_dir(),'acciones-worker-');
    $codigo='<?php require '.var_export(__DIR__.'/../app/services/EmbudoComercialV3SeguimientoService.php',true).';'
        .'mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);'
        .'$_SESSION=["usuario_id"=>22,"rol_id"=>3,"permisos"=>["ventas.embudo.ver","ventas.embudo.mover"]];'
        .'$db=new mysqli("127.0.0.1",getenv("MYSQL_USER"),getenv("MYSQL_PASSWORD"),$argv[1],(int)getenv("MYSQL_PORT"));'
        .'try{(new EmbudoComercialV3SeguimientoService($db))->registrar(22,8,"CONTACTADO",0,"PROGRAMAR","Envío concurrente.","2099-01-02T10:00","");echo "OK";}'
        .'catch(DomainException $e){echo "CONFLICTO";}';
    file_put_contents($worker,$codigo);
    $procesos=[];
    try {
        for($i=0;$i<2;$i++){
            $proceso=proc_open([PHP_BINARY,$worker,$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proceso))throw new RuntimeException('No se pudo iniciar prueba concurrente');
            fclose($pipes[0]);$procesos[]=[$proceso,$pipes];
        }
        $resultados=[];
        foreach($procesos as [$proceso,$pipes]){
            $resultados[]=stream_get_contents($pipes[1]);
            $error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            verificarAccion(proc_close($proceso)===0 && $error==='', 'worker concurrente: '.$error);
        }
        sort($resultados);
        verificarAccion($resultados===['CONFLICTO','OK'],'un único envío concurrente aceptado');
        verificarAccion($etapaDe(8)==='SEGUIMIENTO' && $db->query('SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=8')->fetch_row()[0]==1,'movimiento único');
    } finally {unlink($worker);}
    echo "OK seis etapas, actividades, potenciales, PBX, inscripción, agenda, permisos, versiones y rollback.
";
} finally {$db->query("DROP DATABASE $name");$db->close();}
