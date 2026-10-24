<?php
/**
 * Validación estructural del traslado de Recepción de Inicio a Mis llamadas.
 * No requiere MySQL, Zadarma ni sesiones reales.
 */
$root = dirname(__DIR__);
$get = static fn(string $path): string => (string)file_get_contents($root.'/'.$path);
$home = $get('app/views/dashboard/marketing.php');
$view = $get('app/views/telefonia/recepcion_marketing.php');
$head = $get('app/views/layout/dashboard_head.php');
$nav = $get('app/views/telefonia/mis_llamadas_navegacion.php');
$controller = $get('app/controllers/TelefoniaController.php');
$reception = $get('public/javascript/telefonia_recepcion_marketing.js');
$history = $get('app/views/telefonia/mis_llamadas.php');
$css = $get('public/css/telefonia_mis_llamadas.css');
$check = static function (bool $ok, string $label): void {
    if ($ok) return;
    fwrite(STDERR, "ERROR: $label\n");
    exit(1);
};
$check(!str_contains($home, "require __DIR__ . '/../telefonia/recepcion_marketing.php'"),
    'Recepción sigue duplicada en Inicio');
$check(!str_contains($view, 'data-marketing-reception-details-toggle')
    && !str_contains($view, 'data-marketing-reception-details hidden'),
    'La tabla sigue plegada u ofrece botón para ocultarla');
$check(str_contains($controller, "\$puedeRecepcionMarketing ? 'recepcion' : 'historial'"),
    'Marketing no inicia en Recepción');
$posRec = strpos($nav, "'vista'=>'recepcion'");
$posHist = strpos($nav, "'vista'=>'historial'");
$check($posRec !== false && $posHist !== false && $posRec < $posHist,
    'Marketing no presenta Recepción antes de Historial');
$check(str_contains($head, "'seguimiento_llamadas_player.css'")
    && str_contains($head, "'telefonia_grabaciones_ventas.js'")
    && str_contains($head, "'telefonia_recepcion_marketing.js'"),
    'Falta CSS/JS del reproductor compartido');
$check(str_contains($reception, "get('buscar')")
    && str_contains($reception, 'No disponible · Reintentar'),
    'Falta búsqueda desde historial o error de audio explícito');
$check(str_contains($history, 'Ver llamada')
    && str_contains($history, 'actividad telefónica que se pudo atribuir a tu usuario'),
    'Historial sin detalles autorizados o sin alcance visible');
$check(str_contains($css, '.marketing-reception-filters')
    && str_contains($css, '.impe-my-calls-filters'),
    'Los filtros no usan densidad compartida');
echo "OK: Recepción trasladada, reproductores, filtros y detalle personal\n";
