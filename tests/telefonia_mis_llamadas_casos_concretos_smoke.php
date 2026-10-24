<?php
/**
 * Casos ficticios que reproducen las condiciones observadas en producción.
 * No marca números ni solicita grabaciones a Zadarma.
 */
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', '/');
$puedeGestionAnalista = true;
$puedeRecepcionMarketing = false;
$misLlamadas = [
  'filas' => [
    ['usuario_id'=>101,'pbx_call_id'=>'pbx-llamada-20','direccion'=>'Entrante',
      'numero'=>'+527770000020','extension'=>'101','proceso'=>'Vinculación',
      'fecha'=>'2026-10-18 12:30:20','segundos'=>39,'conectada'=>true],
    ['usuario_id'=>101,'pbx_call_id'=>'pbx-transferida-100-101','direccion'=>'Entrante',
      'numero'=>'+527770000021','extension'=>'101','proceso'=>'Vinculación',
      'fecha'=>'2026-10-18 12:35:00','segundos'=>0,'conectada'=>true],
    ['usuario_id'=>101,'pbx_call_id'=>'pbx-sin-respuesta','direccion'=>'Entrante',
      'numero'=>'+527770000022','extension'=>'101','proceso'=>'Vinculación',
      'fecha'=>'2026-10-18 12:40:00','segundos'=>0,'conectada'=>false],
    ['usuario_id'=>101,'pbx_call_id'=>'pbx-devolucion','direccion'=>'Saliente',
      'numero'=>'+527770000023','extension'=>'101','proceso'=>'Vinculación',
      'fecha'=>'2026-10-18 12:45:00','segundos'=>45,'conectada'=>true,
      'resultado_devolucion'=>'CONTACTO_CORRECTO']
  ],
  'total'=>4,'conectadas'=>3,'entrantes'=>3,'salientes'=>1,
  'pagina'=>1,'paginas'=>1,'por_pagina'=>20,
  'filtros'=>['desde'=>'2026-10-01','hasta'=>'2026-10-18','direccion'=>'']
];
ob_start();
require ROOT_PATH.'/app/views/telefonia/mis_llamadas.php';
$html = ob_get_clean();
$expect = static function (bool $ok,string $case): void {
 if (!$ok) {fwrite(STDERR,"ERROR $case\n");exit(1);}
};
$expect(str_contains($html, 'vista=recibidas&amp;detalle=1&amp;pbx_call_id=pbx-llamada-20'),
    'Caso 20: URL debe identificar la llamada concreta');
$expect(str_contains($html, 'pbx_call_id=pbx-transferida-100-101'),
    'Transferencia: navegación PBX preservada');
$expect(str_contains($html, '00:39') && str_contains($html, '00:45'),
    'Duraciones recibidas de webhooks');
$expect(str_contains($html,'00:00'), 'El tramo con cero segundos debe representarse como 00:00');
$expect(str_contains($html, '00:00 · Sin confirmar'),
    'Conectada sin duración reportada debe declararlo');
$expect(str_contains($html, 'Sin conexión confirmada'),
    'Llamada perdida debe conservar su clasificación');
$expect(str_contains($html,'impe-call-detail-link'),
    'Acceso de diseño compartido');
$expect(substr_count($html, 'Ver llamada') === 3,
    'Solo las tres entrantes tienen acceso a Recibidas');
$ingreso = file_get_contents(ROOT_PATH.'/app/views/seguimiento_vinculacion/llamadas_entrantes.php');
$expect(str_contains($ingreso, 'data-focus-call=') &&
    str_contains($ingreso, 'data-auto-call=') &&
    str_contains($ingreso, 'if (!$desdeMisLlamadas)'),
    'El detalle debe enfocar tarjeta sin abrir formulario y sin Volver a Seguimiento');
$script = file_get_contents(ROOT_PATH.'/public/javascript/seguimiento_entrantes_analista.js');
$expect(str_contains($script, 'row.scrollIntoView') &&
    str_contains($script, 'impe-incoming-targeted') &&
    str_contains($script, "audioToggle.click()") &&
    str_contains($script, 'data-focus-call') === false &&
    str_contains($script, 'page.dataset.focusCall'),
    'El JS debe buscar la tarjeta concreta y expandir audio sólo cuando exista');
$shell = file_get_contents(ROOT_PATH.'/app/views/telefonia/shell.php');
$expect(str_contains($shell,"'alwaysReceive' => \$puedeRecibirShell"),
    'Recepción siempre encendida para extensiones con permiso');
$expect(str_contains($shell,"'incomingResultsUrl' => BASE_URL . 'index.php?controller=telefonia&action=misLlamadas&vista=recibidas'"),
    'Avisos PBX dirigen a Mis llamadas');
$recovery = file_get_contents(ROOT_PATH.'/public/javascript/telefonia_shell.js');
$hostRecovery = file_get_contents(ROOT_PATH.'/public/javascript/telefonia_host.js');
$connection = file_get_contents(ROOT_PATH.'/public/javascript/telefonia_conexion.js');
$expect(!str_contains($recovery,'hostFrame.remove()') &&
    str_contains($hostRecovery,'const active = state.active || Boolean(liveSession') &&
    str_contains($connection,'if (active || !health.online') &&
    str_contains($connection,'socket.connect()'),
    'No reiniciar llamadas activas; reconectar el transporte existente sin expirar dueño');
echo "OK: llamada 20, transferencia 100→101, sin respuesta, devolución, 00:00 y autoconexión\n";
