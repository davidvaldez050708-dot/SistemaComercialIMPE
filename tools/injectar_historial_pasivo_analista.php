<?php
/**
 * Extensión única y conservadora del shell del CRM.
 * Mantiene intacto el JavaScript de recepción, el widget SDK y su contexto.
 * Uso: php tools/injectar_historial_pasivo_analista.php origen.php destino.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Uso: php injectar_historial_pasivo_analista.php origen destino\n");
    exit(2);
}
$source = @file_get_contents($argv[1]);
if ($source === false) {
    fwrite(STDERR, "ERROR: no se pudo leer el shell.\n");
    exit(1);
}
$marker = '<!-- IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL -->';
if (str_contains($source, $marker)) {
    if (!str_contains($source, 'telefonia_analista_historial_pasivo.js')) {
        fwrite(STDERR, "CONFLICTO: marcador existente sin recurso esperado.\n");
        exit(1);
    }
    $output = $source;
} else {
    $needle = "</body>\n</html>";
    if (substr_count($source, $needle) !== 1 ||
        !str_contains($source, '$analistaEntrantesShell') ||
        !str_contains($source, 'impeShellRuntime')) {
        fwrite(STDERR, "CONFLICTO: no se reconoció el shell telefónico de producción.\n");
        exit(1);
    }

    $extension = <<<'PHP'
    <!-- IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL -->
    <?php if ($analistaEntrantesShell): ?>
    <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
    <script>
        window.IMPE_ANALYST_WEBRTC_JOURNAL = <?= json_encode([
            'enabled' => true,
            'endpoint' => BASE_URL . 'index.php?controller=seguimientoVinculacion&action=eventoEntranteWebrtc',
            'csrf' => (string)$_SESSION['csrf_token']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_analista_historial_pasivo.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_analista_historial_pasivo.js') ?>"></script>
    <?php endif; ?>
PHP;
    $output = str_replace($needle, $extension . "\n" . $needle, $source);
}
if (@file_put_contents($argv[2], $output) !== strlen($output)) {
    fwrite(STDERR, "ERROR: no se pudo generar el shell temporal.\n");
    exit(1);
}
echo "OK: extensión pasiva aislada; telefonia_shell.js y host WebRTC no se modifican.\n";