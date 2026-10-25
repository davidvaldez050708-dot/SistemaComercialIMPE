<?php
/**
 * Regresión del incidente del iframe diminuto en Hostinger.
 * Verifica que la respuesta PHP contenga estilos y JS sin depender
 * de /public/css ni /public/javascript servidos vía HTTP.
 */
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', 'https://example.invalid/');
$_SESSION = [
    'usuario_id' => 7,
    'rol' => 'Marketing',
    'nombre' => 'Cuenta',
    'apellidos' => 'Prueba'
];
$asignacionTelefonica = [
    'extension' => '100',
    'permite_entrantes' => true,
    'permite_salientes' => false
];
function tienePermiso(string $codigo): bool {
    return in_array($codigo, ['telefonia.usar', 'telefonia.recibir'], true);
}
ob_start();
require ROOT_PATH . '/app/views/telefonia/shell.php';
$html = ob_get_clean();

$checks = [
    'Se presenta el iframe del CRM' => str_contains($html, 'id="impeCrmFrame"'),
    'Estilos críticos incluidos sin petición HTTP extra' =>
        str_contains($html, '<style id="impeShellStyles">') &&
        str_contains($html, '.impe-phone-shell { position: fixed; inset: 0;') &&
        str_contains($html, '#impeCrmFrame { display: block; width: 100%; height: 100%;'),
    'No depende de una hoja externa para tamaño del iframe' =>
        !str_contains($html, 'rel="stylesheet" href="https://example.invalid/public/css/telefonia_shell.css'),
    'JS embebido, sin descarga externa adicional' =>
        str_contains($html, '<script id="impeShellRuntime">') &&
        str_contains($html, 'window.IMPE_TELEPHONY_SHELL = Object.freeze') &&
        !str_contains($html, '<script src="https://example.invalid/public/javascript/telefonia_shell.js'),
    'Autorecepción exclusivamente para Marketing autorizado' =>
        str_contains($html, '"autoReception":true') &&
        str_contains($html, '"extension":"100"'),
    'Explicación sin modo clásico con JavaScript deshabilitado' =>
        str_contains($html, '<noscript>') &&
        str_contains($html, 'Activa JavaScript en tu navegador') &&
        !str_contains($html, 'CRM clásico') &&
        !str_contains($html, 'Modo clásico'),
    'Sin códigos secretos publicados' =>
        !str_contains($html, 'api_secret') &&
        !str_contains($html, 'api_key'),
    'Solo un shell y solo un runtime' =>
        substr_count($html, 'id="impeCrmFrame"') === 1 &&
        substr_count($html, '<script id="impeShellRuntime">') === 1
];
foreach ($checks as $nombre => $correcto) {
    if (!$correcto) {
        fwrite(STDERR, "FALLO: $nombre\n");
        exit(1);
    }
}
echo 'Shell render: OK (' . count($checks) . " verificaciones)\n";
