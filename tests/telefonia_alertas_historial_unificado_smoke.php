<?php
// Inspección de integración sin credenciales ni llamadas reales.
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$service=$get('app/services/TelefoniaNotificacionService.php');
$reminders=$get('public/javascript/recordatorios.js');
$history=$get('app/views/telefonia/mis_llamadas.php');
$callbacks=$get('public/javascript/telefonia_devoluciones.js');
$view=$get('app/views/telefonia/devoluciones.php');
$assert=static function(bool $pass,string $label):void{
  if(!$pass){fwrite(STDERR,"FALLO: $label\n");exit(1);}
};
$assert(str_contains($service,"&vista='")
    && str_contains($service,'&detalle=1&pbx_call_id=')
    && !str_contains($service,'index.php?controller=home&action=index#recepcion-telefonica')
    && !str_contains($service,"'index.php?controller=telefonia&action=devoluciones'"),
    'Campana usa Mis llamadas y PBX específico');
$assert(str_contains($reminders,'CLAVE_ULTIMO_TOAST_COMPARTIDO_AT')
    && str_contains($reminders,'INTERVALO_TOAST_VENCIDO -')
    && str_contains($reminders,"return 'telefonia:' + telefonoId")
    && str_contains($reminders,'Number(r.telefonia_id || 0) > 0')
    && !str_contains($reminders,'mostrarAvisosTelefonia(')
    && !str_contains($reminders,'if(++shown===3)break'),
    'Un aviso recurrente por turno de ocho minutos, sin ráfaga al entrar');
$assert(str_contains($history, 'vista=devoluciones&detalle_intento=')
    && str_contains($history,'Ver devolución')
    && str_contains($history,'vista=recibidas&detalle=1'),
    'Historial separa devolución saliente de Recibidas');
$assert(str_contains($view,'data-focus-attempt')
    && str_contains($callbacks,'card.classList.add(\'impe-incoming-targeted\')')
    && str_contains($callbacks, "value='TODAS'")
    && str_contains($callbacks,'focusAttempt'),
    'Devolución completada se localiza sin abrir resultado');
echo "OK: enlaces específicos y aviso telefónico rotatorio sin duplicaciones\n";
