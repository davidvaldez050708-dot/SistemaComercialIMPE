<?php
require __DIR__.'/../app/services/VerificacionInstitucionalService.php';
require __DIR__.'/../app/services/TelefoniaResultadoAnalistaService.php';
function comprobarParcial($ok,$mensaje){if(!$ok)throw new RuntimeException($mensaje);}
$actual=['telefono_fuente'=>'5550000000','correo_fuente'=>'contacto@example.test','contacto_nombre'=>'Persona sintética'];
$pares=[[],['verificacion_telefono_confirmado'=>'1'],['verificacion_correo_confirmado'=>'1'],['verificacion_contacto_confirmado'=>'1'],['verificacion_telefono_confirmado'=>'1','verificacion_correo_confirmado'=>'1']];
foreach(['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES'] as $resultado)foreach($pares as $flags){
 $d=VerificacionInstitucionalService::detalles($flags+['resultado'=>$resultado],$actual);
 comprobarParcial(count($d)===count($flags),'Guardar exactamente las confirmaciones elegidas');
 comprobarParcial(VerificacionInstitucionalService::tienePar($d)===(count($flags)===2),'Sólo el par acredita la llamada');
}
foreach(['NO_INTERESADO','OCUPADO','SIN_RESPUESTA','BUZON_VOZ','FUERA_SERVICIO','NUMERO_INCORRECTO','OTRO'] as $resultado){
 comprobarParcial(!VerificacionInstitucionalService::resultadoVerificable($resultado),'Resultado sin bloque de verificación');
 comprobarParcial(VerificacionInstitucionalService::detalles(['resultado'=>$resultado],$actual)===[],'Guardar sin confirmaciones');
 try{VerificacionInstitucionalService::detalles(['resultado'=>$resultado,'verificacion_telefono_confirmado'=>'1'],$actual);throw new LogicException('No debe acreditar este resultado');}catch(DomainException $e){}
}
$d=VerificacionInstitucionalService::detalles(['resultado'=>'CONTACTO_CORRECTO','verificacion_telefono_confirmado'=>'1','nuevo_correo_contacto'=>'dato-capturado@example.test'],$actual);
comprobarParcial(!VerificacionInstitucionalService::tienePar($d),'Un correo capturado no es un correo confirmado');
try{VerificacionInstitucionalService::detalles(['resultado'=>'CONTACTO_CORRECTO','verificacion_correo_confirmado'=>'1','nuevo_correo_contacto'=>'invalido'],$actual);throw new LogicException('Correo inválido');}catch(DomainException $e){}
$reglas=['opciones'=>['CONTACTO_CORRECTO','NO_INTERESADO'],'permite_verificar'=>true,'etiqueta'=>'Conversación confirmada'];
TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'CONTACTO_CORRECTO','verificacion_telefono_confirmado'=>1]);
try{TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'NO_INTERESADO','verificacion_telefono_confirmado'=>1]);throw new LogicException('No interesado no verifica');}catch(DomainException $e){}
echo "PASS: guardar cero/una/dos confirmaciones; sólo el par acredita; resultados y formatos validados.\n";

// Ejecutar el bloque real del controlador que decide acreditación, sin conexiones ni acciones.
$root=dirname(__DIR__);$src=file_get_contents($root.'/app/controllers/SeguimientoVinculacionController.php');
$a=strpos($src,"        try {\n            if (\$resultadoFormulario==='CONTACTO_REFERIDO')");$b=strpos($src,'        $descartar =',$a);comprobarParcial($a!==false&&$b!==false,'Bloque de controlador localizado');
$bloque=substr($src,$a,$b-$a);
eval('class ProbeControladorParcial{function responderJson($d,$c){throw new RuntimeException($d["mensaje"]);}function evaluar($datos,$seguimiento,$origen){$_POST=$datos;$resultadoFormulario=$datos["resultado"];$resultadoAdmiteVerificacion=VerificacionInstitucionalService::resultadoVerificable($resultadoFormulario);$canalFormulario="LLAMADA";$origenLlamada=$origen;$personaAtendio="Persona sintética";'.$bloque.'return ["candidata"=>$esCandidataVerificacion,"efectiva"=>$esVerificacionEfectiva];}}');
foreach($pares as $flags){$out=(new ProbeControladorParcial())->evaluar($flags+['resultado'=>'CONTACTO_CORRECTO'],$actual,'ZADARMA');comprobarParcial($out['efectiva']===(count($flags)===2),'Controlador: parcial no acredita');}
$out=(new ProbeControladorParcial())->evaluar($pares[4]+['resultado'=>'CONTACTO_CORRECTO'],$actual,'MANUAL');comprobarParcial(!$out['efectiva'],'Un par manual no inventa evidencia PBX');

// Ejecutar los bloques reales del servicio del auricular que producen sus banderas de respuesta.
$src=file_get_contents($root.'/app/services/TelefoniaEntrantesAnalistaService.php');$a=strpos($src,'            $admiteVerificacion=');$b=strpos($src,'            $descartar =',$a);$body=substr($src,$a,$b-$a);
$a=strrpos($src,"        return ['seguimiento_id'");$b=strpos($src,'];',$a)+2;$tail=substr($src,$a,$b-$a);
eval('function evaluarAuricularParcial($datos,$seguimiento,$sdk=false){$resultadoFormulario=$datos["resultado"];$call=[];$personaAtendio="Persona sintética";$segundosConversacion=30;$seguimientoId=10;$interaccionId=20;'.$body.$tail.'}');
foreach($pares as $flags){$out=evaluarAuricularParcial($flags+['resultado'=>'CONTACTO_CORRECTO'],$actual);comprobarParcial($out['verificacion_confirmada']===(count($flags)===2),'Auricular: parcial no acredita');comprobarParcial($out['verificacion_parcial']===(count($flags)===1),'Aviso de confirmación parcial correcto');}
$out=evaluarAuricularParcial(['resultado'=>'NO_INTERESADO'],$actual);comprobarParcial(!$out['verificacion_confirmada']&&!$out['verificacion_parcial'],'No interesado se guarda sin verificación');
echo "PASS: ambos controladores separan registro, datos parciales y meta; sin PBX no hay crédito.\n";
// Las guardas de avance y de conteo siguen exigiendo ambas evidencias de la misma interacción.
$sql=file_get_contents($root.'/app/services/VerificacionTelefonoCorreoService.php');
comprobarParcial(str_contains($sql,"foreach (['telefono','correo'] as \$campo)")&&str_contains($sql,'ev_$campo.pbx_call_id=$alias.id_externo'),'Mantener identidad de llamada y par');
$flujo=file_get_contents($root.'/app/services/SeguimientoFlujoService.php');comprobarParcial(str_contains($flujo,'VerificacionTelefonoCorreoService::tieneEfectiva'),'Mantener guarda de avance');
echo "PASS: guardas de conteo y avance conservadas.\n";
