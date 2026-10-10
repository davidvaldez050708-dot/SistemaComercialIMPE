<?php
/**
 * php tests/roles_cuenta_clave_marketing_responsive_smoke.php
 * Comprobación de selectores y estilos sin sesión ni base de datos.
 */
$root = dirname(__DIR__);
$kam = (string)file_get_contents($root . '/public/css/dashboard_cuenta_clave.css');
$marketing = (string)file_get_contents($root . '/public/css/convocatorias.css');
$cuentaClaveView = (string)file_get_contents($root . '/app/views/dashboard/cuenta_clave.php');
$marketingView = (string)file_get_contents($root . '/app/views/dashboard/marketing.php');
$adminView = (string)file_get_contents($root . '/app/views/dashboard/administrador.php');

$checks = [
    'Cuenta Clave utiliza el contenedor de perfil' =>
        str_contains($cuentaClaveView, 'class="kam-dashboard"'),
    'Cuenta Clave conserva indicadores y enlaces' =>
        str_contains($cuentaClaveView, 'kam-kpi-grid') && str_contains($cuentaClaveView, 'kam-panel-link'),
    'Las métricas Cuenta Clave tienen texto legible en móvil' =>
        str_contains($kam, '.kam-dashboard .kam-metric-card small') &&
        str_contains($kam, 'font-size: 10px;'),
    'Se pueden leer nombres operativos en dos líneas' =>
        str_contains($kam, '.kam-dashboard .kam-attention-title strong') &&
        str_contains($kam, '-webkit-line-clamp: 2'),
    'Cuenta Clave conserva áreas de acción táctiles' =>
        str_contains($kam, '.kam-dashboard .kam-panel-link') &&
        str_contains($kam, 'min-height: 40px'),
    'Marketing conserva tabla de publicaciones con scroll' =>
        str_contains($marketingView, 'marketing-recent-table-wrap') &&
        str_contains($marketing, '.marketing-recent-table-wrap'),
    'Marketing ajusta título y acciones en móvil' =>
        str_contains($marketing, '.marketing-recent-publications .marketing-recent-heading') &&
        str_contains($marketing, '.marketing-recent-publications .marketing-recent-link'),
    'Modal de convocatorias mantiene desplazamiento propio' =>
        str_contains($marketingView, 'id="modalConvocatoriasPorVencer"') &&
        str_contains($marketing, '#modalConvocatoriasPorVencer .marketing-expiring-modal-body') &&
        str_contains($marketing, 'max-height: calc(100dvh - 24px)'),
    'Administrador mantiene sus tablas en contenedor responsivo' =>
        str_contains($adminView, 'class="table-responsive"') &&
        str_contains($adminView, 'users-table'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}
echo 'Roles Cuenta Clave, Marketing y Administrador responsive: OK (' . count($checks) . " comprobaciones estáticas)\n";
