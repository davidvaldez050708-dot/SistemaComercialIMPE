<?php
require_once __DIR__.'/../app/services/VerificacionInstitucionalService.php';
require_once __DIR__.'/../app/services/DatosContactoLlamadaService.php';
function verificarUnificado($ok,$mensaje){if(!$ok)throw new RuntimeException($mensaje);}
$datos=['actualizar_datos_llamada'=>'1','persona_atendio'=>'Diana Estrada',
 'nuevo_contacto_nombre'=>'Contacto anterior','nuevo_correo_contacto'=>'contacto@example.org'];
$normal=DatosContactoLlamadaService::extraer($datos,'LLAMADA','CONTACTO_CORRECTO');
verificarUnificado($normal['contacto_nombre']==='Diana Estrada','Una sola persona se guarda en la ficha');
verificarUnificado($normal['correo_verificado']==='contacto@example.org','Conserva correo del borrador');
$referido=DatosContactoLlamadaService::extraer($datos,'LLAMADA','CONTACTO_REFERIDO');
verificarUnificado($referido['contacto_nombre']==='Contacto anterior','El referido conserva su propia identidad');
verificarUnificado($datos['persona_atendio']==='Diana Estrada','No cambia a quien atendio');
verificarUnificado(DatosContactoLlamadaService::extraer($datos,'LLAMADA','OCUPADO')===[],'No acredita datos con linea ocupada');
$datos['persona_atendio']='';
verificarUnificado(!isset(DatosContactoLlamadaService::extraer($datos,'LLAMADA','CONTACTO_CORRECTO')['contacto_nombre']),'Un nombre vacio no borra el contacto existente');
echo "OK: persona unica en atencion normal; referido independiente; correo conservado; sin borrar datos vacios.\n";
