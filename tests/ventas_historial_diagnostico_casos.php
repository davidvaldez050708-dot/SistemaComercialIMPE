<?php
$collisionRef='out_'.str_repeat('f',32);$now=date('Y-m-d H:i:s');
$event($collisionRef,'NOTIFY_OUT_START','150',0,['received_at'=>$now,'call_start'=>$now]);
$sqlNow=gmdate('Y-m-d H:i:s');$tokenOne=str_repeat('1',32);$tokenTwo=str_repeat('2',32);
$s=$db->prepare("INSERT INTO telefonia_ventas_marcaciones(usuario_id,extension,destino,token,pbx_call_id,created_at) VALUES(8,'150','+525551112233',?,?,?),(8,'150','+525551112233',?,NULL,?)");
$s->bind_param('sssss',$tokenOne,$collisionRef,$sqlNow,$tokenTwo,$sqlNow);$s->execute();$s->close();
$before=$db->query('SELECT * FROM telefonia_ventas_marcaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC);
// La versión sin corregir aborta toda la bandeja al intentar ocupar una referencia ya vinculada.
try{(new TelefoniaMarcacionesVentasService($db,false))->reconciliarPendientes(8,'150');throw new RuntimeException('No se reprodujo el conflicto de referencia');}
catch(mysqli_sql_exception $e){vcheck($e->getCode()===1062,'Reproduce la colisión que interrumpe el historial de una sola cuenta');}
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
$db->query("DELETE FROM telefonia_ventas_marcaciones WHERE token IN ('$tokenOne','$tokenTwo')");
echo "Diagnóstico: sólo lectura y colisión de referencia reproducida en base local.\n";
