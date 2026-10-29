<?php
$collisionRef='out_'.str_repeat('f',32);$now=date('Y-m-d H:i:s');
$event($collisionRef,'NOTIFY_OUT_START','150',0,['received_at'=>$now,'call_start'=>$now]);
$sqlNow=gmdate('Y-m-d H:i:s');$tokenOne=str_repeat('1',32);$tokenTwo=str_repeat('2',32);
$s=$db->prepare("INSERT INTO telefonia_ventas_marcaciones(usuario_id,extension,destino,token,pbx_call_id,created_at) VALUES(8,'150','+525551112233',?,?,?),(8,'150','+525551112233',?,NULL,?)");
$s->bind_param('sssss',$tokenOne,$collisionRef,$sqlNow,$tokenTwo,$sqlNow);$s->execute();$s->close();
$before=$db->query('SELECT * FROM telefonia_ventas_marcaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC);
require_once dirname(__DIR__).'/app/services/TelefoniaMarcacionesVentasLegacy.php';
try{(new TelefoniaMarcacionesVentasLegacy($db,false))->reconciliarPendientes(8,'150');throw new RuntimeException('La versión original debe reproducir el fallo');}
catch(mysqli_sql_exception $e){vcheck($e->getCode()===1062,'Versión exacta de Hostinger reproduce error 1062 con la colisión');}
// La colisión sigue impidiendo reutilizar la referencia, sin abortar toda la cuenta.
$marcaciones=new TelefoniaMarcacionesVentasService($db,false);
vreject(fn()=>$marcaciones->vincular(8,$tokenTwo,$collisionRef),'Vinculación directa no ocupa una referencia ya vinculada');
mysqli_report(MYSQLI_REPORT_OFF);
try{vreject(fn()=>$marcaciones->vincular(8,$tokenTwo,$collisionRef),'También conserva la referencia con mysqli sin excepciones automáticas');}
finally{mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);}
$marcaciones->reconciliarPendientes(8,'150');
vcheck($before===$db->query('SELECT * FROM telefonia_ventas_marcaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Conciliar conserva ambos intentos y la asociación original');
$listado=$svc->listar(8);$bandeja=$svc->bandeja(8);
vcheck(count($listado)>0&&$bandeja['total']>0,'El conflicto no bloquea el historial ni la bandeja');
$vista=$svc->obtener(8,$collisionRef,'150');vcheck($vista['pbx_call_id']===$collisionRef,'Detalle del formulario consulta la referencia exacta');
putenv('IMPE_TEST_DATABASE='.$name);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/tools/diagnosticar_historial_ventas.php').' --site '.escapeshellarg(dirname(__DIR__));
$output=[];$status=0;exec($command,$output,$status);$json=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
vcheck($status===0,'Diagnóstico termina correctamente');
$owner=array_values(array_filter($json['cuentas'],static fn($r)=>$r['usuario_id']===8))[0];
vcheck(!isset($owner['error_lectura'])&&!isset($owner['error_conciliacion']),'Diagnóstico lee historial y prueba selección de pendientes');
$flags=array_filter($owner['conciliacion_sin_escrituras'],static fn($r)=>$r['ya_vinculada_a_otro_intento']);
vcheck(count($flags)===1,'Señala la referencia ya vinculada sin intentar asignarla: '.json_encode($owner));
vcheck($before===$db->query('SELECT * FROM telefonia_ventas_marcaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC),'No cambia ninguna marcación');
vcheck(!str_contains(implode('', $output),'5551112233')&&!str_contains(implode('', $output),$collisionRef),'Oculta teléfono y referencia PBX');
$event($collisionRef,'NOTIFY_OUT_END','150',4,['disposition'=>'answered','received_at'=>date('Y-m-d H:i:s',time()+5),'call_start'=>$now]);
$results->guardar(8,$collisionRef,'CONVERSACION_PERSONA',['observacion'=>'Conversación sintética para comprobar el guardado.']);
vcheck($svc->obtener(8,$collisionRef,'150')['registro']['resultado']==='CONVERSACION_PERSONA','Resultado se guarda con la identidad real pese al intento duplicado');
$otherRef='out_'.str_repeat('e',32);$tokenThree=str_repeat('3',32);
$event($otherRef,'NOTIFY_OUT_START','150',0,['destination'=>'+525556667777','received_at'=>$now,'call_start'=>$now]);
$s=$db->prepare("INSERT INTO telefonia_ventas_marcaciones(usuario_id,extension,destino,token,created_at) VALUES(8,'150','+525556667777',?,?)");$s->bind_param('ss',$tokenThree,$sqlNow);$s->execute();$s->close();
$db->query("CREATE TRIGGER diagnostico_error_sql BEFORE UPDATE ON telefonia_ventas_marcaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=1644, MESSAGE_TEXT='fallo de prueba'");
try{try{$marcaciones->vincular(8,$tokenThree,$otherRef);throw new RuntimeException('No ocultar errores ajenos al conflicto');}catch(mysqli_sql_exception $e){vcheck($e->getCode()===1644,'Otros errores SQL siguen propagándose');}}
finally{$db->query('DROP TRIGGER diagnostico_error_sql');}
$marcaciones->reconciliarPendientes(8,'150');
$s=$db->prepare('SELECT pbx_call_id FROM telefonia_ventas_marcaciones WHERE token=?');$s->bind_param('s',$tokenThree);$s->execute();$linked=$s->get_result()->fetch_assoc();$s->close();
vcheck($linked['pbx_call_id']===$otherRef,'La conciliación continúa con otros intentos válidos de la misma cuenta');
$db->query("DELETE FROM telefonia_ventas_marcaciones WHERE token IN ('$tokenOne','$tokenTwo')");
echo "Diagnóstico: sólo lectura y colisión de referencia reproducida en base local.\n";
