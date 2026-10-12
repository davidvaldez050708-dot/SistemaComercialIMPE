#!/usr/bin/env bash
# Ejercita todos los bloques EXACTOS que se observaron en Hostinger.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
R="$ROOT/tools/resolver_conflictos_telefonia.php"
cat > "$TMP/check.php" <<'PHP'
<?php
declare(strict_types=1);
$source = file_get_contents($argv[1]);
$begin = strpos($source, '$rules = [');
$end = strpos($source, "\nif (!isset(", $begin);
if ($begin === false || $end === false) exit(2);
$definition = substr($source, $begin, $end - $begin);
eval($definition);
$paths = [
    'public/javascript/telefonia_host.js',
    'public/css/telefonia_shell.css',
    'public/javascript/telefonia_persistente.js'
];
foreach ($paths as $path) {
    $conflicts = '';
    foreach ($rules[$path] as [$left, $right]) {
        $conflicts .= "<<<<<<< HOSTINGER\n" . rtrim($left) . "\n=======\n" .
            rtrim($right) . "\n>>>>>>> GITHUB\n";
    }
    $hash = md5($path);
    $merged = $argv[2] . '/' . $hash . '.merged';
    $out = $argv[2] . '/' . $hash . '.out';
    $target = $argv[3] . '/' . $path;
    // Este resolvedor sólo reconoce el conflicto histórico de 2026-10-12.
    // El JS actual ya evolucionó: usar el archivo exacto de aquella fecha
    // para no debilitar ni alterar las reglas de seguridad originales.
    if ($path === 'public/javascript/telefonia_persistente.js') {
        $target = $argv[2] . '/telefonia_persistente_historica.js';
        $cmd = 'git -C ' . escapeshellarg($argv[3]) . ' show ' .
            escapeshellarg('8fc7f1dad44cefe6c070da89d48beb10ed5bed9f:' . $path) .
            ' > ' . escapeshellarg($target);
        exec($cmd, $ignored, $status);
        if ($status !== 0) { fwrite(STDERR, "Falta el archivo histórico para el ensayo.\n"); exit(2); }
    }
    file_put_contents($merged, "// CAMBIO-LOCAL-PRESERVADO\n" . $conflicts . "// FIN-LOCAL\n");
    $command = 'php ' . escapeshellarg($argv[1]) . ' ' .
        escapeshellarg($path) . ' ' . escapeshellarg($merged) . ' ' .
        escapeshellarg($target) . ' ' . escapeshellarg($out) . ' 2>&1';
    exec($command, $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "FALLO: conflicto conocido $path " . implode("\n", $lines) . "\n");
        exit(1);
    }
    $resolved = file_get_contents($out);
    if (!str_contains($resolved, '// CAMBIO-LOCAL-PRESERVADO') ||
        !str_contains($resolved, '// FIN-LOCAL') ||
        str_contains($resolved, '<<<<<<<')) {
        fwrite(STDERR, "FALLO: el merge no conservó contexto $path\n");
        exit(1);
    }
    $unknown = str_replace('<<<<<<< HOSTINGER',
        "<<<<<<< HOSTINGER\n// CAMBIO NO AUTORIZADO", $conflicts);
    file_put_contents($merged, $unknown);
    exec($command, $lines, $code);
    if ($code === 0) {
        fwrite(STDERR, "FALLO: aceptó cambio desconocido en $path\n");
        exit(1);
    }
}
echo "Resolución de conflictos WebRTC: OK (4 aprobados, 3 rechazos y ajustes locales)\n";
PHP
php "$TMP/check.php" "$R" "$TMP" "$ROOT"
