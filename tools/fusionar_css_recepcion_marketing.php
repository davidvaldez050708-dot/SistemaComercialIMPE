<?php
/**
 * Inserción selectiva del nuevo bloque visual de Marketing.
 * No importa el resto de CSS desde GitHub: preserva las reglas de Hostinger.
 * Uso: php tools/fusionar_css_recepcion_marketing.php BASE GITHUB HOSTINGER SALIDA
 */
declare(strict_types=1);

if ($argc !== 5) {
    fwrite(STDERR, "Uso: php fusionar_css_recepcion_marketing.php BASE GITHUB HOSTINGER SALIDA\n");
    exit(2);
}
[$self, $basePath, $repoPath, $livePath, $outputPath] = $argv;
foreach ([$basePath, $repoPath, $livePath] as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "ERROR: falta archivo CSS de fusión: $path\n");
        exit(2);
    }
}
$base = (string)file_get_contents($basePath);
$repo = (string)file_get_contents($repoPath);
$live = (string)file_get_contents($livePath);
$markerBefore = '/* Recepción · llamada de retorno vinculada a una llamada perdida. */';
$markerNow = '/* Una acción general por registro, compacta y consistente con el CRM. */';
$endMarker = '.marketing-reception-callback-status {';

$section = static function (string $css, string $marker) use ($endMarker): ?string {
    $start = strpos($css, $marker);
    if ($start === false || strpos($css, $marker, $start + strlen($marker)) !== false) {
        return null;
    }
    $end = strpos($css, $endMarker, $start + strlen($marker));
    if ($end === false || $end <= $start) {
        return null;
    }
    return substr($css, $start, $end - $start);
};

$before = $section($base, $markerBefore);
$now = $section($repo, $markerNow);
if ($before === null || $now === null ||
    substr_count($base, $before) !== 1 ||
    substr_count($repo, $now) !== 1 ||
    str_replace($now, $before, $repo) !== $base) {
    fwrite(STDERR, "CONFLICTO: el CSS GitHub cambió fuera del bloque esperado; no se publicó nada.\n");
    exit(1);
}
if (str_contains($live, $now)) {
    // Los estilos adicionales para el mismo botón podrían ganar
    // por cascada. No aprobar automáticamente una coincidencia parcial.
    $outside = str_replace($now, '', $live);
    if (substr_count($live, $now) !== 1 ||
        preg_match('/\\.marketing-reception-(?:call|action-cell|record-check)(?![a-zA-Z0-9_-])/', $outside)) {
        fwrite(STDERR, "CONFLICTO: existen reglas adicionales del botón Llamar.\n");
        exit(1);
    }
    $merged = $live;
    echo "CSS de Llamar ya integrado; se conservan estilos locales.\n";
} elseif (str_contains($live, $markerNow) ||
    preg_match('/\.marketing-reception-(?:call|action-cell|record-check)(?![a-zA-Z0-9_-])/', $live)) {
    fwrite(STDERR, "CONFLICTO: Hostinger tiene una variante personalizada del nuevo botón Llamar.\n");
    exit(1);
} elseif (substr_count($live, $before) === 1) {
    // El bloque viejo coincide exactamente; cambiar solo ese fragmento.
    $merged = str_replace($before, $now, $live);
    echo "CSS de recepción actualizado en su bloque original; se conservaron los demás estilos.\n";
} elseif (str_contains($live, $markerBefore) && substr_count($live, $markerBefore) > 1) {
    fwrite(STDERR, "CONFLICTO: bloque anterior duplicado; se requiere revisión manual.\n");
    exit(1);
} else {
    // Versiones publicadas con reglas originales retocadas: no eliminarlas.
    // Las nuevas clases son independientes del botón anterior, ya sin uso.
    $merged = rtrim($live) . "\n\n" . $now;
    echo "CSS de Llamar añadido sin modificar personalizaciones anteriores.\n";
}
if (substr_count($merged, $markerNow) !== 1 ||
    !str_contains($merged, '.marketing-reception-call') ||
    file_put_contents($outputPath, $merged) === false) {
    fwrite(STDERR, "ERROR: no fue posible preparar el CSS de recepción.\n");
    exit(1);
}
