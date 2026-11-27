<?php
require __DIR__.'/WhatsAppPresentacionService.php';
function check($v,$msg){if(!$v)throw new RuntimeException($msg);echo "PASS | $msg\n";}
check(WhatsAppPresentacionService::nombre(['prospecto_nombre'=>'Prueba 22','nombre_contacto'=>'Day'])==='Prueba 22','nombre del expediente prevalece');
check(WhatsAppPresentacionService::nombre(['prospecto_nombre'=>' ','aliado_nombre'=>'Institucion','nombre_contacto'=>'Perfil'])==='Institucion','nombre institucional prevalece');
check(WhatsAppPresentacionService::nombre(['nombre_contacto'=>'Perfil'])==='Perfil','sin expediente utiliza perfil');
check(WhatsAppPresentacionService::horaVisible(['direccion'=>'SALIENTE','created_at'=>'2026-11-27 20:58:00','enviado_at'=>'2026-11-27 20:58:00'])==='2026-11-27 14:58:00','salida antigua UTC se presenta en México');
check(WhatsAppPresentacionService::horaVisible(['direccion'=>'ENTRANTE','created_at'=>'2026-11-27 21:00:00','enviado_at'=>'2026-11-27 15:00:00'])==='2026-11-27 15:00:00','entrada local no recibe doble conversion');
$row=['id'=>1,'entrada_id'=>4,'entrada_contenido'=>'hola','entrada_enviado'=>'2026-11-27 15:00:00','entrada_creado'=>'2026-11-27 21:00:00','salida_id'=>3,'salida_contenido'=>'uwu','salida_enviado'=>'2026-11-27 20:58:00','salida_creado'=>'2026-11-27 20:58:00','ultimo_mensaje_at'=>'2026-11-27 20:58:00','ultimo_mensaje_preview'=>'uwu'];
$r=WhatsAppPresentacionService::resumen($row);
check($r['ultimo_mensaje_preview']==='hola'&&$r['ultimo_mensaje_direccion']==='ENTRANTE'&&$r['ultimo_mensaje_at']==='2026-11-27 15:00:00','reconstruye historial sin modificar almacenamiento');
$row['entrada_enviado']='2026-11-27 14:50:00';
$r=WhatsAppPresentacionService::resumen($row);
check($r['ultimo_mensaje_direccion']==='SALIENTE'&&$r['ultimo_mensaje_preview']==='uwu','entrada tardia no desplaza salida mas nueva');
check(WhatsAppPresentacionService::horaVisible(['direccion'=>'SALIENTE','created_at'=>'2026-11-28 02:00:00'])==='2026-11-27 20:00:00','cambio de dia UTC');
echo "PRESENTACION_OK\n";
