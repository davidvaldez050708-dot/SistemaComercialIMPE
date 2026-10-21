<?php
if(PHP_SAPI!=='cli'||!preg_match('/^impe_test_handly[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';require_once __DIR__.'/../app/services/AliadoInternacionalService.php';require_once __DIR__.'/../app/models/ConvocatoriaModel.php';require_once __DIR__.'/../app/models/FormularioRegistroModel.php';require_once __DIR__.'/../app/models/AliadoInternacionalModel.php';
$db=(new Database())->connect(false);$svc=new AliadoInternacionalService($db);
function checkAliado($b,$m){if(!$b)throw new RuntimeException($m);echo "OK: $m\n";}
$d=['nombre'=>'Institución Houston','pais_codigo'=>'US','region'=>'Texas','ciudad'=>'Houston','contacto_nombre'=>'Contacto','contacto_telefono'=>'+17135551234','programas'=>['bachillerato-general','titulacion-experiencia-laboral']];
$id=$svc->guardar($d,2);$a=$svc->obtener($id);checkAliado($a['region']==='Texas'&&$a['ciudad']==='Houston'&&$a['pais_codigo']==='US','alta sin IDs territoriales mexicanos');
try{$svc->guardar($d,2);throw new RuntimeException('Duplicó institución');}catch(DomainException $e){}
$d['id']=$id;$d['ciudad']='Austin';$svc->guardar($d,2);checkAliado($svc->obtener($id)['ciudad']==='Austin','edición conserva país y región');
$err=$d;$err['pais_codigo']='MX';try{$svc->guardar($err,2);throw new RuntimeException('Aceptó México sin flujo territorial');}catch(DomainException $e){}
$sid=$svc->convenio($id,['fecha'=>date('Y-m-d'),'referencia'=>'CONV-USA-001','notas'=>'Convenio internacional'],2);
checkAliado($sid===$svc->convenio($id,['fecha'=>date('Y-m-d'),'referencia'=>'CONV-USA-001','notas'=>'Actualización'],2),'convenio reutiliza una institución canónica');
$s=$db->query('SELECT * FROM seguimientos_vinculacion WHERE id='.$sid)->fetch_assoc();checkAliado($s['estado_id']===null&&$s['analista_id']===null,'no inventa estado ni analista mexicano');
checkAliado(in_array($id,array_map('intval',array_column((new AliadoInternacionalModel($db))->obtenerAliados(),'id')),true),'aliado sigue visible tras vincular convenio');
checkAliado($svc->ofertas($id)[0]['valor']==='bachillerato|bachillerato-286','usa opción existente 286, sin crear programa');
$datos=['titulo'=>'Convocatoria USA','imagen'=>'public/img/brand/Logo-Sep-Blanco.png','fecha_inicio'=>date('Y-m-d'),'fecha_termino'=>date('Y-m-d',strtotime('+30 days')),'estado'=>1,'usuario_id'=>3,'tipo_convocatoria'=>'bachillerato','subtipo_convocatoria'=>'bachillerato-286'];
$ids=[];
foreach(['registro','inscripcion'] as $tipo){
 $ref=bin2hex(random_bytes(16));$datos['enlace_registro']=BASE_URL.'index.php?controller=formularioPublico&action='.$tipo.'&aliado='.$a['token'].'&convocatoria_ref='.$ref;
 $cid=(new ConvocatoriaModel($db))->crear($datos,[],function($c,$cid)use($id,$tipo,$ref){(new AliadoInternacionalService($c))->relacionar($id,$cid,'bachillerato-general',$tipo,$ref,3);});checkAliado($cid>0,'creación '.$tipo.' con modelo compartido');$ids[]=$cid;
 $config=(new FormularioAliadoService($db))->resolverConvocatoria($a['token'],$ref,$tipo);
 $zona=$db->query('SELECT @@session.time_zone z')->fetch_assoc()['z'];
 $hoy=date('Y-m-d');$db->query("UPDATE convocatorias SET fecha_termino='$hoy' WHERE id=$cid");
 foreach(['+12:00','-12:00'] as $offset){
  $db->query("SET SESSION time_zone='$offset'");
  checkAliado((new FormularioAliadoService($db))->resolverConvocatoria($a['token'],$ref,$tipo)['convocatoria_id']===$cid,'vigencia usa reloj del CRM, independientemente de MySQL '.$offset);
 }
 $db->query("SET SESSION time_zone='".$db->real_escape_string($zona)."'");
 $fin=date('Y-m-d',strtotime('+30 days'));$db->query("UPDATE convocatorias SET fecha_termino='$fin' WHERE id=$cid");

 checkAliado(count($config['programas'])===1&&$config['programas'][0]['codigo']==='bachillerato-general','liga restringe a la oferta persistida');
 try{(new FormularioAliadoService($db))->resolverConvocatoria($a['token'],$ref,$tipo==='registro'?'inscripcion':'registro');throw new RuntimeException('Aceptó formulario cambiado');}catch(DomainException $e){}
 $r=['nombre'=>'Ana','apellido'=>'Prueba','fecha_nacimiento'=>'1990-01-01','movil'=>'7771234567','correo'=>'campana@example.test','perfil_interes'=>'Bachillerato General','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>null,'municipio_id'=>null,'pais_nombre'=>'Estados Unidos','estado_provincia'=>'Texas','ciudad'=>'Houston','creado_por'=>null,'aliado_token'=>$a['token'],'convocatoria_ref'=>$ref,'origen'=>$tipo==='registro'?'PUBLICO':'PUBLICO_INSCRIPCION'];
 $m=new FormularioRegistroModel();checkAliado($m->guardar($r)===$m->guardar($r),'procedencia y reenvío de convocatoria idempotentes');
 $r['perfil_interes']='Titulación por experiencia laboral';try{$m->guardar($r);throw new RuntimeException('Aceptó otra oferta del mismo aliado');}catch(DomainException $e){}
}
checkAliado(count($svc->convocatorias())===2&&$ids[0]!==$ids[1],'segunda convocatoria no sobrescribe la primera');
checkAliado((int)$db->query('SELECT COUNT(*) n FROM marketing_convenio_convocatorias WHERE seguimiento_id='.$sid)->fetch_assoc()['n']===2,'ambas convocatorias relacionadas con convenio compartido');
checkAliado((int)$db->query('SELECT COUNT(*) n FROM formulario_aliado_procedencias WHERE convocatoria_id IS NOT NULL')->fetch_assoc()['n']===2,'procedencia enlazada con convocatoria correcta');
$before=(int)$db->query('SELECT COUNT(*) n FROM convocatorias')->fetch_assoc()['n'];
$fallo=(new ConvocatoriaModel($db))->crear($datos,[],function($c,$cid)use($id){(new AliadoInternacionalService($c))->relacionar($id,$cid,'ingles','registro',bin2hex(random_bytes(16)),3);});
checkAliado($fallo===false&&(int)$db->query('SELECT COUNT(*) n FROM convocatorias')->fetch_assoc()['n']===$before,'relación rechazada revierte convocatoria completa');
$datos['titulo']='Nacional';$nacional=(new ConvocatoriaModel($db))->crear($datos,[1]);checkAliado($nacional>0&&(int)$db->query('SELECT COUNT(*) n FROM convocatoria_estados WHERE convocatoria_id='.$nacional)->fetch_assoc()['n']===1,'creación nacional conserva territorios');
$db->query("UPDATE convocatorias SET fecha_inicio='2000-01-01',fecha_termino='2000-01-31' WHERE id=".$ids[0]);
$rc=new ReflectionMethod(ConvocatoriaModel::class,'eliminarConvocatoriasFueraVentanaMensual');$rc->setAccessible(true);$rc->invoke(new ConvocatoriaModel($db));
checkAliado(count($svc->convocatorias())===2,'depuración nacional conserva convocatorias internacionales');
echo "OK: integración internacional y regresión nacional\n";
