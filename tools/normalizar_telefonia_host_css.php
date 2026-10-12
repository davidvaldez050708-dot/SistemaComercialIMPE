<?php
/**
 * Normaliza únicamente el CSS legado exactamente identificado en Hostinger.
 * Sólo trabaja con archivos temporales. Nunca escribe en producción.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    fwrite(STDERR, "Uso: php normalizar_telefonia_host_css.php <live> <git> <temporal>\n");
    exit(2);
}
$live = @file_get_contents($argv[1]);
$git = @file_get_contents($argv[2]);
if ($live === false || $git === false) {
    fwrite(STDERR, "ERROR: No se pudo leer el CSS de telefonía.\n");
    exit(2);
}
$legacy = <<<'CSS'
/*
 * El SDK de Zadarma debe seguir vivo para WebRTC, pero su marcador nativo
 * nunca debe convertirse en una segunda interfaz del CRM.
 */
body > *:not(.telephony-host-sentinel):not(.telephony-host-tab-info):not(script) {
    position: fixed !important;
    left: -10000px !important;
    top: -10000px !important;
    right: auto !important;
    bottom: auto !important;
    width: 1px !important;
    height: 1px !important;
    max-width: 1px !important;
    max-height: 1px !important;
    overflow: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

.telephony-host-sentinel {
    z-index: 2147483647;
}
CSS;
$newMarker = '/* El iframe aislado del shell oculta al proveedor cuando está en reposo.';
$nextMarker = '/* Modo opcional de pestaña: no mostrar una pantalla vacía al usuario. */';
$start = strpos($git, $newMarker);
$end = $start === false ? false : strpos($git, $nextMarker, $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "ERROR: CSS de GitHub no contiene la versión nueva reconocida.\n");
    exit(2);
}
$target = rtrim(substr($git, $start, $end - $start));
$matches = substr_count($live, $legacy);
if ($matches === 1) {
    // El patrón conocido jamás debe coexistir con otras reglas globales que
    // afecten a los controles internos. En tal caso se requiere revisión.
    if (str_contains($live, 'body:not(.telephony-host-incoming) >') ||
        str_contains($live, 'body.telephony-host-incoming >')) {
        fwrite(STDERR, "CONFLICTO: Hay otras reglas que ocultan controles del SDK.\n");
        exit(2);
    }
    // Sólo reemplaza el bloque 1x1 conocido. Las demás reglas locales no se tocan.
    $live = str_replace($legacy, $target, $live);
} elseif ($matches !== 0 ||
          str_contains($live, 'body > *:not(.telephony-host-sentinel)')) {
    fwrite(STDERR, "CONFLICTO: El CSS contiene una variante no reconocida del bloque 1x1.\n");
    exit(2);
}
$incoming = 'body.telephony-host-incoming { overflow: hidden; }';
if (!str_contains($live, $incoming) &&
    !str_contains($live, 'body.telephony-host-incoming {')) {
    $anchor = '/* WebRTC embebido: el SDK sólo muestra la UI oficial cuando el CRM';
    if (substr_count($live, $anchor) !== 1) {
        fwrite(STDERR, "CONFLICTO: No se encontró la posición segura del estado entrante.\n");
        exit(2);
    }
    $live = str_replace($anchor, $incoming . "\n\n" . $anchor, $live);
}
if (@file_put_contents($argv[3], $live) === false) {
    fwrite(STDERR, "ERROR: No se pudo escribir el CSS temporal.\n");
    exit(2);
}
