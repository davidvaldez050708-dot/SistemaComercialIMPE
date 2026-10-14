<?php
/**
 * Smoke visual sin consultas a MySQL, ni conexiones con Zadarma.
 */
declare(strict_types=1);
if (!defined('BASE_URL')) define('BASE_URL', '/crm/');
$_SESSION['csrf_token'] = 'fake-csrf-for-render-tests';
$errorEntrantes = '';
$estadosEntrantes = [];
$bandejaEntrantes = [
    'extension' => '101',
    'llamadas' => [
        ['pbx_call_id'=>'sdk-abcdefghijklmnopqrst','fuente'=>'SDK_WEBRTC',
         'tipo_llamada'=>'INTERNA','clasificacion'=>'INTERNA','numero'=>'100',
         'fecha'=>'2026-10-14 10:49:28','duracion'=>11,'estado_pbx'=>'ended',
         'grabacion_disponible'=>true,'contestada'=>true],
        ['pbx_call_id'=>'sdk-zzzzzzzzzzzzzzzzzzzz','fuente'=>'SDK_WEBRTC',
         'tipo_llamada'=>'INTERNA','clasificacion'=>'INTERNA','numero'=>'100',
         'fecha'=>'2026-10-14 10:34:16','duracion'=>8,'estado_pbx'=>'ended',
         'grabacion_disponible'=>false,'contestada'=>true],
        ['pbx_call_id'=>'in_abc12345','fuente'=>'PBX',
         'tipo_llamada'=>'ENTRANTE','clasificacion'=>'PENDIENTE','numero'=>'5512345678',
         'fecha'=>'2026-10-14 11:00:00','duracion'=>20,'estado_pbx'=>'ended',
         'grabacion_disponible'=>false,'contestada'=>true],
        ['pbx_call_id'=>'in_abc98765','fuente'=>'PBX',
         'tipo_llamada'=>'ENTRANTE','clasificacion'=>'VINCULADA','numero'=>'5587654321',
         'fecha'=>'2026-10-14 11:30:00','duracion'=>31,'estado_pbx'=>'ended',
         'seguimiento_id'=>125,'contestada'=>true],
    ],
];
ob_start();
require __DIR__ . '/../app/views/seguimiento_vinculacion/llamadas_entrantes.php';
$html = ob_get_clean();
function verificarUi(bool $ok, string $mensaje): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR de diseño de telefonía: " . $mensaje . "\n");
        exit(1);
    }
}
verificarUi(substr_count($html, 'class="linkage-call-history-card linkage-incoming-card"') === 4, 'Faltan tarjetas de expediente.');
verificarUi(str_contains($html, 'linkage-call-summary linkage-incoming-stats'), 'Falta el resumen compacto.');
verificarUi(str_contains($html, 'linkage-call-custom-player'), 'No se reutiliza el reproductor.');
verificarUi(str_contains($html, 'data-incoming-audio-toggle'), 'La grabación no se puede desplegar.');
verificarUi(substr_count($html, 'data-incoming-audio-panel hidden') === 1, 'El audio solo debe figurar en la llamada verificada.');
verificarUi(str_contains($html, 'data-incoming-progress') && str_contains($html, 'data-incoming-volume') &&
    str_contains($html, 'data-incoming-play') && str_contains($html, 'linkage-call-player-download'), 'Faltan controles de audio.');
verificarUi(str_contains($html, '/crm/prueba_telefonia/api/grabacion_interna_analista.php?session_id=sdk-abcdefghijklmnopqrst'), 'El audio no usa el endpoint privado.');
verificarUi(!str_contains($html, '<audio controls') && !str_contains($html, 'linkage-incoming-table-wrap'), 'Se conserva el reproductor o tabla anterior.');
verificarUi(substr_count($html, 'data-incoming-sync-audio') === 1, 'La búsqueda debe aparecer solo en interna pendiente.');
verificarUi(substr_count($html, 'data-call-id=') === 1, 'Solo la institucional pendiente admite registrar resultado.');
verificarUi(str_contains($html, 'Ver expediente') && str_contains($html, '&amp;action=detalle&amp;id=125'), 'Enlace al expediente ausente.');
verificarUi(str_contains($html, '14/10/2026 · 10:49'), 'Fecha ilegible.');
verificarUi(substr_count($html, 'Interna · no contabiliza') === 2, 'Las internas no deben contar como contacto.');
verificarUi(str_contains($html, 'modalLlamadaEntranteAnalista') && str_contains($html, 'data-incoming-submit'), 'Modal institucional perdido.');
echo "Interfaz recibidas Analista: OK (4 estados, audio privado, componentes expediente y formulario).\n";
