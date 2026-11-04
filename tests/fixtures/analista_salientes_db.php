<?php
// Se incluye sólo en la base desechable creada por telefonia_atencion_db.php.
$sessionAnterior = $_SESSION ?? [];
$_SESSION['permisos'] = ['telefonia.usar','telefonia.recibir','telefonia.salientes'];
$crearSaliente = static function(string $ref,string $disposition='answered',int $secs=7) use ($events): void {
    $at=time()-20;
    foreach ([['NOTIFY_OUT_START',0],['NOTIFY_ANSWER',2],['NOTIFY_OUT_END',9]] as [$tipo,$offset]) {
        if ($tipo==='NOTIFY_ANSWER' && $disposition!=='answered') continue;
        $events->registrar(['event'=>$tipo,'pbx_call_id'=>$ref,'internal'=>'101','destination'=>'+525551112233',
            'call_start'=>date('Y-m-d H:i:s',$at),'received_at'=>date('Y-m-d H:i:s',$at+$offset),
            'duration'=>$tipo==='NOTIFY_OUT_END'?$secs:0,'disposition'=>$tipo==='NOTIFY_OUT_END'?$disposition:'']);
    }
};
$db->query("INSERT INTO seguimientos_vinculacion (id,estado_id,analista_id,origen,nombre_entidad,telefono_fuente,correo_fuente,contacto_nombre,estado_seguimiento) VALUES (90001,1,1,'MANUAL','Seguimiento de prueba saliente','5551112233','saliente@example.org','Prueba','ESPERANDO_RESPUESTA')");
$crearSaliente('out_manual_fixture_01');
$row=$svc->listarSaliente(1,'out_manual_fixture_01')['llamadas'][0];
check($row['tipo_llamada']==='SALIENTE'&&$row['duracion']===7,'Marcación libre conserva PBX y siete segundos propios');
rejects(fn()=>$svc->listarSaliente(2,'out_manual_fixture_01'),'Otra extensión no abre el resultado');
$input=['pbx_call_id'=>'out_manual_fixture_01','decision'=>'existente','seguimiento_id'=>'90001','resultado'=>'SOLICITO_INFORMACION','persona_atendio'=>'Persona de prueba','observacion'=>'Notas del formulario compartido','duration'=>'9999','telefono_destino'=>'0000000000'];
$guardado=$svc->registrar(1,$input);
$interaccion=one('SELECT * FROM interacciones_vinculacion WHERE id='.(int)$guardado['interaccion_id']);
check($interaccion['id_externo']==='out_manual_fixture_01'&&(int)$interaccion['duracion_segundos']===7,'Guardar vincula la referencia real e ignora segundos enviados por navegador');
check($interaccion['telefono_destino']==='+525551112233'&&str_contains($interaccion['notas'],'Notas del formulario compartido'),'Destino firmado y notas quedan en la interacción');
$recuperada=$svc->listarSaliente(1,'out_manual_fixture_01')['llamadas'][0];
check($recuperada['clasificacion']==='VINCULADA'&&$recuperada['interaccion_id']===$guardado['interaccion_id'],'Una respuesta perdida se recupera leyendo el registro exacto ya guardado');
check(one('SELECT estado_seguimiento FROM seguimientos_vinculacion WHERE id=90001')['estado_seguimiento']==='ESPERANDO_RESPUESTA','Conservar etapa del seguimiento vinculado');
rejects(fn()=>$svc->registrar(1,$input),'Repetir POST no duplica interacción');
check((int)one("SELECT COUNT(*) n FROM interacciones_vinculacion WHERE id_externo='out_manual_fixture_01'")['n']===1,'Un resultado por llamada');
$crearSaliente('out_manual_fixture_02');
$nueva=['pbx_call_id'=>'out_manual_fixture_02','decision'=>'nueva','nombre_entidad'=>'Institución nueva desde auricular','estado_id'=>'1','tipo_entidad'=>'INSTITUCION','resultado'=>'SOLICITO_INFORMACION','persona_atendio'=>'Persona nueva','observacion'=>'Atención inicial'];
$r=$svc->registrar(1,$nueva);
check($r['seguimiento_id']>0&&$r['interaccion_id']>0,'El mismo formulario crea expediente e interacción saliente');
check(one('SELECT analista_id FROM seguimientos_vinculacion WHERE id='.(int)$r['seguimiento_id'])['analista_id']==1,'Nueva institución pertenece al analista autorizado');
$crearSaliente('out_manual_fixture_busy','busy',0);
rejects(fn()=>$svc->registrar(1,array_merge($nueva,['pbx_call_id'=>'out_manual_fixture_busy','nombre_entidad'=>'No crear en ocupado','resultado'=>'OCUPADO'])),'Ocupada no crea institución');
check((int)one("SELECT COUNT(*) n FROM seguimientos_vinculacion WHERE nombre_entidad='No crear en ocupado'")['n']===0,'Fallo revierte el alta y la clasificación');
$sin=$svc->registrar(1,['pbx_call_id'=>'out_manual_fixture_busy','decision'=>'sin_identificar','observacion'=>'Intento ocupado']);
check($sin['estado']==='SIN_IDENTIFICAR','Intento negativo puede cerrarse sin crear expediente');
$crearSaliente('out_manual_fixture_forbidden');$db->query('DELETE FROM rol_permisos WHERE rol_id=1 AND permiso_id=3');
rejects(fn()=>$svc->listarSaliente(1,'out_manual_fixture_forbidden'),'Revocar permiso impide abrir saliente');
$db->query('INSERT INTO rol_permisos VALUES (1,3)');
$_SESSION=$sessionAnterior;
