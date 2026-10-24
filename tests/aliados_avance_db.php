<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_historial_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../app/services/AliadoAvanceService.php';
$db=(new Database())->connect(false);$n=0;
function avanceDb($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;echo "OK $m\n";}
function avanceReject($fn,$code,$m){try{$fn();}catch(DomainException $e){avanceDb($e->getCode()===$code,$m);return;}throw new RuntimeException('Aceptó '.$m);}
$sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_05_aliados_seguimiento_convocatorias.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS [\s\S]+?;/',$sql,$tables);foreach($tables[0] as $ddl)$db->query($ddl);
$perms=['aliados.ver','convocatorias.ver','aliados.ver_historial','aliados.seguimiento_convocatorias'];$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>$perms];
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_row()[0];
$s=new AliadoAvanceService($db);$a=$s->consultar('manual',$manual);$items=$a['avance']['convocatorias'];
avanceDb($a['avance']['compartidas']===2,'recupera dos convocatorias históricas ya compartidas');
avanceDb($a['avance']['total']===count($items),'sólo vigentes autorizadas en el total');
$pub=array_values(array_filter($items,fn($p)=>$p['compartida']))[0];$cid=(int)$pub['id'];
avanceDb($pub['respuesta']['etiqueta']==='Sin respuesta registrada'&&$pub['respuesta']['version']===0,'un envío previo no inventa respuesta humana');
$before=(int)$db->query('SELECT COUNT(*) FROM aliados_manuales_difusiones')->fetch_row()[0];
$data=['convocatoria_id'=>$cid,'estado'=>'SOLICITA_INFORMACION','nota'=>'La institución solicitó las fechas por teléfono.','version'=>0];
$saved=$s->guardarRespuesta('manual',$manual,$data);$p=array_values(array_filter($saved['avance']['convocatorias'],fn($p)=>(int)$p['id']===$cid))[0];
avanceDb($p['respuesta']['estado']==='SOLICITA_INFORMACION'&&$p['respuesta']['responsable']==='Cuenta Clave','respuesta por convocatoria conserva responsable y nota');
avanceDb($saved['avance']['compartidas']===$a['avance']['compartidas']&&(int)$db->query('SELECT COUNT(*) FROM aliados_manuales_difusiones')->fetch_row()[0]===$before,'registrar respuesta no altera envíos ni avance');
avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,$data),409,'reintento o edición desactualizada no duplica respuesta');
$data['version']=$p['respuesta']['version'];$data['estado']='DIFUSION_CONFIRMADA';$data['nota']='Confirmaron la difusión a sus colaboradores.';$s->guardarRespuesta('manual',$manual,$data);
avanceDb((int)$db->query('SELECT COUNT(*) FROM aliados_manuales_respuestas')->fetch_row()[0]===2,'actualizar conserva el historial de respuestas');
avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,array_replace($data,['convocatoria_id'=>999999])),409,'no registra respuesta de publicación ajena');
avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,array_replace($data,['estado'=>'FALSO'])),422,'estado no autorizado rechazado');
$pending=array_values(array_filter($items,fn($p)=>!$p['compartida']));if($pending)avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,array_replace($data,['convocatoria_id'=>$pending[0]['id']])),409,'no responde publicación todavía no compartida');
$row=array_values(array_filter((new AliadoModel($db))->obtenerListado(2,false,['estado_id'=>1]),fn($r)=>($r['origen_tipo']??'')==='manual'))[0];
avanceDb($row['difusion_avance']['compartidas']===2&&$row['seguimiento_convocatoria_estado']==='','directorio muestra avance y no Esperando respuesta ficticio');
// Activa el seguimiento real de un envío institucional ya persistido, sólo en fixture.
$m=new AliadoModel($db);$eid=(int)$db->query('SELECT id FROM aliados_convocatorias_envios WHERE seguimiento_id=10 LIMIT 1')->fetch_row()[0];$m->iniciarSeguimientoConvocatoriaPorEnvio($eid,2);
$inst=$s->consultar('institucional',10);$ip=array_values(array_filter($inst['avance']['convocatorias'],fn($p)=>$p['compartida']))[0];
$idata=['convocatoria_id'=>$ip['id'],'estado'=>'NO_PARTICIPARA','nota'=>'Confirmó que no participará este periodo.','version'=>$ip['respuesta']['version']];
$saved=$s->guardarRespuesta('institucional',10,$idata);$ip2=array_values(array_filter($saved['avance']['convocatorias'],fn($p)=>(int)$p['id']===(int)$ip['id']))[0];
avanceDb($ip2['respuesta']['estado']==='NO_PARTICIPARA'&&$ip2['respuesta']['version']>$ip['respuesta']['version'],'institucional usa seguimiento y auditoría existentes');
avanceReject(fn()=>$s->guardarRespuesta('institucional',10,$idata),409,'respuesta institucional desactualizada rechazada');
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>$perms];avanceDb($s->consultar('manual',$manual)['ok']&&$s->consultar('institucional',10)['ok'],'Marketing consulta ambos avances');
$_SESSION['usuario_id']=3;$_SESSION['rol']='Cuenta Clave';$_SESSION['rol_id']=4;avanceReject(fn()=>$s->consultar('manual',$manual),403,'Cuenta Clave ajena no consulta avance');avanceReject(fn()=>$s->guardarRespuesta('institucional',10,$idata),403,'Cuenta Clave ajena no actualiza respuesta');
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>['aliados.ver','convocatorias.ver']];avanceDb(!$s->consultar('manual',$manual)['avance']['convocatorias'][0]['puede_responder'],'consulta sin permiso de operar respuestas');avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,$data),403,'permiso de seguimiento requerido al guardar');
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'rol'=>'Administrador','permisos'=>$perms];avanceReject(fn()=>$s->guardarRespuesta('manual',$manual,$data),403,'Administrador conserva supervisión de consulta');
avanceDb((int)$db->query('SELECT COUNT(*) FROM seguimientos_vinculacion')->fetch_row()[0]===1,'respuestas manuales no crean expedientes de Vinculación');
echo "PASS $n comprobaciones de avance y respuestas con SQL real\n";
