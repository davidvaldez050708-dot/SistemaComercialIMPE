<?php
if(PHP_SAPI!=='cli')exit(2);
define('BASE_URL','https://wa.test/');
require __DIR__.'/../app/services/WhatsAppContextoService.php';
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'csrf_token'=>'fixture-only'];
$mode=$argv[1]??'panel';$approved=str_contains($mode,'approved');$open=str_contains($mode,'open');
$cuentasVentas=[['id'=>1,'nombre'=>'Fundación Red Educativa México','numero_mostrado'=>'+52 56 6725 8084']];$configVentas=[];
$busqueda='';$candidatos=[['id'=>22,'nombre'=>'Asesor de prueba','rol'=>'Ventas']];
$pendientes=['total'=>0,'filas'=>[],'pagina'=>1,'paginas'=>1];
if($mode==='queue-filled'){$pendientes['total']=1;$pendientes['filas']=[['id'=>7,'nombre_contacto'=>'Contacto por revisar','telefono_normalizado'=>'527770000001','canal'=>'Fundación Red Educativa México','ultimo_mensaje_preview'=>'Quiero información sobre los programas educativos.','ultimo_mensaje_at'=>'2026-11-20 10:00']];}
$conversacionSeleccionada=['id'=>7,'prospecto_id'=>140,'prospecto_nombre'=>'PRUEBA 22 · Agenda y reprogramación','nombre_contacto'=>'Contacto','telefono_contacto'=>'527770000001','cuenta_nombre'=>'Fundación Red Educativa México','cuenta_numero'=>'+52 56 6725 8084','responsable_nombre'=>'prueba llamadas con un nombre largo de asesor','cuenta_tipo'=>'EMPRESARIAL','ventana_servicio_hasta'=>'2026-11-21 10:00'];
$conversaciones=[];for($i=0;$i<8;$i++){$c=$conversacionSeleccionada;$c['id']=7+$i;$c['ultimo_mensaje_at']='2026-11-20 10:00';$c['ultimo_mensaje_preview']='Hola, me interesa continuar con mi registro.';$conversaciones[]=$c;}
$mensajesIniciales=[];if($open)for($i=1;$i<22;$i++)$mensajesIniciales[]=['id'=>$i,'direccion'=>$i%2?'ENTRANTE':'SALIENTE','contenido'=>$i%2?'Hola, ¿me pueden compartir información sobre el programa?':'Claro, te ayudo con los requisitos y el proceso de registro.','estado'=>'LEIDO','tipo'=>'TEXT','created_at'=>'2026-11-20 10:00'];
$estadoConfiguracionWhatsapp=['lista'=>true];$puedeEnviarWhatsapp=true;$ventanaServicioAbierta=$open;$puedePlantillaPrueba=false;
$plantillaVentas=$approved?['plantilla_nombre'=>'inicio','plantilla_cuerpo'=>'Hola, somos Fundación Red Educativa México. ¿Deseas que continuemos con tu registro?','plantilla_categoria'=>'MARKETING','plantilla_idioma'=>'es_MX']:null;
if(str_starts_with($mode,'panel')){require __DIR__.'/../app/views/whatsapp/panel.php';exit;}
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;display=swap"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="https://wa.test/public/css/global.css"><link rel="stylesheet" href="https://wa.test/public/css/dashboard.css"><link rel="stylesheet" href="https://wa.test/public/css/whatsapp.css"><style>body{padding:28px;background:#f5f7fa} @media(max-width:600px){body{padding:12px}}</style><body>';
if($mode==='config')require __DIR__.'/../app/views/whatsapp/ventas_config.php';
elseif(str_starts_with($mode,'queue'))require __DIR__.'/../app/views/whatsapp/asignaciones.php';
else{$estructuraWhatsappDisponible=true;$puedeGestionarCuentas=false;require __DIR__.'/../app/views/whatsapp/index.php';}
echo '</body></html>';
