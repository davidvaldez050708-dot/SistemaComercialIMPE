<?php
declare(strict_types=1);
// Contrato sin BD ni Zadarma para la unificación del centro telefónico.
$root=dirname(__DIR__);
$get=static fn(string $name):string=>(string)file_get_contents($root.'/'.$name);
$check=static function(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('Notificaciones: '.$why);
};
$common=$get('app/services/TelefoniaNotificacionService.php');
$sales=$get('app/services/TelefoniaNotificacionVentasService.php');
$marketing=$get('app/controllers/ConvocatoriaNotificacionController.php');
$view=$get('app/views/layout/topbar.php');
$api=$get('prueba_telefonia/api/leer_todas_notificaciones_llamada.php');
$frontend=$get('public/javascript/telefonia_notificaciones.js');
$reminders=$get('public/javascript/recordatorios.js');
$check(str_contains($common,'operativasActuales'),
    'No conservar notificaciones basándose en historial antiguo');
$check(str_contains($common,'$this->operativasActuales[$llave]=true'),
    'Transferencia todavía pendiente debe existir en los hechos');
$check(str_contains($common,"devolucion='PENDIENTE'") || str_contains($common,"'PENDIENTE'"),
    'Devolución se retira al atenderse');
$check(str_contains($common,"'leida'=>(int)"),
    'No restablecer leído a cero cada vez que consulta la campana');
$check(str_contains($sales,'llave IN ($placeholders)') &&
    str_contains($sales,"llave NOT LIKE '%:perdida'"),
    'Venta solo consulta obligaciones activas y permite leer perdidas informativas');
$check(str_contains($sales,'pendiente_operativo'),
    'Ventas distingue lectura de obligación');
$check(str_contains($marketing,'pendiente_operativo'),
    'Marketing conserva conteo de obligaciones no cerradas');
$check(str_contains($view,'data-phone-notification-read-all'),
    'Analista y Ventas disponen de lectura masiva telefónica');
$check(str_contains($frontend,'csrf_token:root.dataset.phoneNotificationCsrf'),
    'Se requiere token CSRF para marcar todas');
$check(str_contains($api,'hash_equals(') && str_contains($api,'leerTodas($uid)'),
    'Endpoint propio requiere CSRF y sólo marca leída');
$check(!str_contains($api,'devolucion=') && !str_contains($api,'resultado='),
    'Leer nunca escribe estados de negocio');
$check(str_contains($reminders,'Number(recordatorio.leida||0)===0'),
    'La campana general refleja leída sin ocultar obligación');
echo "OK: lectura masiva de telefonía, obligaciones operativas visibles y permisos propios.\n";
