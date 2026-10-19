<?php
// Inspección de integración sin credenciales ni llamadas reales.
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$service=$get('app/services/TelefoniaNotificacionService.php');
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
// La cadencia y la rotación se verifican en Chromium real mediante
// recordatorios_toasts_browser.cjs, no por cadenas del código fuente.
$assert(str_contains($history, 'vista=devoluciones&detalle_intento=')
    && str_contains($history,'Ver devolución')
    && str_contains($history,'vista=recibidas&detalle=1'),
    'Historial separa devolución saliente de Recibidas');
$assert(str_contains($view,'data-focus-attempt')
    && str_contains($callbacks,'card.classList.add(\'impe-incoming-targeted\')')
    && str_contains($callbacks, "value='TODAS'")
    && str_contains($callbacks,'focusAttempt'),
    'Devolución completada se localiza sin abrir resultado');
$assert(str_contains($service,"modo='CONSULTADA' AND estado='DEVUELTA'")
    && str_contains($service,":SIN_CONFIRMAR';")
    && str_contains($service,"SET leida=1 WHERE usuario_id=? AND extension=? AND llave=?"),
    'Transferencia consultada sin respuesta no dispara dos avisos y el provisional se limpia');
echo "OK: enlaces específicos y aviso telefónico rotatorio sin duplicaciones\n";

