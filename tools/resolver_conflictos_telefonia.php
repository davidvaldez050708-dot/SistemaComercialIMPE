<?php
/**
 * Resuelve exclusivamente los cuatro bloques reportados en Hostinger.
 * Se aplica al resultado temporal de git merge-file, nunca a producción.
 * Cada par LOCAL/GITHUB debe coincidir exactamente (sin cambios semánticos).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    fwrite(STDERR, "Uso: php resolver_conflictos_telefonia.php <ruta> <fusion> <git> <temporal>\n");
    exit(2);
}
[$cmd, $path, $mergedPath, $githubPath, $outPath] = $argv;
$merged = @file_get_contents($mergedPath);
$github = @file_get_contents($githubPath);
if ($merged === false || $github === false) {
    fwrite(STDERR, "CONFLICTO: No fue posible inspeccionar los archivos temporales.\n");
    exit(2);
}
$rules = [
    'public/javascript/telefonia_host.js' => [
        [
            <<<'HOSTINGER'

        document.querySelectorAll(
            '[data-impe-native-phone-hidden="1"]'
        ).forEach(function (raiz) {
            if (!(raiz instanceof HTMLElement)) {
                return;
            }

            raiz.dataset.impeNativePhoneHidden = '0';
            raiz.removeAttribute('aria-hidden');
            raiz.style.setProperty(
                'position',
                'fixed',
                'important'
            );
            raiz.style.setProperty(
                'left',
                'auto',
                'important'
            );
            raiz.style.setProperty(
                'top',
                'auto',
                'important'
            );
            raiz.style.setProperty(
                'right',
                '10px',
                'important'
            );
            raiz.style.setProperty(
                'bottom',
                '10px',
                'important'
            );
            raiz.style.setProperty(
                'opacity',
                '1',
                'important'
            );
            raiz.style.setProperty(
                'pointer-events',
                'auto',
                'important'
            );
        });
HOSTINGER,
            <<<'GITHUB'
        document.body.classList.add('telephony-host-incoming');
GITHUB
        ],
        [
            <<<'HOSTINGER'
    const observerNativo =
        typeof MutationObserver === 'function'
            ? new MutationObserver(function () {
                requestAnimationFrame(
                    ocultarInterfazNativa
                );
            })
            : null;

    observerNativo?.observe(
        document.body,
        {
            childList: true,
            subtree: true
        }
    );
HOSTINGER,
            <<<'GITHUB'
    // Ningún MutationObserver recoloca elementos del proveedor.
GITHUB
        ],
    ],
    'public/css/telefonia_shell.css' => [
        [
            <<<'HOSTINGER'
  position: fixed; width: 3px; height: 3px; right: 0; bottom: 0; z-index: 1100;
HOSTINGER,
            <<<'GITHUB'
  position: fixed; width: min(420px, calc(100vw - 22px));
  height: min(450px, calc(100dvh - 24px));
  right: -10000px; bottom: 12px; z-index: 1100;
GITHUB
        ],
    ],
    'public/javascript/telefonia_persistente.js' => [
        [
            <<<'HOSTINGER'
        panel.hidden = !show || managedByShell;
HOSTINGER,
            <<<'GITHUB'
        // Las entrantes se contestan en el widget oficial del shell.
        // Evitar la segunda tarjeta con "Llamar" desactivado que confundía
        // con una acción de contestar inexistente en nuestro cliente PBX.
        // Una llamada entrante, incluso finalizada, pertenece a una sola
        // interfaz: el shell. No crear segundo panel ni resumen huérfano.
        const incomingInShell =
            state?.direction === 'incoming' &&
            getHostMode() === 'embedded';
        panel.hidden = !show || managedByShell || incomingInShell;
GITHUB
        ],
    ],
];
if (!isset($rules[$path])) {
    fwrite(STDERR, "CONFLICTO: Ruta sin resolución aprobada: $path\n");
    exit(2);
}
$pattern = '/^<<<<<<< HOSTINGER\r?\n(.*?)^=======\r?\n(.*?)^>>>>>>> GITHUB\r?\n/ms';
$count = preg_match_all($pattern, $merged, $matches, PREG_SET_ORDER);
if ($count === false || $count === 0 ||
    substr_count($merged, '<<<<<<< HOSTINGER') !== $count ||
    str_contains($merged, '||||||| BASE')) {
    fwrite(STDERR, "CONFLICTO: Marcadores Git desconocidos o incompletos.\n");
    exit(2);
}
$used = [];
$resolved = preg_replace_callback($pattern, static function (array $match) use ($rules, $path, $github, &$used): string {
    foreach ($rules[$path] as $index => [$local, $remote]) {
        if (trim($match[1]) !== trim($local) ||
            trim($match[2]) !== trim($remote) ||
            isset($used[$index]) ||
            !str_contains($github, trim($remote))) {
            continue;
        }
        $used[$index] = true;
        // Se conserva todo el texto ya fusionado fuera del bloque.
        return $match[2];
    }
    throw new RuntimeException('CONFLICTO: Fragmento inesperado o modificado en ' . $path);
}, $merged);
if ($resolved === null || count($used) !== $count ||
    str_contains($resolved, '<<<<<<< ') ||
    str_contains($resolved, '>>>>>>> ') ||
    str_contains($resolved, '||||||| ')) {
    fwrite(STDERR, "CONFLICTO: La fusión no quedó completamente resuelta.\n");
    exit(2);
}
if (@file_put_contents($outPath, $resolved) === false) {
    fwrite(STDERR, "ERROR: No se pudo escribir la copia temporal de la fusión.\n");
    exit(2);
}
echo "Fusión selectiva aprobada: $path ($count bloque(s)).\n";
