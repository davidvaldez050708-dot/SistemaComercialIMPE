<?php
/**
 * Actualiza SOLO dos operaciones del teléfono persistente de Hostinger:
 * iniciar una saliente con el SDK que ya vive en el shell y ocultar
 * tarjetas duplicadas para llamadas manuales. No sustituye el resto del JS.
 *
 * Uso: php tools/fusionar_salientes_persistente.php GITHUB HOSTINGER TEMPORAL
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    fwrite(STDERR, "Uso: php fusionar_salientes_persistente.php GITHUB HOSTINGER TEMPORAL\n");
    exit(2);
}
[$command, $sourcePath, $livePath, $outputPath] = $argv;
$source = @file_get_contents($sourcePath);
$live = @file_get_contents($livePath);
if ($source === false || $live === false) {
    fwrite(STDERR, "CONFLICTO: no se pudo leer el JavaScript de recepción.\n");
    exit(1);
}
$fail = static function (string $reason): never {
    fwrite(STDERR, "CONFLICTO: telefonia_persistente.js: $reason. No se publicó nada.\n");
    exit(1);
};
$once = static function (string $data, string $needle, string $message) use ($fail): int {
    if (substr_count($data, $needle) !== 1) {
        $fail($message);
    }
    return (int) strpos($data, $needle);
};
$startAnchor = '    const startCall = async function (payload) {';
$endAnchor = '    const cancelPrepared = function () {';
$startAt = $once($live, $startAnchor, 'inicio de llamada ambiguo');
$endAt = strpos($live, $endAnchor, $startAt);
if ($endAt === false || $endAt <= $startAt ||
    substr_count($live, $endAnchor) !== 1) {
    $fail('el flujo de llamada se encuentra alterado');
}
$sourceStart = $once($source, $startAnchor, 'GitHub no tiene el inicio esperado');
$sourceEnd = strpos($source, $endAnchor, $sourceStart);
if ($sourceEnd === false) $fail('faltan límites del flujo GitHub');
$sourceCall = substr($source, $sourceStart, $sourceEnd - $sourceStart);
$sourcePattern = '/^            if \(getHostMode\(\) === \x27embedded\x27\) \{\n.*?^            \} else \{\n                await prepare\(\{ openHost: true \}\);\n            \}/ms';
if (preg_match($sourcePattern, $sourceCall, $found) !== 1 ||
    substr_count($found[0], 'shell.ensureHost();') !== 1 ||
    substr_count($found[0], 'await prepare({ openHost: false });') !== 1 ||
    substr_count($found[0], 'await waitForHost();') !== 1) {
    $fail('la implementación GitHub no coincide con el flujo revisado');
}
$callBlock = substr($live, $startAt, $endAt - $startAt);
if (str_contains($callBlock, 'shell.ensureHost();') &&
    str_contains($callBlock, 'await prepare({ openHost: false });')) {
    echo "Inicio saliente del shell ya integrado; se conservan cambios locales.\n";
} else {
    $legacy = '/^[ \t]*await prepare\(\{\s*openHost\s*:\s*true\s*\}\);[ \t]*$/m';
    if (preg_match_all($legacy, $callBlock) !== 1) {
        $fail('la apertura WebRTC local no corresponde al flujo conocido');
    }
    $replaced = preg_replace($legacy, $found[0], $callBlock, 1, $count);
    if ($replaced === null || $count !== 1) $fail('no se pudo preparar la llamada');
    $live = substr_replace($live, $replaced, $startAt, strlen($callBlock));
    echo "Integrado inicio saliente con SDK único sin cambiar recepción.\n";
}
// Para el modal, sólo tocar la misma tarjeta que ya usa Seguimiento.
// Las condiciones que Hostinger haya añadido se conservan.
$panelAnchor = 'const incomingInShell =';
$panelAt = $once($live, $panelAnchor, 'falta la protección del modal entrante');
$panelPos = strpos($live, 'panel.hidden =', $panelAt + strlen($panelAnchor));
if ($panelPos === false || $panelPos - $panelAt > 2500) {
    $fail('el panel telefónico local no tiene una estructura reconocida');
}
$beforePanel = substr($live, $panelAt, $panelPos - $panelAt);
if (str_contains($beforePanel, 'manualOutgoingInShell')) {
    // La actualización ya se había insertado. Verificar ambas condiciones.
    if (preg_match('/panel\.hidden\s*=\s*([^;]+);/', substr($live, $panelPos, 360), $m) !== 1 ||
        !str_contains($m[1], 'manualOutgoingInShell')) {
        $fail('el panel ya tiene cambios salientes parciales');
    }
    $ifSection = substr($live, $panelPos, 1000);
    if (preg_match('/if\s*\((.*?)\)\s*\{\s*return\s*;?\s*\}/s', $ifSection, $iff) !== 1 ||
        !str_contains($iff[1], 'manualOutgoingInShell')) {
        $fail('el retorno del panel no conserva el bloqueo duplicado');
    }
    echo "Panel saliente único ya integrado; estilos locales conservados.\n";
} else {
    // Verificar ancla del modo embebido y mantener el criterio de entrantes.
    if (!str_contains($beforePanel, "'embedded'") ||
        !str_contains($beforePanel, "state?.direction") ||
        !str_contains($beforePanel, "'incoming'")) {
        $fail('la protección local de entrantes fue modificada de forma desconocida');
    }
    $prefix = substr($live, 0, $panelPos);
    $tail = substr($live, $panelPos);
    if (preg_match('/^panel\.hidden\s*=\s*([^;]+);/s', $tail, $visible) !== 1 ||
        !str_contains($visible[1], 'managedByShell') ||
        !str_contains($visible[1], 'incomingInShell')) {
        $fail('el criterio de visibilidad del panel es desconocido');
    }
    $follow = substr($tail, strlen($visible[0]));
    if (preg_match('/^(\s*if\s*\()(.*?)(\)\s*\{\s*return\s*;\s*\})/s', $follow, $early) !== 1 ||
        !str_contains($early[2], 'managedByShell') ||
        !str_contains($early[2], 'incomingInShell')) {
        $fail('el retorno temprano del panel es desconocido');
    }
    $insertion = <<<'JS'
        // El marcador manual usa la misma tarjeta móvil del shell.
        // Seguimiento conserva su propia tarjeta de resultados.
        const manualOutgoingInShell =
            getHostMode() === 'embedded' &&
            state?.direction === 'outgoing' &&
            ['TELEPHONY_MANUAL', 'MARKETING_CALLBACK', 'DIALER'].includes(
                String(state?.context?.type || '').toUpperCase()
            ) && (Boolean(state?.active) || state?.phase === 'finished');
JS;
    $newVisible = str_replace($visible[1], rtrim($visible[1]) . ' || manualOutgoingInShell', $visible[0]);
    $newEarly = $early[1] . rtrim($early[2]) . ' || manualOutgoingInShell' . $early[3];
    $newTail = $newVisible . str_replace($early[0], $newEarly, $follow);
    $live = $prefix . $insertion . "\n        " . $newTail;
    echo "Integrada tarjeta saliente manual sin cambiar Seguimiento.\n";
}
if (substr_count($live, 'const manualOutgoingInShell') !== 1 ||
    !str_contains($live, 'shell.ensureHost();') ||
    !str_contains($live, 'await prepare({ openHost: false });') ||
    !str_contains($live, 'if (!show') ||
    str_contains($live, '<<<<<<< ') ||
    @file_put_contents($outputPath, $live) === false) {
    $fail('no se completaron las comprobaciones del código resultante');
}
