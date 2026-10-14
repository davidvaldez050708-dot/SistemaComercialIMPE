<?php
/**
 * Resuelve EXCLUSIVAMENTE el hunk WebRTC ya diagnosticado en Hostinger.
 * Entrada: merge diff3 en archivo temporal. Salida: ruta temporal staged.
 * Protege la eliminación del filtro nativeVisible presente en producción.
 * Cualquier otro conflicto se RECHAZA sin escribir el destino.
 */
declare(strict_types=1);

// Detecta idempotentemente la versión ya publicada por ESTE reconciliador.
// Sólo acepta igualdad exacta con el objetivo menos una línea (la condición
// de visibilidad que producción había quitado intencionadamente).
if (PHP_SAPI === 'cli' && count($argv) === 4 &&
    $argv[1] === '--is-reconciled') {
    $published = @file_get_contents($argv[2]);
    $target = @file_get_contents($argv[3]);
    $guard = "            !(cfg.analystIncomingResults && nativeVisible) &&\n";
    if ($published === false || $target === false ||
        substr_count($target, $guard) !== 1 ||
        $published !== str_replace($guard, '', $target)) {
        exit(1);
    }
    exit(0);
}

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Uso: php resolver_conflicto_shell_analista.php merge.txt staged.js\n");
    exit(2);
}
$merge = @file_get_contents($argv[1]);
if ($merge === false) {
    fwrite(STDERR, "ERROR: no se leyó el archivo temporal de fusión.\n");
    exit(1);
}
$known = <<<'DIFF3'
<<<<<<< PRODUCCION
        const customVisible = (incoming || shownOutgoing) &&
||||||| BASE
        const customVisible = (incoming || shownOutgoing) &&
            !(cfg.analystIncomingResults && nativeVisible) &&
=======
        const customVisible = (incoming || finishedSdk || shownOutgoing) &&
            !(cfg.analystIncomingResults && nativeVisible) &&
>>>>>>> OBJETIVO
DIFF3;
$known .= "\n";
$resolved = "        const customVisible = (incoming || finishedSdk || shownOutgoing) &&\n";

// Debe haber exactamente UN conflicto y DEBE coincidir carácter por carácter.
// Nunca elegir silenciosamente «ours» o «theirs» en otros cambios.
$open = preg_match_all('/^<<<<<<< /m', $merge);
$base = preg_match_all('/^\\|\\|\\|\\|\\|\\|\\| /m', $merge);
$separator = preg_match_all('/^=======$/m', $merge);
$close = preg_match_all('/^>>>>>>> /m', $merge);
if ($open !== 1 || $base !== 1 || $separator !== 1 || $close !== 1 ||
    substr_count($merge, $known) !== 1) {
    fwrite(STDERR, "CONFLICTO REAL: hay diferencias distintas al hunk WebRTC conocido.\n");
    exit(1);
}
$result = str_replace($known, $resolved, $merge, $count);
if ($count !== 1 || preg_match('/^(<<<<<<< |\\|\\|\\|\\|\\|\\|\\| |=======|>>>>>>> )/m', $result)) {
    fwrite(STDERR, "CONFLICTO REAL: la fusión conserva marcadores sin resolver.\n");
    exit(1);
}
if (@file_put_contents($argv[2], $result) !== strlen($result)) {
    fwrite(STDERR, "ERROR: no se pudo escribir el JavaScript temporal.\n");
    exit(1);
}
echo "OK: conflicto WebRTC exacto resuelto sin restaurar el filtro que ocultaba el modal.\n";
